<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'Get started with basic features',
                'price' => 0,
                'billing_period' => 'monthly',
                'conversations_limit' => 100,
                'messages_per_conversation' => 20,
                'knowledge_items_limit' => 10,
                'tokens_limit' => 50000,
                'leads_limit' => 50,
                'features' => [
                    'Basic chatbot',
                    'Email support',
                    'Standard widget',
                ],
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'For growing businesses',
                'price' => 29,
                'billing_period' => 'monthly',
                'conversations_limit' => 1000,
                'messages_per_conversation' => 50,
                'knowledge_items_limit' => 100,
                'tokens_limit' => 500000,
                'leads_limit' => 500,
                'features' => [
                    'Everything in Free',
                    'Priority support',
                    'Custom branding',
                    'Advanced analytics',
                    'Export data',
                ],
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => 'For large teams and enterprises',
                'price' => 99,
                'billing_period' => 'monthly',
                'conversations_limit' => -1, // Unlimited
                'messages_per_conversation' => -1,
                'knowledge_items_limit' => -1,
                'tokens_limit' => 2000000,
                'leads_limit' => -1,
                'features' => [
                    'Everything in Pro',
                    'Unlimited conversations',
                    'Unlimited knowledge items',
                    'Unlimited leads',
                    'Dedicated support',
                    'Custom integrations',
                    'API access',
                ],
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan
            );
        }
    }
}
