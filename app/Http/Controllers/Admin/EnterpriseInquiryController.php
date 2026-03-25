<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseInquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EnterpriseInquiryController extends Controller
{
    public function index(Request $request): Response
    {
        $query = EnterpriseInquiry::with('tenant:id,name');

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Search by name, email, or company
        if ($request->has('search') && $request->search) {
            $search = (string) $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortField = (string) $request->input('sort', 'created_at');
        $sortDirection = (string) $request->input('direction', 'desc');

        $query->orderBy($sortField, $sortDirection);

        $inquiries = $query->paginate(20)->withQueryString();

        // Counts for tabs
        $counts = [
            'all' => EnterpriseInquiry::count(),
            'pending' => EnterpriseInquiry::where('status', 'pending')->count(),
            'contacted' => EnterpriseInquiry::where('status', 'contacted')->count(),
            'closed' => EnterpriseInquiry::where('status', 'closed')->count(),
        ];

        return Inertia::render('Admin/Inquiries/Index', [
            'inquiries' => $inquiries,
            'counts' => $counts,
            'filters' => [
                'status' => $request->input('status', 'all'),
                'search' => $request->input('search', ''),
                'sort' => $sortField,
                'direction' => $sortDirection,
            ],
        ]);
    }

    public function update(Request $request, EnterpriseInquiry $inquiry): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,contacted,closed',
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        $inquiry->update($validated);

        return back()->with('success', 'Inquiry updated successfully.');
    }
}
