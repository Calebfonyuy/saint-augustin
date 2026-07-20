<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * SaintAugustin User Model
 *
 * Roles are static flags (Admin, Musician, Projectionist) stored as a
 * JSON array — no role CRUD, no profile management (SRS D-3).
 *
 * @property string   $id
 * @property string   $email
 * @property string   $password
 * @property string   $display_name
 * @property string[] $roles
 */
class User extends Authenticatable
{
    use HasApiTokens;
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'email',
        'display_name',
        'password',
        'google_id',
        'roles',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'roles'             => 'array',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<Invitation, $this> */
    public function invitations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Invitation::class, 'invited_by');
    }

    // ── Role helpers ─────────────────────────────────────────────────

    public function isAdmin(): bool
    {
        return in_array('admin', $this->roles ?? [], true);
    }

    public function isMusician(): bool
    {
        return in_array('musician', $this->roles ?? [], true);
    }

    public function isProjectionist(): bool
    {
        return in_array('projectionist', $this->roles ?? [], true);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles ?? [], true);
    }

    // ── Password reset ────────────────────────────────────────────────

    /**
     * Send the password reset notification using our branded email.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
