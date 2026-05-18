<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CrawlUrlBlocklist;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrawlUrlBlocklist>
 */
class CrawlUrlBlocklistFactory extends Factory
{
    protected $model = CrawlUrlBlocklist::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'url_normalized' => 'https://example.com/some-page',
            'excluded_at' => now(),
        ];
    }

    public function forTenant(Tenant $tenant): self
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
