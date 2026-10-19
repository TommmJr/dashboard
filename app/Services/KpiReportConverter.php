<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
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

    /**
     * Penanda baris NKO; nomor barisnya berbeda di tiap sheet.
     *
     * Publik supaya importer bisa memakai penanda yang SAMA untuk mendeteksi
     * baris NKO: kalau deteksi converter dan importer berbeda, satu sisi bisa
     * memperlakukan baris sebagai NKO dan sisi lain tidak. Nilai ini juga
     * dipakai sebagai nama kanonis indikator NKO, sehingga varian label
     * ("NILAI KINERJA ORGANISASI (NKO)", "NKO") mengerucut ke satu identitas.
     */
    public const NKO_LABEL = 'NILAI KINERJA ORGANISASI';

    /**
     * Kategori indikator untuk baris NKO (kolom `indicators.category`).
     * Dipakai importer saat menulis dan reader terstruktur saat membaca,
     * supaya keduanya tidak membekukan string yang sama di dua tempat.
     */
    public const INDICATOR_CATEGORY_NKO = 'nko';

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
     * Kolom metadata sumber, stabil di semua sheet sampel: A nomor indikator,
     * B label, C aggregation/polarity code, D formula source, E satuan,
     * F bobot. Dipakai extractStructured() saja; extract() tidak butuh.
     */
    private const NO_COLUMN = 'A';

    private const AGGREGATION_COLUMN = 'C';

    private const FORMULA_COLUMN = 'D';

    private const SATUAN_COLUMN = 'E';

    private const BOBOT_COLUMN = 'F';

    /** Jenis nilai terstruktur untuk target/realisasi/pencapaian/nilai/ket. */
    public const TYPE_NUMERIC = 'numeric';

    public const TYPE_DATE = 'date';

    public const TYPE_TEXT = 'text';

    public const TYPE_EMPTY = 'empty';

    /**
     * Rentang serial tanggal Excel 1900 yang dianggap tanggal: 2000-01-01 s/d
     * 2100-12-31. readDataOnly(true) membuang number format (wajib, berkas
     * ~3 MB OOM kalau style ikut dimuat), jadi tanggal dan angka biasa tidak
     * bisa dibedakan lewat format sel; deteksi di sini heuristik rentang yang
     * wajar untuk periode laporan KPI, bukan bukti format.
     *
     * ponytail: heuristik rentang, bukan bukti numFmt. Kalau nanti ada cara
     * murah membaca numFmt per sel, ganti ke deteksi format yang pasti.
     */
    private const EXCEL_DATE_MIN = 36526.0;

    private const EXCEL_DATE_MAX = 73415.0;

    /**
     * Bentuk formula kolom PENCAPAIAN yang benar-benar terlihat di workbook.
     * Selain pola ini -> null (jangan mengarang).
     *
     * - 'average'        : =AVERAGE(...) agregasi anak pada baris grup (TJBT M10)
     * - 'date_delta'     : selisih tanggal REALISASI - TARGET pada baris yang sama,
     *                      absolut ($L14-$K14, RSL M13) maupun relatif (L33-K33,
     *                      TJBT M33). Kolomnya diambil dari header sheet.
     * - 'polarity_ratio' : bercabang pada $C (L/K atau 2-(L/K), TJBT M11 / TRANS L10)
     */
    public const PENCAPAIAN_FORMULA_AVERAGE = 'average';

    public const PENCAPAIAN_FORMULA_DATE_DELTA = 'date_delta';

    public const PENCAPAIAN_FORMULA_POLARITY_RATIO = 'polarity_ratio';

    /** Polaritas dari aggregation code; hanya 1 dan 3 yang punya bukti. */
    public const POLARITY_HIGHER_IS_BETTER = 'higher_is_better';

    public const POLARITY_LOWER_IS_BETTER = 'lower_is_better';

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
     * Ekstrak baris KPI terstruktur dari laporan: SATU baris sumber menjadi
     * SATU observation, dengan metadata sumber (kolom A-F) dan tipe nilai yang
     * dipertahankan apa adanya.
     *
     * Ini jalur TERPISAH dari extract()/extractPerUnit(). extract() membuang
     * kolom C-F, hierarki, dan tipe target/realisasi (semuanya jadi 3 kolom
     * metric_name/value/period) sehingga TIDAK bisa dipakai sebagai sumber
     * metadata terstruktur. Method ini tidak mengubah extract()/extractPerUnit()
     * sama sekali.
     *
     * Hierarki ditentukan dari kolom A (nomor), BUKAN dari bobot:
     * - A terisi + C kosong -> kepala grup (mis. TJBT r10 "Faktor Ketersediaan
     *   Trafo dan Transmisi", bobot 20; P3B KAL r10 "Keandalan Line Transmisi").
     * - A terisi + C terisi -> KPI independen (mis. TJBT r17 "Emergency Response
     *   Time", C=1, bobot 12). Tanda A ada, tetapi bukan grup.
     * - A kosong -> anak dari kepala grup terakhir (mis. TJBT r11, r36).
     * Bobot sengaja TIDAK dipakai sebagai pembeda: parent boleh F=NULL
     * (TJBT r35) dan anak boleh F="Max -3" (TJBT r36).
     *
     * Nilai target/realisasi disimpan bertipe (numeric/date/text/empty). Karena
     * readDataOnly(true) membuang number format, serial tanggal dikenali lewat
     * rentang serial yang wajar (EXCEL_DATE_MIN..EXCEL_DATE_MAX) dan HANYA pada
     * kolom target/realisasi. Ini heuristik yang disengaja: lebih baik menandai
     * tanggal daripada menyimpannya sebagai angka 45900.
     *
     * period: nilai dari pemanggil (fallbackPeriod) dipakai apa adanya.
     * sheet_period dari banner K6/J6 HANYA dilaporkan sebagai bukti dan TIDAK
     * dipakai menimpa period sampai blocker G3 (periode otoritatif 14 sheet
     * hidden) diputus.
     *
     * REKAP dilewati (derived recap). Sheet legacy/suspect berbasis nama bulan
     * (JAN..DES) dilewati.
     *
     * @param  ?string  $fallbackPeriod  periode dari pemanggil, boleh null
     * @return list<array<string, mixed>>
     */
    public function extractStructured(string $sourcePath, ?string $fallbackPeriod = null): array
    {
        $spreadsheet = $this->load($sourcePath);

        $rows = [];
        $this->diagnostics = [];

        try {
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $unit = trim($sheet->getTitle());

                if (strtoupper($unit) === self::SUMMARY_SHEET) {
                    continue;
                }

                if ($this->isSuspectSheet($unit)) {
                    continue;
                }

                $result = $this->extractStructuredSheet($sheet, $unit, $fallbackPeriod);
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
     * Sheet legacy/suspect berbasis nama bulan (JAN..DES, ID maupun EN) yang
     * tidak boleh menjadi observation. Tidak ada di workbook sampel, tetapi
     * dijaga agar varian berkas lama tidak ikut terbaca.
     */
    private function isSuspectSheet(string $unit): bool
    {
        return in_array(mb_strtoupper(trim($unit)), [
            'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC',
            'MEI', 'AGU', 'OKT', 'DES',
        ], true);
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
     * Ekstrak satu sheet unit menjadi baris KPI terstruktur.
     *
     * Satu baris indikator -> satu entri dengan metadata sumber lengkap
     * (lihat extractStructured()). Baris seksi/legenda memakai blocklist yang
     * sama dengan extractSheet() supaya tidak ada baris yang diperlakukan
     * beda antara jalur lama dan jalur terstruktur.
     *
     * @return array{rows: list<array<string, mixed>>, diagnostics: array<string, mixed>}
     */
    private function extractStructuredSheet(Worksheet $sheet, string $unit, ?string $fallbackPeriod): array
    {
        $columns = $this->locateStructuredColumns($sheet, $unit);
        $highestRow = $sheet->getHighestRow();
        $nkoRow = $this->findNkoRow($sheet, $highestRow);
        $sheetPeriod = $this->sheetPeriod($sheet, $columns);

        $rows = [];
        $diagnostics = ['rows_emitted' => 0, 'rows_rejected' => []];
        $parentRow = null;

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

            $aggregationCode = $this->rawString($sheet->getCell(self::AGGREGATION_COLUMN.$row)->getValue());
            $isNko = $row === $nkoRow;

            if ($isNko) {
                // NKO agregat level unit, bukan indikator anak.
                $isGroup = false;
                $parent = null;
            } elseif ($this->hasRowNumber($sheet->getCell(self::NO_COLUMN.$row)->getValue())) {
                // A terisi = baris top-level. Kepala grup bila C kosong; bila C
                // terisi (KPI independen) tetap top-level tetapi bukan induk.
                // Keduanya jadi kandidat induk untuk anak di bawahnya.
                $isGroup = $aggregationCode === null;
                $parent = null;
                $parentRow = $row;
            } else {
                $isGroup = false;
                $parent = $parentRow;
            }

            $bobot = $this->bobot($sheet->getCell(self::BOBOT_COLUMN.$row));

            $rows[] = [
                'org_unit' => $unit,
                'row' => $row,
                'label' => $label,
                'aggregation_code' => $aggregationCode,
                'polarity' => $this->polarity($aggregationCode),
                'formula' => $this->rawText($sheet->getCell(self::FORMULA_COLUMN.$row)->getValue()),
                'satuan' => $this->rawText($sheet->getCell(self::SATUAN_COLUMN.$row)->getValue()),
                'bobot' => $bobot['raw'],
                'bobot_value' => $bobot['value'],
                'bobot_min' => null,
                'bobot_max' => null,
                'is_group' => $isGroup,
                'parent_row' => $parent,
                'pencapaian_formula_kind' => $this->pencapaianFormulaKind(
                    $this->columnValue($sheet, $columns['pencapaian'], $row),
                    $row,
                    $columns['target'],
                    $columns['realisasi'],
                ),
                'target' => $this->typedValue($this->columnCell($sheet, $columns['target'], $row), dateSensitive: true),
                'realisasi' => $this->typedValue($this->columnCell($sheet, $columns['realisasi'], $row), dateSensitive: true),
                'pencapaian' => $this->typedValue($this->columnCell($sheet, $columns['pencapaian'], $row)),
                'nilai' => $this->typedValue($this->columnCell($sheet, $columns['nilai'], $row)),
                'ket' => $this->typedValue($this->columnCell($sheet, $columns['ket'], $row)),
                'period' => $fallbackPeriod,
                'sheet_period' => $sheetPeriod,
            ];
            $diagnostics['rows_emitted']++;
        }

        return ['rows' => $rows, 'diagnostics' => $diagnostics];
    }

    /**
     * Temukan kolom TARGET/REALISASI/PENCAPAIAN/NILAI/KET dari baris header.
     *
     * Baris header yang sama dengan locateValueColumns() (yang memuat TARGET,
     * REALISASI, dan NILAI); PENCAPAIAN dan KET dicari di baris itu juga tetapi
     * boleh tidak ada (null) supaya sheet tanpa kolom itu tetap terbaca.
     *
     * @return array{target: string, realisasi: string, pencapaian: ?string, nilai: string, ket: ?string, header_row: int}
     */
    private function locateStructuredColumns(Worksheet $sheet, string $unit): array
    {
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $expected = [
            'target' => 'TARGET',
            'realisasi' => 'REALISASI',
            'pencapaian' => 'PENCAPAIAN',
            'nilai' => 'NILAI',
            'ket' => 'KET',
        ];

        for ($row = 1; $row <= min(15, $sheet->getHighestRow()); $row++) {
            $found = [];

            for ($col = 1; $col <= $highestColumn; $col++) {
                $label = $this->normalizeHeader($sheet->getCell([$col, $row])->getValue());

                if ($label === null) {
                    continue;
                }

                foreach ($expected as $key => $header) {
                    if ($label === $header && ! isset($found[$key])) {
                        $found[$key] = Coordinate::stringFromColumnIndex($col);
                    }
                }
            }

            if (isset($found['target'], $found['realisasi'], $found['nilai'])) {
                return [
                    'target' => $found['target'],
                    'realisasi' => $found['realisasi'],
                    'pencapaian' => $found['pencapaian'] ?? null,
                    'nilai' => $found['nilai'],
                    'ket' => $found['ket'] ?? null,
                    'header_row' => $row,
                ];
            }
        }

        throw new RuntimeException(
            "Sheet '{$unit}': kolom TARGET/REALISASI/NILAI tidak ditemukan di header. "
            .'Layout laporan berubah — periksa berkas sebelum mengonversi.'
        );
    }

    /**
     * Banner periode sheet dari sel tepat di atas baris header pada kolom
     * TARGET (mis. TJBT K6 "S.D. AGUSTUS 2026", TRANS J6 "S.D. FEBRUARI 2025").
     *
     * HANYA dilaporkan sebagai bukti; jangan dipakai menimpa period sampai
     * blocker G3 (periode otoritatif 14 sheet hidden) diputus.
     *
     * @param  array{target: string, header_row: int}  $columns
     */
    private function sheetPeriod(Worksheet $sheet, array $columns): ?string
    {
        $value = $sheet->getCell($columns['target'].($columns['header_row'] - 1))->getValue();

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Baca satu sel menjadi nilai bertipe: numeric, date, text, atau empty.
     *
     * dateSensitive hanya untuk TARGET/REALISASI yang boleh berisi tenggat.
     * Karena readDataOnly membuang number format, serial tanggal dikenali
     * lewat rentang seri yang wajar (lihat EXCEL_DATE_MIN/MAX), bukan format sel.
     *
     * @return array{value: ?float, text: ?string, date: ?string, type: string}
     */
    private function typedValue(?Cell $cell, bool $dateSensitive = false): array
    {
        $empty = ['value' => null, 'text' => null, 'date' => null, 'type' => self::TYPE_EMPTY];

        if ($cell === null) {
            return $empty;
        }

        $value = $cell->getValue();

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $empty;
        }

        if ($cell->getDataType() === DataType::TYPE_ERROR) {
            return ['value' => null, 'text' => (string) $value, 'date' => null, 'type' => self::TYPE_TEXT];
        }

        if (is_bool($value)) {
            return ['value' => null, 'text' => $value ? 'TRUE' : 'FALSE', 'date' => null, 'type' => self::TYPE_TEXT];
        }

        if (is_string($value) && str_starts_with($value, '=')) {
            $cached = $cell->getOldCalculatedValue();

            if ($cached === null || (is_string($cached) && trim($cached) === '')) {
                // Formula tanpa cached value: tidak ada nilai yang bisa dipakai.
                return $empty;
            }

            $value = $cached;
        }

        if (is_numeric($value)) {
            return $this->numericOrDate((float) $value, $dateSensitive);
        }

        return [
            'value' => null,
            'text' => is_string($value) ? $value : (string) $value,
            'date' => null,
            'type' => self::TYPE_TEXT,
        ];
    }

    /**
     * Angka biasa, atau tanggal bila dateSensitive dan angkanya bulat di dalam
     * rentang serial tanggal yang wajar.
     *
     * @return array{value: ?float, text: ?string, date: ?string, type: string}
     */
    private function numericOrDate(float $number, bool $dateSensitive): array
    {
        if ($dateSensitive
            && floor($number) === $number
            && $number >= self::EXCEL_DATE_MIN
            && $number <= self::EXCEL_DATE_MAX) {
            return [
                'value' => null,
                'text' => null,
                'date' => Date::excelToDateTimeObject((int) $number)->format('Y-m-d'),
                'type' => self::TYPE_DATE,
            ];
        }

        return ['value' => $number, 'text' => null, 'date' => null, 'type' => self::TYPE_NUMERIC];
    }

    /**
     * Baca bobot (kolom F): raw dipertahankan apa adanya, value hanya bila
     * benar-benar numerik.
     *
     * Band seperti "Max -3" TIDAK diubah jadi batas: "Max -3" bisa berarti
     * pengurangan maksimum 3 (rentang -3..0) atau nilai maksimum -3, jadi
     * bobot_min/bobot_max dibiarkan null alih-alih mengarang batas.
     *
     * @return array{raw: ?string, value: ?float}
     */
    private function bobot(Cell $cell): array
    {
        $raw = $cell->getValue();

        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return ['raw' => null, 'value' => null];
        }

        if (is_string($raw) && str_starts_with($raw, '=')) {
            $cached = $cell->getOldCalculatedValue();

            return ['raw' => $raw, 'value' => is_numeric($cached) ? (float) $cached : null];
        }

        return [
            'raw' => is_string($raw) ? $raw : (string) $raw,
            'value' => is_numeric($raw) ? (float) $raw : null,
        ];
    }

    /**
     * Polaritas dari aggregation code. Hanya 1 dan 3 yang punya bukti di
     * formula pencapaian (IF($C=3, L/K, IF($C=1, 2-(L/K)))); nilai lain
     * dibiarkan null sampai blocker "apakah C hanya 1/3" diputus.
     */
    private function polarity(?string $code): ?string
    {
        return match ($code) {
            '3' => self::POLARITY_HIGHER_IS_BETTER,
            '1' => self::POLARITY_LOWER_IS_BETTER,
            default => null,
        };
    }

    /**
     * Klasifikasi jenis formula PENCAPAIAN dari teks formula (kolom yang
     * ditunjuk header PENCAPAIAN). Hanya pola yang punya bukti di workbook
     * sampel yang dikenali; selain itu null (jangan mengarang).
     *
     * date_delta dikenali dari SELISIH REALISASI - TARGET pada baris itu
     * sendiri, bukan dari huruf kolom:
     * - absolut  : `$L14-$K14` (MES/TEK/RSL/RST)
     * - relatif  : `L33-K33`   (TJBT M33)
     * Kolom target/realisasi diambil dari header sheet ($targetColumn /
     * $realisasiColumn), jadi `L-K` yang bukan selisih tanggal tetap null.
     * Hanya urutan REALISASI - TARGET yang diterima: itulah satu-satunya
     * bentuk yang terbukti di workbook. Urutan sebaliknya = skor negatif,
     * tidak punya bukti, jadi sengaja tidak diklasifikasikan.
     *
     * @param  mixed  $formula  nilai mentah sel pencapaian
     * @param  int  $row  nomor baris yang sedang dibaca
     * @param  string  $targetColumn  kolom TARGET hasil deteksi header
     * @param  string  $realisasiColumn  kolom REALISASI hasil deteksi header
     */
    private function pencapaianFormulaKind(
        mixed $formula,
        int $row,
        string $targetColumn,
        string $realisasiColumn,
    ): ?string {
        if (! is_string($formula) || ! str_starts_with($formula, '=')) {
            return null;
        }

        if (str_contains($formula, 'AVERAGE(')) {
            return self::PENCAPAIAN_FORMULA_AVERAGE;
        }

        if ($this->isDateDeltaFormula($formula, $row, $targetColumn, $realisasiColumn)) {
            return self::PENCAPAIAN_FORMULA_DATE_DELTA;
        }

        if (preg_match('/\$C\d+\s*=\s*[13]/', $formula) === 1) {
            return self::PENCAPAIAN_FORMULA_POLARITY_RATIO;
        }

        return null;
    }

    /**
     * Formula memuat `<realisasi><row> - <target><row>` (absolut atau relatif).
     * Pemisah `-` tidak boleh direktif `$` milik kolom berikutnya; `\s*`
     * sebelum `-` sudah menolak kasus itu (cell ref tidak diakhiri spasi).
     */
    private function isDateDeltaFormula(
        string $formula,
        int $row,
        string $targetColumn,
        string $realisasiColumn,
    ): bool {
        $reference = fn (string $column): string => sprintf(
            '\$?%s\$?%d(?!\d)',
            preg_quote($column, '/'),
            $row,
        );

        $pattern = sprintf('/%s\s*-\s*%s/', $reference($realisasiColumn), $reference($targetColumn));

        return preg_match($pattern, $formula) === 1;
    }

    /**
     * Baris punya nomor di kolom A (indikator bernomor), bukan anak.
     */
    private function hasRowNumber(mixed $value): bool
    {
        return $value !== null && ! (is_string($value) && trim($value) === '');
    }

    /**
     * Nilai sel sebagai string (untuk code/bobot), null bila kosong.
     */
    private function rawString(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }

    /**
     * Nilai sel sebagai teks apa adanya (untuk satuan/formula source), null
     * bila kosong. Tidak di-trim supaya teks sumber tidak berubah.
     */
    private function rawText(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }

    /**
     * Sel pada kolom opsional (mis. PENCAPAIAN/KET yang bisa tidak ada).
     */
    private function columnCell(Worksheet $sheet, ?string $column, int $row): ?Cell
    {
        return $column === null ? null : $sheet->getCell($column.$row);
    }

    /**
     * Nilai mentah sel pada kolom opsional.
     */
    private function columnValue(Worksheet $sheet, ?string $column, int $row): mixed
    {
        return $column === null ? null : $sheet->getCell($column.$row)->getValue();
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
