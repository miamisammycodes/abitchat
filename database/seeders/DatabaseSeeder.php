<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds production-safe reference data only.
     *
     * No users or tenants are seeded — production must never contain fake
     * accounts. Bootstrap a platform admin with `php artisan admin:create`;
     * tenant owners and their staff arrive through registration.
     */
    public function run(): void
    {
        Plan::create([
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
    }
}
