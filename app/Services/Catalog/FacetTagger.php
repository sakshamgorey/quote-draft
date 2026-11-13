<?php

namespace App\Services\Catalog;

use App\Casts\PostgresArray;
use Illuminate\Support\Facades\File;

/**
 * Applies the store's own facet values (learned by FacetStore's per-value
 * queries) to normalised product rows. These are exact where the category-path
 * guesses in ProductNormalizer are partial, so for tag columns they are merged
 * in, and custom_fire_rated "Yes" marks a product fire-rated.
 */
class FacetTagger
{
    /** @var array<string, array<string, list<string>>> uid => column => values */
    private array $tags = [];

    /** @var array<string, array> uid => raw result, for products only seen in facet pages */
    private array $raw = [];

    /** @param  array<string, array<string, list<string>>>  $files  FacetStore::ensure() output */
    public function load(array $files): static
    {
        $columns = config('searchspring.facet_fields');

        foreach ($files as $field => $values) {
            foreach ($values as $value => $paths) {
                $tag = $this->tagValue($field, (string) $value);

                foreach ($paths as $path) {
                    $fetchedAt = File::lastModified($path);

                    foreach (json_decode(File::get($path), true)['results'] ?? [] as $result) {
                        $uid = (string) $result['uid'];
                        $this->raw[$uid] ??= $result + ['_fetched_at' => $fetchedAt];

                        if ($tag !== null) {
                            $this->tags[$uid][$columns[$field]][] = $tag;
                        }
                    }
                }
            }
        }

        return $this;
    }

    /** Raw results from facet pages, so products outside the 9 categories still get imported. */
    public function rawResults(): array
    {
        return array_values($this->raw);
    }

    /**
     * @param  array<string, array>  $rows  keyed by uid
     * @return array<string, array>
     */
    public function apply(array $rows): array
    {
        foreach ($rows as $uid => $row) {
            foreach ($this->tags[$uid] ?? [] as $column => $values) {
                if ($column === 'fire_rated') {
                    $rows[$uid]['fire_rated'] = $row['fire_rated'] || in_array('Yes', $values, true);

                    continue;
                }

                $merged = array_values(array_unique([...PostgresArray::parse($row[$column]), ...$values]));
                $rows[$uid][$column] = PostgresArray::literal($merged);
            }
        }

        return $rows;
    }

    /** Store spelling → our tag, or null when the value isn't a real attribute ("NA", "Retrofit"…). */
    private function tagValue(string $field, string $value): ?string
    {
        $value = trim(html_entity_decode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5), ENT_QUOTES | ENT_HTML5));

        if ($field === 'custom_fire_rated') {
            return $value;
        }

        // "300 psf Load", "15 PSF Load" → "300 PSF", matching the name-derived load tags.
        if (preg_match('/^(\d+)\s*psf(\s+load)?$/i', $value, $m)) {
            return "{$m[1]} PSF";
        }

        $value = config('catalog.aliases')[$value] ?? $value;
        $drop = array_map('mb_strtolower', [...config('catalog.drop_surface'), 'Not Applicable', 'N/A']);

        return in_array(mb_strtolower($value), $drop, true) ? null : $value;
    }
}
