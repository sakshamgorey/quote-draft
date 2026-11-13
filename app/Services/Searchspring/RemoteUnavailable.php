<?php

namespace App\Services\Searchspring;

use RuntimeException;

/** 5xx, timeouts, non-JSON bodies. The import stops and reports it. */
class RemoteUnavailable extends RuntimeException {}
