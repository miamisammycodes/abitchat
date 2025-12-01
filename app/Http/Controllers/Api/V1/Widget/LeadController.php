<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Leads\LeadScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LeadController extends Controller
{
    public function __construct(
        private LeadScoringService $scoringService
    ) {}

    /**
     * Capture lead information from the widget.
     */
    public function capture(Request $request): JsonResponse
    {
        $request->validate([
            'api_key' => 'required|string',
            'conversation_id' => 'required|integer',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:255',
            'custom_fields' => 'nullable|array',
        ]);

        $tenant = Tenant::where('api_key', $request->api_key)->first();

        if (! $tenant) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

        $conversation = Conversation::where('id', $request->conversation_id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Check for existing lead by email (deduplication)
        $existingLead = null;
        if ($request->email) {
            $existingLead = Lead::where('tenant_id', $tenant->id)
                ->where('email', $request->email)
                ->first();
        }

        if ($existingLead) {
            // Update existing lead with new info
            $existingLead->update([
                'name' => $request->name ?? $existingLead->name,
                'phone' => $request->phone ?? $existingLead->phone,
                'company' => $request->company ?? $existingLead->company,
                'custom_fields' => array_merge(
                    $existingLead->custom_fields ?? [],
                    $request->custom_fields ?? []
                ),
            ]);

            // Update conversation reference
            $conversation->update(['lead_id' => $existingLead->id]);

            // Recalculate score
            $this->scoringService->updateLeadScore($existingLead);

            Log::debug('[Lead] (NO $) Existing lead updated', [
                'lead_id' => $existingLead->id,
                'conversation_id' => $conversation->id,
            ]);

            return response()->json([
                'success' => true,
                'lead_id' => $existingLead->id,
                'is_new' => false,
            ]);
        }

        // Create new lead
        $lead = Lead::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'company' => $request->company,
            'custom_fields' => $request->custom_fields,
            'status' => 'new',
            'score' => 0,
        ]);

        // Update conversation
        $conversation->update(['lead_id' => $lead->id]);

        // Calculate and update score
        $this->scoringService->updateLeadScore($lead);

        Log::debug('[Lead] (NO $) New lead captured', [
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'score' => $lead->score,
        ]);

        return response()->json([
            'success' => true,
            'lead_id' => $lead->id,
            'is_new' => true,
        ]);
    }
}
