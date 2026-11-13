<?php

namespace App\Services\Drafts;

use RuntimeException;

/** The extractor's output wasn't the JSON shape we asked for. */
class InvalidExtraction extends RuntimeException {}
