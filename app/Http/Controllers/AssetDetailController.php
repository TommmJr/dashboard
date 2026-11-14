<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\AssetStockReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AssetDetailController extends Controller
{
    /**
     * Guard otorisasi fail-closed untuk akses Rincian Trafo & Aset:
     * HANYA Staf Divisi milik divisi Managemen Aset (slug: managemen-aset atau data-aset)
     * yang berhak mengakses (100% MANSET ONLY).
     * Role lain (Admin, Management, Editor Dashboard, Staf Perencanaan/Pengusahaan) ditolak 403.
     */
    public static function canAccessAssetDetail(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $user->loadMissing('role', 'division');

        if ($user->hasRole(Role::STAFF_DIVISI)) {
            return in_array($user->division?->slug, ['managemen-aset', 'data-aset'], true);
        }

        return false;
    }

    /**
     * Menampilkan halaman Rincian Trafo & Aset mandiri (/dashboard/asset-detail/{section?}).
     */
    public function index(Request $request, AssetStockReport $assetStockReport, ?string $section = null): View
    {
        $user = $request->user();

        if (! static::canAccessAssetDetail($user)) {
            abort(403, 'Akses terbatas untuk Divisi Managemen Aset.');
        }

        $section = $section ?? $request->query('section') ?? 'trafo';

        if (! in_array($section, ['trafo', 'mva', 'transmisi', 'tower'], true)) {
            abort(404, 'Halaman rincian aset tidak ditemukan.');
        }

        $divisionId = $user->division_id;
        $data = $assetStockReport->detailedBreakdown(divisionId: $divisionId);
        $ownership = $data['ownership'] ?? [
            'parent_code' => 'UIT JBT',
            'parent_name' => 'Unit Induk Transmisi Jawa Bagian Tengah (UIT JBT)',
            'parent_source' => 'Rekap Induk UIT (Tab 1a)',
            'distribution_scope' => 'Distribusi Wilayah 8 UPT Kanonis (Tab 1b)',
            'has_uit_totals' => false,
        ];

        return view('dashboard.asset-detail', [
            'data' => $data,
            'section' => $section,
            'ownership' => $ownership,
        ]);
    }
}
