<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CrawlUrlBlocklistFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $excluded_at
 */
class CrawlUrlBlocklist extends Model
{
    /** @use HasFactory<CrawlUrlBlocklistFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'crawl_url_blocklist';

    protected $fillable = [
        'tenant_id',
        'url_normalized',
        'excluded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'excluded_at' => 'datetime',
        ];
    }
}
