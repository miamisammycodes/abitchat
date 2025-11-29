<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Tenant;
use Tests\TestCase;

class LeadManagementTest extends TestCase
{
    public function test_leads_index_requires_authentication(): void
    {
        $response = $this->get('/leads');

        $response->assertRedirect('/login');
    }

    public function test_leads_index_can_be_rendered(): void
    {
        $this->actingAsTenantUser();

        $response = $this->get('/leads');

        $response->assertStatus(200);
    }

    public function test_user_can_view_their_leads(): void
    {
        $this->actingAsTenantUser();

        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'status' => 'new',
            'score' => 50,
        ]);

        $response = $this->get('/leads');

        $response->assertStatus(200);
    }

    public function test_user_can_view_single_lead(): void
    {
        $this->actingAsTenantUser();

        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'status' => 'new',
            'score' => 75,
        ]);

        $response = $this->get("/leads/{$lead->id}");

        $response->assertStatus(200);
    }

    public function test_user_cannot_view_other_tenants_leads(): void
    {
        $this->actingAsTenantUser();

        $otherTenant = Tenant::create([
            'name' => 'Other Company',
            'slug' => 'other-company',
            'status' => 'active',
        ]);

        $otherLead = Lead::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Lead',
            'email' => 'other@example.com',
            'status' => 'new',
            'score' => 30,
        ]);

        $response = $this->get("/leads/{$otherLead->id}");

        $response->assertStatus(404);
    }

    public function test_user_can_update_lead_status(): void
    {
        $this->actingAsTenantUser();

        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Status Test Lead',
            'email' => 'status@example.com',
            'status' => 'new',
            'score' => 50,
        ]);

        $response = $this->put("/leads/{$lead->id}", [
            'status' => 'contacted',
        ]);

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'status' => 'contacted',
        ]);

        $response->assertRedirect();
    }

    public function test_user_can_update_lead_info(): void
    {
        $this->actingAsTenantUser();

        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Original Name',
            'email' => 'original@example.com',
            'status' => 'new',
            'score' => 50,
        ]);

        $response = $this->put("/leads/{$lead->id}", [
            'name' => 'Updated Name',
            'company' => 'New Company',
        ]);

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'name' => 'Updated Name',
            'company' => 'New Company',
        ]);

        $response->assertRedirect();
    }

    public function test_lead_status_must_be_valid(): void
    {
        $this->actingAsTenantUser();

        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Validation Test',
            'email' => 'validation@example.com',
            'status' => 'new',
            'score' => 50,
        ]);

        $response = $this->put("/leads/{$lead->id}", [
            'status' => 'invalid_status',
        ]);

        $response->assertSessionHasErrors('status');
    }

    public function test_user_can_delete_lead(): void
    {
        $this->actingAsTenantUser();

        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'To Delete',
            'email' => 'delete@example.com',
            'status' => 'new',
            'score' => 50,
        ]);

        $response = $this->delete("/leads/{$lead->id}");

        $this->assertDatabaseMissing('leads', [
            'id' => $lead->id,
        ]);

        $response->assertRedirect(route('client.leads.index'));
    }

    public function test_user_cannot_delete_other_tenants_leads(): void
    {
        $this->actingAsTenantUser();

        $otherTenant = Tenant::create([
            'name' => 'Other Company',
            'slug' => 'other-company-2',
            'status' => 'active',
        ]);

        $otherLead = Lead::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Lead Delete',
            'email' => 'other-delete@example.com',
            'status' => 'new',
            'score' => 30,
        ]);

        $response = $this->delete("/leads/{$otherLead->id}");

        $response->assertStatus(404);

        // Lead should still exist
        $this->assertDatabaseHas('leads', [
            'id' => $otherLead->id,
        ]);
    }

    public function test_leads_can_be_filtered_by_status(): void
    {
        $this->actingAsTenantUser();

        Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'New Lead',
            'email' => 'new@example.com',
            'status' => 'new',
            'score' => 50,
        ]);

        Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Contacted Lead',
            'email' => 'contacted@example.com',
            'status' => 'contacted',
            'score' => 60,
        ]);

        $response = $this->get('/leads?status=new');

        $response->assertStatus(200);
    }

    public function test_leads_can_be_searched(): void
    {
        $this->actingAsTenantUser();

        Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Searchable Lead',
            'email' => 'searchable@example.com',
            'status' => 'new',
            'score' => 50,
        ]);

        $response = $this->get('/leads?search=searchable');

        $response->assertStatus(200);
    }

    public function test_leads_can_be_exported(): void
    {
        $this->actingAsTenantUser();

        Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Export Lead',
            'email' => 'export@example.com',
            'status' => 'new',
            'score' => 50,
        ]);

        $response = $this->get('/leads/export');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }
}
