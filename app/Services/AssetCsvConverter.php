<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Konversi verbatim berkas CSV (ekspor workbook aset) menjadi satu XLSX
 * per CSV: satu sheet per workbook, nilai polos tanpa formula.
 *
 * Tujuan (Faza A arsitektur aset, task.md 3.0f): membuat berkas kompatibel
 * dengan pipeline XLSX yang sudah ada TANPA mengubah data — ini konversi
 * FORMAT, bukan isi. Metode identik dengan konversi manual 2026-09-24 yang
 * terverifikasi (456 baris metric KIN26/1b, spot-check cocok, kebocoran
 * agregat source 0): `Obsidian/00 - Dashboard/Asset CSV Inventory and
 * Validation 2026-09-24.md` section F.
 *
 * Aturan koersi sel (faithful, tanpa interpretasi):
 * - "" atau "-"        -> sel kosong (nilai kosong pada rekap aset);
 * - "1,860.0" / "- 15" -> float (pemisah ribuan koma + spasi dibuang);
 * - sel error ("#DIV/0!", "#N/A") -> string (di-skip AssetStockConverter,
 *   sama seperti perilaku workbook asli);
 * - E-notation ("1.23E+5") dan teks lain -> string ter-trim, representasi
 *   asli terjaga (TIDAK dikoersi float).
 *
 * Parser fgetcsv (RFC 4180) karena register memuat quoted field dengan
 * newline di dalamnya (2b TRF: 679 baris mentah -> 660 record).
 */
class AssetCsvConverter
{
    /** Prefix judul sheet yang dibuang (nama workbook sumber). */
    private const TITLE_PREFIX = 'DATA ASET REGISTER UIT JBT - ';

    /**
     * Konversi satu berkas CSV menjadi satu XLSX satu sheet.
     *
     * @return array{source: string, output: string, rows: int, numeric_cells: int, sha256: string}
     */
    public function convertFile(string $csvPath, string $xlsxPath): array
    {
        $handle = fopen($csvPath, 'r');

        if ($handle === false) {
            throw new RuntimeException("Gagal membuka berkas CSV: {$csvPath}");
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle(basename($csvPath, '.csv')));

        $rowNum = 0;
        $numericCells = 0;

        // Escape '\' dipertahankan dari konversi terverifikasi sesi 17.
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rowNum++;

            foreach ($row as $colIdx => $raw) {
                $value = $this->coerce($raw);

                if ($value !== null) {
                    if (is_float($value)) {
                        $numericCells++;
                        $sheet->getCell([$colIdx + 1, $rowNum])
                            ->setValueExplicit($value, DataType::TYPE_NUMERIC);
                    } else {
                        $sheet->getCell([$colIdx + 1, $rowNum])
                            ->setValueExplicit($value, DataType::TYPE_STRING);
                    }
                }
            }
        }

        fclose($handle);

        (new Xlsx($spreadsheet))->save($xlsxPath);
        $spreadsheet->disconnectWorksheets();

        return [
            'source' => basename($csvPath),
            'output' => basename($xlsxPath),
            'rows' => $rowNum,
            'numeric_cells' => $numericCells,
            'sha256' => substr((string) hash_file('sha256', $csvPath), 0, 16),
        ];
    }

    /**
     * Konversi semua CSV dalam direktori sumber menjadi satu XLSX per CSV
     * di direktori keluaran (dibuat bila belum ada).
     *
     * @return list<array{source: string, output: string, rows: int, numeric_cells: int, sha256: string}>
     */
    public function convertDirectory(string $sourceDir, string $outputDir): array
    {
        if (! is_dir($sourceDir)) {
            throw new RuntimeException("Direktori sumber tidak ditemukan: {$sourceDir}");
        }

        $files = glob(rtrim($sourceDir, '/\\').'/*.csv');

        if ($files === false || $files === []) {
            throw new RuntimeException("Tidak ada berkas CSV di: {$sourceDir}");
        }

        sort($files);

        if (! is_dir($outputDir) && ! @mkdir($outputDir, 0777, true) && ! is_dir($outputDir)) {
            throw new RuntimeException("Gagal membuat direktori keluaran: {$outputDir}");
        }

        $results = [];

        foreach ($files as $csvPath) {
            $base = basename($csvPath, '.csv');
            $results[] = $this->convertFile($csvPath, $outputDir.'/'.$base.'.xlsx');
        }

        return $results;
    }

    /**
     * Ubah satu sel CSV menjadi nilai XLSX (aturan di docblock kelas).
     */
    private function coerce(?string $raw): null|float|string
    {
        $t = trim((string) $raw);

        if ($t === '' || $t === '-') {
            return null;
        }

        $compact = str_replace(' ', '', $t);

        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $compact)
            || preg_match('/^-?\d+(\.\d+)?$/', $compact)
        ) {
            return (float) str_replace(',', '', $compact);
        }

        return $t;
    }

    /**
     * Judul sheet Excel: buang prefix nama workbook, max 31 karakter,
     * tanpa karakter terlarang.
     */
    private function sheetTitle(string $base): string
    {
        $title = str_replace(self::TITLE_PREFIX, '', $base);
        $title = preg_replace('/[\\\\\/\*\?\:\[\]]/', '', $title);

        return mb_substr((string) $title, 0, 31) ?: 'Sheet1';
    }
}
