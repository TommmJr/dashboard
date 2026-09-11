<?php

namespace App\Http\Controllers;

use App\Models\PerformanceMetric;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PerformanceMetricController extends Controller
{
    /**
     * Form edit metric. Gate dataset.update sudah dicek di route middleware;
     * di sini hanya cek scoping divisi untuk staf.
     */
    public function edit(PerformanceMetric $metric): View
    {
        $this->authorizeDivisionAccess($metric);

        $metric->load('dataset:id,original_name', 'division:id,name');

        return view('performance_metrics.edit', compact('metric'));
    }

    /**
     * Simpan perubahan metric. Hanya 3 field yang boleh diubah;
     * division_id, dataset_id, unit_id diabaikan meski dikirim.
     *
     * Unique constraint: metric_name+period harus unik per dataset,
     * kecuali row yang sedang diedit itu sendiri.
     */
    public function update(Request $request, PerformanceMetric $metric): RedirectResponse
    {
        $this->authorizeDivisionAccess($metric);

        $validated = $request->validate([
            'metric_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('performance_metrics')
                    ->where('dataset_id', $metric->dataset_id)
                    ->where('period', $request->input('period'))
                    ->ignore($metric->id),
            ],
            'value' => 'required|numeric',
            'period' => 'required|date_format:Y-m-d',
        ]);

        $metric->update($validated);

        return redirect()
            ->route('datasets.show', $metric->dataset_id)
            ->with('success', 'Data metric berhasil diperbarui.');
    }

    /**
     * Staf hanya boleh menyentuh metric dari divisinya sendiri.
     * Role yang seesAllDivisions (admin/management/editor) boleh bebas.
     */
    private function authorizeDivisionAccess(PerformanceMetric $metric): void
    {
        $user = Auth::user();

        if ($this->seesAllDivisions($user)) {
            return;
        }

        abort_unless(
            $user->division_id !== null && $user->division_id === $metric->division_id,
            403,
        );
    }

    /**
     * Identik dengan DatasetController::seesAllDivisions().
     * Disengaja tidak di-share — YAGNI, hanya dua controller.
     */
    private function seesAllDivisions($user): bool
    {
        return Gate::forUser($user)->allows('dataset.delete')
            || $user->hasRole(Role::MANAGEMENT)
            || $user->hasRole(Role::EDITOR_DASHBOARD);
    }
}
