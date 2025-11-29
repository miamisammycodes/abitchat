<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
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
            'tokens_limit' => 10000,
            'leads_limit' => 50,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        Plan::create([
            'name' => 'Starter',
            'slug' => 'starter',
            'price' => 29,
            'billing_period' => 'monthly',
            'conversations_limit' => 1000,
            'knowledge_items_limit' => 50,
            'tokens_limit' => 100000,
            'leads_limit' => 500,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Plan::create([
            'name' => 'Professional',
            'slug' => 'professional',
            'price' => 99,
            'billing_period' => 'monthly',
            'conversations_limit' => -1,
            'knowledge_items_limit' => 200,
            'tokens_limit' => -1,
            'leads_limit' => -1,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // Create test tenant
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
            'api_key' => bin2hex(random_bytes(32)),
            'status' => 'active',
            'plan_id' => $freePlan->id,
            'settings' => [
                'welcome_message' => 'Hello! How can I help you today?',
                'primary_color' => '#4F46E5',
                'position' => 'bottom-right',
            ],
        ]);

        // Create test user associated with tenant
        User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'tenant_id' => $tenant->id,
        ]);

        // Create admin user
        AdminUser::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);
    }
}
