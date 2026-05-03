<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    public function test_for_tenant_scope_filters_to_one_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        Conversation::factory()->for($a)->count(2)->create();
        Conversation::factory()->for($b)->count(3)->create();

        $this->assertSame(2, Conversation::forTenant($a)->count());
        $this->assertSame(3, Conversation::forTenant($b)->count());
    }

    public function test_with_status_default_excludes_archived(): void
    {
        $tenant = Tenant::factory()->create();
        Conversation::factory()->for($tenant)->create(['status' => 'active']);
        Conversation::factory()->for($tenant)->closed()->create();
        Conversation::factory()->for($tenant)->archived()->create();

        $this->assertSame(2, Conversation::forTenant($tenant)->withStatus(null)->count());
    }

    public function test_with_status_all_includes_archived(): void
    {
        $tenant = Tenant::factory()->create();
        Conversation::factory()->for($tenant)->create(['status' => 'active']);
        Conversation::factory()->for($tenant)->archived()->create();

        $this->assertSame(2, Conversation::forTenant($tenant)->withStatus('all')->count());
    }

    public function test_with_status_specific_filters_exact_match(): void
    {
        $tenant = Tenant::factory()->create();
        Conversation::factory()->for($tenant)->create(['status' => 'active']);
        Conversation::factory()->for($tenant)->closed()->create();
        Conversation::factory()->for($tenant)->archived()->create();

        $this->assertSame(1, Conversation::forTenant($tenant)->withStatus('archived')->count());
        $this->assertSame(1, Conversation::forTenant($tenant)->withStatus('closed')->count());
    }

    public function test_created_between_inclusive_at_both_ends(): void
    {
        $tenant = Tenant::factory()->create();
        Carbon::setTestNow('2026-04-01 00:00:00');
        $a = Conversation::factory()->for($tenant)->create();
        Carbon::setTestNow('2026-04-15 12:00:00');
        $b = Conversation::factory()->for($tenant)->create();
        Carbon::setTestNow('2026-05-01 23:59:59');
        $c = Conversation::factory()->for($tenant)->create();
        Carbon::setTestNow();

        $ids = Conversation::forTenant($tenant)
            ->createdBetween('2026-04-15', '2026-05-01')
            ->orderBy('id')
            ->pluck('id')
            ->toArray();

        $this->assertSame([$b->id, $c->id], $ids);
    }

    public function test_latest_message_returns_most_recent(): void
    {
        $conv = Conversation::factory()->create();
        Message::factory()->for($conv)->create(['created_at' => now()->subMinutes(5)]);
        Message::factory()->for($conv)->create(['created_at' => now()->subMinute()]);
        $m3 = Message::factory()->for($conv)->create(['created_at' => now()]);

        $this->assertSame($m3->id, $conv->latestMessage()->first()->id);
    }
}
