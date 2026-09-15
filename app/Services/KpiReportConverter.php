<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Konversi laporan KPI hierarkis (satu sheet per unit — pola sampel mentor
 * "08. Realisasi Kinerja ...") menjadi template generik 3 kolom
 * (metric_name, value, period) yang bisa diunggah lewat pipeline impor biasa.
 *
 * Kenapa konverter terpisah, bukan importer baru: format laporan mentor rapuh
 * (baris NKO beda-beda tiap sheet, kolom bulanan sisa template tahun lalu,
 * sel berisi formula, blok tanda tangan approval). Kerapuhan itu diisolasi di
 * sini supaya DatasetImportService tidak ikut rapuh. Skema DB tidak berubah.
 *
 * PENTING — kolom TARGET/REALISASI/NILAI dicari lewat header, bukan
 * di-hardcode. Layout tidak seragam: di file mentor yang sama, sheet `TRANS`
 * menggeser kolom satu ke kiri (TARGET di J, NILAI di M) sementara 14 sheet
 * lain memakai K/L/N. Membaca posisi tetap menghasilkan angka yang salah tanpa
 * error — kelas bug yang paling berbahaya. Kalau header tidak ketemu,
 * konverter GAGAL dengan pesan jelas alih-alih menebak.
 *
 * Skema metric_name yang dihasilkan (prefix unit supaya satu file bisa
 * memuat banyak unit tanpa menambah kolom):
 * - "{UNIT} | NKO"                     -> nilai kinerja organisasi unit
 * - "{UNIT} | {indikator}"             -> NILAI (bobot x pencapaian)
 * - "{UNIT} | {indikator} | target"    -> TARGET kumulatif s.d. periode
 * - "{UNIT} | {indikator} | realisasi" -> REALISASI kumulatif s.d. periode
 */
class KpiReportConverter
{
    /** Sheet ringkasan lintas-unit, bukan data unit. */
    private const SUMMARY_SHEET = 'REKAP';

    /** Penanda baris NKO; nomor barisnya berbeda di tiap sheet. */
    private const NKO_LABEL = 'NILAI KINERJA ORGANISASI';

    /** Kolom label indikator (stabil di semua sheet sampel). */
    private const LABEL_COLUMN = 'B';

    /**
     * Nama metrik NKO pada `metric_name`, plus akhiran lengkapnya.
     *
     * Sumber tunggal: dipakai converter saat menulis dan dashboard saat
     * membaca. NKO satu-satunya angka yang sebanding lintas unit, jadi
     * dashboard hanya mengagregasi baris ini — bukan SUM seluruh value
     * (target/realisasi punya satuan berbeda: Rp Juta, Dokumen, Kali, %).
     */
    public const NKO_METRIC = 'NKO';

    public const NKO_SUFFIX = ' | '.self::NKO_METRIC;

