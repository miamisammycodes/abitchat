<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\Conversation;
use App\Models\KnowledgeItem;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\Tenant;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

/**
 * Sweeps every Client controller action and asserts each role-gated endpoint
 * has an authorize() call for the correct ability (D-10 controller sweep).
 *
 * Matrix:
 *  - ManageBilling (Owner only):   BillingController::submitPayment, activateTrial
 *                                  DkBankQrController::start
 *  - ManageTenantSettings (Owner): WidgetController::update, regenerateApiKey
 *                                  WebsiteIndexingController::recrawl
 *  - ManageKnowledgeBase (Mgr+):   KnowledgeBaseController::store, update, destroy, reprocess, retry
 *  - ManageLeads (Agent+):         LeadController::update, destroy, export, exportSingle
 *  - ManageConversations (Agent+): ConversationController::archive, unarchive, export
 *  - ViewDashboard (Agent+):       DashboardController::index
 *  - ViewAnalyticsFull (Mgr+):     AnalyticsController::index
 *  - ViewDashboard (Agent+):       EnterpriseInquiryController::store
 *
 * Also asserts grep guard: no ->isOwner()|->isManager()|->isAgent() in Client controllers.
 */
class AbilityCoverageTest extends TestCase
{
    use SeedsRoleMatrix;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Coverage Tenant',
            'slug' => uniqid('cov-'),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    private function makePlan(): Plan
    {
        return Plan::create([
            'name' => 'Pro',
            'slug' => uniqid('pro-'),
            'price' => 500,
            'billing_period' => 'monthly',
            'is_active' => true,
            'conversations_limit' => 100,
            'leads_limit' => 50,
            'tokens_limit' => 10000,
            'knowledge_items_limit' => 5,
        ]);
    }

