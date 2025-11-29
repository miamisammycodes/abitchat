<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AdminActivityLog extends Model
{
    protected $fillable = [
        'admin_user_id',
        'action_type',
        'target_type',
        'target_id',
        'details',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Log an admin activity
     */
    public static function log(
        string $actionType,
        ?Model $target = null,
        array $details = []
    ): self {
        $admin = Auth::guard('admin')->user();

        return self::create([
            'admin_user_id' => $admin->id,
            'action_type' => $actionType,
            'target_type' => $target ? get_class($target) : null,
            'target_id' => $target?->id,
            'details' => $details,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }

    /**
     * Get action type label
     */
    public function getActionLabelAttribute(): string
    {
        $labels = [
            'login' => 'Logged in',
            'logout' => 'Logged out',
            'approve_transaction' => 'Approved transaction',
            'reject_transaction' => 'Rejected transaction',
            'update_client_status' => 'Updated client status',
            'update_client_plan' => 'Updated client plan',
        ];

        return $labels[$this->action_type] ?? ucwords(str_replace('_', ' ', $this->action_type));
    }
}
