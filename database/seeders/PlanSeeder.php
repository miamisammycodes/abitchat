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
                'name' => 'Free Trial',
                'slug' => 'free',
                'description' => '14-day free trial to get started',
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
                'is_contact_sales' => false,
                'sort_order' => 1,
            ],
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'For growing businesses',
                'price' => 5000,
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
                'is_contact_sales' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => 'For large teams and enterprises',
                'price' => 7000,
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
                'is_contact_sales' => false,
                'sort_order' => 3,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Custom solutions for large organizations',
                'price' => 0,
                'billing_period' => 'monthly',
                'conversations_limit' => -1, // Unlimited
                'messages_per_conversation' => -1,
                'knowledge_items_limit' => -1,
                'tokens_limit' => -1,
                'leads_limit' => -1,
                'features' => [
                    'Everything in Business',
                    'Unlimited tokens',
                    'Dedicated account manager',
                    'Custom SLA',
                    'On-premise deployment option',
                    'White-label solution',
                    'Priority feature requests',
                ],
                'is_active' => true,
                'is_contact_sales' => true,
                'sort_order' => 4,
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
