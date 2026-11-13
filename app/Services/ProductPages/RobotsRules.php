<?php

namespace App\Services\ProductPages;

/**
 * The store's robots.txt rules for our user agent: the group naming our agent
 * token if there is one, otherwise "*". Supports "*" and "$" in paths, longest
 * match wins, Allow beats Disallow on a tie, and Crawl-delay.
 */
class RobotsRules
{
    /** @param  list<array{allow: bool, pattern: string}>  $rules */
    public function __construct(
        private array $rules = [],
        public readonly ?float $crawlDelay = null,
    ) {}

    public static function parse(string $robots, string $userAgent): self
    {
        $token = strtolower(strtok($userAgent, '/ ') ?: $userAgent);
        $groups = [];
        $current = null;
        $lastWasAgent = false;

        foreach (preg_split('/\R/', $robots) as $raw) {
            $line = trim(preg_replace('/#.*$/', '', $raw));
            if (! preg_match('/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $m)) {
                continue;
            }
            [$key, $value] = [strtolower($m[1]), trim($m[2])];

            if ($key === 'user-agent') {
                if (! $lastWasAgent) {
                    $groups[] = ['agents' => [], 'rules' => [], 'delay' => null];
                    $current = array_key_last($groups);
                }
                $groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;

                continue;
            }

            $lastWasAgent = false;
            if ($current === null) {
                continue;
            }

            // An empty Disallow means "allow everything": nothing to record.
            if (($key === 'allow' || $key === 'disallow') && $value !== '') {
                $groups[$current]['rules'][] = ['allow' => $key === 'allow', 'pattern' => $value];
            } elseif ($key === 'crawl-delay' && is_numeric($value)) {
                $groups[$current]['delay'] = (float) $value;
            }
        }

        $mine = collect($groups)->first(fn ($g) => collect($g['agents'])->contains(fn ($a) => $a !== '*' && $a !== '' && str_contains($token, $a)))
            ?? collect($groups)->first(fn ($g) => in_array('*', $g['agents'], true));

        return new self($mine['rules'] ?? [], $mine['delay'] ?? null);
    }

    public function allows(string $url): bool
    {
        $path = (parse_url($url, PHP_URL_PATH) ?: '/').(($q = parse_url($url, PHP_URL_QUERY)) ? "?{$q}" : '');
        $best = null;

        foreach ($this->rules as $rule) {
            $regex = '#^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($rule['pattern'], '#')).'#';
            if (preg_match($regex, $path)) {
                $len = strlen($rule['pattern']);
                if (! $best || $len > $best[0] || ($len === $best[0] && $rule['allow'])) {
                    $best = [$len, $rule['allow']];
                }
            }
        }

        return $best === null || $best[1];
    }
}
