<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Models\PerformanceMetric;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PerformanceChartController extends Controller
{
    /**
     * Mengambil data agregasi untuk grafik.
     *
     * Aturan scoping (per audit keamanan #3):
     * - admin & management: melihat agregasi SELURUH divisi.
     * - role lain (staf_divisi, editor_dashboard): hanya divisinya sendiri.
     *
     * Filter diterapkan langsung di query untuk mencegah kebocoran data
     * silang divisi — TIDAK mengandalkan filter di view/frontend.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Query via DB::table (bukan model): cast 'date' pada model
        // PerformanceMetric menggeser period saat serialisasi karena app
        // berjalan di Asia/Jakarta sedangkan cast menganggap DB adalah UTC.
        // Di sini period adalah kolom DATE murni; format eksplisit di PHP.
        $query = DB::table('performance_metrics')
            ->selectRaw('period, SUM(value) as total')
            ->groupBy('period')
            ->orderBy('period', 'asc')
            // Dataset ARCHIVED tidak masuk agregasi chart (keputusan
            // archive-vs-delete). Baris legacy tanpa dataset (dataset_id
            // NULL, hasil slice #1) tetap dihitung.
            ->where(function ($q) {
                $q->whereNull('dataset_id')
                    ->orWhereExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('datasets')
                            ->whereColumn('datasets.id', 'performance_metrics.dataset_id')
                            ->where('datasets.status', Dataset::STATUS_READY);
                    });
            });

        // Non-admin/non-management: batasi ke division_id miliknya.
        // User tanpa division_id tidak akan melihat data apa pun.
        $isPrivileged = $user->hasRole(Role::ADMIN) || $user->hasRole(Role::MANAGEMENT);

        if (! $isPrivileged) {
            $query->where('division_id', $user->division_id);
        }

        $data = $query->get();

        return response()->json([
            'labels' => $data->map(fn ($row) => Carbon::parse($row->period)->format('Y-m-d')),
            'values' => $data->pluck('total'),
        ]);
    }
}
