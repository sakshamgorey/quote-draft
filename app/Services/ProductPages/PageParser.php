<?php

namespace App\Services\ProductPages;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Reads one saved bestaccessdoors.com (BigCommerce) product page into plain data:
 * options and their priced choices, spec lines, description, page price, size
 * picker links and compatibility statements. No database, no network.
 *
 * Choice labels are typed by hand on the store ("Key Operated Cylinder Cam Latch
 * $16 + 5 days", "Red + 121.95"), so prices are read with patterns; anything
 * unclear is marked needs_review and never guessed.
 */
class PageParser
{
    private const BASE_URL = 'https://www.bestaccessdoors.com';

    // "$16", "$1,700.81", "+ 121.95" (a bare "+ 5" is lead time, not money).
    // "$16", "- $5", and the store's dollar-less "+ 121.95", "+ 116.1", "+ 46" — but not "+ 5 days" or "+ 3/4 inch".
    private const MONEY = '/(?<sign>-)?\s*\$\s*(?<a>\d[\d,]*(?:\.\d{1,2})?)|\+\s*(?<b>\d[\d,]*(?:\.\d{1,2})?)'.self::NOT_A_MEASURE.'/i';

    private const NOT_A_MEASURE = '(?![\d.,\/]|\s*(?:business\s+)?(?:days?|weeks?|hours?|hrs?|%|"|\'|in\b|inch|ft\b|feet|mm\b|cm\b))';

    private const LEAD_TIME = '/\+?\s*(\d+)\s*(?:business\s+)?(day|days|week|weeks)\b/i';

    private const COMPATIBLE = '/compatible\s+with\s+((?:BA-[A-Z0-9-]+[\s,\/&and]*)+)/i';

    // Labels that name a physical spec, as opposed to a marketing headline.
    private const SPEC_LABELS = '/^((door|frame|panel|cover|curb|leaf)\s*)?(size|dimensions?|material|construction|gauge|thickness|finish|color|hinges?|locks?|latch(es)?|locking mechanism|handles?|flange|frame|cover|curb|door|seal|gasket|insulation|fire rating|rating|load|weight|components|hardware|installation|rough (wall )?opening|mounting|operation|opening|finish(es)?|certification|warranty)$/i';

    /** @return array<string, mixed> */
    public function parse(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new DOMXPath($dom);

        $warnings = [];
        $lines = $this->descriptionLines($xp);
        if ($lines === []) {
            $warnings[] = 'No product description found.';
        }

        $specs = $this->specLines($lines);
        $options = $this->options($xp, $warnings);
        [$insulated, $basis, $evidence] = $this->insulation($specs, $lines);

        return [
            'store_product_id' => $this->storeProductId($xp, $html),
            'page_price' => $this->pagePrice($xp, $html),
            'description' => $lines === [] ? null : mb_substr(implode("\n", $lines), 0, 12000),
            'spec_lines' => $specs,
            'spec_sheet_url' => $this->specSheet($xp),
            'insulated' => $insulated,
            'insulated_basis' => $basis,
            'insulation_evidence' => $evidence,
            'gauge' => $this->gauge($specs, $lines),
            'flange_types' => $this->flanges($specs, $lines),
            'material' => $this->specValue($specs, '/^(material|construction)$/i'),
            'options' => $options,
            'size_links' => $this->sizeLinks($xp),
            'compatible_models' => $this->compatibleModels(implode("\n", $lines)),
            'warnings' => $warnings,
        ];
    }

    /**
     * One choice label → name, price change, lead time, flags.
     *
     * @return array{name: string, price_delta_cents: ?int, lead_time_text: ?string, lead_time_days: ?int, is_default: bool, is_none: bool, needs_review: bool}
     */
    public function choice(string $raw): array
    {
        $raw = $this->clean($raw);
        preg_match_all(self::MONEY, $raw, $m, PREG_SET_ORDER);
        $amounts = array_values(array_unique(array_map(fn ($x) => ($x['sign'] ?? '').str_replace(',', '', ($x['a'] ?? '') !== '' ? $x['a'] : $x['b']), $m)));

        $lead = preg_match(self::LEAD_TIME, $raw, $l) ? $l : null;
        $isDefault = (bool) preg_match('/\bstandard\b/i', $raw);
        $isNone = (bool) preg_match('/^(no\s+selected|not\s+selected|none|no\s+thanks)\b/i', $raw);

        // No money mentioned: the store only writes a price when a choice costs extra.
        $price = match (count($amounts)) {
            0 => 0,
            1 => (int) round((float) $amounts[0] * 100),
            default => null,
        };

        $name = preg_replace([
            '/,?\s*[-–]?\s*\$\s*\d[\d,]*(?:\.\d{1,2})?/u',
            '/\+\s*\$?\s*\d[\d,]*(?:\.\d{1,2})?'.self::NOT_A_MEASURE.'/iu',
            self::LEAD_TIME,
            '/\s*[-–]\s*standard\b/iu',
        ], '', $raw);
        $name = trim(preg_replace('/\s{2,}/', ' ', $name), " \t,-–+");

        return [
            'name' => $name !== '' ? $name : $raw,
            'price_delta_cents' => $price,
            'lead_time_text' => $lead ? '+ '.$lead[1].' '.strtolower($lead[2]) : null,
            'lead_time_days' => $lead ? (int) $lead[1] * (str_starts_with(strtolower($lead[2]), 'week') ? 7 : 1) : null,
            'is_default' => $isDefault,
            'is_none' => $isNone,
            'needs_review' => $price === null,
        ];
    }

    /** @return list<string> paragraphs / list items of the description, in order */
    private function descriptionLines(DOMXPath $xp): array
    {
        $root = $xp->query('//*[@id="description-content"]')->item(0)
            ?? $xp->query('//*[contains(@class,"productView-description")]')->item(0);

        if (! $root) {
            return [];
        }

        foreach ($xp->query('.//script|.//style|.//iframe|.//img|.//noscript', $root) as $n) {
            $n->parentNode->removeChild($n);
        }

        $lines = [];
        foreach ($xp->query('.//p|.//li|.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $root) as $block) {
            // Skip blocks that contain other blocks: their children are visited themselves.
            if ($xp->query('.//p|.//li', $block)->length > 0) {
                continue;
            }
            $text = $this->clean($block->textContent);
            if ($text !== '' && ! preg_match('/^(product video|leed certification:?)$/i', $text)) {
                $lines[] = $text;
            }
        }

        return array_values(array_unique($lines));
    }

    /**
     * "Cover: 14 gauge … insulation", "Flange: Exposed flange". Marketing lines share the
     * "Label: sentence" shape ("Easy to Install: BA-AHD's intelligent design…"), so a line
     * counts only with a known spec label, or a short label with a short value.
     *
     * @return list<array{label: string, value: string}>
     */
    private function specLines(array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $specs = [];
        // Some pages put several "• Cover: … • Curb: …" bullets in one paragraph.
        $parts = array_merge(...array_map(fn ($l) => preg_split('/\s*[•·]\s*/u', $l), $lines));

        foreach (array_filter($parts) as $line) {
            $line = preg_replace('/^[\-–*]\s*/u', '', $line);

            if (! preg_match('/^([A-Za-z][A-Za-z0-9 \/&().\'-]{1,40}?)\s*:\s*(.{1,800})$/u', $line, $m)) {
                continue;
            }

            $label = trim($m[1]);
            // Lost characters on the store's side show up as "??": drop that noise.
            $value = mb_substr(trim(preg_replace('/\?{2,}/', '', $m[2])), 0, 300);
            if ($value === '') {
                continue;
            }
            $known = preg_match(self::SPEC_LABELS, $label);
            $short = str_word_count($label) <= 2 && mb_strlen($value) <= 90;

            if (($known || $short) && ! preg_match('/^(note|usa|canada|http|https|tip|options?)$/i', $label)) {
                $specs[] = ['label' => $label, 'value' => $value];
            }
        }

        return $specs;
    }

    private function specValue(array $specs, string $labelPattern): ?string
    {
        foreach ($specs as $s) {
            if (preg_match($labelPattern, $s['label'])) {
                return mb_substr($s['value'], 0, 120);
            }
        }

        return null;
    }

    /** @return array{0: ?bool, 1: ?string, 2: ?string} insulated, basis, evidence */
    private function insulation(array $specs, array $lines): array
    {
        $candidates = [...array_map(fn ($s) => "{$s['label']}: {$s['value']}", $specs), ...$lines];

        foreach ($candidates as $line) {
            if (preg_match('/\b(un|non)[- ]?insulated\b|\bwithout insulation\b|\bno insulation\b/i', $line)) {
                return [false, 'stated', mb_substr($line, 0, 300)];
            }
        }

        foreach ($candidates as $line) {
            if (preg_match('/\binsulat(ed|ion)\b/i', $line)) {
                return [true, 'stated', mb_substr($line, 0, 300)];
            }
        }

        // A spec list that never mentions insulation: almost certainly not insulated.
        return $specs !== [] ? [false, 'not_mentioned', null] : [null, null, null];
    }

    private function gauge(array $specs, array $lines): ?int
    {
        foreach ([...array_column($specs, 'value'), ...$lines] as $text) {
            if (preg_match('/\b(\d{1,2})\s*[- ]?\s*(?:ga\.?|gauge)\b/i', $text, $m) && (int) $m[1] >= 8 && (int) $m[1] <= 30) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /** @return list<string> flange types named in the specs or description (catalog vocabulary) */
    private function flanges(array $specs, array $lines): array
    {
        $text = implode("\n", [...array_map(fn ($s) => "{$s['label']}: {$s['value']}", $specs), ...$lines]);
        $found = [];

        foreach (config('catalog.flange_keywords', []) as $phrase => $flange) {
            if (stripos($text, $phrase) !== false) {
                $found[] = $flange;
            }
        }

        return array_values(array_unique($found));
    }

    /** @param  list<string>  $warnings */
    private function options(DOMXPath $xp, array &$warnings): array
    {
        $options = [];

        foreach ($xp->query('//*[@data-product-attribute]') as $position => $field) {
            /** @var DOMElement $field */
            $labelNode = $xp->query('.//label[contains(@class,"form-label")][not(@for)] | .//*[contains(@class,"form-label")][1]', $field)->item(0);
            $label = $labelNode ? $this->clean($labelNode->textContent) : '';
            $required = (bool) preg_match('/\(\*\)|\*|required/i', $label);
            $label = trim(preg_replace('/\(\*\)|\*|required/i', '', $label), " :\t");

            $input = $xp->query('.//*[starts-with(@name,"attribute[")]', $field)->item(0);
            $optionId = $input && preg_match('/attribute\[(\d+)\]/', $input->getAttribute('name'), $m) ? (int) $m[1] : null;

            // The page repeats each choice id on more than one element: keep the first labelled one.
            $values = [];
            $images = [];
            foreach ($xp->query('.//*[@data-product-attribute-value]', $field) as $item) {
                $id = (int) $item->getAttribute('data-product-attribute-value');
                $text = $this->choiceLabel($xp, $item);
                if ($text !== '' && ! isset($values[$id])) {
                    $values[$id] = [$id, $text];
                }
                $images[$id] ??= $this->choiceImage($xp, $item);
            }
            foreach ($xp->query('.//select/option[@value!=""]', $field) as $opt) {
                $id = (int) $opt->getAttribute('value');
                $values[$id] ??= [$id, $this->clean($opt->textContent)];
            }

            $values = array_values(array_filter($values, fn ($v) => $v[1] !== ''));
            if ($label === '' && $values === []) {
                continue;
            }

            $choices = [];
            foreach ($values as $i => [$valueId, $raw]) {
                $choices[] = ['store_value_id' => $valueId ?: null, 'raw_label' => $raw, 'image_url' => $images[$valueId] ?? null, 'position' => $i] + $this->choice($raw);
            }

            // "No Selected" is the default when nothing is marked Standard.
            if ($choices !== [] && ! in_array(true, array_column($choices, 'is_default'), true)) {
                foreach ($choices as &$c) {
                    if ($c['is_none']) {
                        $c['is_default'] = true;
                        break;
                    }
                }
                unset($c);
            }

            // Several "Standard" choices: keep the first as the default. Usually legitimate
            // (BA-RAH: Wood or Fesco, both $0); conflicting duplicates are caught below.
            $defaults = array_keys(array_filter(array_column($choices, 'is_default')));
            foreach (array_slice($defaults, 1) as $i) {
                $choices[$i]['is_default'] = false;
            }

            // Same choice name at two different prices: a person has to say which is right.
            $byName = [];
            foreach ($choices as $i => $c) {
                $byName[mb_strtolower($c['name'])][] = $i;
            }
            foreach ($byName as $indexes) {
                if (count($indexes) > 1 && count(array_unique(array_map(fn ($i) => $choices[$i]['price_delta_cents'], $indexes))) > 1) {
                    foreach ($indexes as $i) {
                        $choices[$i]['needs_review'] = true;
                    }
                    $warnings[] = "Option \"{$label}\" lists \"{$choices[$indexes[0]]['name']}\" at different prices.";
                }
            }

            if ($required && ! in_array(true, array_column($choices, 'is_default'), true)) {
                $warnings[] = "Required option \"{$label}\" has no standard choice.";
            }
            foreach ($choices as $c) {
                if ($c['price_delta_cents'] === null) {
                    $warnings[] = "Couldn't read the price of \"{$c['raw_label']}\" ({$label}).";
                }
            }

            $options[] = [
                'store_option_id' => $optionId,
                'label' => $label !== '' ? $label : 'Option',
                'display_type' => $field->getAttribute('data-product-attribute'),
                'required' => $required,
                'position' => $position,
                'values' => $choices,
            ];
        }

        return $options;
    }

    /** A choice's visible text; image-only choices fall back to the image title. */
    private function choiceLabel(DOMXPath $xp, DOMNode $item): string
    {
        $label = $xp->query('.//label', $item)->item(0);
        $text = $this->clean($label ? $label->textContent : $item->textContent);

        if ($text === '' && ($img = $xp->query('.//img', $item)->item(0))) {
            $text = $this->clean($img->getAttribute('title') ?: $img->getAttribute('alt'));
            $text = preg_replace('/\s*California Access Doors$/i', '', $text);
        }

        return $text;
    }

    /** The choice's picture: a product-list thumbnail, or a swatch's background image. */
    private function choiceImage(DOMXPath $xp, DOMNode $item): ?string
    {
        foreach ($xp->query('.//img', $item) as $img) {
            /** @var DOMElement $img */
            foreach (['src', 'data-src'] as $attribute) {
                $url = trim($img->getAttribute($attribute));
                if (str_starts_with($url, 'http')) {
                    return $url; // lazy-load placeholders are data: URIs
                }
            }
        }

        foreach ($xp->query('.//*[contains(@style,"background-image")]', $item) as $swatch) {
            /** @var DOMElement $swatch */
            if (preg_match('/url\(\s*["\']?(https?:[^"\')]+)/', $swatch->getAttribute('style'), $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /** @return list<array{label: string, url: string, active: bool}> */
    private function sizeLinks(DOMXPath $xp): array
    {
        $links = [];
        foreach ($xp->query('//*[contains(@class,"size_section")]//a[@href]') as $a) {
            $href = $a->getAttribute('href');
            $links[] = [
                'label' => $this->clean($a->textContent),
                'url' => str_starts_with($href, 'http') ? $href : self::BASE_URL.'/'.ltrim($href, '/'),
                'active' => str_contains(' '.$a->getAttribute('class').' ', ' active '),
            ];
        }

        return $links;
    }

    /** @return list<array{model: string, evidence: string}> */
    public function compatibleModels(string $text): array
    {
        $found = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match_all(self::COMPATIBLE, $line, $m)) {
                foreach ($m[1] as $list) {
                    preg_match_all('/BA-[A-Z0-9]+(?:-[A-Z]+)*/i', $list, $models);
                    foreach ($models[0] as $model) {
                        $found[strtoupper($model)] = ['model' => strtoupper($model), 'evidence' => mb_substr(trim($line), 0, 300)];
                    }
                }
            }
        }

        return array_values($found);
    }

    private function pagePrice(DOMXPath $xp, string $html): ?float
    {
        if (preg_match('/"without_tax":\{"formatted":"[^"]*","value":([\d.]+)/', $html, $m)) {
            return (float) $m[1];
        }

        $node = $xp->query('//*[@data-product-price-without-tax]')->item(0);

        return $node && preg_match('/[\d,]+\.\d{2}/', $node->textContent, $m) ? (float) str_replace(',', '', $m[0]) : null;
    }

    private function storeProductId(DOMXPath $xp, string $html): ?int
    {
        $input = $xp->query('//input[@name="product_id"]')->item(0);
        if ($input && ctype_digit($input->getAttribute('value'))) {
            return (int) $input->getAttribute('value');
        }

        return preg_match('/data-yotpo-product-id="(\d+)"/', $html, $m) ? (int) $m[1] : null;
    }

    private function specSheet(DOMXPath $xp): ?string
    {
        $a = $xp->query('//*[@id="tab-downloads"]//a[contains(translate(@href,"PDF","pdf"),".pdf")]')->item(0);

        return $a ? $a->getAttribute('href') : null;
    }

    private function clean(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? '');
    }
}
