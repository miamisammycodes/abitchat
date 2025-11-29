<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\Leads\LeadService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends Controller
{
    public function __construct(
        private LeadService $leadService
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $tenant = $request->user()->tenant;

        $query = Lead::where('tenant_id', $tenant->id)
            ->with(['conversation' => fn ($q) => $q->withCount('messages')]);

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by score range
        if ($request->has('score_min')) {
            $query->where('score', '>=', (int) $request->score_min);
        }
        if ($request->has('score_max')) {
            $query->where('score', '<=', (int) $request->score_max);
        }

        // Search by name, email, phone
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortField = $request->input('sort', 'created_at');
        $sortDirection = $request->input('direction', 'desc');
        $allowedSorts = ['created_at', 'score', 'name', 'status'];

        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortDirection);
        }

        $leads = $query->paginate(20)->withQueryString();

        $stats = $this->leadService->getStats($tenant);

        return Inertia::render('Client/Leads/Index', [
            'leads' => $leads,
            'stats' => $stats,
            'filters' => [
                'status' => $request->input('status', 'all'),
                'search' => $request->input('search', ''),
                'sort' => $sortField,
                'direction' => $sortDirection,
            ],
        ]);
    }

    public function show(Request $request, Lead $lead): InertiaResponse
    {
        $tenant = $request->user()->tenant;

        // Ensure lead belongs to tenant
        if ($lead->tenant_id !== $tenant->id) {
            abort(404);
        }

        $lead->load([
            'conversation.messages' => fn ($q) => $q->orderBy('created_at', 'asc'),
            'conversations' => fn ($q) => $q->withCount('messages')->orderBy('created_at', 'desc'),
        ]);

        return Inertia::render('Client/Leads/Show', [
            'lead' => $lead,
        ]);
    }

    public function update(Request $request, Lead $lead)
    {
        $tenant = $request->user()->tenant;

        if ($lead->tenant_id !== $tenant->id) {
            abort(404);
        }

        $validated = $request->validate([
            'status' => 'sometimes|in:new,contacted,qualified,converted,lost',
            'name' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'sometimes|nullable|string|max:50',
            'company' => 'sometimes|nullable|string|max:255',
            'score_adjustment' => 'sometimes|integer|min:-100|max:100',
            'score_reason' => 'sometimes|nullable|string|max:255',
        ]);

        // Handle score adjustment separately
        if (isset($validated['score_adjustment'])) {
            $this->leadService->adjustScore(
                $lead,
                $validated['score_adjustment'],
                $validated['score_reason'] ?? ''
            );
            unset($validated['score_adjustment'], $validated['score_reason']);
        }

        // Update other fields
        if (!empty($validated)) {
            $lead->update($validated);
        }

        return back()->with('success', 'Lead updated successfully.');
    }

    public function destroy(Request $request, Lead $lead)
    {
        $tenant = $request->user()->tenant;

        if ($lead->tenant_id !== $tenant->id) {
            abort(404);
        }

        $lead->delete();

        return redirect()->route('client.leads.index')
            ->with('success', 'Lead deleted successfully.');
    }

    public function exportSingle(Request $request, Lead $lead): StreamedResponse
    {
        $tenant = $request->user()->tenant;

        if ($lead->tenant_id !== $tenant->id) {
            abort(404);
        }

        $lead->load(['conversation.messages']);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="lead-' . $lead->id . '-' . now()->format('Y-m-d') . '.csv"',
        ];

        $callback = function () use ($lead) {
            $file = fopen('php://output', 'w');

            // Lead Info Section
            fputcsv($file, ['LEAD INFORMATION']);
            fputcsv($file, ['Field', 'Value']);
            fputcsv($file, ['ID', $lead->id]);
            fputcsv($file, ['Name', $lead->name]);
            fputcsv($file, ['Email', $lead->email]);
            fputcsv($file, ['Phone', $lead->phone]);
            fputcsv($file, ['Company', $lead->company]);
            fputcsv($file, ['Score', $lead->score]);
            fputcsv($file, ['Status', $lead->status]);
            fputcsv($file, ['Source', $lead->source]);
            fputcsv($file, ['Created At', $lead->created_at->format('Y-m-d H:i:s')]);
            fputcsv($file, []);

            // Conversation History Section
            if ($lead->conversation && $lead->conversation->messages->count() > 0) {
                fputcsv($file, ['CONVERSATION HISTORY']);
                fputcsv($file, ['Timestamp', 'Role', 'Message']);

                foreach ($lead->conversation->messages as $message) {
                    fputcsv($file, [
                        $message->created_at->format('Y-m-d H:i:s'),
                        ucfirst($message->role),
                        $message->content,
                    ]);
                }
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function export(Request $request): StreamedResponse
    {
        $tenant = $request->user()->tenant;

        $query = Lead::where('tenant_id', $tenant->id);

        // Apply same filters as index
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $leads = $query->orderBy('created_at', 'desc')->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="leads-' . now()->format('Y-m-d') . '.csv"',
        ];

        $callback = function () use ($leads) {
            $file = fopen('php://output', 'w');

            // Header row
            fputcsv($file, [
                'ID',
                'Name',
                'Email',
                'Phone',
                'Company',
                'Score',
                'Status',
                'Source',
                'Created At',
            ]);

            // Data rows
            foreach ($leads as $lead) {
                fputcsv($file, [
                    $lead->id,
                    $lead->name,
                    $lead->email,
                    $lead->phone,
                    $lead->company,
                    $lead->score,
                    $lead->status,
                    $lead->source,
                    $lead->created_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
