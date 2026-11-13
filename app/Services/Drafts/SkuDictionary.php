<?php

namespace App\Services\Drafts;

/**
 * Reads SKU-ACRONYM-DICTIONARY.md: what each hyphen-delimited SKU code means within
 * which brand / model family, the embedded patterns, and the rules for an AI reading
 * it. The file is ~110 KB, so a prompt only gets the entries for the SKUs at hand.
 */
class SkuDictionary
{
    /** Section headings → the dictionary's own class names. */
    private const SECTIONS = [
        '### One supported attribute' => 'SCOPED_MEANING',
        '### One catalog family' => 'FAMILY_IDENTITY',
        '### Multiple meanings' => 'CONTEXT_DEPENDENT',
        '### Unresolved' => 'UNRESOLVED_PART',
    ];

    private const MAX_TEXT = 400;

    private string $path;

    /** @var ?array<string, array{class: string, meaning: string, scope: string}> */
    private ?array $codes = null;

    /** @var list<array{codes: list<string>, reading: string, guardrail: string}> */
    private array $embedded = [];

    private string $rules = '';

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? config('drafts.ai_suggestions.dictionary');
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** The dictionary's numbered "Rules for an AI consuming this dictionary". */
    public function rules(): string
    {
        $this->load();

        return $this->rules;
    }

    /**
     * Entries for every code in these SKUs, plus the embedded patterns they contain.
     * Codes are matched exactly as written ("3X3" and "3x3" are different entries).
     *
     * @param  iterable<string>  $skus
     * @return array{codes: array<string, array{class: string, meaning: string, scope: string}>, embedded: list<array{codes: list<string>, reading: string, guardrail: string}>}
     */
    public function for(iterable $skus): array
    {
        $this->load();
        $codes = [];
        $embedded = [];

        foreach ($skus as $sku) {
            foreach (explode('-', $sku) as $segment) {
                if (isset($this->codes[$segment]) && $segment !== 'BA') {
                    $codes[$segment] = $this->codes[$segment];
                }
            }

            foreach ($this->embedded as $i => $row) {
                foreach ($row['codes'] as $code) {
                    if ($code !== '' && str_contains(mb_strtoupper($sku), mb_strtoupper($code))) {
                        $embedded[$i] = $row;
                    }
                }
            }
        }

        ksort($codes);

        return ['codes' => $codes, 'embedded' => array_values($embedded)];
    }

    private function load(): void
    {
        if ($this->codes !== null) {
            return;
        }

        $this->codes = [];
        $rules = [];
        $section = null;

        foreach ($this->exists() ? file($this->path, FILE_IGNORE_NEW_LINES) : [] as $line) {
            if (str_starts_with($line, '#')) {
                $section = match (true) {
                    str_starts_with($line, '## Rules for an AI') => 'rules',
                    str_starts_with($line, '## Embedded codes') => 'embedded',
                    default => collect(self::SECTIONS)->first(fn ($class, $heading) => str_starts_with($line, $heading)),
                };

                continue;
            }

            if ($section === 'rules' && preg_match('/^\d+\.\s/', $line)) {
                $rules[] = $line;
            } elseif ($section === 'embedded' && preg_match('/^\|(.+?)\|(.+?)\|(.+?)\|\s*$/', $line, $m) && str_contains($m[1], '`')) {
                // "| BILCO `20SV`, `40SV`, `50SVTB` | reading | guardrail |"; "IG-MAG-C..." keeps its fixed part.
                preg_match_all('/`([^`]+)`/', $m[1], $found);
                $codes = array_merge(...array_map(fn ($c) => explode('/', str_replace(['...', '*'], '', $c)), $found[1]));
                $this->embedded[] = ['codes' => array_values(array_filter(array_map('trim', $codes), fn ($c) => strlen($c) >= 2)),
                    'reading' => $this->text($m[2]), 'guardrail' => $this->text($m[3])];
            } elseif (in_array($section, self::SECTIONS, true) && preg_match('/^\|\s*`([^`]+)`\s*\|(.*)$/', $line, $m)) {
                // "| `HS` | meaning | brand and model scope | rows | evidence |"
                $cells = array_map('trim', explode('|', $m[2]));
                $this->codes[$m[1]] = ['class' => $section, 'meaning' => $this->text($cells[0] ?? ''), 'scope' => $this->text($cells[1] ?? '')];
            }
        }

        $this->rules = implode("\n", $rules);
    }

    private function text(string $cell): string
    {
        $cell = trim(preg_replace('/\s+/', ' ', strip_tags($cell)) ?? '');

        return mb_strlen($cell) > self::MAX_TEXT ? mb_substr($cell, 0, self::MAX_TEXT).'…' : $cell;
    }
}
