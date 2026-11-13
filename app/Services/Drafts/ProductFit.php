<?php

namespace App\Services\Drafts;

use App\Models\Product;
use App\Models\ProductOptionValue;
use Illuminate\Support\Collection;

/**
 * How well one product fits the details stated in the notes, detail by detail:
 *
 *   yes      the catalog says so (store tags, the product's page)
 *   option   not as standard, but one of its options does ("Stainless Steel 304" +$87)
 *   unknown  the catalog doesn't say: counts as possible, ranked below confirmed
 *   no       the catalog says otherwise
 *
 * Type, opening size and fire rating are normally filtered upstream; their failures
 * are also checked here for exact SKUs and manually selected products. A model or brand the
 * catalog doesn't carry at all says nothing about our products: unknown, flagged.
 * Features ("airtight") only ever rank: yes or unknown, never no.
 */
class ProductFit
{
    public const YES = 'yes';

    public const OPTION = 'option';

    public const UNKNOWN = 'unknown';

    public const NO = 'no';

    private const LABELS = [
        'model' => 'Model',
        'brand' => 'Brand',
        'application' => 'Application',
        'surface_type' => 'Surface',
        'material' => 'Material',
        'flange_type' => 'Flange',
        'insulated' => 'Insulated',
        'load_psf' => 'Load',
        'load_lbs' => 'Capacity',
    ];

    /** Words looked for per feature; anything else is looked for as written. Store tags use the right-hand spellings. */
    private const FEATURE_TERMS = [
        'airtight' => ['airtight', 'air & dust resistant', 'air and dust'],
        'watertight' => ['watertight', 'floodtight', 'waterproof'],
        'waterproof' => ['waterproof', 'watertight', 'floodtight'],
        'high security' => ['security'],
        'vandal resistant' => ['vandal', 'security'],
        'tile insert' => ['tile'],
        'piano hinge' => ['piano hinge', 'continuous hinge'],
        'touch latch' => ['touch latch', 'push latch'],
        'gasketed' => ['gasket'],
        'soundproof' => ['soundproof', 'acoustical', 'sound rated'],
        'acoustic' => ['acoustical', 'soundproof', 'sound rated'],
    ];

    /** The store itself, not a manufacturer: "Best Access" says nothing about the brand. */
    private const STORE_BRAND = '/^\s*best(\s*access(\s*doors)?)?\s*$/i';

    private const BRAND_STOPWORDS = ['usa', 'inc', 'industries', 'associates', 'enterprises', 'construction', 'systems', 'co', 'corp', 'llc', 'mfg', 'manufacturing', 'company', 'the'];

    /** @var array<string, ?array{0: string, 1: string}> written model code => how it matches the catalog */
    private array $models = [];

    private ?Collection $brands = null;

    /**
     * @param  ?list<int>  $selectedOptionValueIds  null for candidates; actual choices for a selected product
     * @return array{checks: list<array{spec: string, label: string, value: string, verdict: string, evidence: ?string, option_value_id: ?int}>, yes: int, unknown: int, no: int}
     */
    public function check(RequestedItem $item, Product $product, ?array $selectedOptionValueIds = null): array
    {
        $checks = [];

        foreach (self::LABELS as $spec => $label) {
            if (! $item->has($spec) || ($spec === 'brand' && $this->isStoreBrand($item->value('brand')))) {
                continue;
            }

            $wanted = $item->value($spec);
            [$verdict, $evidence] = match ($spec) {
                'model' => $this->model($product, $item),
                'brand' => $this->brand($product, (string) $wanted),
                'application' => $this->application($product, (string) $wanted),
                'surface_type' => $this->tagged($product->surface_type, (string) $wanted),
                'material' => $this->material($product, (string) $wanted),
                'flange_type' => $this->flange($product, (string) $wanted),
                'insulated' => $this->insulated($product, (bool) $wanted),
                'load_psf' => $this->load($product, (float) $wanted),
                'load_lbs' => $this->capacity($product, (float) $wanted),
            };

            $viaOption = null;
            if ($verdict !== self::YES && ($viaOption = $this->viaOption($product, $spec, $wanted, $selectedOptionValueIds))) {
                [$verdict, $evidence] = [self::OPTION, $this->optionLabel($viaOption)];
            } elseif ($selectedOptionValueIds !== null && $verdict !== self::YES && ($required = $this->viaOption($product, $spec, $wanted))) {
                [$verdict, $evidence] = [self::NO, 'Required choice is not selected: '.$this->optionLabel($required)];
            }

            $checks[] = [
                'spec' => $spec,
                'label' => $label,
                'value' => $this->display($spec, $wanted),
                'verdict' => $verdict,
                'evidence' => $evidence,
                'option_value_id' => $viaOption?->id,
            ];
        }

        foreach ($item->features as $feature) {
            [$verdict, $evidence] = $this->feature($product, $feature['text']);
            $checks[] = ['spec' => 'feature', 'label' => 'Feature', 'value' => $feature['text'], 'verdict' => $verdict, 'evidence' => $evidence, 'option_value_id' => null];
        }

        array_push($checks, ...$this->hardChecks($item, $product));

        $count = fn (string ...$verdicts) => count(array_filter($checks, fn ($c) => in_array($c['verdict'], $verdicts, true)));

        return ['checks' => $checks, 'yes' => $count(self::YES, self::OPTION), 'unknown' => $count(self::UNKNOWN), 'no' => $count(self::NO)];
    }

