<?php

namespace App\Services\ProductPages;

use App\Models\Product;
use App\Models\ProductLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes one parsed page into product_pages / product_options(+values) / product_links.
 * Re-importing a product replaces its options and links (idempotent).
 */
class PageImporter
{
    /** "for Roof Hatches" in an accessory's name → the product type it goes with. */
    private const ACCESSORY_FOR = [
        '/\bfor (curb[- ]mount(ed)?|deck[- ]mount(ed)?) skylights?\b/i' => 'skylight',
        '/\bfor skylights?\b/i' => 'skylight',
        '/\bfor roof hatch(es)?\b/i' => 'roof_hatch',
        '/\bfor floor (doors?|hatch(es)?)\b/i' => 'floor_door',
        '/\bfor (scissor )?attic ladders?\b/i' => 'ladder',
        '/\bfor smoke vents?\b/i' => 'smoke_vent',
        '/\bfor access (doors?|panels?)\b/i' => 'access_door',
    ];

    public function __construct(private PageParser $parser) {}

    /** @return array<string, mixed> the parsed page, for reporting */
    public function import(Product $product, string $html, ?Carbon $fetchedAt = null): array
    {
        $page = $this->parser->parse($html);

        // Names carry compatibility too: "Flashing Kit Compatible with BA-PDS, BA-PDX".
        $models = collect([...$this->parser->compatibleModels($product->name), ...$page['compatible_models']])->unique('model')->values();

        $warnings = $page['warnings'];
        if ($page['page_price'] !== null && $product->price !== null && abs($page['page_price'] - (float) $product->price) >= 0.01) {
            $warnings[] = sprintf('Page price $%.2f differs from feed price $%.2f.', $page['page_price'], $product->price);
        }

        DB::transaction(function () use ($product, $page, $models, $warnings, $fetchedAt) {
            $product->page()->updateOrCreate([], [
                'store_product_id' => $page['store_product_id'],
                'parser_version' => config('product_pages.parser_version'),
                'fetched_at' => $fetchedAt,
                'parsed_at' => now(),
                'page_price' => $page['page_price'],
                'description' => $page['description'],
                'spec_lines' => $page['spec_lines'],
                'spec_sheet_url' => $page['spec_sheet_url'],
                'insulated' => $page['insulated'],
                'insulated_basis' => $page['insulated_basis'],
                'insulation_evidence' => $page['insulation_evidence'],
                'gauge' => $page['gauge'],
                'flange_types' => $page['flange_types'],
                'material' => $page['material'],
                'warnings' => $warnings,
            ]);

            $product->options()->delete(); // values cascade
            foreach ($page['options'] as $option) {
                $row = $product->options()->create(collect($option)->except('values')->all());
                foreach ($option['values'] as $value) {
                    $row->values()->create($value);
                }
            }

            $product->links()->delete();
            $byUrl = Product::query()->whereIn('url', array_column($page['size_links'], 'url'))->pluck('id', 'url');

            foreach ($page['size_links'] as $link) {
                if ($link['active']) {
                    continue; // the page itself
                }
                $product->links()->create([
                    'kind' => ProductLink::SIZE_SIBLING,
                    // The store has two slugs for many pages (…woodfesco… vs …wood-fesco…):
                    // fall back to the same model in the linked size.
                    'related_product_id' => $byUrl[$link['url']] ?? $this->sameModelInSize($product, $link['label']),
                    'related_url' => $link['url'],
                    'evidence' => $link['label'],
                ]);
            }

            foreach ($models as $m) {
                $product->links()->create(['kind' => ProductLink::COMPATIBLE_WITH, 'related_model' => $m['model'], 'evidence' => $m['evidence']]);
            }

            foreach (self::ACCESSORY_FOR as $pattern => $type) {
                // Same type is fine: a flashing kit is typed skylight but is still *for* skylights.
                if (preg_match($pattern, $product->name, $hit)) {
                    $product->links()->create(['kind' => ProductLink::ACCESSORY_FOR, 'related_type' => $type, 'evidence' => $hit[0]]);
                    break;
                }
            }
        });

        return array_merge($page, ['warnings' => $warnings, 'compatible_models' => $models->all()]);
    }

    /** '24" x 36"' → the product of the same model with that opening, if we have it. */
    private function sameModelInSize(Product $product, string $label): ?int
    {
        if (! preg_match('/([\d.]+)\s*"?\s*x\s*([\d.]+)/i', $label, $m)) {
            return null;
        }

        return Product::query()
            ->where('model', $product->model)
            ->where('nominal_width', (float) $m[1])
            ->where('nominal_height', (float) $m[2])
            ->value('id');
    }
}
