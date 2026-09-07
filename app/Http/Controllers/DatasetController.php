<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DatasetController extends Controller
{
    /**
     * Daftar dataset.
     *
     * Scoping (keputusan archive-vs-delete, plan Fase 1):
     * - admin, management, editor: melihat semua divisi.
     * - staf_divisi: hanya dataset divisinya sendiri.
     * Default menyembunyikan ARCHIVED; filter via ?status=ARCHIVED dll.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = Dataset::query()
            ->with(['division:id,name', 'user:id,name'])
            ->latest();

        if (! $this->seesAllDivisions($user)) {
            $query->where('division_id', $user->division_id);
        }

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        } else {
            $query->where('status', '!=', Dataset::STATUS_ARCHIVED);
        }

        $datasets = $query->paginate(15)->withQueryString();

        return view('datasets.index', compact('datasets'));
    }

    /**
     * Detail dataset + preview baris metric yang terimpor.
     */
    public function show(Dataset $dataset)
    {
        $user = Auth::user();
        abort_unless(
            $this->seesAllDivisions($user) || ($user->division_id !== null && $user->division_id === $dataset->division_id),
            403,
        );

        $dataset->load(['division:id,name', 'user:id,name']);
        $metrics = $dataset->metrics()->orderBy('period')->paginate(20);

        return view('datasets.show', compact('dataset', 'metrics'));
    }

    /**
     * Archive dataset (PATCH): staf pemilik divisi boleh archive dataset
     * divisinya; admin bebas. Dataset archived keluar dari agregasi chart
     * tetapi data tetap di DB (reversible via upload ulang).
     */
    public function archive(Dataset $dataset)
    {
        $this->authorizeDivisionOwnership($dataset);

        abort_if($dataset->status === Dataset::STATUS_ARCHIVED, 422, 'Dataset sudah diarsipkan.');

        $dataset->update(['status' => Dataset::STATUS_ARCHIVED]);

        return redirect()
            ->route('datasets.index')
            ->with('success', "Dataset \"{$dataset->original_name}\" berhasil diarsipkan.");
    }

    /**
     * Delete permanen (admin only, gate dataset.delete di route).
     * Baris metric ikut terhapus via cascade (audit: jejak file sumber
     * hilang bersama datanya, hanya boleh dilakukan admin).
     */
    public function destroy(Dataset $dataset)
    {
        Storage::disk('local')->delete('datasets/'.$dataset->filename);
        $dataset->delete();

        return redirect()
            ->route('datasets.index')
            ->with('success', "Dataset \"{$dataset->original_name}\" dihapus permanen.");
    }

    /**
     * Staf hanya boleh menyentuh dataset divisinya sendiri; role lain yang
     * lolos gate (admin) bebas. Tanpa division_id yang cocok = 403.
     */
    private function authorizeDivisionOwnership(Dataset $dataset): void
    {
        $user = Auth::user();

        if ($this->seesAllDivisions($user)) {
            return;
        }

        abort_unless($user->division_id !== null && $user->division_id === $dataset->division_id, 403);
    }

    /**
     * Role tanpa pembatasan divisi: admin selalu; role lain mengikuti
     * permission dataset.delete (hanya admin saat ini) — selebihnya
     * management/editor melihat semua divisi (dashboard transparan),
     * staf_divisi dibatasi divisinya.
     */
    private function seesAllDivisions($user): bool
    {
        return Gate::forUser($user)->allows('dataset.delete')
            || $user->hasRole(Role::MANAGEMENT)
            || $user->hasRole(Role::EDITOR_DASHBOARD);
    }
}