    /** Hard-filter failures must stay visible when a SKU or manual choice bypasses SQL filters. */
    private function hardChecks(RequestedItem $item, Product $product): array
    {
        $checks = [];
        $add = function (string $spec, string $label, string $value, string $verdict, string $evidence) use (&$checks): void {
            $checks[] = compact('spec', 'label', 'value', 'verdict', 'evidence') + ['option_value_id' => null];
        };

        if ($item->type !== null && $item->type !== $product->product_type) {
            $add('product_type', 'Product type', Product::TYPE_LABELS[$item->type], self::NO, Product::TYPE_LABELS[$product->product_type]);
        }

        if ($size = $item->value('size')) {
            $w = $product->nominal_width;
            $h = $product->nominal_height;
            $wanted = $this->sizeLabel($size['width'], $size['height']);
            if ($w === null || $h === null) {
                $add('size', 'Opening', $wanted, self::UNKNOWN, 'Opening size is not recorded');
            } elseif (! (($w == $size['width'] && $h == $size['height']) || ($w == $size['height'] && $h == $size['width']))) {
                $add('size', 'Opening', $wanted, self::NO, $this->sizeLabel($w, $h));
            }
        }

        if ($item->has('fire_rated') && $product->fire_rated !== $item->value('fire_rated')) {
            $add('fire_rated', 'Fire-rated', $item->value('fire_rated') ? 'Yes' : 'No',
                $product->fire_rated === null ? self::UNKNOWN : self::NO,
                $product->fire_rated === null ? 'Fire rating is not recorded' : ($product->fire_rated ? 'Fire-rated' : 'Non-fire-rated'));
        }

        return $checks;
    }

    private function sizeLabel(float $width, float $height): string
    {
        return (float) $width.'" x '.(float) $height.'"';
    }

    /** An exact SKU identifies a product, without resolving contradictory requirements. */
    public function conflictMessage(Product $product, array $check): string
    {
        return "{$product->sku}: the request specifies {$check['label']} {$check['value']}, but the catalog lists "
            .($check['evidence'] ?: 'a different specification').'. Confirm the requirement or choose another product.';
    }

    /**
     * Model or brand names in the notes that match nothing we sell. Matching then
     * runs on the other details, and the salesperson is told.
     *
     * @return list<array{spec: string, label: string, value: string, quote: ?string}>
     */
    public function unknownNames(RequestedItem $item): array
    {
        $unknown = [];

        if ($item->has('model') && $this->namedModel($item) === null) {
            $unknown[] = ['spec' => 'model', 'label' => 'Model', 'value' => (string) $item->value('model'), 'quote' => $item->specs['model']['quote'] ?? null];
        }

        $brand = $item->value('brand');
        if ($brand !== null && ! $this->isStoreBrand($brand) && ! $this->brandInCatalog($this->brandWords((string) $brand))) {
            $unknown[] = ['spec' => 'brand', 'label' => 'Brand', 'value' => (string) $brand, 'quote' => $item->specs['brand']['quote'] ?? null];
        }

        return $unknown;
    }

    /**
     * How the written model matches the catalog, most specific first: a full SKU
     * ("BA-AHD-12-12"), a model ("BA-AHD"), a model without our "BA-" prefix ("AHD"),
     * or part of a model ("UF-5000" in "BA-UF-5000-PC"). Null when nothing matches.
     *
     * @return ?array{0: 'sku'|'model'|'partial', 1: string} the mode and the code to compare
     */
    public function namedModel(RequestedItem $item): ?array
    {
        $code = $this->code((string) $item->value('model'));

        if (strlen($code) < 3) {
            return null;
        }

        if (! array_key_exists($code, $this->models)) {
            $sku = "regexp_replace(upper(sku), '[^A-Z0-9]', '', 'g')";
            $model = "regexp_replace(upper(coalesce(model, '')), '[^A-Z0-9]', '', 'g')";

            $this->models[$code] = match (true) {
                Product::whereRaw("{$sku} = ?", [$code])->exists() => ['sku', $code],
                Product::whereRaw("{$model} = ?", [$code])->exists() => ['model', $code],
                Product::whereRaw("{$model} = ?", ['BA'.$code])->exists() => ['model', 'BA'.$code],
                Product::whereRaw("{$model} like ?", ["%{$code}%"])->exists() => ['partial', $code],
                default => null,
            };
        }

        return $this->models[$code];
    }

