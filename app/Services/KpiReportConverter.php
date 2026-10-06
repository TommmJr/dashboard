<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
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
 *
 * LOSS-AWARE: setiap sel yang tidak menghasilkan angka dihitung beserta
 * alasannya (kosong, teks, formula tanpa cached value, error) dan setiap
 * baris yang ditolak dicatat (baris seksi/legenda, label ber-delimiter).
 * Ringkasannya tersedia lewat diagnostics(). Tidak ada lagi baris yang
 * dibuang tanpa jejak.
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

    /** Sel terbaca sebagai angka dan dipakai apa adanya. */
    public const REASON_OK = 'ok';

    /** Sel kosong: nilainya memang tidak ada di sumber. */
    public const REASON_EMPTY = 'empty';

    /** Sel berisi teks (mis. target "Sesuai Buku Prioritas KKP 2025"). */
    public const REASON_TEXT = 'text';

    /** Sel formula tanpa cached value: hasil hitung tidak ikut tersimpan. */
    public const REASON_UNCACHED_FORMULA = 'uncached_formula';

    /** Sel error Excel (#REF!, #N/A, ...). */
    public const REASON_ERROR = 'error';

    /**
     * Nilai numerik pada TARGET/REALISASI yang TIDAK dapat dipastikan bukan
     * serial tanggal Excel. Nilainya tetap dipakai (tidak diubah), hanya
     * ditandai: readDataOnly(true) membuang number format, jadi tanggal dan
     * angka biasa tidak terbedakan tanpa membaca styling (yang OOM di berkas
     * besar). Bukan kegagalan — sinyal untuk audit.
     */
    public const REASON_UNVERIFIED_NUMERIC = 'unverified_numeric';

    /** Baris seksi/agregat, bukan indikator. */
    public const REASON_NON_INDICATOR = 'non_indicator';

    /** Label memuat delimiter ' | ' sehingga metric_name-nya ambigu. */
    public const REASON_AMBIGUOUS_DELIMITER = 'ambiguous_delimiter';

    /**
     * Baris seksi/agregat yang muncul di kolom label tiap sheet tetapi bukan
     * indikator. Kolom nilainya sering berisi angka (total kolom), jadi tanpa
     * blocklist baris ini ikut ter-import sebagai metrik palsu.
     */
    private const NON_INDICATOR_LABELS = [
        'KPI BERSAMA KORPORAT',
        'KPI DIREKTORAT',
        'KEY PERFORMANCE INDICATORS',
        'PERFORMANCE INDICATORS',
        'TOTAL BOBOT',
        'INDIKATOR KINERJA KUNCI',
    ];

    /**
     * Baris legenda status di kolom label (mis. "Target Tercapai (Pencapaian
     * >= 100)"). Bukan indikator; harus ditolak walau kolom nilainya berisi
     * angka.
     */
    private const LEGEND_PATTERN = '/Tercapai\s*\(|Dilakukan\s+Penilaian/iu';

    /**
     * Diagnostics per unit dari pemanggilan extract()/extractPerUnit()
     * terakhir. Kanal tambahan supaya tidak ada baris yang dibuang senyap;
     * bentuk return kedua method itu sengaja tidak diubah agar caller lama
     * tetap jalan.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $diagnostics = [];

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
        $this->diagnostics = [];

        try {
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $unit = trim($sheet->getTitle());

                if (strtoupper($unit) === self::SUMMARY_SHEET) {
                    continue;
                }

                if ($only !== [] && ! in_array(strtoupper($unit), $only, true)) {
                    continue;
                }

                $result = $this->extractSheet($sheet, $unit, $period);
                $this->diagnostics[$unit] = $result['diagnostics'];

                foreach ($result['rows'] as $row) {
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
        $this->diagnostics = [];

        try {
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $unit = trim($sheet->getTitle());

                if (strtoupper($unit) === self::SUMMARY_SHEET) {
                    continue;
                }

                try {
                    $result = $this->extractSheet($sheet, $unit, $period);
                    $results[$unit] = $result['rows'];
                    $this->diagnostics[$unit] = $result['diagnostics'];
                } catch (RuntimeException $e) {
                    $results[$unit] = $e;
                    $this->diagnostics[$unit] = ['failed' => $e->getMessage()];
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $results;
    }

    /**
     * Ringkasan data yang dipakai, ditandai, dan dibuang dari pemanggilan
     * extract()/extractPerUnit() terakhir, per unit.
     *
     * Bentuk return kedua method itu TIDAK berubah supaya caller lama tetap
     * jalan; diagnostics adalah kanal tambahan supaya tidak ada baris yang
     * hilang tanpa jejak. Sheet yang gagal dicatat sebagai ['failed' => pesan].
     *
     * @return array<string, array<string, mixed>>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
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
     * Ekstrak satu sheet unit menjadi baris-baris metric, plus diagnostics
     * per kolom supaya baris yang tidak dipakai punya jejak.
     *
     * @return array{rows: array<int, array{0: string, 1: float, 2: string}>, diagnostics: array<string, mixed>}
     */
    private function extractSheet(Worksheet $sheet, string $unit, string $period): array
    {
        $columns = $this->locateValueColumns($sheet, $unit);
        $highestRow = $sheet->getHighestRow();
        $nkoRow = $this->findNkoRow($sheet, $highestRow);
        $rows = [];

        // Hitungan alasan per kolom value + alasan baris ditolak.
        $diagnostics = [
            'rows_emitted' => 0,
            'columns' => [
                'nilai' => [],
                'target' => [],
                'realisasi' => [],
            ],
            'rows_rejected' => [],
            'unverified_numeric' => 0,
        ];

        for ($row = 1; $row <= $highestRow; $row++) {
            $label = $this->cleanLabel($sheet->getCell(self::LABEL_COLUMN.$row)->getValue());

            if ($label === null) {
                continue;
            }

            if ($this->isNonIndicatorLabel($label)) {
                $this->count($diagnostics['rows_rejected'], self::REASON_NON_INDICATOR);

                continue;
            }

            if (str_contains($label, ' | ')) {
                $this->count($diagnostics['rows_rejected'], self::REASON_AMBIGUOUS_DELIMITER);

                continue;
            }

            $nilai = $this->number($sheet->getCell($columns['nilai'].$row));

            if ($row === $nkoRow) {
                if ($nilai['value'] !== null) {
                    $rows[] = ["{$unit}".self::NKO_SUFFIX, $nilai['value'], $period];
                    $diagnostics['rows_emitted']++;
                } else {
                    $this->count($diagnostics['columns']['nilai'], $nilai['reason']);
                }

                continue;
            }

            if ($nilai['value'] !== null) {
                $rows[] = ["{$unit} | {$label}", $nilai['value'], $period];
                $diagnostics['rows_emitted']++;
            } else {
                $this->count($diagnostics['columns']['nilai'], $nilai['reason']);
            }

            foreach (['target' => $columns['target'], 'realisasi' => $columns['realisasi']] as $suffix => $column) {
                $value = $this->number($sheet->getCell($column.$row), dateSensitive: true);

                if ($value['reason'] === self::REASON_UNVERIFIED_NUMERIC) {
                    $diagnostics['unverified_numeric']++;
                }

                if ($value['value'] !== null) {
                    $rows[] = ["{$unit} | {$label} | {$suffix}", $value['value'], $period];
                    $diagnostics['rows_emitted']++;
                } else {
                    $this->count($diagnostics['columns'][$suffix], $value['reason']);
                }
            }
        }

        return ['rows' => $rows, 'diagnostics' => $diagnostics];
    }

    /**
     * Baris seksi/legenda, bukan indikator. Diekstrak dari kolom label supaya
     * tidak ikut ter-import sebagai metrik palsu walau kolom nilainya berisi
     * angka (mis. total kolom pada baris "TOTAL BOBOT").
     */
    private function isNonIndicatorLabel(string $label): bool
    {
        $normalized = mb_strtoupper($label);

        if (in_array($normalized, self::NON_INDICATOR_LABELS, true)) {
            return true;
        }

        return preg_match(self::LEGEND_PATTERN, $label) === 1;
    }

    /**
     * Tambah satu hitungan pada array asosiatif alasan.
     *
     * @param  array<string, int>  $counter
     */
    private function count(array &$counter, string $reason): void
    {
        $counter[$reason] = ($counter[$reason] ?? 0) + 1;
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
     *
     * Mengembalikan nilai BESERTA alasan, bukan nilai saja: semua jalur yang
     * tidak menghasilkan angka harus bisa dihitung pemanggil, bukan dibuang
     * diam-diam (lihat diagnostics()).
     *
     * Batasan yang disengaja: readDataOnly(true) membuang number format, jadi
     * sel tanggal Excel tidak terbedakan dari angka biasa. Sel numerik pada
     * kolom tanggal-sensitif ditandai REASON_UNVERIFIED_NUMERIC tanpa mengubah
     * nilainya — lebih baik memberi sinyal daripada menebak atau membuka
     * styling (yang OOM di berkas besar).
     *
     * @param  bool  $dateSensitive  true untuk TARGET/REALISASI yang boleh berisi tanggal
     * @return array{value: ?float, reason: string}
     */
    private function number(Cell $cell, bool $dateSensitive = false): array
    {
        $value = $cell->getValue();

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return ['value' => null, 'reason' => self::REASON_EMPTY];
        }

        if (is_bool($value)) {
            return ['value' => null, 'reason' => self::REASON_TEXT];
        }

        if ($cell->getDataType() === DataType::TYPE_ERROR) {
            return ['value' => null, 'reason' => self::REASON_ERROR];
        }

        if (is_numeric($value)) {
            return [
                'value' => (float) $value,
                'reason' => $dateSensitive ? self::REASON_UNVERIFIED_NUMERIC : self::REASON_OK,
            ];
        }

        if (is_string($value) && str_starts_with($value, '=')) {
            $cached = $cell->getOldCalculatedValue();

            if (is_numeric($cached)) {
                return [
                    'value' => (float) $cached,
                    'reason' => $dateSensitive ? self::REASON_UNVERIFIED_NUMERIC : self::REASON_OK,
                ];
            }

            if (is_string($cached) && trim($cached) !== '') {
                return ['value' => null, 'reason' => self::REASON_TEXT];
            }

            return ['value' => null, 'reason' => self::REASON_UNCACHED_FORMULA];
        }

        return ['value' => null, 'reason' => self::REASON_TEXT];
    }
}
