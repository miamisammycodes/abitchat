<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    public function test_registration_page_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'newuser@example.com',
            'company_name' => 'New Company',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        // Check tenant was created
        $this->assertDatabaseHas('tenants', [
            'name' => 'New Company',
        ]);

        // Check user was created and linked to tenant
        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->tenant_id);

        $response->assertRedirect(route('dashboard'));
    }

    public function test_registration_requires_valid_email(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'invalid-email',
            'company_name' => 'New Company',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_registration_requires_password_confirmation(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'company_name' => 'New Company',
            'password' => 'password123',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_registration_requires_company_name(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('company_name');
    }

    public function test_duplicate_email_cannot_register(): void
    {
        // Create existing user
        $this->createTenantWithUser();

        $response = $this->post('/register', [
            'name' => 'Another User',
            'email' => 'test@example.com', // Same email
            'company_name' => 'Another Company',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('email');
    }
}
