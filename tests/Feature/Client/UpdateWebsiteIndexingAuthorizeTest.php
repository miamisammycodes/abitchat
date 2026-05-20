<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\Tenant;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

/**
 * Tests UpdateWebsiteIndexingRequest::authorize() delegates to Gate::allows(manage-tenant-settings).
 *
 * This is the trigger test that started Phase 16.1:
 * - Owner (has manage-tenant-settings) → authorized (200/302 success)
 * - Manager (no manage-tenant-settings) → 403
 * - Agent (no manage-tenant-settings) → 403
 * - Unauthenticated → 302 redirect to /login
 */
class UpdateWebsiteIndexingAuthorizeTest extends TestCase
{
    use SeedsRoleMatrix;

    private Tenant $policyTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policyTenant = Tenant::factory()->create(['auto_recrawl' => false]);
    }

    /** @test */
    public function test_owner_is_authorized_to_update_indexing_settings(): void
    {
        $this->actingAsOwner($this->policyTenant);

        $response = $this->patch('/widget-settings/website-indexing', [
            'website_url' => 'https://example.com',
            'auto_recrawl' => true,
        ]);

        // 302 back with success — authorized, no errors
        $response->assertSessionHasNoErrors();
        $response->assertStatus(302);
    }

    /** @test */
    public function test_manager_is_denied_update_indexing_settings(): void
    {
        $this->actingAsManager($this->policyTenant);

        $response = $this->patch('/widget-settings/website-indexing', [
            'website_url' => 'https://example.com',
            'auto_recrawl' => true,
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function test_agent_is_denied_update_indexing_settings(): void
    {
        $this->actingAsAgent($this->policyTenant);

        $response = $this->patch('/widget-settings/website-indexing', [
            'website_url' => 'https://example.com',
            'auto_recrawl' => true,
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function test_unauthenticated_is_redirected_to_login(): void
    {
        $response = $this->patch('/widget-settings/website-indexing', [
            'website_url' => 'https://example.com',
            'auto_recrawl' => true,
        ]);

        // Auth middleware redirects to /login before FormRequest::authorize() runs
        $response->assertStatus(302);
        $response->assertRedirect('/login');
    }
}
