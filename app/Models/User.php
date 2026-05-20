<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property-read Collection<int, UserRole> $roles
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<UserRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * Determine if the user holds the given role.
     *
     * For platform-level roles (SuperAdmin), the tenant argument is ignored
     * since super_admin rows always have tenant_id = null.
     * For tenant-scoped roles, the tenant argument is required.
     */
    public function hasRole(Role $role, ?Tenant $tenant = null): bool
    {
        return $this->roles->contains(function (UserRole $ur) use ($role, $tenant): bool {
            if ($ur->role !== $role) {
                return false;
            }

            if ($role->isPlatformLevel()) {
                return true;
            }

            return $ur->tenant_id === $tenant?->id;
        });
    }

    /**
     * Determine if the user holds at least the given role within the tenant.
     *
     * Only considers non-platform-level (tenant-scoped) roles.
     * SuperAdmin is orthogonal to the tenant hierarchy and is excluded.
     */
    public function hasRoleAtLeast(Role $min, Tenant $tenant): bool
    {
        return $this->roles->contains(function (UserRole $ur) use ($min, $tenant): bool {
            if ($ur->role->isPlatformLevel()) {
                return false;
            }

            return $ur->tenant_id === $tenant->id
                && $ur->role->rank() >= $min->rank();
        });
    }
}
