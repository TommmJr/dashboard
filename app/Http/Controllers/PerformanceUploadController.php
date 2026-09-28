<?php

namespace App\Http\Controllers;

use App\Models\PerformanceMetric;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class PerformanceUploadController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
        ]);

        $file = $request->file('file');
        $filePath = $file->getRealPath();

        Log::info('Upload Performance - Memulai proses import', [
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $filePath,
            'file_size' => $file->getSize(),
        ]);

        // Division uploader untuk tagging scope otorisasi.
        $uploaderDivisionId = Auth::user()?->division_id;

        $importedCount = 0;
        $skippedCount = 0;
        $skippedReasons = [];
        $rowNumber = 1;
        $handle = null;

        // try/finally: pastikan file handle selalu ditutup, termasuk saat
        // exception terjadi di tengah loop (audit #5: anti-handle-leak).
        try {
            $handle = fopen($filePath, 'r');
            if ($handle === false) {
                throw new \RuntimeException("Tidak dapat membuka file: {$filePath}");
            }

            // Baca header (tidak di-log untuk mencegah kebocoran data).
            $header = fgetcsv($handle);

            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                $rowNumber++;
                // PENTING: jangan log isi baris mentah - data performance bersifat
                // sensitif. Cukup log nomor baris + error message saja.

                // 1) Periksa kelengkapan kolom minimum.
                if (count($data) < 3) {
                    $skippedCount++;
                    $skippedReasons[$rowNumber] = 'kolom tidak cukup';

                    continue;
                }

                $metricName = trim($data[0]);
                $valueRaw = trim($data[1]);
                $periodRaw = trim($data[2]);
                $unitId = $data[3] ?? null;

                // 2) Validasi value harus numeric (audit #5).
                if (! is_numeric($valueRaw)) {
                    $skippedCount++;
                    $skippedReasons[$rowNumber] = 'value bukan numerik';

                    continue;
                }

                // 3) Validasi period harus tanggal valid Y-m-d (audit #5).
                //    Carbon::createFromFormat mengembalikan exception jika tidak match.
                try {
                    $period = Carbon::createFromFormat('Y-m-d', $periodRaw);
                    // Double-check: parse ulang untuk hindari silent fallback
                    // dari Carbon (mis. "2026-13-40" bisa di-rolling ke tanggal lain).
                    if ($period->format('Y-m-d') !== $periodRaw) {
                        throw new \UnexpectedValueException('format tanggal tidak valid');
                    }
                } catch (Throwable) {
                    $skippedCount++;
                    $skippedReasons[$rowNumber] = 'period bukan tanggal Y-m-d';

                    continue;
                }

                try {
                    PerformanceMetric::create([
                        'metric_name' => $metricName,
                        'value' => $valueRaw,
                        'period' => $period->format('Y-m-d'),
                        'unit_id' => $unitId,
                        // Auto-tag dengan division_id uploader agar controller
                        // chart bisa melakukan scoping per-divisi (audit #3).
                        'division_id' => $uploaderDivisionId,
                    ]);
                    $importedCount++;
                } catch (Throwable $e) {
                    $skippedCount++;
                    $skippedReasons[$rowNumber] = 'error DB: '.$e->getMessage();
                }
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        // Log metadata saja, tanpa isi baris.
        Log::info('Upload Performance - Proses selesai.', [
            'imported_count' => $importedCount,
            'skipped_count' => $skippedCount,
            'rows_processed' => $rowNumber,
        ]);

        $message = "Berhasil mengimpor {$importedCount} data kinerja.";
        if ($skippedCount > 0) {
            // Tampilkan jumlah baris gagal ke user, tanpa membocorkan data.
            $message .= " {$skippedCount} baris dilewati karena tidak valid.";
        }

        return back()->with('success', $message)->with(
            'skipped_count',
            $skippedCount,
        );
    }
}
