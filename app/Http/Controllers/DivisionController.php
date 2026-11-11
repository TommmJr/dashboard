<?php

namespace App\Http\Controllers;

use App\Models\Division;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * CRUD divisi — hanya admin (via middleware can:user.view / can:user.create
 * dll. yang sudah dimiliki admin). Tidak memerlukan permission baru karena
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
            'name' => 'required|string|max:255|unique:divisions,name',
            'description' => 'nullable|string|max:1000',
        ]);

        $slug = Str::slug($validated['name']);
        if ($slug === '' || Division::where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'name' => 'Nama divisi menghasilkan identifier slug yang sudah digunakan atau tidak valid.',
            ]);
        }

        $division = Division::create([
            'name' => $validated['name'],
            'slug' => $slug,
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
            'name' => 'required|string|max:255|unique:divisions,name,'.$division->id,
            'description' => 'nullable|string|max:1000',
        ]);

        $division->update([
            'name' => $validated['name'],
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
