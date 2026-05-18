<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Models\CrawlSession;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrawlSession>
 */
class CrawlSessionFactory extends Factory
{
    protected $model = CrawlSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'mode' => CrawlMode::Initial,
            'status' => CrawlSessionStatus::Queued,
            'pages_discovered' => 0,
            'pages_indexed' => 0,
            'pages_failed' => 0,
            'pages_skipped_budget' => 0,
            'pages_skipped_unchanged' => 0,
        ];
    }

    public function forTenant(Tenant $tenant): self
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
