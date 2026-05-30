<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CrawlSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property CrawlSessionStatus $status
 * @property CrawlMode $mode
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class CrawlSession extends Model
{
    /** @use HasFactory<CrawlSessionFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'mode',
        'status',
        'started_at',
        'completed_at',
        'pages_discovered',
        'pages_indexed',
        'pages_failed',
        'pages_skipped_budget',
        'pages_skipped_unchanged',
        'pages_skipped_no_content',
        'error_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CrawlSessionStatus::class,
            'mode' => CrawlMode::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'pages_discovered' => 'integer',
            'pages_indexed' => 'integer',
            'pages_failed' => 'integer',
            'pages_skipped_budget' => 'integer',
            'pages_skipped_unchanged' => 'integer',
            'pages_skipped_no_content' => 'integer',
        ];
    }
}
