<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Models\CrawlSession;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrawlSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $session = CrawlSession::factory()->forTenant($tenant)->create();

        $this->assertSame($tenant->id, $session->tenant_id);
    }

    public function test_status_and_mode_are_enum_casts(): void
    {
        $session = CrawlSession::factory()->create([
            'status' => CrawlSessionStatus::Running,
            'mode' => CrawlMode::Initial,
        ]);

        $this->assertSame(CrawlSessionStatus::Running, $session->fresh()->status);
        $this->assertSame(CrawlMode::Initial, $session->fresh()->mode);
    }

    public function test_counts_default_to_zero(): void
    {
        $session = CrawlSession::factory()->create();

        $this->assertSame(0, $session->pages_discovered);
        $this->assertSame(0, $session->pages_indexed);
        $this->assertSame(0, $session->pages_failed);
        $this->assertSame(0, $session->pages_skipped_budget);
        $this->assertSame(0, $session->pages_skipped_unchanged);
    }
}
