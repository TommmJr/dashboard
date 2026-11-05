<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Services\AuditLogFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    /**
     * Halaman index laporan — daftar semua export yang tersedia.
     *
     * Gate: report.export (di route).
     * Menyiapkan daftar dataset untuk pilihan validationExport per-dataset.
     * Scoping identik dengan datasetSummaryExport.
     */
    public function index(Request $request): View
    {
        $user = Auth::user();

        $datasets = Dataset::query()
            ->with('division:id,name')
            ->orderBy('created_at', 'desc')
            ->when(! $this->seesAllDivisions($user), fn ($q) => $q->where('division_id', $user->division_id))
            ->get(['id', 'original_name', 'status', 'division_id', 'created_at']);

        return view('reports.index', compact('datasets'));
    }

    /**
     * Export laporan validasi satu dataset ke .xlsx.
     *
     * Gate: dataset.view + report.export (di route).
     * Scoping: identik dengan DatasetController::show() —
     * seesAllDivisions atau staf divisi yang sama.
     *
     * Sumber: $dataset->validation_report['issues'] — detail yang tersimpan
     * saat import (dibatasi 50 pertama; lihat DatasetImportService).
     * Bila issues_truncated=true, baris catatan ditambahkan di akhir file
     * agar export tidak disangka memuat semua masalah, sama seperti
     * keterangan di halaman detail dataset.
     * Dataset tanpa issues menghasilkan file header-only.
     */
    public function validationExport(Dataset $dataset): BinaryFileResponse
    {
        $user = Auth::user();
        abort_unless(
            $this->seesAllDivisions($user) || ($user->division_id !== null && $user->division_id === $dataset->division_id),
            403,
        );

        $issues = $dataset->validation_report['issues'] ?? [];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Validasi');

        $sheet->fromArray(['Baris', 'Severity', 'Pesan'], null, 'A1');
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);

        foreach ($issues as $i => $issue) {
            $sheet->fromArray([
                $issue['row'] ?? '',
                $issue['severity'] ?? '',
                $issue['message'] ?? '',
            ], null, 'A'.($i + 2));
        }

        if (! empty($dataset->validation_report['issues_truncated'])) {
            $total = ($dataset->validation_report['fatal'] ?? 0)
                + ($dataset->validation_report['warning'] ?? 0);
            $noteRow = count($issues) + 3;
            $sheet->fromArray(
                ["Catatan: hanya 50 masalah pertama yang disimpan. Total masalah: {$total}."],
                null,
                'A'.$noteRow,
            );
        }

        foreach (['A', 'B', 'C'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'val_report_').'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        $baseName = pathinfo($dataset->original_name, PATHINFO_FILENAME);
        $filename = $baseName.'-validation.xlsx';

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * Export ringkasan semua dataset ke .xlsx.
     *
     * Gate: dataset.view + report.export (di route).
     * Scoping: identik dengan DatasetController::index() —
     * admin/management/editor lihat semua; staf_divisi hanya divisinya.
     *
     * Kolom Terakhir Diperbarui = MAX(performance_metrics.updated_at)
     * per dataset — di-JOIN, bukan kolom baru di tabel datasets.
     */
    public function datasetSummaryExport(Request $request): BinaryFileResponse
    {
        $user = Auth::user();

        // Subquery MAX(updated_at) per dataset — menghindari ONLY_FULL_GROUP_BY
        // di MySQL strict mode saat SELECT * dengan GROUP BY hanya pada id.
        $lastUpdatedSub = DB::table('performance_metrics')
            ->selectRaw('dataset_id, MAX(updated_at) as last_updated')
            ->groupBy('dataset_id');

        $query = Dataset::query()
            ->with(['division:id,name', 'user:id,name'])
            ->leftJoinSub($lastUpdatedSub, 'pm_max', 'pm_max.dataset_id', '=', 'datasets.id')
            ->select('datasets.*', 'pm_max.last_updated as last_metric_updated_at')
            ->orderBy('datasets.created_at', 'desc');

        if (! $this->seesAllDivisions($user)) {
            $query->where('datasets.division_id', $user->division_id);
        }

        $datasets = $query->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ringkasan Dataset');

        $headers = ['Nama File', 'Divisi', 'Pengunggah', 'Status', 'Ukuran', 'Tanggal Upload', 'Terakhir Diperbarui'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);

        foreach ($datasets as $i => $dataset) {
            $lastUpdated = $dataset->last_metric_updated_at
                ? Carbon::parse($dataset->last_metric_updated_at)->format('d/m/Y H:i')
                : '-';

            $sheet->fromArray([
                $dataset->original_name,
                $dataset->division?->name ?? '-',
                $dataset->user?->name ?? '-',
                $dataset->status->value,
                number_format($dataset->size / 1024, 1, ',', '.').' KB',
                $dataset->created_at->format('d/m/Y H:i'),
                $lastUpdated,
            ], null, 'A'.($i + 2));
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'ds_summary_').'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        $filename = 'dataset-summary-'.now()->format('Y-m-d').'.xlsx';

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * Export audit log ke .xlsx dengan batasan rentang tanggal wajib (maks 90 hari).
     *
     * Gate: audit.view + report.export (di route).
     * Scoping: identik dengan AuditLogController::index() —
     * tanpa audit.view_all hanya entri Dataset + PerformanceMetric.
     *
     * Wajib: `from` dan `to` hadir, rentang <= 90 hari — 422 bila tidak.
     */
    public function auditExport(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = Carbon::createFromFormat('Y-m-d', $validated['from'])->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $validated['to'])->endOfDay();

        abort_if(
            $from->diffInDays($to) > 90,
            422,
            'Rentang tanggal maksimal 90 hari.',
        );

        $user = $request->user();
        $dataOnly = ! $user->can('audit.view_all');

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->cleanAuditTrail()
            ->when($dataOnly, fn ($q) => $q->whereIn('auditable_type', [
                (new Dataset)->getMorphClass(),
                (new PerformanceMetric)->getMorphClass(),
            ]))
            ->whereDate('created_at', '>=', $validated['from'])
            ->whereDate('created_at', '<=', $validated['to'])
            ->oldest('created_at')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Audit Log');

        $headers = ['Waktu', 'User', 'Aksi', 'Objek', 'Perubahan', 'IP'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        foreach ($logs as $i => $log) {
            $rows = AuditLogFormatter::rows($log);
            $changeLines = array_map(
                fn ($r) => $r['label'].': '.($r['old'] ?? '').($r['old'] !== null && $r['new'] !== null ? ' -> ' : '').($r['new'] ?? ''),
                $rows,
            );
            $changes = implode("\n", $changeLines);

            $sheet->fromArray([
                $log->created_at->format('d/m/Y H:i:s'),
                $log->user?->name ?? '-',
                AuditLogFormatter::actionLabel($log->action),
                AuditLogFormatter::objectLabel($log),
                $changes,
                $log->ip ?? '-',
            ], null, 'A'.($i + 2));
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'audit_export_').'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        $filename = 'audit-log-'.$validated['from'].'-'.$validated['to'].'.xlsx';

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * Role tanpa pembatasan divisi: ikut logika DatasetController.
     * admin (punya dataset.delete) + management + editor_dashboard.
     */
    private function seesAllDivisions($user): bool
    {
        return Gate::forUser($user)->allows('dataset.delete')
            || $user->hasRole(Role::MANAGEMENT)
            || $user->hasRole(Role::EDITOR_DASHBOARD);
    }
}
