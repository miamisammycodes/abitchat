<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\Role;
use App\Models\EnterpriseInquiry;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\Admin\EnterpriseInquiryNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Billing\PaymentReceiptNotification;
use App\Notifications\Leads\NewLeadNotification;
use App\Services\Billing\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\TestCase;

class EmailRenderingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_receipt_renders_with_brand_and_amount(): void
    {
        // Stub the PDF generator — the dompdf render with font embedding adds
        // ~880KB and ~1s per call. We're testing the email body, not the PDF.
        $this->mock(ReceiptService::class, function ($mock) {
            $mock->shouldReceive('generatePdf')->andReturn('fake-pdf-bytes');
        });

        $tenant = Tenant::create([
            'name' => 'Demo Co',
            'slug' => uniqid('demo-'),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $plan = Plan::create([
            'name' => 'Starter',
            'slug' => uniqid('starter-'),
            'description' => 'Starter plan',
            'price' => 999.00,
            'billing_period' => 'yearly',
            'conversations_limit' => 100,
            'messages_per_conversation' => 50,
            'knowledge_items_limit' => 10,
            'tokens_limit' => 10000,
            'leads_limit' => 100,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $tx = Transaction::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'approved',
            'transaction_number' => 'TXN-DEMO',
            'amount' => 999.00,
            'payment_method' => 'dk_qr',
            'payment_date' => now(),
            'approved_at' => now(),
            'dk_reference_no' => 'DKQR-TEST-001',
        ]);

        $owner = User::factory()->create();
        UserRole::create(['user_id' => $owner->id, 'tenant_id' => $tenant->id, 'role' => Role::Owner]);

        $html = (string) (new PaymentReceiptNotification($tx))->toMail($owner)->render();

        $this->assertStringContainsString('AbitChat', $html);
        $this->assertStringContainsString('support@abit.bt', $html);
        $this->assertStringContainsString('Nu. 999.00', $html);
        $this->assertStringContainsString('TXN-DEMO', $html);
    }

    public function test_payment_receipt_falls_back_to_dk_reference_when_transaction_number_null(): void
    {
        $this->mock(ReceiptService::class, function ($mock) {
            $mock->shouldReceive('generatePdf')->andReturn('fake-pdf-bytes');
        });

        $tenant = Tenant::create([
            'name' => 'Demo Co',
            'slug' => uniqid('demo-'),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $plan = Plan::create([
            'name' => 'Starter',
            'slug' => uniqid('starter-'),
            'description' => 'Starter plan',
            'price' => 10.00,
            'billing_period' => 'yearly',
            'conversations_limit' => 100,
            'messages_per_conversation' => 50,
            'knowledge_items_limit' => 10,
            'tokens_limit' => 10000,
            'leads_limit' => 100,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $tx = Transaction::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'approved',
            'transaction_number' => null,
            'dk_reference_no' => '77000010',
            'amount' => 10.00,
            'payment_method' => 'dk_qr',
            'payment_date' => now(),
            'approved_at' => now(),
        ]);

        $owner = User::factory()->create();
        UserRole::create(['user_id' => $owner->id, 'tenant_id' => $tenant->id, 'role' => Role::Owner]);

        $html = (string) (new PaymentReceiptNotification($tx))->toMail($owner)->render();

        $this->assertStringContainsString('77000010', $html);
    }

    public function test_lead_notification_renders_with_score_label(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => uniqid('test-'),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $lead = Lead::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'status' => 'new',
            'score' => 75,
        ]);

        $html = (string) (new NewLeadNotification($lead))->toMail(new AnonymousNotifiable)->render();

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Warm', $html);
        $this->assertStringContainsString('AbitChat', $html);
    }

    public function test_enterprise_inquiry_renders_with_company(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => uniqid('test-'),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $inq = EnterpriseInquiry::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'company' => 'Acme Corp',
            'email' => 'jane@acme.test',
            'message' => 'Interested in enterprise plan',
            'status' => 'pending',
        ]);

        $html = (string) (new EnterpriseInquiryNotification($inq))->toMail(new AnonymousNotifiable)->render();

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Acme Corp', $html);
        $this->assertStringContainsString('AbitChat', $html);
    }

    public function test_password_reset_renders_with_token(): void
    {
        $user = User::factory()->create();

        $html = (string) (new ResetPasswordNotification('token-xyz'))->toMail($user)->render();

        $this->assertStringContainsString('token-xyz', $html);
        $this->assertStringContainsString('AbitChat', $html);
        $this->assertStringContainsString('support@abit.bt', $html);
    }
}
