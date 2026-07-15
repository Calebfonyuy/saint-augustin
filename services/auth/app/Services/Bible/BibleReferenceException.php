<?php

namespace App\Services\Bible;

use RuntimeException;

/**
 * Thrown when a scripture reference cannot be parsed or is out of range
 * (unknown book, malformed chapter:verse). Controllers translate this into
 * a 422 (client error), distinct from BibleApiException (upstream failure).
 */
class BibleReferenceException extends RuntimeException
{
}
