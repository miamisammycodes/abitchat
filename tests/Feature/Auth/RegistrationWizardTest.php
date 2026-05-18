<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Jobs\CrawlWebsiteJob;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RegistrationWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_without_website_url_does_not_dispatch_crawl(): void
    {
        Bus::fake();

        $response = $this->post('/register', [
            'name' => 'User',
            'email' => 'noweb@example.com',
            'company_name' => 'Co',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertRedirect('/dashboard');
        Bus::assertNotDispatched(CrawlWebsiteJob::class);
        $this->assertNull(Tenant::where('name', 'Co')->first()->website_url);
    }

    public function test_registration_with_website_url_dispatches_crawl_and_saves_url(): void
    {
        Bus::fake();

        $response = $this->post('/register', [
            'name' => 'User',
            'email' => 'with@example.com',
            'company_name' => 'Co',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'website_url' => 'https://example.com',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertSame('https://example.com', Tenant::where('name', 'Co')->first()->website_url);
        Bus::assertDispatched(CrawlWebsiteJob::class, function (CrawlWebsiteJob $job) {
            return $job->tenant->website_url === 'https://example.com' && $job->mode === 'initial';
        });
    }

    public function test_malformed_url_rejected_at_validation(): void
    {
        $response = $this->post('/register', [
            'name' => 'User',
            'email' => 'bad@example.com',
            'company_name' => 'Co',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'website_url' => 'not a url',
        ]);

        $response->assertSessionHasErrors('website_url');
        $this->assertDatabaseMissing('tenants', ['name' => 'Co']);
    }

    public function test_private_url_rejected_by_safe_external_url(): void
    {
        $response = $this->post('/register', [
            'name' => 'User',
            'email' => 'ssrf@example.com',
            'company_name' => 'Co',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'website_url' => 'http://localhost/admin',
        ]);

        $response->assertSessionHasErrors('website_url');
    }

    public function test_unreachable_but_well_formed_url_still_creates_tenant(): void
    {
        // No HEAD check at submit — validation only checks URL format + SSRF safety,
        // not HTTP reachability. A valid public domain passes even if its web server is down.
        Bus::fake();

        $response = $this->post('/register', [
            'name' => 'User',
            'email' => 'unreach@example.com',
            'company_name' => 'Co',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'website_url' => 'https://example.net',
        ]);

        $response->assertRedirect('/dashboard');
        Bus::assertDispatched(CrawlWebsiteJob::class, 1);
    }
}
