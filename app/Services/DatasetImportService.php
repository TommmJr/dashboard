<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\PerformanceMetric;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Pipeline impor dataset (Tahap 4): parse Excel/CSV -> validasi per-baris
 * -> simpan ke performance_metrics -> kelola status lifecycle Dataset.
 *
 * Mapping kolom bersumber dari config/dataset_import.php (satu tempat)
 * supaya penyesuaian kolom final mentor hanya mengubah konfigurasi.
 *
 * Prinsip keamanan (pola audit 86bca5a):
 * - Tidak ada isi baris mentah yang di-log; hanya nomor baris + alasan.
 * - period divalidasi ketat Y-m-d dengan double-check anti silent-rolling
 *   (Carbon::createFromFormat + hasFormat): tanggal seperti 2026-13-40
 *   ditolak, bukan diam-diam digulung ke tanggal lain.
 * - Seluruh proses dibungkus transaksi DB: tidak ada dataset yang
 *   "menggantung" di UPLOADING/VALIDATING ketika error di tengah jalan.
 */
class DatasetImportService
{
    /**
     * Jalankan pipeline impor untuk file yang diunggah.
     *
     * @return array{imported: int, skipped: int, errors: array<int, string>}
     */
    public function import(UploadedFile $file, User $uploader, Dataset $dataset): array
    {
        $rows = $this->parseRows($file);

        $imported = 0;
        $errors = [];
        $inserts = [];

        DB::transaction(function () use ($rows, $uploader, $dataset, &$imported, &$errors, &$inserts) {
            $dataset->update(['status' => Dataset::STATUS_VALIDATING]);

            foreach ($rows as $rowNumber => $row) {
                $error = $this->validateRow($row);
                if ($error !== null) {
                    $errors[$rowNumber] = $error;

                    continue;
                }

                $inserts[] = [
                    'metric_name' => trim((string) $row[0]),
                    'value' => (float) $row[1],
                    'period' => Carbon::createFromFormat('Y-m-d', trim((string) $row[2]))->format('Y-m-d'),
                    'unit_id' => $uploader->id,
                    'division_id' => $uploader->division_id,
                    'dataset_id' => $dataset->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($inserts !== []) {
                PerformanceMetric::insert($inserts);
            }

            $imported = count($inserts);

            if ($imported === 0) {
                // Gagal total: tidak ada satu pun baris valid.
                $dataset->update([
                    'status' => Dataset::STATUS_FAILED,
                    'error_message' => 'Tidak ada baris valid. '.count($errors).' baris ditolak.',
                ]);
            } else {
                $dataset->update([
                    'status' => Dataset::STATUS_READY,
                    'error_message' => null,
                ]);
            }
        });

        return ['imported' => $imported, 'skipped' => count($errors), 'errors' => $errors];
    }

    /**
     * Bangun file template .xlsx generik sesuai config kolom.
     */
    public function buildTemplate(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach (config('dataset_import.columns') as $index => $column) {
            $sheet->setCellValue([$index + 1, 1], $column['header']);
        }

        // Baris contoh agar staf paham format yang diharapkan.
        $sheet->setCellValue([1, 2], 'contoh_metrik');
        $sheet->setCellValue([2, 2], '123.45');
        $sheet->setCellValue([3, 2], '2026-01-31');

        $tempPath = tempnam(sys_get_temp_dir(), 'tpl_');
        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return $tempPath;
    }

    /**
     * Parse file menjadi array baris [nomor_baris => [kolom...]].
     * Baris 1 adalah header dan tidak ikut dikembalikan.
     *
     * @return array<int, array<int, mixed>>
     */
    private function parseRows(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());

        try {
            $data = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        // Buang header; indeks baris dimulai dari 2 agar cocok dengan nomor
        // baris yang dilihat staf di Excel.
        unset($data[0]);

        $rows = [];
        foreach ($data as $index => $row) {
            // Lewati baris yang benar-benar kosong.
            if (count(array_filter($row, fn ($cell) => $cell !== null && trim((string) $cell) !== '')) === 0) {
                continue;
            }
            $rows[$index + 2] = array_map(fn ($cell) => $cell === null ? '' : trim((string) $cell), $row);
        }

        return $rows;
    }

    /**
     * Validasi satu baris terhadap aturan kolom di config.
     * Mengembalikan pesan error (FATAL -> baris di-skip) atau null bila valid.
     */
    private function validateRow(array $row): ?string
    {
        $columns = config('dataset_import.columns');

        foreach ($columns as $index => $column) {
            $value = $row[$index] ?? '';

            if ($column['required'] && $value === '') {
                return "kolom '{$column['header']}' kosong";
            }

            if ($value === '') {
                continue;
            }

            $invalid = match ($column['rule']) {
                'numeric' => ! is_numeric($value),
                'date' => ! $this->isValidDate($value),
                default => false,
            };

            if ($invalid) {
                return "kolom '{$column['header']}' tidak valid ({$column['rule']})";
            }
        }

        return null;
    }

    /**
     * Validasi tanggal ketat Y-m-d, anti silent-rolling: Carbon::parse
     * biasa akan menggulung "2026-13-40" ke tanggal valid lain; createFromFormat
     * + hasFormatWithModifiers memastikan input persis sesuai format.
     */
    private function isValidDate(string $value): bool
    {
        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (Throwable) {
            return false;
        }

        // Double-check: hasil parse harus memformat balik persis ke input.
        // Ini menolak silent-rolling seperti 2026-13-40 atau 2026-02-30.
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
