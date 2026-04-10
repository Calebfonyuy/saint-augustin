<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'email',
        'display_name',
        'password',
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
}
