<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\PerformanceMetric;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
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
     * Severity validasi (keputusan Tahap 5):
     * - FATAL   : baris di-skip (kolom wajib kosong, value non-numeric,
     *             tanggal invalid, duplikat kunci metric_name+period dalam
     *             file yang sama).
     * - WARNING : baris TETAP masuk tetapi ditandai (outlier statistik
     *             sederhana: |value - mean| > 3 * std-dev dalam file).
     * Ringkasan disimpan di datasets.validation_report (JSON).
     *
     * @return array{imported: int, skipped: int, warnings: int, errors: array<int, string>}
     */
    public function import(UploadedFile $file, User $uploader, Dataset $dataset): array
    {
        $rows = $this->parseRows($file);

        $imported = 0;
        $warnings = 0;
        $errors = [];
        $issues = [];
        $inserts = [];

        DB::transaction(function () use ($rows, $uploader, $dataset, &$imported, &$warnings, &$errors, &$issues, &$inserts) {
            $dataset->update(['status' => Dataset::STATUS_VALIDATING]);

            // Pass 1: validasi per-baris + deteksi duplikat kunci.
            $seenKeys = [];
            foreach ($rows as $rowNumber => $row) {
                $error = $this->validateRow($row);
                if ($error !== null) {
                    $errors[$rowNumber] = $error;
                    $issues[] = ['row' => $rowNumber, 'severity' => 'FATAL', 'message' => $error];

                    continue;
                }

                $key = mb_strtolower(trim((string) $row[0])).'|'.trim((string) $row[2]);
                if (isset($seenKeys[$key])) {
                    $errors[$rowNumber] = "duplikat kunci dengan baris {$seenKeys[$key]}";
                    $issues[] = ['row' => $rowNumber, 'severity' => 'FATAL', 'message' => $errors[$rowNumber]];

                    continue;
                }
                $seenKeys[$key] = $rowNumber;

                $inserts[$rowNumber] = [
                    'metric_name' => trim((string) $row[0]),
                    'value' => (float) $row[1],
                    'period' => Carbon::createFromFormat('Y-m-d', trim((string) $row[2]))->format('Y-m-d'),
                    'unit_id' => $uploader->id,
                    // Divisi pemilik data = divisi dataset (staf: divisinya
                    // sendiri; admin: divisi yang dipilih di form). Bukan
                    // division_id uploader yang bisa NULL pada admin.
                    'division_id' => $dataset->division_id,
                    'dataset_id' => $dataset->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Pass 2: deteksi outlier (WARNING) pada baris yang lolos FATAL.
            // Hanya dihitung bila ada >= 3 baris valid agar std-dev bermakna.
            if (count($inserts) >= 3) {
                $values = array_column($inserts, 'value');
                $mean = array_sum($values) / count($values);
                $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / count($values);
                $stdDev = sqrt($variance);

                if ($stdDev > 0) {
                    foreach ($inserts as $rowNumber => $insert) {
                        if (abs($insert['value'] - $mean) > 3 * $stdDev) {
                            $warnings++;
                            $issues[] = [
                                'row' => $rowNumber,
                                'severity' => 'WARNING',
                                'message' => 'nilai outlier (>3 standar deviasi dari rata-rata file)',
                            ];
                        }
                    }
                }
            }

            if ($inserts !== []) {
                // Bulk insert demi performa; observer Auditable pada model
                // tidak terpanggil, jadi jejaknya dicatat sebagai satu
                // entri IMPORT pada dataset (bukan per-baris).
                PerformanceMetric::insert(array_values($inserts));

                AuditLog::record(
                    AuditLog::ACTION_IMPORT,
                    $dataset,
                    null,
                    ['rows_imported' => count($inserts), 'rows_skipped' => count($errors)],
                    $uploader->id,
                );
            }

            $imported = count($inserts);

            $report = [
                'ok' => $imported - $warnings,
                'warning' => $warnings,
                'fatal' => count($errors),
                // Simpan maksimal 50 issue pertama; selebihnya hanya hitungan.
                // Mencegah JSON multi-MB pada file berisi ribuan baris error.
                'issues' => array_slice($issues, 0, 50),
                'issues_truncated' => count($issues) > 50,
            ];

            if ($imported === 0) {
                // Gagal total: tidak ada satu pun baris valid.
                $dataset->update([
                    'status' => Dataset::STATUS_FAILED,
                    'error_message' => 'Tidak ada baris valid. '.count($errors).' baris ditolak.',
                    'validation_report' => $report,
                ]);
            } else {
                $dataset->update([
                    'status' => Dataset::STATUS_READY,
                    'error_message' => null,
                    'validation_report' => $report,
                ]);
            }
        });

        return ['imported' => $imported, 'skipped' => count($errors), 'warnings' => $warnings, 'errors' => $errors];
    }

    /**
     * Fitur unduh template dihapus (2026-09-15): buildTemplate() tidak
     * lagi dipakai. Method dihapus karena tidak ada sisa referensi di
     * controller/route/view.
     */

    /**
     * Parse file menjadi array baris [nomor_baris => [kolom...]].
     * Baris 1 adalah header dan tidak ikut dikembalikan.
     *
     * Kolom bertipe 'date' di config diperlakukan khusus: PhpSpreadsheet
     * membaca date yang diisi via Excel date-picker sebagai serial float.
     * Di sini kita konversi ke string Y-m-d agar pipeline validasi konsisten.
     *
     * readDataOnly: jangan muat style/format sel. Laporan KPI riil (16 sheet,
     * ~3 MB) mati OOM di memory_limit 128M bila style ikut dimuat; dengan
     * readDataOnly turun ke ~58 MB. Style tidak dibutuhkan karena nilai
     * dibaca mentah dan tipe kolom sudah ditentukan config.
     *
     * @return array<int, array<int, mixed>>
     */
    private function parseRows(UploadedFile $file): array
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file->getRealPath());
        $columns = config('dataset_import.columns');
        $dateColumnIndexes = collect($columns)
            ->keys()
            ->filter(fn ($i) => ($columns[$i]['rule'] ?? '') === 'date')
            ->all();

        try {
            $worksheet = $spreadsheet->getActiveSheet();
            $data = [];

            foreach ($worksheet->getRowIterator() as $rowIndex => $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                $rowData = [];
                $colIndex = 0;

                foreach ($cellIterator as $cell) {
                    $rawValue = $cell->getValue();

                    // Kolom date: konversi serial float Excel ke Y-m-d.
                    if (in_array($colIndex, $dateColumnIndexes, true)
                        && $rawValue !== null
                        && $rawValue !== ''
                        && is_numeric($rawValue)
                    ) {
                        try {
                            $phpDate = Date::excelToDateTimeObject($rawValue);
                            $rowData[] = $phpDate->format('Y-m-d');
                        } catch (Throwable) {
                            $rowData[] = trim((string) $rawValue);
                        }
                    } else {
                        $strValue = $rawValue === null ? '' : trim((string) $rawValue);

                        // Kolom date: string DD/MM/YYYY atau YYYY-MM-DD sama-sama
                        // di-normalize ke Y-m-d agar pipeline validasi konsisten.
                        if (in_array($colIndex, $dateColumnIndexes, true) && $strValue !== '') {
                            $normalized = $this->normalizeDate($strValue);
                            $rowData[] = $normalized ?? $strValue;
                        } else {
                            $rowData[] = $strValue;
                        }
                    }

                    $colIndex++;
                }

                $data[] = $rowData;
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        // Buang header (index 0); nomor baris dimulai dari 2.
        array_shift($data);

        $rows = [];
        foreach ($data as $index => $row) {
            // Lewati baris yang benar-benar kosong.
            if (count(array_filter($row, fn ($cell) => $cell !== null && trim((string) $cell) !== '')) === 0) {
                continue;
            }
            $rows[$index + 2] = $row;
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
                return match ($column['rule']) {
                    // is_numeric() hanya menerima gaya PHP/Inggris (1234.56).
                    // Gaya Indonesia (1.234,56) SENGAJA ditolak dengan pesan
                    // eksplisit, bukan dinormalisasi otomatis: "1.234" ambigu
                    // (1,234 vs 1234) dan menebak-nebak separator bisa diam-diam
                    // menggeser nilai 1000x. Normalisasi hanya aman bila suatu
                    // saat ada kebutuhan CSV locale Indonesia yang jelas.
                    'numeric' => "kolom '{$column['header']}' tidak valid (numeric): pakai titik desimal tanpa pemisah ribuan (contoh: 1234.56)",
                    'date' => "kolom '{$column['header']}' tidak valid (date): pakai DD/MM/YYYY atau YYYY-MM-DD",
                    default => "kolom '{$column['header']}' tidak valid ({$column['rule']})",
                };
            }
        }

        return null;
    }

    /**
     * Validasi tanggal — terima:
     * - DD/MM/YYYY (format template, familiar staf Indonesia)
     * - YYYY-MM-DD (format ISO, untuk CSV manual atau export lain)
     * - datetime dengan komponen jam (mis. "2026-01-31 10:30:00"),
     *   tanggalnya diambil, jamnya dibuang.
     *
     * Semua di-normalize ke Y-m-d sebelum disimpan. Anti silent-rolling:
     * round-trip format harus identik dengan input.
     */
    private function isValidDate(string $value): bool
    {
        return $this->normalizeDate($value) !== null;
    }

    /**
     * Normalisasi string tanggal ke Y-m-d.
     * Kembalikan null bila format tidak dikenali atau tanggal tidak valid.
     */
    private function normalizeDate(string $value): ?string
    {
        // Datetime dengan komponen jam (dari CSV/export sistem lain):
        // ambil tanggalnya saja. Validitas tanggal tetap dicek round-trip
        // di bawah, jadi "31/02/2026 10:00" tetap ditolak, bukan digulung.
        if (preg_match('/^(\d{2}\/\d{2}\/\d{4}|\d{4}-\d{2}-\d{2})[T ]\d{1,2}:\d{2}(?::\d{2})?$/', $value, $matches)) {
            $value = $matches[1];
        }

        // Format 1: DD/MM/YYYY (dari template Excel dengan format DD/MM/YYYY)
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
            try {
                $date = Carbon::createFromFormat('d/m/Y', $value);
                if ($date !== false && $date->format('d/m/Y') === $value) {
                    return $date->format('Y-m-d');
                }
            } catch (Throwable) {
            }

            return null;
        }

        // Format 2: YYYY-MM-DD (ISO, untuk CSV atau input manual)
        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return ($date !== false && $date->format('Y-m-d') === $value)
            ? $value
            : null;
    }
}
