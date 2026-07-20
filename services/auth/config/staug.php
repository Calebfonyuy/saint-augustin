<?php

/*
 * SaintAugustin — STAUG data-interchange configuration.
 *
 * STAUG is the signed ZIP archive format used to move songs, songbooks, and
 * playlists between instances (SRS FR-DX/FR-DI/FR-DF). Values are read via
 * config('staug.*') — never env() inline — so `php artisan config:cache`
 * snapshots them correctly (mirrors config/invitation.php).
 */

return [

    /*
     * HMAC-SHA256 key that signs every STAUG manifest. DR-critical: an
     * archive can only be validated by an instance holding the same key.
     * Generate with `openssl rand -base64 32`. Provisioned across every env
     * layer in Stage 0; this file is the only place code reads it.
     */
    'signing_key' => env('STAUG_SIGNING_KEY'),

    /*
     * Archive schema version written into each manifest's `version` field.
     * Bump when the manifest layout changes incompatibly.
     */
    'format_version' => '1',

    /*
     * How long the emailed presigned download URL for a full export stays
     * valid. Kept under the SigV4 7-day cap; 48h is a deliberate choice
     * aligned with the MinIO `exports/` lifecycle-expiry rule.
     */
    'export_url_ttl_hours' => (int) env('STAUG_EXPORT_URL_TTL_HOURS', 48),

    /*
     * Full-DB export is one-at-a-time. The controller sets an atomic cache
     * marker under this key with the TTL below as a crash backstop; the job
     * clears it on completion/failure.
     */
    'full_export_lock_key'         => 'staug:full-export:running',
    'full_export_lock_ttl_minutes' => (int) env('STAUG_FULL_EXPORT_LOCK_TTL_MINUTES', 30),

];
