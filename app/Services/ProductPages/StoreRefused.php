<?php

namespace App\Services\ProductPages;

use RuntimeException;

/** The store said slow down (429), failed (5xx) or was unreachable: stop fetching. */
class StoreRefused extends RuntimeException {}