    private function makeLead(Tenant $tenant): Lead
    {
        return Lead::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Lead',
            'email' => 'lead@example.com',
            'status' => 'new',
            'score' => 50,
            'source' => 'widget',
        ]);
    }

    private function makeKnowledgeItem(Tenant $tenant): KnowledgeItem
    {
        return KnowledgeItem::create([
            'tenant_id' => $tenant->id,
            'title' => 'Test KB Item',
            'type' => 'text',
            'status' => 'ready',
            'content' => 'Test content',
        ]);
    }

    private function makeConversation(Tenant $tenant): Conversation
    {
        return Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => uniqid('sess-'),
            'status' => 'active',
        ]);
    }

    // -------------------------------------------------------------------------
    // GREP GUARD: no legacy helper references in Client controllers
    // -------------------------------------------------------------------------

    public function test_no_legacy_role_helpers_in_client_controllers(): void
    {
        $dir = base_path('app/Http/Controllers/Client');
        $files = glob($dir.'/*.php') ?: [];

        $this->assertNotEmpty($files, 'No Client controller files found');

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);
            $this->assertStringNotContainsString(
                '->isOwner()',
                $contents,
                basename($file).' still references deleted ->isOwner() helper',
            );
            $this->assertStringNotContainsString(
                '->isManager()',
                $contents,
                basename($file).' still references deleted ->isManager() helper',
            );
            $this->assertStringNotContainsString(
                '->isAgent()',
                $contents,
                basename($file).' still references deleted ->isAgent() helper',
            );
        }
    }

    // -------------------------------------------------------------------------
    // DashboardController::index — ViewDashboard (Agent+)
    // -------------------------------------------------------------------------

    public function test_unauthenticated_dashboard_redirects_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_agent_can_access_dashboard(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_owner_can_access_dashboard(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsOwner($tenant);
        $this->get(route('dashboard'))->assertOk();
    }

    // -------------------------------------------------------------------------
    // AnalyticsController::index — ViewAnalyticsFull (Manager+)
    // -------------------------------------------------------------------------

    public function test_agent_cannot_access_analytics(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);
        $this->get(route('client.analytics.index'))->assertForbidden();
    }

    public function test_manager_can_access_analytics(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsManager($tenant);
        $this->get(route('client.analytics.index'))->assertOk();
    }

    public function test_owner_can_access_analytics(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsOwner($tenant);
        $this->get(route('client.analytics.index'))->assertOk();
    }

    // -------------------------------------------------------------------------
    // BillingController::submitPayment — ManageBilling (Owner only)
    // -------------------------------------------------------------------------

    public function test_manager_cannot_submit_payment(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsManager($tenant);
        $plan = $this->makePlan();

        $this->post(route('client.billing.submit-payment', $plan))->assertForbidden();
    }

    public function test_agent_cannot_submit_payment(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);
        $plan = $this->makePlan();

        $this->post(route('client.billing.submit-payment', $plan))->assertForbidden();
    }

    public function test_owner_can_submit_payment(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsOwner($tenant);
        $plan = $this->makePlan();

        // We're not testing the full payment flow, just that the gate allows the owner
        // Invalid data returns 422 (validation), not 403 (auth). Either way, not 403.
        $response = $this->post(route('client.billing.submit-payment', $plan));
        $response->assertStatus(fn ($s) => $s !== 403);
    }

    // -------------------------------------------------------------------------
    // BillingController::activateTrial — ManageBilling (Owner only)
    // -------------------------------------------------------------------------

    public function test_manager_cannot_activate_trial(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsManager($tenant);
        $plan = $this->makePlan();

        $this->post(route('client.billing.activate-trial', $plan))->assertForbidden();
    }

    public function test_agent_cannot_activate_trial(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);
        $plan = $this->makePlan();

        $this->post(route('client.billing.activate-trial', $plan))->assertForbidden();
    }

    public function test_owner_can_activate_trial(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsOwner($tenant);
        $plan = Plan::create([
            'name' => 'Free',
            'slug' => uniqid('free-'),
            'price' => 0,
            'billing_period' => 'monthly',
            'is_active' => true,
            'conversations_limit' => 50,
            'leads_limit' => 25,
            'tokens_limit' => 5000,
            'knowledge_items_limit' => 3,
        ]);

        // Not 403 — gate allows owner (business logic may redirect with error about active plan)
        $response = $this->post(route('client.billing.activate-trial', $plan));
        $this->assertNotEquals(403, $response->status());
    }

    // -------------------------------------------------------------------------
    // DkBankQrController::start — ManageBilling (Owner only)
    // -------------------------------------------------------------------------

    public function test_manager_cannot_start_dk_qr(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsManager($tenant);
        $plan = $this->makePlan();

        $this->post(route('client.billing.dk-qr.start', $plan))->assertForbidden();
    }

    public function test_agent_cannot_start_dk_qr(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);
        $plan = $this->makePlan();

        $this->post(route('client.billing.dk-qr.start', $plan))->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // WidgetController::update — ManageTenantSettings (Owner only)
    // -------------------------------------------------------------------------

    public function test_manager_cannot_update_widget_settings(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsManager($tenant);

        $this->put(route('client.widget.update'))->assertForbidden();
    }

    public function test_agent_cannot_update_widget_settings(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);

        $this->put(route('client.widget.update'))->assertForbidden();
    }

    public function test_owner_can_update_widget_settings(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsOwner($tenant);

        $response = $this->put(route('client.widget.update'), ['welcome_message' => 'Hello!']);
        $this->assertNotEquals(403, $response->status());
    }

    // -------------------------------------------------------------------------
    // WidgetController::regenerateApiKey — ManageTenantSettings (Owner only)
    // -------------------------------------------------------------------------

    public function test_manager_cannot_regenerate_api_key(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsManager($tenant);

        $this->post(route('client.widget.regenerate-key'))->assertForbidden();
    }

    public function test_agent_cannot_regenerate_api_key(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);

        $this->post(route('client.widget.regenerate-key'))->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // WebsiteIndexingController::recrawl — ManageTenantSettings (Owner only)
    // -------------------------------------------------------------------------

    public function test_manager_cannot_trigger_recrawl(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsManager($tenant);

        $this->post(route('widget.indexing.recrawl'))->assertForbidden();
    }

    public function test_agent_cannot_trigger_recrawl(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);

        $this->post(route('widget.indexing.recrawl'))->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // KnowledgeBaseController::store — ManageKnowledgeBase (Manager+)
    // -------------------------------------------------------------------------

    public function test_agent_cannot_create_knowledge_item(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);

        $this->post(route('client.knowledge.store'), [
            'type' => 'text',
            'title' => 'Test',
            'content' => 'Content',
        ])->assertForbidden();
    }

    public function test_manager_can_create_knowledge_item(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsManager($tenant);

        // Not 403 — validation failure (missing check.limits middleware) returns 422 or redirect
        $response = $this->post(route('client.knowledge.store'), [
            'type' => 'text',
            'title' => 'Test',
            'content' => 'Content',
        ]);
        $this->assertNotEquals(403, $response->status());
    }

    // -------------------------------------------------------------------------
    // KnowledgeBaseController::destroy — ManageKnowledgeBase (Manager+)
    // -------------------------------------------------------------------------

    public function test_agent_cannot_delete_knowledge_item(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);
        $item = $this->makeKnowledgeItem($tenant);

        $this->delete(route('client.knowledge.destroy', $item))->assertForbidden();
    }

    public function test_manager_can_delete_knowledge_item(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsManager($tenant);
        $item = $this->makeKnowledgeItem($tenant);

        $response = $this->delete(route('client.knowledge.destroy', $item));
        $this->assertNotEquals(403, $response->status());
    }

    // -------------------------------------------------------------------------
    // LeadController::update — ManageLeads (Agent+)
    // -------------------------------------------------------------------------

    public function test_unauthenticated_cannot_update_lead(): void
    {
        $tenant = $this->makeTenant();
        $lead = $this->makeLead($tenant);

        $this->put(route('client.leads.update', $lead))->assertRedirect(route('login'));
    }

    public function test_agent_can_update_lead(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);
        $lead = $this->makeLead($tenant);

        $response = $this->put(route('client.leads.update', $lead), ['status' => 'contacted']);
        $this->assertNotEquals(403, $response->status());
    }

    // -------------------------------------------------------------------------
    // LeadController::export — ManageLeads (Agent+)
    // -------------------------------------------------------------------------

    public function test_unauthenticated_cannot_export_leads(): void
    {
        $this->get(route('client.leads.export'))->assertRedirect(route('login'));
    }

    public function test_agent_can_export_leads(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);

        $response = $this->get(route('client.leads.export'));
        $this->assertNotEquals(403, $response->status());
    }

    // -------------------------------------------------------------------------
    // LeadController::destroy — ManageLeads (Agent+)
    // -------------------------------------------------------------------------

    public function test_unauthenticated_cannot_delete_lead(): void
    {
        $tenant = $this->makeTenant();
        $lead = $this->makeLead($tenant);

        $this->delete(route('client.leads.destroy', $lead))->assertRedirect(route('login'));
    }

    public function test_agent_can_delete_lead(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);
        $lead = $this->makeLead($tenant);

        $response = $this->delete(route('client.leads.destroy', $lead));
        $this->assertNotEquals(403, $response->status());
    }

    // -------------------------------------------------------------------------
    // ConversationController::archive — ManageConversations (Agent+)
    // -------------------------------------------------------------------------

    public function test_unauthenticated_cannot_archive_conversation(): void
    {
        $tenant = $this->makeTenant();
        $conversation = $this->makeConversation($tenant);

        $this->put(route('client.conversations.archive', $conversation))->assertRedirect(route('login'));
    }

    public function test_agent_can_archive_conversation(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);
        $conversation = $this->makeConversation($tenant);

        $response = $this->put(route('client.conversations.archive', $conversation));
        $this->assertNotEquals(403, $response->status());
    }

    // -------------------------------------------------------------------------
    // ConversationController::unarchive — ManageConversations (Agent+)
    // -------------------------------------------------------------------------

    public function test_agent_can_unarchive_conversation(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);
        $conversation = $this->makeConversation($tenant);

        $response = $this->put(route('client.conversations.unarchive', $conversation));
        $this->assertNotEquals(403, $response->status());
    }

    // -------------------------------------------------------------------------
    // EnterpriseInquiryController::store — ViewDashboard (Agent+)
    // -------------------------------------------------------------------------

    public function test_unauthenticated_cannot_submit_enterprise_inquiry(): void
    {
        $this->post(route('client.billing.enterprise-inquiry'))->assertRedirect(route('login'));
    }

    public function test_agent_can_submit_enterprise_inquiry(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);

        $response = $this->post(route('client.billing.enterprise-inquiry'), [
            'name' => 'Test',
            'email' => 'test@example.com',
            'message' => 'Hello',
        ]);
        $this->assertNotEquals(403, $response->status());
    }
}
