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
     * HANYA role Admin dan Staf Divisi milik divisi Managemen Aset
     * (slug: managemen-aset atau data-aset) yang berhak mengakses.
     * Role lain (Management, Editor Dashboard, Staf Perencanaan/Pengusahaan) ditolak 403.
     */
    public static function canAccessAssetDetail(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $user->loadMissing('role', 'division');

        if ($user->hasRole(Role::ADMIN)) {
            return true;
        }

        if ($user->hasRole(Role::STAFF_DIVISI)) {
            return in_array($user->division?->slug, ['managemen-aset', 'data-aset'], true);
        }

        return false;
    }

    /**
     * Menampilkan halaman Rincian Trafo & Aset mandiri (/dashboard/asset-detail).
     */
    public function index(Request $request, AssetStockReport $assetStockReport): View
    {
        $user = $request->user();

        if (! static::canAccessAssetDetail($user)) {
            abort(403, 'Akses terbatas untuk Administrator dan Divisi Managemen Aset.');
        }

        $divisionId = $user->hasRole(Role::ADMIN) ? null : $user->division_id;
        $data = $assetStockReport->detailedBreakdown(divisionId: $divisionId);

        return view('dashboard.asset-detail', [
            'data' => $data,
        ]);
    }
}
