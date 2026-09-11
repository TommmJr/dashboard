<?php

namespace App\Http\Controllers;

use App\Models\Division;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * CRUD divisi — hanya admin (via middleware can:user.view / can:user.create
 * dll. yang sudah dimiliki admin). Tidak memerlukan permission baru karena
 * pengelolaan divisi adalah bagian dari administrasi sistem, bukan data.
 *
 * Proteksi delete: divisi yang masih punya user atau dataset tidak bisa
 * dihapus (integrity — jangan tinggalkan orphan FK).
 */
class DivisionController extends Controller
{
    public function index(): View
    {
        $divisions = Division::query()
            ->withCount(['users', 'datasets'])
            ->orderBy('name')
            ->get();

        return view('divisions.index', compact('divisions'));
    }

    public function create(): View
    {
        return view('divisions.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:divisions,name',
            'description' => 'nullable|string|max:1000',
        ]);

        $division = Division::create([
            'name'        => $validated['name'],
            'slug'        => Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
        ]);

        return redirect()->route('divisions.index')
            ->with('success', "Divisi \"{$division->name}\" berhasil dibuat.");
    }

    public function edit(Division $division): View
    {
        return view('divisions.edit', compact('division'));
    }

    public function update(Request $request, Division $division): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:divisions,name,'.$division->id,
            'description' => 'nullable|string|max:1000',
        ]);

        $division->update([
            'name'        => $validated['name'],
            'slug'        => Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
        ]);

        return redirect()->route('divisions.index')
            ->with('success', "Divisi \"{$division->name}\" berhasil diperbarui.");
    }

    public function destroy(Division $division): RedirectResponse
    {
        // Cegah hapus divisi yang masih punya user atau dataset aktif.
        if ($division->users()->exists()) {
            return redirect()->route('divisions.index')
                ->with('error', "Divisi \"{$division->name}\" tidak dapat dihapus karena masih memiliki user.");
        }

        if ($division->datasets()->exists()) {
            return redirect()->route('divisions.index')
                ->with('error', "Divisi \"{$division->name}\" tidak dapat dihapus karena masih memiliki dataset.");
        }

        $name = $division->name;
        $division->delete();

        return redirect()->route('divisions.index')
            ->with('success', "Divisi \"{$name}\" berhasil dihapus.");
    }
}
