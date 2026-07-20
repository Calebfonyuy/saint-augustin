<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ShareLink model (SRS 3.3 / 7.1).
 *
 * Modes:
 *   • MODE_MUSICIAN   — public musician view: chords, transposition, sheets
 *   • MODE_PROJECTION — public projection view: lyrics-only, presentation
 *
 * `isUsable()` is the single source of truth for whether a token can be
 * resolved into a playlist (not revoked, not expired). Public lookups go
 * through this method so revocation takes effect immediately.
 *
 * @property string                                   $id
 * @property string                                   $playlist_id
 * @property string                                   $token
 * @property string                                   $mode
 * @property string|null                              $created_by
 * @property \Illuminate\Support\Carbon|null          $expires_at
 * @property \Illuminate\Support\Carbon|null          $revoked_at
 */
class ShareLink extends Model
{
    /** @use HasFactory<\Database\Factories\ShareLinkFactory> */
    use HasFactory;
    use HasUuids;

    public const MODE_MUSICIAN   = 'musician';
    public const MODE_PROJECTION = 'projection';

    public const MODES = [self::MODE_MUSICIAN, self::MODE_PROJECTION];

    protected $fillable = [
        'playlist_id',
        'token',
        'mode',
        'created_by',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Generate a URL-safe random token. 32 bytes → 43 base64url chars,
     * which fits comfortably under the column's 64-char limit.
     */
    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Used by the public share endpoint to decide whether to honor a token.
     * Anything other than a clean "active" state returns false so callers
     * can respond with a uniform 404 (no information leakage about why).
     */
    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Playlist, $this> */
    public function playlist(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }
}
