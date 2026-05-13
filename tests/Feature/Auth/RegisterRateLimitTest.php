<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RegisterRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_sixth_register_attempt_from_same_ip_is_rate_limited(): void
    {
        // Five blank submissions hit validation (302 redirect with errors) but each consumes a slot.
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('register.store'), [])->assertStatus(302);
        }

        $this->post(route('register.store'), [])->assertStatus(429);
    }
}
