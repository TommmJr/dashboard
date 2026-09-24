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
 * Konversi rekap aset "KIN26 - STOP ONLY" menjadi template generik 3 kolom
 * (metric_name, value, period) yang bisa diunggah lewat pipeline impor biasa.
 *
 * Bentuk berkas (sampel mentor "Dataset bidang aset.xlsx", satu sheet):
 * - Band header 3 level yang di-merge: baris kategori (mis. "Trafo 500/150 KV"),
 *   baris sub-tipe (mis. "Trafo 150/70 KV"), baris satuan (mis. "Bank").
 * - Kolom identitas A-C: No | UPT | ULTG. Tiap baris ULTG adalah butir data
 *   (hasil SUMIFS dari sheet sumber); baris "Total UPT", "TOTAL UIT JBT",
 *   "OPR"/"SELISIH" adalah agregat/rekonsiliasi dan di-SKIP.
 * - Satu sel judul berisi #REF! (referensi sheet yang tidak ikut terlampirkan).
 *   Sel error/formula tanpa cached numeric TIDAK diimpor sebagai 0.
 *
 * Mengikuti pola KpiReportConverter (sengaja tidak dijadikan base class
 * bersama — lihat larangan refactor di scope sesi ini):
 * - posisi kolom UPT/ULTG dicari lewat header, bukan di-hardcode;
 * - readDataOnly wajib (hindari OOM);
 * - header tidak ketemu -> exception jelas, jangan menebak.
 *
 * Skema metric_name yang dihasilkan:
 * - "ASET | {UPT} | {ULTG} | {kategori} | {sub-tipe} | {satuan}"
 *   (level header yang kosong dilewati, mis. "ASET | BANDUNG | BANDUNG BARAT
 *   | Trafo 500/150 KV | Bank").
 */
class AssetStockConverter
{
    /**
     * Prefix domain pada `metric_name`.
     *
     * Sumber tunggal: dipakai converter saat menulis dan dashboard saat
     * membaca (isolasi kartu KPI dari domain Aset). Baris Aset satu-satunya
     * yang berprefix ini, jadi filter `ASET | %` memisahkan kedua domain
     * tanpa kolom tambahan.
     */
    public const PREFIX = 'ASET | ';

    /** Awalan label agregat (mis. "Total UPT", "TOTAL UIT JBT") — di-skip. */
    private const TOTAL_PREFIX = 'total';

    /** Label baris rekonsiliasi pada kolom ULTG — di-skip. */
    private const SKIP_ULTG = ['opr', 'selisih'];

    /** Dalamnya band header: kategori | sub-tipe | satuan. */
    private const HEADER_DEPTH = 3;

    /** Batas atas pencarian baris header identitas. */
    private const HEADER_SCAN_MAX = 15;

    /**
     * Ekstrak baris (metric_name, value, period) dari workbook rekap aset.
     *
     * @return array<int, array{0: string, 1: float, 2: string}>
     */
    public function extract(string $sourcePath, string $period): array
    {
        $spreadsheet = $this->load($sourcePath);

        $rows = [];

        try {
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                foreach ($this->extractSheet($sheet, $period) as $row) {
                    $rows[] = $row;
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $rows;
    }

    /**
     * Tulis baris hasil ekstraksi ke file .xlsx berformat template generik.
     *
     * @param  array<int, array{0: string, 1: float, 2: string}>  $rows
     * @return string path file sementara
     */
    public function write(array $rows): string
    {
        return $this->writeTo($rows, tempnam(sys_get_temp_dir(), 'aset_').'.xlsx');
    }

    /**
     * Muat workbook read-only. readDataOnly wajib: style sel tidak dibutuhkan
     * (nilai dibaca mentah) dan file besar bisa menghabiskan memori bila
     * style ikut dimuat (lihat DatasetImportService::parseRows).
     */
    private function load(string $sourcePath): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($sourcePath);
        $reader->setReadDataOnly(true);

        return $reader->load($sourcePath);
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
            // nilai 0 seperti null, sehingga atribut bernilai 0 jadi sel kosong.
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
     * Ekstrak satu sheet rekap menjadi baris-baris metric.
     *
     * @return array<int, array{0: string, 1: float, 2: string}>
     */
    private function extractSheet(Worksheet $sheet, string $period): array
    {
        [$headerRow, $uptCol, $ultgCol] = $this->locateIdentity($sheet);
        $attributes = $this->attributes($sheet, $headerRow, $ultgCol);
        $rows = [];

        for ($row = $headerRow + self::HEADER_DEPTH; $row <= $sheet->getHighestRow(); $row++) {
            $upt = $this->cleanLabel($sheet->getCell([$uptCol, $row])->getValue());
            $ultg = $this->cleanLabel($sheet->getCell([$ultgCol, $row])->getValue());

            if ($upt === null || $ultg === null) {
                continue;
            }

            $ultgLower = mb_strtolower($ultg);

            // Subtotal per UPT, grand total, dan baris rekonsiliasi: agregat
            // dari baris ULTG di atasnya — mengimpornya akan menghitung ganda.
            if (str_starts_with($ultgLower, self::TOTAL_PREFIX)
                || str_starts_with(mb_strtolower($upt), self::TOTAL_PREFIX)
                || in_array($ultgLower, self::SKIP_ULTG, true)
            ) {
                continue;
            }

            foreach ($attributes as $col => $attribute) {
                $value = $this->number($sheet->getCell([$col, $row]));

                if ($value !== null) {
                    $rows[] = [self::PREFIX."{$upt} | {$ultg} | {$attribute}", $value, $period];
                }
            }
        }

        return $rows;
    }

    /**
     * Temukan baris header identitas (memuat "UPT" dan "ULTG") beserta kolomnya.
     *
     * Layout vertikal bisa bergeser (baris judul di atasnya), jadi baris
     * header dicari di 15 baris pertama, bukan di-hardcode.
     *
     * @return array{0: int, 1: int, 2: int} [baris header, kolom UPT, kolom ULTG]
     */
    private function locateIdentity(Worksheet $sheet): array
    {
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($row = 1; $row <= min(self::HEADER_SCAN_MAX, $sheet->getHighestRow()); $row++) {
            $found = [];

            for ($col = 1; $col <= $highestColumn; $col++) {
                $label = $this->normalizeHeader($this->cellText($sheet->getCell([$col, $row])->getValue()));

                foreach (['upt' => 'UPT', 'ultg' => 'ULTG'] as $key => $expected) {
                    if ($label === $expected && ! isset($found[$key])) {
                        $found[$key] = $col;
                    }
                }
            }

            if (count($found) === 2) {
                return [$row, $found['upt'], $found['ultg']];
            }
        }

        throw new RuntimeException(
            "Sheet '{$sheet->getTitle()}': kolom UPT/ULTG tidak ditemukan di header. "
            .'Layout rekap berubah — periksa berkas sebelum mengonversi.'
        );
    }

    /**
     * Bangun label atribut per kolom nilai dari band header 3 level.
     *
     * Dua baris grup (kategori, sub-tipe) memakai forward-fill: di bawah
     * readDataOnly info merge tidak dimuat reader, dan sel kosong pada baris
     * grup adalah lanjutan merge horizontal dari grup di kirinya (band header
     * rekap aset memang tersusun dari merge selebar kelompoknya). Baris daun
     * (satuan) dibaca persis — sel kosong berarti atribut itu tidak punya
     * satuan, bukan warisan kolom kiri.
     *
     * @return array<int, string> kolom (1-based) => label "kategori | sub-tipe | satuan"
     */
    private function attributes(Worksheet $sheet, int $headerRow, int $ultgCol): array
    {
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $groups = $this->headerRowLabels($sheet, $headerRow, $ultgCol + 1, $highestColumn, true);
        $subTypes = $this->headerRowLabels(
            $sheet,
            $headerRow + 1,
            $ultgCol + 1,
            $highestColumn,
            true,
            $groups,
        );
        $units = $this->headerRowLabels($sheet, $headerRow + 2, $ultgCol + 1, $highestColumn, false);
        $attributes = [];

        for ($col = $ultgCol + 1; $col <= $highestColumn; $col++) {
            $parts = array_filter([$groups[$col], $subTypes[$col], $units[$col]]);

            if ($parts !== []) {
                $attributes[$col] = implode(' | ', $parts);
            }
        }

        return $attributes;
    }

    /**
     * Baca satu baris header menjadi label per kolom. Dengan $fill, sel kosong
     * mewarisi label tak-kosong terakhir di kirinya (pengganti info merge yang
     * tidak tersedia saat readDataOnly).
     *
     * @param  array<int, string|null>  $resetAt  boundary labels; forward-fill resets when they change
     * @return array<int, string|null>
     */
    private function headerRowLabels(
        Worksheet $sheet,
        int $row,
        int $fromCol,
        int $toCol,
        bool $fill,
        array $resetAt = [],
    ): array {
        $labels = [];
        $last = null;

        for ($col = $fromCol; $col <= $toCol; $col++) {
            if ($resetAt !== [] && ($resetAt[$col] ?? null) !== ($resetAt[$col - 1] ?? null)) {
                $last = null;
            }

            $text = $this->cellText($sheet->getCell([$col, $row])->getValue());

            if ($text !== null) {
                $last = $text;
            }

            $labels[$col] = $fill ? $last : $text;
        }

        return $labels;
    }

    /**
     * Teks sel header yang dinormalisasi, atau null bila kosong/bukan teks.
     */
    private function cellText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', $value));

        return $text !== '' ? $text : null;
    }

    /**
     * Normalisasi teks header menjadi kata kunci uppercase, atau null
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
     * Ambil label identitas (UPT/ULTG), atau null bila sel bukan label.
     * Isi angka murni (mis. nomor urut) bukan identitas.
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
     * Sel error (#REF!, #DIV/0!, ...) bukan numeric di kedua sisi, jadi
     * menghasilkan null dan dilewati — tidak pernah diimpor sebagai 0.
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
