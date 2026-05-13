<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ForgotPasswordRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_sixth_forgot_password_attempt_from_same_ip_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.email'), ['email' => "user{$i}@example.com"])
                ->assertStatus(302);
        }

        $this->post(route('password.email'), ['email' => 'user6@example.com'])
            ->assertStatus(429);
    }
}
