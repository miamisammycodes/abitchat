<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create plans
        $freePlan = Plan::create([
            'name' => 'Free',
            'slug' => 'free',
            'price' => 0,
            'billing_period' => 'monthly',
            'conversations_limit' => 100,
            'knowledge_items_limit' => 10,
            'tokens_limit' => 50000,
            'leads_limit' => 50,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        Plan::create([
            'name' => 'Starter',
            'slug' => 'starter',
            'price' => 999,
            'billing_period' => 'monthly',
            'conversations_limit' => 1000,
            'knowledge_items_limit' => 50,
            'tokens_limit' => 500000,
            'leads_limit' => 500,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Plan::create([
            'name' => 'Professional',
            'slug' => 'professional',
            'price' => 2999,
            'billing_period' => 'monthly',
            'conversations_limit' => -1,
            'knowledge_items_limit' => 200,
            'tokens_limit' => -1,
            'leads_limit' => -1,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // ── Test Company tenant ──────────────────────────────────────────────
        // WithoutModelEvents disables the creating hook that auto-sets api_key_hash,
        // so we must set it explicitly here to keep the column in sync.
        $tenantApiKey = bin2hex(random_bytes(32));
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
            'api_key' => $tenantApiKey,
            'api_key_hash' => hash('sha256', $tenantApiKey.config('app.key')),
            'status' => 'active',
            'plan_id' => $freePlan->id,
            'settings' => [
                'welcome_message' => 'Hello! How can I help you today?',
                'primary_color' => '#4F46E5',
                'position' => 'bottom-right',
            ],
        ]);

        // admin@example.com — SuperAdmin only (no tenant)
        $adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'tenant_id' => null,
        ]);
        UserRole::create([
            'user_id' => $adminUser->id,
            'role' => Role::SuperAdmin,
            'tenant_id' => null,
        ]);

        // test@example.com — Owner of Test Company + SuperAdmin (dual-role; exercises chooser flow)
        $testUser = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'tenant_id' => $tenant->id,
        ]);
        UserRole::create([
            'user_id' => $testUser->id,
            'role' => Role::Owner,
            'tenant_id' => $tenant->id,
        ]);
        UserRole::create([
            'user_id' => $testUser->id,
            'role' => Role::SuperAdmin,
            'tenant_id' => null,
        ]);

        // manager@example.com — Manager of Test Company
        $managerUser = User::create([
            'name' => 'Manager User',
            'email' => 'manager@example.com',
            'password' => Hash::make('password'),
            'tenant_id' => $tenant->id,
        ]);
        UserRole::create([
            'user_id' => $managerUser->id,
            'role' => Role::Manager,
            'tenant_id' => $tenant->id,
        ]);

        // agent@example.com — Agent of Test Company
        $agentUser = User::create([
            'name' => 'Agent User',
            'email' => 'agent@example.com',
            'password' => Hash::make('password'),
            'tenant_id' => $tenant->id,
        ]);
        UserRole::create([
            'user_id' => $agentUser->id,
            'role' => Role::Agent,
            'tenant_id' => $tenant->id,
        ]);

        // ── Demo Co tenant ───────────────────────────────────────────────────
        // WithoutModelEvents suppresses the creating hook here too,
        // so api_key and api_key_hash must be set explicitly.
        $demoApiKey = bin2hex(random_bytes(32));
        $demoTenant = Tenant::create([
            'name' => 'Demo Co',
            'slug' => 'demo-co',
            'api_key' => $demoApiKey,
            'api_key_hash' => hash('sha256', $demoApiKey.config('app.key')),
            'status' => 'active',
            'settings' => [
                'welcome_message' => 'Hi there! How can we help?',
                'primary_color' => '#10B981',
                'position' => 'bottom-left',
            ],
        ]);

        // owner@demo.example — Owner of Demo Co
        $demoOwner = User::create([
            'name' => 'Demo Owner',
            'email' => 'owner@demo.example',
            'password' => Hash::make('password'),
            'tenant_id' => $demoTenant->id,
        ]);
        UserRole::create([
            'user_id' => $demoOwner->id,
            'role' => Role::Owner,
            'tenant_id' => $demoTenant->id,
        ]);

        // manager@demo.example — Manager of Demo Co
        $demoManager = User::create([
            'name' => 'Demo Manager',
            'email' => 'manager@demo.example',
            'password' => Hash::make('password'),
            'tenant_id' => $demoTenant->id,
        ]);
        UserRole::create([
            'user_id' => $demoManager->id,
            'role' => Role::Manager,
            'tenant_id' => $demoTenant->id,
        ]);

        // agent@demo.example — Agent of Demo Co
        $demoAgent = User::create([
            'name' => 'Demo Agent',
            'email' => 'agent@demo.example',
            'password' => Hash::make('password'),
            'tenant_id' => $demoTenant->id,
        ]);
        UserRole::create([
            'user_id' => $demoAgent->id,
            'role' => Role::Agent,
            'tenant_id' => $demoTenant->id,
        ]);
    }
}
