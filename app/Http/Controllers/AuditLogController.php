<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\PerformanceMetric;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuditLogController extends Controller
{
    /**
     * Daftar audit log (append-only). Gate audit.view di route.
     * Filter divalidasi terhadap whitelist untuk mencegah probing.
     *
     * Scoping: pemegang audit.view TANPA audit.view_all (Editor Dashboard/IT)
     * hanya melihat entri perubahan DATA (dataset & baris metrik) — sesuai
     * matriks User Roles: "IT cuma liat perubahan datasetnya aja". Login,
     * perubahan user, dsb. tidak ditampilkan ke mereka.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', Rule::in([
                AuditLog::ACTION_LOGIN,
                AuditLog::ACTION_LOGOUT,
                AuditLog::ACTION_CREATE,
                AuditLog::ACTION_UPDATE,
                AuditLog::ACTION_DELETE,
                AuditLog::ACTION_IMPORT,
            ])],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $user = $request->user();
        $dataOnly = ! $user->can('audit.view_all');

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($dataOnly, fn ($q) => $q->whereIn('auditable_type', [
                (new Dataset)->getMorphClass(),
                (new PerformanceMetric)->getMorphClass(),
            ]))
            ->when($validated['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($validated['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($validated['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($validated['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString();

        $actions = $dataOnly
            ? [
                AuditLog::ACTION_CREATE,
                AuditLog::ACTION_UPDATE,
                AuditLog::ACTION_DELETE,
                AuditLog::ACTION_IMPORT,
            ]
            : [
                AuditLog::ACTION_LOGIN,
                AuditLog::ACTION_LOGOUT,
                AuditLog::ACTION_CREATE,
                AuditLog::ACTION_UPDATE,
                AuditLog::ACTION_DELETE,
                AuditLog::ACTION_IMPORT,
            ];

        return view('audit-logs.index', compact('logs', 'actions', 'dataOnly'));
    }
}
