<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePlanRequest;
use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Plan::query();

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('is_active', $request->status === 'active');
        }

        // Search by name or slug
        if ($request->has('search') && $request->search) {
            $search = (string) $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortField = (string) $request->input('sort', 'sort_order');
        $sortDirection = (string) $request->input('direction', 'asc');
        $allowedSorts = ['sort_order', 'name', 'price', 'created_at', 'is_active'];

        if (!in_array($sortField, $allowedSorts, true)) {
            $sortField = 'sort_order';
        }
        if (!in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'asc';
        }

        $query->orderBy($sortField, $sortDirection);

        $plans = $query->paginate(20)->withQueryString();

        // Counts for tabs
        $counts = [
            'all' => Plan::count(),
            'active' => Plan::where('is_active', true)->count(),
            'inactive' => Plan::where('is_active', false)->count(),
        ];

        return Inertia::render('Admin/Plans/Index', [
            'plans' => $plans,
            'counts' => $counts,
            'filters' => [
                'status' => $request->input('status', 'all'),
                'search' => $request->input('search', ''),
                'sort' => $sortField,
                'direction' => $sortDirection,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Plans/Create');
    }

    public function store(StorePlanRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        // Set sort_order to max + 1 if not provided
        if (!isset($validated['sort_order'])) {
            $validated['sort_order'] = Plan::max('sort_order') + 1;
        }

        Plan::create($validated);

        return redirect()
            ->route('admin.plans.index')
            ->with('success', 'Plan created successfully.');
    }

    public function edit(Plan $plan): Response
    {
        return Inertia::render('Admin/Plans/Edit', [
            'plan' => $plan,
        ]);
    }

    public function update(UpdatePlanRequest $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validated();

        $plan->update($validated);

        return redirect()
            ->route('admin.plans.index')
            ->with('success', 'Plan updated successfully.');
    }

    public function toggleStatus(Plan $plan): RedirectResponse
    {
        $plan->update([
            'is_active' => !$plan->is_active,
        ]);

        $status = $plan->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Plan {$status} successfully.");
    }
}
