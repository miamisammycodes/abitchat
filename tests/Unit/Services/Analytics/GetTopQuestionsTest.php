<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Analytics;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Analytics\AnalyticsService;
use Tests\TestCase;

class GetTopQuestionsTest extends TestCase
{
    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Analytics',
            'slug' => 'analytics-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function test_groups_identical_questions_and_returns_one_sample_per_group(): void
    {
        $tenant = $this->makeTenant();
        $conv = Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => 'sess-'.uniqid(),
            'status' => 'active',
        ]);

        for ($i = 0; $i < 3; $i++) {
            Message::create([
                'conversation_id' => $conv->id,
                'role' => 'user',
                'content' => 'What is the price?',
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            Message::create([
                'conversation_id' => $conv->id,
                'role' => 'user',
                'content' => 'How do I sign up?',
            ]);
        }

        $top = app(AnalyticsService::class)->getTopQuestions($tenant);

        $this->assertCount(2, $top);
        $this->assertSame('What is the price?', $top[0]['question']);
        $this->assertSame(3, $top[0]['count']);
        $this->assertSame('How do I sign up?', $top[1]['question']);
        $this->assertSame(2, $top[1]['count']);
    }
}