    /** "BA-AHD 12x12" and "ba ahd-12-12" are the same code: BAAHD1212. */
    public function code(string $text): string
    {
        $text = preg_replace('/(\d)\s*[x×]\s*(\d)/iu', '$1$2', $text) ?? $text;

        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($text)) ?? '';
    }

    private function model(Product $product, RequestedItem $item): array
    {
        $named = $this->namedModel($item);

        if ($named === null) {
            return [self::UNKNOWN, 'Not in our catalog'];
        }

        [$mode, $code] = $named;
        $model = $this->code((string) $product->model);
        $hit = match ($mode) {
            'sku' => $this->code($product->sku) === $code,
            'model' => $model === $code,
            'partial' => str_contains($model, $code),
        };

        return $hit ? [self::YES, null] : [self::NO, $product->model ?: $product->sku];
    }

    /** "Acudor" is met by "Acudor USA"; a brand we don't carry is unknown, not no. */
    private function brand(Product $product, string $wanted): array
    {
        $words = $this->brandWords($wanted);

        if (! $this->brandInCatalog($words)) {
            return [self::UNKNOWN, 'Not in our catalog'];
        }
        if (! $product->brand) {
            return [self::UNKNOWN, null];
        }

        return array_diff($words, $this->brandWords($product->brand)) === [] ? [self::YES, null] : [self::NO, $product->brand];
    }

    private function isStoreBrand(mixed $brand): bool
    {
        return is_string($brand) && preg_match(self::STORE_BRAND, $brand) === 1;
    }

    /** @param  list<string>  $words */
    private function brandInCatalog(array $words): bool
    {
        $this->brands ??= Product::query()->whereNotNull('brand')->distinct()->pluck('brand')->map(fn ($b) => $this->brandWords($b));

        return $words !== [] && $this->brands->contains(fn (array $have) => array_diff($words, $have) === []);
    }

    /** @return list<string> */
    private function brandWords(string $brand): array
    {
        preg_match_all('/[a-z0-9]+/', mb_strtolower($brand), $m);

        return array_values(array_diff($m[0], self::BRAND_STOPWORDS));
    }

    /** Store tags and the name first, then the product page. Absent means unknown: pages don't list everything. */
    private function feature(Product $product, string $feature): array
    {
        $key = mb_strtolower(trim($feature));
        $terms = array_map(fn ($t) => $this->squash($t), self::FEATURE_TERMS[$key] ?? [$key, preg_replace('/(ed|ing)$/', '', $key)]);
        $found = fn (string $text) => (bool) array_filter($terms, fn ($t) => strlen($t) >= 3 && str_contains($this->squash($text), $t));

        if ($found(implode(' | ', [$product->name, ...$product->purpose]))) {
            return [self::YES, null];
        }

        $page = $product->page;
        $specs = array_map(fn (array $s) => "{$s['label']}: {$s['value']}", $page?->spec_lines ?? []);
        if ($page && $found(implode(' | ', [(string) $page->description, ...$specs]))) {
            return [self::YES, 'Product page'];
        }

        return [self::UNKNOWN, null];
    }

    /** "Air-tight", "air tight" and "airtight" read the same. */
    private function squash(string $text): string
    {
        return preg_replace('/[^a-z0-9&]/', '', mb_strtolower($text)) ?? '';
    }

    /** Store facet tags: empty means the store never said. */
    private function tagged(array $tags, string $wanted): array
    {
        if ($tags === []) {
            return [self::UNKNOWN, null];
        }

        return in_array(mb_strtolower($wanted), array_map('mb_strtolower', $tags), true)
            ? [self::YES, null]
            : [self::NO, implode(', ', $tags)];
    }

    /** An attic ladder goes in the ceiling: "Ceiling" is met by a ladder the store tags "Attic". */
    private function application(Product $product, string $wanted): array
    {
        $verdict = $this->tagged($product->application, $wanted);

        if ($verdict[0] === self::NO && $product->product_type === 'ladder' && mb_strtolower($wanted) === 'ceiling'
            && in_array('attic', array_map('mb_strtolower', $product->application), true)) {
            return [self::YES, 'Attic ladder: installs in the ceiling'];
        }

        return $verdict;
    }

    /** "Steel" is met by galvanized / stainless / cold rolled steel; "Stainless Steel" only by stainless. */
    private function material(Product $product, string $wanted): array
    {
        $known = array_filter([...$product->material, $product->page?->material]);
        if ($known === []) {
            return [self::UNKNOWN, null];
        }

        $text = mb_strtolower(implode(' | ', $known));

        return str_contains($text, mb_strtolower($wanted)) ? [self::YES, null] : [self::NO, implode(', ', $known)];
    }

    private function flange(Product $product, string $wanted): array
    {
        $known = array_values(array_unique([...$product->flange_type, ...($product->page?->flange_types ?? [])]));

        return $this->tagged($known, $wanted);
    }

    /** Explicit page evidence wins over tags; an omission is not evidence of no insulation. */
    private function insulated(Product $product, bool $wanted): array
    {
        $page = $product->page;
        $stated = $page?->insulated !== null && $page->insulated_basis !== 'not_mentioned';
        $is = $stated ? $page->insulated : $product->insulated;

        if ($is === null) {
            return [self::UNKNOWN, null];
        }

        $evidence = $stated ? $page->insulation_evidence : null;

        return [$is === $wanted ? self::YES : self::NO, $evidence];
    }

    /** "300 PSF" is met by any rating at or above it. */
    private function load(Product $product, float $wanted): array
    {
        $ratings = [];
        foreach ($product->purpose as $tag) {
            if (preg_match('/^(\d+(?:\.\d+)?)\s*PSF$/i', $tag, $m)) {
                $ratings[] = (float) $m[1];
            }
        }

        if ($ratings === []) {
            return [self::UNKNOWN, null];
        }

        return max($ratings) >= $wanted ? [self::YES, null] : [self::NO, max($ratings).' PSF'];
    }

    /**
     * "Must hold 400 lbs" is met by a rated capacity at or above it, read from the title
     * ("… - 400 lbs. Load Capacity") or the page's "Load Capacity: 350 lbs" spec.
     */
    private function capacity(Product $product, float $wanted): array
    {
        $ratings = [];
        if (preg_match('/(\d{2,5})\s*(?:lbs?|pounds)\.?\s*(?:load\s*)?capacity/i', $product->name, $m)) {
            $ratings[] = (float) $m[1];
        }
        foreach ($product->page?->spec_lines ?? [] as $spec) {
            if (preg_match('/capacity|load rating|weight rating/i', $spec['label']) && preg_match('/(\d{2,5})\s*(?:lbs?|pounds)\b/i', $spec['value'], $m)) {
                $ratings[] = (float) $m[1];
            }
        }

        if ($ratings === []) {
            return [self::UNKNOWN, null];
        }

        $rated = max($ratings);
        $shown = $this->display('load_lbs', $rated);

        return $rated >= $wanted ? [self::YES, "Rated {$shown}"] : [self::NO, $shown];
    }

    /** An option choice that provides what the base product doesn't ("Stainless Steel 304 #4"). */
    private function viaOption(Product $product, string $spec, mixed $wanted, ?array $selectedOptionValueIds = null): ?ProductOptionValue
    {
        $needle = match ($spec) {
            'material', 'flange_type' => mb_strtolower((string) $wanted),
            'insulated' => $wanted ? 'insulat' : null,
            default => null,
        };

        if ($needle === null || ! $product->relationLoaded('options')) {
            return null;
        }

        foreach ($product->options as $option) {
            foreach ($option->values as $value) {
                if ($selectedOptionValueIds !== null && ! in_array($value->id, $selectedOptionValueIds, true)) {
                    continue;
                }
                $name = mb_strtolower($value->name);
                if (! $value->is_none && ! $value->needs_review && str_contains($name, $needle) && ! preg_match('/\b(un|non)[- ]?insulated\b/', $name)) {
                    return $value->setRelation('option', $option);
                }
            }
        }

        return null;
    }

    private function optionLabel(ProductOptionValue $value): string
    {
        $price = $value->price_delta_cents ? sprintf(' (+$%s)', number_format($value->price_delta_cents / 100, 2)) : '';

        return "{$value->option->label}: {$value->name}{$price}";
    }

    private function display(string $spec, mixed $value): string
    {
        return match ($spec) {
            'insulated' => $value ? 'Yes' : 'No',
            'load_psf' => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').' PSF',
            'load_lbs' => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').' lbs',
            default => (string) $value,
        };
    }
}
