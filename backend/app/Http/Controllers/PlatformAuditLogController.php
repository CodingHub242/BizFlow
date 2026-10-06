<?php

namespace App\Http\Controllers;

use App\Models\PlatformAuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformAuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'action' => [
                'nullable',
                'string',
                'in:business.approved,business.rejected,business.suspended',
            ],
        ]);

        $action = $validated['action'] ?? null;

        $logs = PlatformAuditLog::query()
            ->with(['platformAdmin', 'target'])
            ->when(
                $action,
                fn ($query) => $query->where('action', $action)
            )
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('platform.audit-logs.index', [
            'logs' => $logs,
            'action' => $action,
        ]);
    }
}