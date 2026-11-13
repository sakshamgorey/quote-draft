<?php

namespace App\Services\Searchspring;

use RuntimeException;

/** Searchspring told us to slow down. The import stops; we never hammer. */
class RateLimitedByRemote extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Rate limited by Searchspring (HTTP 429), retry after {$retryAfter}s");
    }
}
