<?php

namespace App\Services\Staug;

use RuntimeException;

/**
 * HMAC-SHA256 signer for STAUG manifests (SRS FR-DX-3).
 *
 * The signature is computed over a fixed domain-separation prefix followed by
 * the *raw bytes* of the manifest as they are stored in the archive. Callers
 * must sign and verify the exact stored bytes and never a re-serialization —
 * json_encode output is not stable across flags/PHP versions, so re-encoding
 * a decoded manifest would silently break verification. The single manifest
 * signature transitively covers every song body because the manifest records
 * a per-song sha256.
 *
 * A missing signing key is a server misconfiguration (→ RuntimeException →
 * 500), deliberately distinct from an invalid archive (StaugValidationException
 * → 422). We never HMAC with an empty key.
 */
class StaugSigner
{
    /**
     * Domain-separation prefix. Binds the signature to this signing scheme
     * independently of the archive's own `format_version`, so the manifest
     * schema can evolve without changing the signing domain.
     */
    private const SIGNATURE_PREFIX = "STAUG-SIG-V1\n";

    /** Compute the lowercase-hex signature for the given raw manifest bytes. */
    public function sign(string $rawManifestBytes): string
    {
        return hash_hmac('sha256', self::SIGNATURE_PREFIX.$rawManifestBytes, $this->key());
    }

    /** Constant-time verification of a hex signature against raw manifest bytes. */
    public function verify(string $rawManifestBytes, string $signatureHex): bool
    {
        return hash_equals($this->sign($rawManifestBytes), $signatureHex);
    }

    private function key(): string
    {
        $key = trim((string) config('staug.signing_key'));

        if ($key === '') {
            throw new RuntimeException('STAUG signing key is not configured (STAUG_SIGNING_KEY).');
        }

        return $key;
    }
}
