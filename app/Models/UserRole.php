<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property Role $role
 */
class UserRole extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['user_id', 'role', 'tenant_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => Role::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * D-09 invariant guard: the schema-level UNIQUE(user_id, role, tenant_id) does NOT
     * enforce uniqueness when tenant_id IS NULL on MySQL (each NULL is treated as
     * distinct). Enforce the SuperAdmin singleton at the application layer so a single
     * user cannot accumulate multiple super_admin rows.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $userRole): void {
            if ($userRole->role === Role::SuperAdmin) {
                if ($userRole->tenant_id !== null) {
                    throw new LogicException('SuperAdmin role must have tenant_id = NULL (D-09)');
                }

                $exists = static::query()
                    ->where('user_id', $userRole->user_id)
                    ->where('role', Role::SuperAdmin->value)
                    ->whereNull('tenant_id')
                    ->exists();

                if ($exists) {
                    throw new LogicException(
                        "User {$userRole->user_id} already has a super_admin role; cannot insert duplicate (D-09)"
                    );
                }
            }
        });
    }
}
