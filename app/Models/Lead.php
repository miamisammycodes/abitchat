<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'name',
        'email',
        'phone',
        'company',
        'score',
        'status',
        'custom_fields',
        'metadata',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'custom_fields' => 'array',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function isNew(): bool
    {
        return $this->status === 'new';
    }

    public function isQualified(): bool
    {
        return $this->status === 'qualified';
    }

    public function isConverted(): bool
    {
        return $this->status === 'converted';
    }

    public function markAsContacted(): void
    {
        $this->update(['status' => 'contacted']);
    }

    public function markAsQualified(): void
    {
        $this->update(['status' => 'qualified']);
    }

    public function markAsConverted(): void
    {
        $this->update(['status' => 'converted']);
    }

    public function markAsLost(): void
    {
        $this->update(['status' => 'lost']);
    }

    public function updateScore(int $score): void
    {
        $this->update(['score' => min(100, max(0, $score))]);
    }
}
