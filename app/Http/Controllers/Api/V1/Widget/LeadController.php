<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Leads\LeadScoring;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LeadController extends Controller
{
    public function __construct(
        private LeadScoring $scoring
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

        $conversation = Conversation::query()
            ->whereKey($request->conversation_id)
            ->forTenant($tenant)
            ->first();

        if (! $conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Check for existing lead by email (deduplication)
        $existingLead = null;
        if ($request->email) {
            $existingLead = Lead::forTenant($tenant)
                ->where('email', $request->email)
                ->first();
        }

        if ($existingLead) {
            // Only fill blank fields. Never overwrite values already on record
            // with attacker-controlled input from the widget. custom_fields are
            // intentionally not merged from widget input on duplicate match.
            $updates = [];
            foreach (['name', 'phone', 'company'] as $field) {
                if (empty($existingLead->{$field}) && filled($request->input($field))) {
                    $updates[$field] = $request->input($field);
                }
            }

            if ($updates !== []) {
                $existingLead->fill($updates);
            }

            $conversation->update(['lead_id' => $existingLead->id]);

            $existingLead->fill(['score' => $this->scoring->score($existingLead, $conversation)]);

            if ($existingLead->isDirty()) {
                $existingLead->save();
            }

            Log::debug('[Lead] (NO $) Existing lead reattached to conversation', [
                'lead_id' => $existingLead->id,
                'conversation_id' => $conversation->id,
            ]);

            return response()->json([
                'success' => true,
                'lead_id' => $existingLead->id,
            ]);
        }

        $lead = new Lead([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'company' => $request->company,
            'custom_fields' => $request->custom_fields,
            'status' => 'new',
        ]);
        $lead->fill(['score' => $this->scoring->score($lead, $conversation)]);
        $lead->save();

        $conversation->update(['lead_id' => $lead->id]);

        Log::debug('[Lead] (NO $) New lead captured', [
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'score' => $lead->score,
        ]);

        return response()->json([
            'success' => true,
            'lead_id' => $lead->id,
        ]);
    }
}
