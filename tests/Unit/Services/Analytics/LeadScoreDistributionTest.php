<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Analytics;

use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Analytics\AnalyticsService;
use App\Services\Leads\LeadScoring;
use Tests\TestCase;

class LeadScoreDistributionTest extends TestCase
{
    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Dist',
            'slug' => 'dist-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    private function makeLead(Tenant $tenant, int $score): Lead
    {
        return Lead::create([
            'tenant_id' => $tenant->id,
            'status' => 'new',
            'source' => 'widget',
            'score' => $score,
        ]);
    }

    public function test_distribution_buckets_match_the_canonical_thresholds(): void
    {
        $tenant = $this->makeTenant();

        // Boundary scores: one just below warm, exactly warm, just below hot, exactly hot.
        $this->makeLead($tenant, LeadScoring::WARM_THRESHOLD - 1); // 39 → cold
        $this->makeLead($tenant, LeadScoring::WARM_THRESHOLD);     // 40 → warm
        $this->makeLead($tenant, LeadScoring::HOT_THRESHOLD - 1);  // 69 → warm
        $this->makeLead($tenant, LeadScoring::HOT_THRESHOLD);      // 70 → hot

        $dist = app(AnalyticsService::class)->getLeadScoreDistribution($tenant);

        $this->assertSame(1, $dist['cold']);
        $this->assertSame(2, $dist['warm']);
        $this->assertSame(1, $dist['hot']);
    }
}
