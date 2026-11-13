<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductLink;
use App\Services\ProductPages\PageFetcher;
use App\Services\ProductPages\PageImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class ParseProductPages extends Command
{
    protected $signature = 'catalog:parse-pages
        {--sku=* : Only these SKUs}
        {--details : Print every product\'s options and specs}';

    protected $description = 'Read saved product pages into options, specs, descriptions and links, and report what was found';

    public function handle(PageFetcher $fetcher, PageImporter $importer): int
    {
        $products = Product::query()->when($this->option('sku'), fn ($q, $skus) => $q->whereIn('sku', $skus))->get()
            ->filter(fn (Product $p) => File::exists($fetcher->path($p)))->values();

        if ($products->isEmpty()) {
            $this->warn('No saved pages to parse. Run catalog:fetch-pages first.');

            return self::FAILURE;
        }

        $stats = ['pages' => 0, 'with_options' => 0, 'options' => 0, 'choices' => 0, 'priced_choices' => 0, 'review' => 0, 'pictured_choices' => 0,
            'insulated_stated' => 0, 'insulated_inferred' => 0, 'filled_blank_insulation' => 0, 'gauge' => 0, 'flange' => 0,
            'description' => 0, 'price_mismatch' => 0, 'compatible' => 0];
        $warnings = [];

        foreach ($products as $product) {
            $path = $fetcher->path($product);
            $page = $importer->import($product, File::get($path), Carbon::createFromTimestamp(File::lastModified($path)));

            $stats['pages']++;
            $stats['with_options'] += $page['options'] !== [] ? 1 : 0;
            $stats['options'] += count($page['options']);
            foreach ($page['options'] as $o) {
                $stats['choices'] += count($o['values']);
                $stats['priced_choices'] += count(array_filter($o['values'], fn ($v) => $v['price_delta_cents'] > 0));
                $stats['review'] += count(array_filter($o['values'], fn ($v) => $v['needs_review']));
                $stats['pictured_choices'] += count(array_filter($o['values'], fn ($v) => $v['image_url'] !== null));
            }
            $stats['insulated_stated'] += $page['insulated_basis'] === 'stated' ? 1 : 0;
            $stats['insulated_inferred'] += $page['insulated_basis'] === 'not_mentioned' ? 1 : 0;
            $stats['filled_blank_insulation'] += $page['insulated'] !== null && $product->insulated === null ? 1 : 0;
            $stats['gauge'] += $page['gauge'] ? 1 : 0;
            $stats['flange'] += $page['flange_types'] !== [] ? 1 : 0;
            $stats['description'] += $page['description'] ? 1 : 0;
            $stats['compatible'] += count($page['compatible_models']);
            foreach ($page['warnings'] as $w) {
                $warnings[] = [$product->sku, $w];
                $stats['price_mismatch'] += str_starts_with($w, 'Page price') ? 1 : 0;
            }

            if ($this->option('details')) {
                $this->details($product, $page);
            }
        }

        $this->report($stats, $warnings, $products->pluck('id'));

        return self::SUCCESS;
    }

    private function report(array $s, array $warnings, $ids): void
    {
        $pct = fn (int $n) => $s['pages'] ? sprintf('%d (%d%%)', $n, round($n * 100 / $s['pages'])) : '0';

        $this->newLine();
        $this->table(['', 'value'], [
            ['Pages parsed', $s['pages']],
            ['With options', $pct($s['with_options'])],
            ['Option groups / choices', "{$s['options']} / {$s['choices']}"],
            ['Choices that add a price', $s['priced_choices']],
            ['Choices with a picture', $s['pictured_choices']],
            ['Choices needing review (price unclear or conflicting)', $s['review']],
            ['Insulation stated on page', $pct($s['insulated_stated'])],
            ['Insulation inferred (specs, no mention)', $pct($s['insulated_inferred'])],
            ['Blank insulation now filled', $s['filled_blank_insulation']],
            ['Gauge found', $pct($s['gauge'])],
            ['Flange found', $pct($s['flange'])],
            ['Description found', $pct($s['description'])],
            ['Page price ≠ feed price', $s['price_mismatch']],
            ['Compatibility links (models)', $s['compatible']],
        ]);

        $missing = ProductLink::query()->whereIn('product_id', $ids)->where('kind', ProductLink::SIZE_SIBLING)->whereNull('related_product_id')
            ->distinct()->pluck('related_url');
        $this->line("Size-picker links to products not in our catalog: {$missing->count()}");
        foreach ($missing->take(15) as $url) {
            $this->line("  {$url}");
        }

        if ($warnings !== []) {
            $this->newLine();
            $this->line('Warnings:');
            foreach (array_slice($warnings, 0, 40) as [$sku, $w]) {
                $this->line("  {$sku}: {$w}");
            }
            if (count($warnings) > 40) {
                $this->line('  … '.(count($warnings) - 40).' more');
            }
        }
    }

    private function details(Product $product, array $page): void
    {
        $this->newLine();
        $this->info("{$product->sku}  {$product->name}");
        $this->line(sprintf('  insulated: %s (%s)  gauge: %s  flange: %s  page price: %s',
            var_export($page['insulated'], true), $page['insulated_basis'] ?? '-', $page['gauge'] ?? '-',
            implode('/', $page['flange_types']) ?: '-', $page['page_price'] !== null ? '$'.number_format($page['page_price'], 2) : '-'));
        foreach (array_slice($page['spec_lines'], 0, 8) as $spec) {
            $this->line("  spec  {$spec['label']}: ".mb_substr($spec['value'], 0, 90));
        }
        foreach ($page['options'] as $o) {
            $this->line("  option {$o['label']}".($o['required'] ? ' (required)' : '').': '.implode(' | ', array_map(
                fn ($v) => ($v['is_default'] ? '*' : '').$v['name'].($v['price_delta_cents'] ? ' +$'.number_format($v['price_delta_cents'] / 100, 2) : '')
                    .($v['lead_time_text'] ? " [{$v['lead_time_text']}]" : '').($v['needs_review'] ? ' [REVIEW]' : ''),
                $o['values'])));
        }
        foreach ($page['compatible_models'] as $m) {
            $this->line("  compatible with {$m['model']}");
        }
    }
}
