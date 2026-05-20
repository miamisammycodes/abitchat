<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
