<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $query = AdminActivityLog::with(['admin:id,name', 'target']);

        // Filter by action type
        if ($request->has('action') && $request->action !== 'all') {
            $query->where('action_type', $request->action);
        }

        // Filter by admin
        if ($request->has('admin_id') && $request->admin_id !== 'all') {
            $query->where('admin_user_id', $request->admin_id);
        }

        // Date range
        if ($request->has('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $logs = $query->latest()->paginate(50)->withQueryString();

        // Get distinct action types for filter
        $actionTypes = AdminActivityLog::distinct()->pluck('action_type');

        return Inertia::render('Admin/Logs/Index', [
            'logs' => $logs,
            'actionTypes' => $actionTypes,
            'filters' => [
                'action' => $request->input('action', 'all'),
                'admin_id' => $request->input('admin_id', 'all'),
                'from' => $request->input('from'),
                'to' => $request->input('to'),
            ],
        ]);
    }
}
