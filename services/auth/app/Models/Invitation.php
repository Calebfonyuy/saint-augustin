<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-issued invitation model.
 *
 * An admin creates an invitation for a given email with pre-assigned roles.
 * The recipient follows the invite link (containing the token) to register.
 * One pending invitation per email address (unique constraint on email).
 *
 * @property string      $id
 * @property string      $email
 * @property string      $token
 * @property string[]    $roles
 * @property string|null $invited_by
 * @property \Illuminate\Support\Carbon      $expires_at
 * @property \Illuminate\Support\Carbon|null $accepted_at
 */
class Invitation extends Model
{
    /** @use HasFactory<\Database\Factories\InvitationFactory> */
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'email',
        'token',
        'roles',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'roles'       => 'array',
            'expires_at'  => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function invitedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    // ── Status helpers ────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }
}
