<?php

namespace App\Http\Controllers;

use App\Models\PerformanceMetric;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        $query = PerformanceMetric::query()
            ->selectRaw('period, SUM(value) as total')
            ->groupBy('period')
            ->orderBy('period', 'asc');

        // Non-admin/non-management: batasi ke division_id miliknya.
        // User tanpa division_id tidak akan melihat data apa pun.
        $isPrivileged = $user->hasRole(Role::ADMIN) || $user->hasRole(Role::MANAGEMENT);

        if (! $isPrivileged) {
            $query->where('division_id', $user->division_id);
        }

        $data = $query->get();

        return response()->json([
            'labels' => $data->pluck('period'),
            'values' => $data->pluck('total'),
        ]);
    }
}
