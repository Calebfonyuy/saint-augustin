<?php

namespace App\Services\Staug;

use RuntimeException;

/**
 * Thrown when a STAUG archive fails structural or cryptographic validation —
 * a missing/mismatched signature, a tampered body, an unlisted or missing
 * song file, or an unsafe entry name. Controllers translate this into a 422
 * so a malformed or forged upload is a client error, distinct from a server
 * misconfiguration (a missing signing key throws a plain RuntimeException →
 * 500).
 */
class StaugValidationException extends RuntimeException
{
}
