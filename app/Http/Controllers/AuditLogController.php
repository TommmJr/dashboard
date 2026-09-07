<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Daftar audit log (append-only). Gate audit.view di route
     * (admin, management, editor_dashboard).
     * Filter sederhana: action, user, dan rentang tanggal.
     */
    public function index(Request $request)
    {
        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($request->query('action'), fn ($q, $action) => $q->where('action', $action))
            ->when($request->query('user_id'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString();

        $actions = AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action');

        return view('audit-logs.index', compact('logs', 'actions'));
    }
}
