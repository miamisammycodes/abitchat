<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuthRateLimitIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_register_and_forgot_password_have_independent_rate_limit_buckets(): void
    {
        // Hit /register 4 times — one slot short of the 5/min ceiling.
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('register.store'), [])->assertStatus(302);
        }

        // Hit /forgot-password 4 times from the same IP — should NOT consume
        // the /register bucket.
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('password.email'), ['email' => "iso{$i}@example.com"])
                ->assertStatus(302);
        }

        // Both endpoints should still have 1 slot left.
        $this->post(route('register.store'), [])
            ->assertStatus(302, 'register bucket must not have been drained by forgot-password attempts');
        $this->post(route('password.email'), ['email' => 'last@example.com'])
            ->assertStatus(302, 'forgot-password bucket must not have been drained by register attempts');
    }
}
