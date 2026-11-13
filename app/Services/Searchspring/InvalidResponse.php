<?php

namespace App\Services\Searchspring;

use RuntimeException;

/** 4xx other than 429. Retrying won't help. */
class InvalidResponse extends RuntimeException {}
