<?php

namespace App\Services\Drafts;

/**
 * Checks the extractor's JSON before anything trusts it.
 *
 * The LLM only reads the message; every value it returns must be backed by the
 * customer's own words. A spec whose quote isn't in the message, whose value is
 * outside our vocabulary, or whose size numbers aren't in its quote is dropped
 * (and the matcher asks the customer instead).
 */
class DraftValidator
{
    public const MAX_ITEMS = 20;

    private const ENUM_SPECS = ['application', 'surface_type', 'material', 'flange_type'];

    /**
     * @return list<RequestedItem>
     *
     * @throws InvalidExtraction when the payload isn't the shape we asked for at all
     */
    public function validate(mixed $items, string $message): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            throw new InvalidExtraction('Extractor returned no items list.');
        }

        $haystack = $this->normalize($message);
        $rawLines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $message)), fn ($l) => $l !== ''));
        $lines = array_map(fn ($l) => $this->normalize($l), $rawLines);
        $types = config('catalog.product_types');
        $items = array_values(array_filter(array_slice($items, 0, self::MAX_ITEMS), 'is_array'));
        $quotes = array_map(fn ($raw) => $this->quoteIn($raw['quote'] ?? null, $haystack), $items);
        $result = [];

        foreach ($items as $i => $raw) {
            $type = in_array($raw['product_type'] ?? null, $types, true) ? $raw['product_type'] : null;
            $quote = $quotes[$i];
            $others = array_filter($quotes, fn ($q, $j) => $j !== $i && $q !== null, ARRAY_FILTER_USE_BOTH);
            // The notes line holding this item: the last place to find its quantity ("I need 5 24" x 24" …").
            $itemLine = $quote === null ? null : collect($rawLines)->first(fn ($l, $k) => str_contains($lines[$k], $this->normalize($quote)));
            $specs = [];
            $rejected = [];

            $raw = $this->loadUnits($raw);

            foreach (['quantity', 'size', 'fire_rated', 'insulated', 'load_psf', 'load_lbs', 'brand', 'model', ...self::ENUM_SPECS] as $name) {
                $spec = $raw[$name] ?? null;

                if ($spec === null) {
                    continue;
                }

                $checked = $this->spec($name, $spec, $haystack, $quote, $itemLine);

                // "fire rated if possible" on the access-panel line must not leak onto the floor door.
                if ($checked !== null && $this->belongsToAnotherLine($checked['quote'], $quote, $others, $lines)) {
                    $checked = null;
                }

                if ($checked === null) {
                    $rejected[] = $name;
                } else {
                    $specs[$name] = $checked;
                }
            }

            // An item with no type, no verifiable words and no specs is noise.
            if ($type === null && $quote === null && $specs === []) {
                continue;
            }

            $result[] = new RequestedItem($type, $quote, $specs, $rejected,
                $this->quotedList($raw, 'options_wanted', $haystack, $quote, $others, $lines),
                $this->quotedList($raw, 'features', $haystack, $quote, $others, $lines),
            );
        }

        return $result;
    }

    /**
     * Add-ons / variants asked for ("keyed lock"): same rules as specs, words must be in the
     * notes and on this item's line. Mapping them to a product's real choices happens later
     * (OptionSelector), against that product's options only.
     *
     * @return list<array{text: string, quote: string}>
     */
    private function quotedList(array $raw, string $key, string $haystack, ?string $itemQuote, array $others, array $lines): array
    {
        $wanted = [];

        foreach (array_slice(is_array($raw[$key] ?? null) ? $raw[$key] : [], 0, 8) as $request) {
            $quote = is_array($request) ? $this->quoteIn($request['quote'] ?? null, $haystack) : null;
            $text = is_array($request) && is_string($request['text'] ?? null) ? trim(mb_substr($request['text'], 0, 80)) : '';

            if ($quote !== null && $text !== '' && ! $this->belongsToAnotherLine($quote, $itemQuote, $others, $lines)) {
                $wanted[] = ['text' => $text, 'quote' => $quote];
            }
        }

        return $wanted;
    }

    /**
     * The non-item fields: who it's for, discount asked, delivery, internal remarks, follow-ups.
     * Same rule as specs: only words that really are in the notes survive. None of these
     * ever changes a price; they become flags for the salesperson.
     *
     * @return array{customer_reference: ?string, requested_discount: ?array{value_pct: float, quote: string},
     *               delivery_request: ?string, internal_notes: list<string>, follow_ups: list<string>, rejected: list<string>}
     */
    public function notes(array $response, string $message): array
    {
        $haystack = $this->normalize($message);
        $quote = fn (mixed $field) => is_array($field) ? $this->quoteIn($field['quote'] ?? null, $haystack) : null;
        $quotes = fn (string $key) => array_values(array_unique(array_filter(array_map(
            $quote, array_slice(is_array($response[$key] ?? null) ? $response[$key] : [], 0, 10)
        ))));
        $rejected = [];

        $discount = null;
        if (is_array($raw = $response['requested_discount'] ?? null)) {
            $q = $quote($raw);
            $pct = $raw['value_pct'] ?? null;

            // The percentage must be written in its own quote: "she wants 10%" → 10, never computed.
            if ($q !== null && is_numeric($pct) && $pct > 0 && $pct <= 100 && in_array((float) $pct, $this->numbers($q), false)) {
                $discount = ['value_pct' => (float) $pct, 'quote' => $q];
            } else {
                $rejected[] = 'requested_discount';
            }
        }

        return [
            'customer_reference' => $quote($response['customer_reference'] ?? null),
            'requested_discount' => $discount,
            'delivery_request' => $quote($response['delivery_request'] ?? null),
            'internal_notes' => $quotes('internal_notes'),
            'follow_ups' => $quotes('follow_ups'),
            'rejected' => $rejected,
        ];
    }

    /** @return ?array{value: mixed, quote: string} */
    private function spec(string $name, mixed $spec, string $haystack, ?string $itemQuote, ?string $itemLine = null): ?array
    {
        if (! is_array($spec) || ($quote = $this->quoteIn($spec['quote'] ?? null, $haystack)) === null) {
            return null;
        }

        // Models often quote a bare "2"; the item's own words ("2 floor doors") are the better
        // evidence, then its whole line, where the number must stand alone (not a size, hours, gauge).
        if ($name === 'quantity' && is_int($spec['value'] ?? null) && ! $this->quantityIn($spec['value'], $quote)) {
            if ($itemQuote !== null && $this->quantityIn($spec['value'], $itemQuote)) {
                $quote = $itemQuote;
            } elseif ($itemLine !== null && $this->standaloneQuantity($spec['value'], $itemLine)) {
                $quote = mb_substr($itemLine, 0, 200);
            }
        }

        $value = match ($name) {
            'quantity' => is_int($spec['value'] ?? null) && $spec['value'] >= 1 && $spec['value'] <= 100000
                && $this->quantityIn($spec['value'], $quote) ? $spec['value'] : null,
            'fire_rated', 'insulated' => is_bool($spec['value'] ?? null) ? $spec['value'] : null,
            'load_psf', 'load_lbs' => $this->load($spec['value'] ?? null, $quote),
            'size' => $this->size($spec, $quote),
            // A name must be written in its own quote: "BA-PFI" in "the BA-PFI door".
            'brand', 'model' => is_string($spec['value'] ?? null) && trim($spec['value']) !== '' && mb_strlen($spec['value']) <= 60
                && str_contains($this->normalize($quote), $this->normalize($spec['value'])) ? trim($spec['value']) : null,
            // "drywall" alone names a surface, not a flange.
            'flange_type' => in_array($spec['value'] ?? null, config('catalog.vocabulary.flange_type'), true)
                && str_contains($this->normalize($quote), 'flange') ? $spec['value'] : null,
            default => in_array($spec['value'] ?? null, config("catalog.vocabulary.{$name}"), true) ? $spec['value'] : null,
        };

        return $value === null ? null : ['value' => $value, 'quote' => $quote];
    }

    /** @return ?array{width: float, height: float} */
    private function size(array $spec, string $quote): ?array
    {
        $w = $spec['width'] ?? null;
        $h = $spec['height'] ?? null;

        if (! is_numeric($w) || ! is_numeric($h) || $w <= 0 || $h <= 0 || $w > 600 || $h > 600) {
            return null;
        }

        // Both numbers must come from the quote, as written or converted from feet.
        $written = $this->numbers($quote);
        $supported = fn (float $n) => (bool) array_filter($written, fn ($x) => abs($x - $n) < 0.01 || abs($x * 12 - $n) < 0.01);

        return $supported((float) $w) && $supported((float) $h) ? ['width' => (float) $w, 'height' => (float) $h] : null;
    }

    /**
     * The unit the notes wrote decides which load it is. "Needs to support 400lbs" is a weight
     * in pounds even when the model filed it as PSF or as an add-on; "300 psf" is a floor load.
     */
    private function loadUnits(array $raw): array
    {
        $quoteOf = fn (mixed $spec) => is_array($spec) && is_string($spec['quote'] ?? null) ? $spec['quote'] : '';
        $pounds = fn (string $text) => preg_match('/\d\s*(?:lbs?|pounds?)\b/i', $text) && ! preg_match('/psf|per\s+sq|sq(?:uare)?\.?\s*f(?:oo|ee)?t/i', $text);
        $perSquareFoot = fn (string $text) => (bool) preg_match('/\d\s*psf\b|per\s+sq/i', $text);

        if ($pounds($quoteOf($raw['load_psf'] ?? null))) {
            $raw['load_lbs'] ??= $raw['load_psf'];
            $raw['load_psf'] = null;
        }
        if ($perSquareFoot($quoteOf($raw['load_lbs'] ?? null))) {
            $raw['load_psf'] ??= $raw['load_lbs'];
            $raw['load_lbs'] = null;
        }

        foreach (['options_wanted', 'features'] as $list) {
            foreach (is_array($raw[$list] ?? null) ? $raw[$list] : [] as $i => $wish) {
                $quote = $quoteOf($wish);
                if (($raw['load_lbs'] ?? null) === null && $pounds($quote) && preg_match('/(\d{2,5})\s*(?:lbs?|pounds?)\b/i', $quote, $m)) {
                    $raw['load_lbs'] = ['value' => (float) $m[1], 'quote' => $quote];
                    unset($raw[$list][$i]);
                }
            }
        }

        return $raw;
    }

    private function load(mixed $value, string $quote): ?float
    {
        if (! is_numeric($value) || $value <= 0 || $value > 10000) {
            return null;
        }

        return in_array((float) $value, $this->numbers($quote), false) ? (float) $value : null;
    }

    /**
     * In a list-style message (one item per line), a spec whose words sit only on
     * lines that hold another item's words — and not this item's — belongs to that other item.
     * Single-paragraph messages never trigger this: everything shares one line.
     */
    private function belongsToAnotherLine(string $specQuote, ?string $itemQuote, array $otherQuotes, array $lines): bool
    {
        if ($itemQuote === null || $otherQuotes === []) {
            return false;
        }

        $spec = $this->normalize($specQuote);
        $item = $this->normalize($itemQuote);
        $specLines = array_filter($lines, fn ($l) => str_contains($l, $spec));

        if ($specLines === [] || array_filter($specLines, fn ($l) => str_contains($l, $item)) !== []) {
            return false; // spans lines, or shares a line with this item
        }

        foreach ($specLines as $line) {
            $ownedByOther = array_filter($otherQuotes, fn ($q) => str_contains($line, $this->normalize($q)));
            if ($ownedByOther === []) {
                return false; // a line no item claims, e.g. "all doors must be fire rated"
            }
        }

        return true;
    }

    /**
     * The number must be in its own quote ("4 access panels", "two", "a dozen").
     * A bare quote like "2" isn't enough on its own: it also matches "$1,200".
     */
    private function quantityIn(int $value, string $quote): bool
    {
        if (in_array((float) $value, $this->numbers($quote), false)) {
            return mb_strlen(trim($quote)) > mb_strlen((string) $value);
        }

        $words = ['a' => 1, 'an' => 1, 'one' => 1, 'single' => 1, 'two' => 2, 'pair' => 2, 'three' => 3, 'four' => 4,
            'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'dozen' => 12];

        foreach (preg_split('/\W+/u', mb_strtolower($quote)) as $word) {
            if (($words[$word] ?? null) === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * The number appears on its own in the line: not part of a size ("24" x 24"", "2x3"),
     * a fraction, a range or a measure ("2 hours", "16 gauge", "300 psf", "$5").
     */
    private function standaloneQuantity(int $value, string $line): bool
    {
        $pattern = '/(?<![\d.\/x×$"\'″-])\b'.$value.'\b(?!\s*(?:["\'″]|[x×]\s*\d|\/|\.\d|-\s*\d|%|(?:hours?|hrs?|gauge|ga|psf|lbs?|inch(?:es)?|in|ft|feet|mm|cm)\b))/iu';

        return preg_match($pattern, $line) === 1;
    }

    /** "22 1/2", "22½", "2'" … → floats. */
    private function numbers(string $text): array
    {
        $text = strtr($text, ['½' => '.5', '¼' => '.25', '¾' => '.75']);
        preg_match_all('/(\d+(?:\.\d+)?)(?:\s+(\d)\/(\d{1,2}))?/', $text, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => (float) $x[1] + (isset($x[3]) && $x[3] ? (int) $x[2] / (int) $x[3] : 0), $m);
    }

    /** The quote, trimmed, if it really occurs in the message; null otherwise. */
    private function quoteIn(mixed $quote, string $haystack): ?string
    {
        if (! is_string($quote) || trim($quote) === '' || mb_strlen($quote) > 500) {
            return null;
        }

        return str_contains($haystack, $this->normalize($quote)) ? trim($quote) : null;
    }

    /** Case, whitespace and quote-mark differences don't make a quote "invented". */
    private function normalize(string $text): string
    {
        $text = strtr($text, ['“' => '"', '”' => '"', '″' => '"', '‘' => "'", '’' => "'", '′' => "'", '×' => 'x']);

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    }
}
