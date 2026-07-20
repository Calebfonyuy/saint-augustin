<?php

namespace App\Services\Bible;

use RuntimeException;

/**
 * Thrown when the HelloAO Bible API is unreachable or returns an
 * unusable response. Controllers translate this into a 502/503-style error
 * (distinct from a 422 for an unparseable/invalid reference).
 */
class BibleApiException extends RuntimeException
{
}
