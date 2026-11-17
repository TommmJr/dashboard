<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Services\SystemHealthService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuditLogController extends Controller
{
    public function __construct(
        private readonly SystemHealthService $systemHealth,
    ) {}

    /**
     * Daftar audit log (append-only). Gate audit.view di route.
     * Filter divalidasi terhadap whitelist untuk mencegah probing.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $dataOnly = ! $user->can('audit.view_all');

        $entityOptions = $dataOnly
            ? [
                'dataset' => 'Dataset',
                'performance_metric' => 'Baris data kinerja',
            ]
            : [
                'dataset' => 'Dataset',
                'performance_metric' => 'Baris data kinerja',
                'user' => 'Pengguna',
                'division' => 'Divisi',
                'dashboard_layout' => 'Tata letak dashboard',
                'account' => 'Aktivitas akun',
            ];

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'entity' => ['nullable', 'string', Rule::in(array_keys($entityOptions))],
            'action' => ['nullable', 'string', Rule::in([
                AuditLog::ACTION_LOGIN,
                AuditLog::ACTION_LOGOUT,
                AuditLog::ACTION_LOGIN_FAILED,
                AuditLog::ACTION_CREATE,
                AuditLog::ACTION_UPDATE,
                AuditLog::ACTION_DELETE,
                AuditLog::ACTION_IMPORT,
            ])],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->cleanAuditTrail()
            ->when($dataOnly, fn ($q) => $q->whereIn('auditable_type', [
                (new Dataset)->getMorphClass(),
                (new PerformanceMetric)->getMorphClass(),
            ]))
            ->searchKeyword($validated['q'] ?? null)
            ->filterEntity($validated['entity'] ?? null)
            ->when($validated['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($validated['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($validated['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($validated['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('created_at')
            ->paginate(50)
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
                AuditLog::ACTION_LOGIN_FAILED,
                AuditLog::ACTION_CREATE,
                AuditLog::ACTION_UPDATE,
                AuditLog::ACTION_DELETE,
                AuditLog::ACTION_IMPORT,
            ];

        $isAdmin = $user->hasRole(Role::ADMIN);
        $systemHealth = $isAdmin ? $this->systemHealth->snapshot() : null;

        return view('audit-logs.index', compact('logs', 'actions', 'dataOnly', 'entityOptions', 'isAdmin', 'systemHealth'));
    }
}
