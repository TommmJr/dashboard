<?php

namespace App\Http\Controllers;

use App\Services\KpiPeriodAnnotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KpiPeriodAnnotationController extends Controller
{
    public function __construct(private readonly KpiPeriodAnnotationService $annotations) {}

    /**
     * Simpan atau perbarui catatan konteks bisnis untuk satu periode grafik tren KPI.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless(KpiPeriodAnnotationService::canMutate($user), 403);
        assert($user !== null);

        $validated = $request->validate([
            'period' => ['required', 'string', 'max:10'],
            'note' => ['required', 'string', 'max:'.KpiPeriodAnnotationService::MAX_NOTE_LENGTH],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $canonicalPeriod = KpiPeriodAnnotationService::canonicalizePeriod((string) $validated['period']);
        if ($canonicalPeriod === null) {
            throw ValidationException::withMessages([
                'period' => 'Format periode tidak valid. Gunakan format YYYY-MM atau tanggal kalender yang sah.',
            ]);
        }

        $sanitizedNote = KpiPeriodAnnotationService::sanitizeNote((string) $validated['note']);
        if ($sanitizedNote === '') {
            throw ValidationException::withMessages([
                'note' => 'Catatan periode tidak boleh kosong atau hanya berisi karakter tidak valid.',
            ]);
        }

        if (mb_strlen($sanitizedNote) > KpiPeriodAnnotationService::MAX_NOTE_LENGTH) {
            throw ValidationException::withMessages([
                'note' => 'Catatan periode maksimal '.KpiPeriodAnnotationService::MAX_NOTE_LENGTH.' karakter.',
            ]);
        }

        $result = $this->annotations->upsert(
            $canonicalPeriod,
            $sanitizedNote,
            $user,
            is_string($validated['start_date'] ?? null) ? $validated['start_date'] : null,
            is_string($validated['end_date'] ?? null) ? $validated['end_date'] : null,
        );
        $message = $result['created']
            ? 'Catatan periode berhasil ditambahkan.'
            : 'Catatan periode berhasil diperbarui.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'annotation' => $result['annotation'],
                'annotations' => $result['annotations'],
            ]);
        }

        return back()->with('status', $message);
    }

    /**
     * Hapus catatan konteks bisnis untuk satu periode grafik tren KPI.
     */
    public function destroy(Request $request, string $period): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless(KpiPeriodAnnotationService::canMutate($user), 403);
        assert($user !== null);

        $canonicalPeriod = KpiPeriodAnnotationService::canonicalizePeriod($period);
        if ($canonicalPeriod === null) {
            throw ValidationException::withMessages([
                'period' => 'Format periode tidak valid.',
            ]);
        }

        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $remaining = $this->annotations->delete(
            $canonicalPeriod,
            $user,
            is_string($validated['start_date'] ?? null) ? $validated['start_date'] : null,
            is_string($validated['end_date'] ?? null) ? $validated['end_date'] : null,
        );
        if ($remaining === null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Catatan periode tidak ditemukan.'], 404);
            }

            return back()->with('warning', 'Catatan periode tidak ditemukan.');
        }

        $message = 'Catatan periode berhasil dihapus.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'annotations' => $remaining,
            ]);
        }

        return back()->with('status', $message);
    }
}