    /**
     * Ekstrak baris (metric_name, value, period) dari laporan KPI.
     *
     * @param  list<string>  $onlyUnits  batasi ke kode unit tertentu (mis. ['TJBT']); kosong = semua
     * @return array<int, array{0: string, 1: float, 2: string}>
     */
    public function extract(string $sourcePath, string $period, array $onlyUnits = []): array
    {
        $spreadsheet = $this->load($sourcePath);

        $only = array_map('strtoupper', $onlyUnits);
        $rows = [];

        try {
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $unit = trim($sheet->getTitle());

                if (strtoupper($unit) === self::SUMMARY_SHEET) {
                    continue;
                }

                if ($only !== [] && ! in_array(strtoupper($unit), $only, true)) {
                    continue;
                }

                foreach ($this->extractSheet($sheet, $unit, $period) as $row) {
                    $rows[] = $row;
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $rows;
    }

    /**
     * Ekstrak semua unit sekaligus, SATU sheet gagal tidak menghentikan sheet
     * lain — dipakai alur import web 1-klik dari laporan mentor.
     *
     * Berbeda dengan extract() yang melempar RuntimeException dan membuang
     * semua hasil bila satu sheet bermasalah, di sini kegagalan per-sheet
     * dikembalikan sebagai nilai (Throwable) di bawah unitnya, sehingga
     * pemanggil bisa melapor per unit sambil tetap mengimpor unit sehat.
     *
     * @return array<string, array<int, array{0: string, 1: float, 2: string}>|Throwable> unit => baris, atau Throwable bila sheet gagal
     */
    public function extractPerUnit(string $sourcePath, string $period): array
    {
        $spreadsheet = $this->load($sourcePath);

        $results = [];

        try {
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $unit = trim($sheet->getTitle());

                if (strtoupper($unit) === self::SUMMARY_SHEET) {
                    continue;
                }

                try {
                    $results[$unit] = $this->extractSheet($sheet, $unit, $period);
                } catch (RuntimeException $e) {
                    $results[$unit] = $e;
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $results;
    }

    /**
     * Muat workbook read-only. readDataOnly wajib: laporan 16 sheet ~3 MB
     * mati OOM di 128M kalau style sel ikut dimuat (lihat
     * DatasetImportService::parseRows).
     */
    private function load(string $sourcePath): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($sourcePath);
        $reader->setReadDataOnly(true);

        return $reader->load($sourcePath);
    }

    /**
     * Tulis baris hasil ekstraksi ke file .xlsx berformat template generik.
     *
     * @param  array<int, array{0: string, 1: float, 2: string}>  $rows
     * @return string path file sementara
     */
    public function write(array $rows): string
    {
        return $this->writeTo($rows, tempnam(sys_get_temp_dir(), 'kpi_').'.xlsx');
    }

    /**
     * Tulis satu berkas template per unit ke direktori tujuan.
     *
     * Dipakai karena importer menetapkan SATU divisi per dataset (bukan per
     * baris), sedangkan laporan mentor lintas-unit. Berkas terpisah per unit
     * membuat tiap unit tertaut ke divisinya sendiri tanpa mengubah pipeline.
     *
     * @param  array<int, array{0: string, 1: float, 2: string}>  $rows
     * @return array<string, string> unit => path berkas
     */
    public function writeSplit(array $rows, string $directory): array
    {
        $paths = [];

        foreach ($this->groupByUnit($rows) as $unit => $unitRows) {
            $safe = preg_replace('/[^\w\s\-.]/u', '', $unit) ?: 'unit';
            $path = rtrim($directory, '\\/').DIRECTORY_SEPARATOR.$safe.'.xlsx';

            $paths[$unit] = $this->writeTo($unitRows, $path);
        }

        return $paths;
    }

    /**
     * Kelompokkan baris hasil ekstraksi per unit.
     *
     * Unit adalah prefix sebelum ' | ' pada metric_name (mis. "P3B SUM | NKO"
     * -> "P3B SUM").
     *
     * @param  array<int, array{0: string, 1: float, 2: string}>  $rows
     * @return array<string, array<int, array{0: string, 1: float, 2: string}>>
     */
    public function groupByUnit(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[explode(' | ', $row[0], 2)[0]][] = $row;
        }

        return $grouped;
    }

    /**
     * @param  array<int, array{0: string, 1: float, 2: string}>  $rows
     */
    private function writeTo(array $rows, string $path): string
    {
        $headers = array_column(config('dataset_import.columns'), 'header');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');

        $sheet->fromArray($headers, null, 'A1');
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);

        foreach ($rows as $index => $row) {
            // strictNullComparison=true: tanpa ini fromArray() memperlakukan
            // nilai 0 seperti null, sehingga indikator bernilai 0 jadi sel kosong.
            $sheet->fromArray($row, null, 'A'.($index + 2), true);
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * Ekstrak satu sheet unit menjadi baris-baris metric.
     *
     * @return array<int, array{0: string, 1: float, 2: string}>
     */
    private function extractSheet(Worksheet $sheet, string $unit, string $period): array
    {
        $columns = $this->locateValueColumns($sheet, $unit);
        $highestRow = $sheet->getHighestRow();
        $nkoRow = $this->findNkoRow($sheet, $highestRow);
        $rows = [];

        for ($row = 1; $row <= $highestRow; $row++) {
            $label = $this->cleanLabel($sheet->getCell(self::LABEL_COLUMN.$row)->getValue());

            if ($label === null) {
                continue;
            }

            $nilai = $this->number($sheet->getCell($columns['nilai'].$row));

            if ($row === $nkoRow) {
                if ($nilai !== null) {
                    $rows[] = ["{$unit}".self::NKO_SUFFIX, $nilai, $period];
                }

                continue;
            }

            if ($nilai !== null) {
                $rows[] = ["{$unit} | {$label}", $nilai, $period];
            }

            foreach (['target' => $columns['target'], 'realisasi' => $columns['realisasi']] as $suffix => $column) {
                $value = $this->number($sheet->getCell($column.$row));

                if ($value !== null) {
                    $rows[] = ["{$unit} | {$label} | {$suffix}", $value, $period];
                }
            }
        }

        return $rows;
    }

    /**
     * Temukan kolom TARGET/REALISASI/NILAI dari baris header sheet.
     *
     * Layout tidak seragam antar sheet (lihat docblock kelas), jadi posisi
     * kolom tidak boleh di-hardcode. Header dicari di 15 baris pertama:
     * baris yang memuat label "NILAI" sekaligus "TARGET" dan "REALISASI".
     *
     * @return array{target: string, realisasi: string, nilai: string}
     */
    private function locateValueColumns(Worksheet $sheet, string $unit): array
    {
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($row = 1; $row <= min(15, $sheet->getHighestRow()); $row++) {
            $found = [];

            for ($col = 1; $col <= $highestColumn; $col++) {
                $label = $this->normalizeHeader($sheet->getCell([$col, $row])->getValue());

                if ($label === null) {
                    continue;
                }

                foreach (['target' => 'TARGET', 'realisasi' => 'REALISASI', 'nilai' => 'NILAI'] as $key => $expected) {
                    if ($label === $expected && ! isset($found[$key])) {
                        $found[$key] = Coordinate::stringFromColumnIndex($col);
                    }
                }
            }

            if (count($found) === 3) {
                return $found;
            }
        }

        throw new RuntimeException(
            "Sheet '{$unit}': kolom TARGET/REALISASI/NILAI tidak ditemukan di header. "
            .'Layout laporan berubah — periksa berkas sebelum mengonversi.'
        );
    }

    /**
     * Normalisasi teks header menjadi satu kata kunci uppercase, atau null
     * bila sel bukan teks.
     */
    private function normalizeHeader(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
    }

    /**
     * Cari baris NKO di kolom label. Nomor barisnya tidak seragam antar sheet
     * (TJBT baris 40, RST baris 30, TRANS baris 27), jadi tidak boleh di-hardcode.
     */
    private function findNkoRow(Worksheet $sheet, int $highestRow): ?int
    {
        for ($row = 1; $row <= $highestRow; $row++) {
            $label = $sheet->getCell(self::LABEL_COLUMN.$row)->getValue();

            if (is_string($label) && stripos($label, self::NKO_LABEL) !== false) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Ambil label indikator dari kolom B, atau null bila barisnya bukan indikator.
     * Baris legenda nomor kolom (isinya cuma angka, mis. "2") dibuang.
     */
    private function cleanLabel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $label = trim(preg_replace('/\s+/', ' ', $value));

        return $label !== '' && preg_match('/\p{L}/u', $label) === 1 ? $label : null;
    }

    /**
     * Baca angka dari sel, termasuk sel berisi formula.
     *
     * Sel formula: getValue() mengembalikan string "=...", sedangkan hasil
     * hitungnya tersimpan sebagai cached value di file (getOldCalculatedValue()).
     * Dengan begitu nilai NKO/NILAI bisa dibaca tanpa membuka Excel untuk recalculate.
     */
    private function number(Cell $cell): ?float
    {
        $value = $cell->getValue();

        if (is_numeric($value)) {
            return (float) $value;
        }

        $cached = $cell->getOldCalculatedValue();

        return is_numeric($cached) ? (float) $cached : null;
    }
}
