<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\UsageRecord;
use Tests\TestCase;

class AdminClientShowTest extends TestCase
{
    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'name' => 'A',
            'email' => 'a@test.example',
            'password' => bcrypt('x'),
        ]);
    }

    public function test_show_renders_stats_for_a_client_with_data(): void
    {
        $tenant = Tenant::create([
            'name' => 'Show Client',
            'slug' => 'show-client-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        // Seed one of each so all eight queries in show() return non-zero.
        Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => 'sess-'.uniqid(),
            'status' => 'active',
        ]);
        Lead::create([
            'tenant_id' => $tenant->id,
            'name' => 'Seed',
            'email' => 'seed@example.com',
            'status' => 'new',
            'score' => 50,
        ]);
        UsageRecord::create([
            'tenant_id' => $tenant->id,
            'type' => 'tokens',
            'quantity' => 1234,
            'period' => now()->format('Y-m'),
        ]);
        $plan = Plan::create([
            'name' => 'P', 'slug' => 'p-'.uniqid(),
            'price' => 0, 'billing_period' => 'monthly', 'is_active' => true,
            'conversations_limit' => 10, 'leads_limit' => 10,
            'tokens_limit' => 1000, 'knowledge_items_limit' => 1,
        ]);
        Transaction::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'amount' => 0,
            'status' => 'pending',
            'transaction_number' => 'tx-'.uniqid(),
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->get("/admin/clients/{$tenant->id}");

        $response->assertStatus(200);

        $stats = $response->viewData('page')['props']['stats'];
        $this->assertGreaterThanOrEqual(1, $stats['conversations']['total']);
        $this->assertGreaterThanOrEqual(1, $stats['leads']['total']);
        $this->assertGreaterThanOrEqual(1234, $stats['tokens']['total']);

        $props = $response->viewData('page')['props'];
        $this->assertNotEmpty($props['transactions']);
        $this->assertNotEmpty($props['recentConversations']);
    }
}
