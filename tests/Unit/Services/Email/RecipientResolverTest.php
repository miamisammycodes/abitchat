<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Enums\EmailType;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Email\RecipientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use LogicException;
use Tests\TestCase;

class RecipientResolverTest extends TestCase
{
    use RefreshDatabase;

    private RecipientResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new RecipientResolver;
    }

    public function test_returns_owners_for_tenant_facing_email_types(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create();
        UserRole::create([
            'user_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'role' => Role::Owner,
        ]);

        $this->assertCount(1, $this->resolver->recipientsFor(EmailType::Receipt, $tenant));
        $this->assertCount(1, $this->resolver->recipientsFor(EmailType::LeadNotification, $tenant));
        $this->assertCount(1, $this->resolver->recipientsFor(EmailType::QuotaWarning, $tenant));
    }

    public function test_returns_multiple_owners_when_present(): void
    {
        $tenant = Tenant::factory()->create();
        $owner1 = User::factory()->create();
        $owner2 = User::factory()->create();
        foreach ([$owner1, $owner2] as $u) {
            UserRole::create([
                'user_id' => $u->id,
                'tenant_id' => $tenant->id,
                'role' => Role::Owner,
            ]);
        }

        $recipients = $this->resolver->recipientsFor(EmailType::Receipt, $tenant);

        $this->assertCount(2, $recipients);
        $this->assertEqualsCanonicalizing(
            [$owner1->id, $owner2->id],
            $recipients->pluck('id')->all(),
        );
    }

    public function test_excludes_non_owner_roles(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $agent = User::factory()->create();
        UserRole::create(['user_id' => $owner->id, 'tenant_id' => $tenant->id, 'role' => Role::Owner]);
        UserRole::create(['user_id' => $manager->id, 'tenant_id' => $tenant->id, 'role' => Role::Manager]);
        UserRole::create(['user_id' => $agent->id, 'tenant_id' => $tenant->id, 'role' => Role::Agent]);

        $recipients = $this->resolver->recipientsFor(EmailType::Receipt, $tenant);

        $this->assertCount(1, $recipients);
        $this->assertSame($owner->id, $recipients->first()->id);
    }

    public function test_returns_empty_collection_when_no_owners(): void
    {
        $tenant = Tenant::factory()->create();

        $recipients = $this->resolver->recipientsFor(EmailType::Receipt, $tenant);

        $this->assertInstanceOf(Collection::class, $recipients);
        $this->assertCount(0, $recipients);
    }

    public function test_excludes_owners_of_other_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        UserRole::create(['user_id' => $ownerA->id, 'tenant_id' => $tenantA->id, 'role' => Role::Owner]);
        UserRole::create(['user_id' => $ownerB->id, 'tenant_id' => $tenantB->id, 'role' => Role::Owner]);

        $recipients = $this->resolver->recipientsFor(EmailType::Receipt, $tenantA);

        $this->assertCount(1, $recipients);
        $this->assertSame($ownerA->id, $recipients->first()->id);
    }

    public function test_enterprise_inquiry_returns_anonymous_notifiable_for_admin_address(): void
    {
        config(['mail.admin_inquiry_address' => 'admin@abit.bt']);

        $recipients = $this->resolver->recipientsFor(EmailType::EnterpriseInquiry);

        $this->assertCount(1, $recipients);
        $this->assertInstanceOf(AnonymousNotifiable::class, $recipients->first());
        $this->assertSame('admin@abit.bt', $recipients->first()->routeNotificationFor('mail'));
    }

    public function test_team_invite_throws_logic_exception(): void
    {
        $this->expectException(LogicException::class);
        $this->resolver->recipientsFor(EmailType::TeamInvite, Tenant::factory()->create());
    }

    public function test_password_reset_throws_logic_exception(): void
    {
        $this->expectException(LogicException::class);
        $this->resolver->recipientsFor(EmailType::PasswordReset);
    }
}
