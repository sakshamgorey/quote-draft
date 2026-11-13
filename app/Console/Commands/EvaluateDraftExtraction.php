<?php

namespace App\Console\Commands;

use App\Ai\Agents\QuoteExtractor;
use App\Models\Product;
use App\Services\Drafts\DraftValidator;
use App\Services\Drafts\ProductMatcher;
use App\Services\Drafts\RequestedItem;
use Illuminate\Console\Command;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

class EvaluateDraftExtraction extends Command
{
    protected $signature = 'drafts:eval
        {--case=* : Only cases whose name contains this text}
        {--pause=5 : Seconds between calls, to stay under the per-minute limit}';

    protected $description = 'Send the eval notes to the real LLM and score its answers (costs API calls; never runs on its own)';

    /** Fixtures: tests/Fixtures/drafts/eval-cases.json. An expected_sku of "none" means no product may be preselected. */
    public function handle(DraftValidator $validator, ProductMatcher $matcher): int
    {
        $cases = json_decode(file_get_contents(base_path('tests/Fixtures/drafts/eval-cases.json')), true);
        $only = $this->option('case');
        $model = config('ai.providers.gemini.models.text.default');
        $passed = 0;
        $failed = 0;

        foreach ($cases as $case) {
            if ($only && ! array_filter($only, fn ($name) => str_contains($case['name'], $name))) {
                continue;
            }

            sleep((int) $this->option('pause'));

            try {
                $response = $this->extract($case['message'], $model);
            } catch (Throwable $e) {
                $this->error("ERR  {$case['name']}: ".class_basename($e).' '.$e->getMessage());
                $failed++;

                continue;
            }

            $items = $validator->validate($response['items'] ?? [], $case['message']);
            $problems = [...$this->compare($items, $case['expected']), ...$this->picks($items, $case['expected_sku'] ?? [], $matcher)];

            $problems ? $failed++ : $passed++;
            $problems
                ? $this->line("<fg=red>FAIL</> {$case['name']}\n      ".implode("\n      ", $problems))
                : $this->line("<fg=green>ok</>   {$case['name']}");
        }

        $this->newLine();
        $this->line("$passed passed, $failed failed (model $model)");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** One call; falls back to the secondary model when the primary is busy or limited. */
    private function extract(string $message, string $model): array
    {
        try {
            return (new QuoteExtractor)->prompt($message, model: $model, timeout: 25)->toArray();
        } catch (ProviderOverloadedException|RateLimitedException|ProviderConnectionException) {
            return (new QuoteExtractor)->prompt($message, model: config('ai.providers.gemini.models.text.fallback'), timeout: 40)->toArray();
        }
    }

    /**
     * @param  list<RequestedItem>  $items
     * @param  list<array<string, mixed>>  $expected
     * @return list<string>
     */
    private function compare(array $items, array $expected): array
    {
        $problems = [];

        if (count($items) !== count($expected)) {
            $problems[] = 'items '.count($items).' vs '.count($expected);
        }

        foreach ($expected as $i => $want) {
            $got = $items[$i] ?? null;
            if (! $got) {
                continue;
            }

            foreach ($want as $field => $value) {
                $problem = $this->checkField($got, $field, $value);
                if ($problem) {
                    $problems[] = "#$i $problem";
                }
            }
        }

        return $problems;
    }

    private function checkField(RequestedItem $got, string $field, mixed $want): ?string
    {
        if (in_array($field, ['features', 'options_wanted'], true)) {
            $have = array_column($field === 'features' ? $got->features : $got->optionRequests, 'text');
            $missing = array_filter($want, fn ($w) => ! array_filter($have, fn ($h) => str_contains($this->normalize($h), $this->normalize($w)) || str_contains($this->normalize($w), $this->normalize($h))));

            return $missing || ($want === [] && $have !== []) ? "$field got ".json_encode($have) : null;
        }

        $have = $field === 'product_type' ? ($got->type ?? 'unknown') : $got->value($field);

        $ok = match (true) {
            is_array($want) => $have !== null && abs($have['width'] - $want['width']) < .01 && abs($have['height'] - $want['height']) < .01,
            in_array($field, ['brand', 'model'], true) => $have !== null && $this->normalize($have) === $this->normalize($want),
            default => $have == $want && ($want !== null || $have === null),
        };

        return $ok ? null : "$field got ".json_encode($have).' want '.json_encode($want);
    }

    /**
     * @param  list<RequestedItem>  $items
     * @param  array<int, ?string>  $skus
     * @return list<string>
     */
    private function picks(array $items, array $skus, ProductMatcher $matcher): array
    {
        $problems = [];

        foreach ($skus as $i => $sku) {
            if ($sku === null || ! isset($items[$i])) {
                continue;
            }

            $pick = $matcher->match($items[$i])['pick'];
            $got = $pick ? Product::find($pick['id'])?->sku : null;

            if ($sku === 'none' ? $got !== null : $got !== $sku) {
                $problems[] = "#$i pick got ".json_encode($got).' ('.($pick['confidence'] ?? '-').") want $sku";
            }
        }

        return $problems;
    }

    private function normalize(?string $text): string
    {
        return mb_strtolower(preg_replace('/[^a-z0-9 ]/i', '', (string) $text));
    }
}
