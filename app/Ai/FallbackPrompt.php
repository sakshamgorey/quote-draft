<?php

namespace App\Ai;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

/**
 * One prompt, with one retry on the fallback model when the default is busy, rate
 * limited or too slow. Both attempts fit in the caller's budget, which stays under
 * DraftController::REQUEST_TIME_LIMIT. Bad requests and invalid output are not
 * retried: another model wouldn't fix them.
 */
class FallbackPrompt
{
    /** The default model gets this long before the fallback takes over. A 503 returns in ~1 s. */
    public const FIRST_ATTEMPT_TIMEOUT = 25;

    /** Not worth a second call with less time than this. */
    public const MIN_RETRY_SECONDS = 8;

    /**
     * @param  ?string  $primary  the model to try first; the configured default when null
     * @return array{0: mixed, 1: string} the agent's response and the model that produced it
     */
    public static function run(Agent $agent, string $prompt, int $budget, ?string $primary = null): array
    {
        $primary ??= config('ai.providers.gemini.models.text.default');
        $fallback = config('ai.providers.gemini.models.text.fallback');
        $canRetry = filled($fallback) && $fallback !== $primary;
        $deadline = microtime(true) + $budget;

        try {
            return [$agent->prompt($prompt, model: $primary, timeout: $canRetry ? self::FIRST_ATTEMPT_TIMEOUT : $budget), $primary];
        } catch (ProviderOverloadedException|RateLimitedException|ProviderConnectionException $e) {
            $left = (int) floor($deadline - microtime(true));

            if (! $canRetry || $left < self::MIN_RETRY_SECONDS) {
                throw $e;
            }

            Log::info('AI call retrying on fallback model', [
                'agent' => class_basename($agent), 'primary' => $primary, 'fallback' => $fallback, 'reason' => class_basename($e), 'seconds_left' => $left,
            ]);

            return [$agent->prompt($prompt, model: $fallback, timeout: $left), $fallback];
        }
    }
}
