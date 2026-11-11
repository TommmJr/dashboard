<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceObservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Jalur tulis terstruktur (Unit 4B): hasil extractStructured() -> org_units,
 * indicators, performance_observations.
 *
 * Ini DUAL-WRITE, bukan migrasi: performance_metrics tetap diisi jalur legacy
 * (DatasetImportService) seperti sebelumnya. Kedua sisi tidak digabung dan
 * tidak saling menggantikan — tabel struktural adalah tabel baru yang hidup
 * berdampingan sampai exit criteria deprecation.
 *
 * Kelas ini BUKAN reader XLSX. Satu-satunya sumber baris adalah
 * KpiReportConverter::extractStructured(), yang dipanggil di sini. Tidak ada
 * pembacaan sel kedua: kalau ekstraksi berubah, perubahan itu hanya ada di
 * converter.
 *
 * Prinsip loss-aware: nilai bertipe dari sumber (numeric/text/date) disalin
 * apa adanya ke kolom pasangannya. Tidak ada konversi diam-diam antara tipe,
 * dan tidak ada angka yang dikarang (mis. bobot band "Max -3" tetap
 * bobot_value=NULL).
 */
class StructuredKpiImporter
{
    /**
     * Penanda baris NKO dipakai lewat KpiReportConverter::NKO_LABEL supaya
     * deteksi di sini identik dengan deteksi di converter.
     *
     * Baris NKO TIDAK lagi dibuang. Ia masuk tabel
     * terstruktur sebagai indikator berkategori 'nko', satu per unit, dengan
     * nilai mentah workbook di `nilai` dan bobot NULL.
     */
    public function __construct(private readonly KpiReportConverter $converter) {}

    /**
     * Nama bulan (ID + EN) yang muncul di banner periode sheet -> nomor bulan.
     * Dipakai HANYA untuk membandingkan banner dengan periode efektif; banner
     * mentahnya tetap disimpan apa adanya di `sheet_period`.
     */
    private const MONTH_NUMBERS = [
        'JANUARI' => '01', 'FEBRUARI' => '02', 'MARET' => '03', 'APRIL' => '04',
        'MEI' => '05', 'JUNI' => '06', 'JULI' => '07', 'AGUSTUS' => '08',
        'SEPTEMBER' => '09', 'OKTOBER' => '10', 'NOVEMBER' => '11', 'DESEMBER' => '12',
        'JANUARY' => '01', 'FEBRUARY' => '02', 'MARCH' => '03', 'MAY' => '05',
        'JUNE' => '06', 'JULY' => '07', 'AUGUST' => '08', 'OCTOBER' => '10',
        'DECEMBER' => '12', 'AGUST' => '08', 'SEPT' => '09', 'OKT' => '10',
        'JAN' => '01', 'FEB' => '02', 'MAR' => '03', 'APR' => '04',
        'JUN' => '06', 'JUL' => '07', 'AUG' => '08', 'SEP' => '09',
        'OCT' => '10', 'NOV' => '11', 'DEC' => '12', 'AGU' => '08', 'DES' => '12',
    ];

    /**
     * Apakah label ini baris NKO (agregat level unit, bukan indikator KPI).
     *
     * Substring "NKO" saja TIDAK cukup: label seperti "JUMLAH NKO GANGGUAN"
     * atau "NKO TEKNIS" juga memuat "NKO" tetapi itu indikator nyata, bukan
     * baris agregat. Karena itu pencocokannya sempit:
     *
     *   - label memuat penanda kanonis "NILAI KINERJA ORGANISASI"; atau
     *   - label PERSIS varian pendek "NKO" / "(NKO)".
     *
     * Varian panjang tetap terdeteksi lewat penanda kanonis, sehingga
     * "NILAI KINERJA ORGANISASI (NKO)" juga mengerucut ke satu identitas.
     */
    private function isNkoIndicator(string $label): bool
    {
        $normalized = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $label)));

        if (str_contains($normalized, KpiReportConverter::NKO_LABEL)) {
            return true;
        }

        return in_array($normalized, ['NKO', '(NKO)'], true);
    }

    /**
     * Identitas tersimpan untuk satu baris sumber.
     *
     * Nama indikator = kolom B apa adanya, KECUALI baris NKO: varian labelnya
     * dikerucutkan ke satu nama kanonis supaya "NILAI KINERJA ORGANISASI",
     * "NILAI KINERJA ORGANISASI (NKO)", dan "NKO" tidak membuat identitas
     * berbeda untuk unit yang sama (unique key-nya (org_unit_id, name)).
     *
     * @return array{name: string, category: ?string}
     */
    private function indicatorIdentity(string $label): array
    {
        if ($this->isNkoIndicator($label)) {
            return ['name' => KpiReportConverter::NKO_LABEL, 'category' => KpiReportConverter::INDICATOR_CATEGORY_NKO];
        }

        return ['name' => $label, 'category' => null];
    }

    /**
     * Normalisasi nama indikator untuk toleransi unicode whitespace (\p{Z}, non-breaking space)
     * dan perataan multiple internal spaces.
     */
    private function normalizeIndicatorName(string $name): string
    {
        $normalized = preg_replace('/[\s\p{Z}]+/u', ' ', $name) ?? $name;

        return trim($normalized);
    }

    /**
     * Kunci lookup indikator ternormalisasi (case-insensitive & whitespace trimmed/collapsed)
     * agar selaras dengan collation utf8mb4_unicode_ci pada skema database.
     */
    private function indicatorLookupKey(int|string $orgUnitId, string $name): string
    {
        return $orgUnitId."\0".mb_strtolower($this->normalizeIndicatorName($name), 'UTF-8');
    }

    /**
     * Tulis satu dataset KPI ke tabel struktural.
     *
     * Mengembalikan hitungan supaya pemanggil bisa melaporkan apa yang terjadi
     * tanpa membaca ulang DB. Tidak ada exception yang ditelan di sini: pemanggil
     * (controller) yang memutuskan apakah kegagalan tulis terstruktur boleh
     * menggagalkan impor legacy.
     *
     * @return array{org_units: int, indicators: int, observations: int, excluded: int}
     */
    public function import(string $sourcePath, Dataset $dataset, string $period): array
    {
        $rows = $this->converter->extractStructured($sourcePath, $period);

        return DB::transaction(function () use ($rows, $dataset, $period) {
            $counts = ['org_units' => 0, 'indicators' => 0, 'observations' => 0, 'excluded' => 0];
            // Peta row -> indicator_id per unit, untuk meresolusi parent_row.
            $indicatorByRow = [];
            $units = [];

            // Pre-fetch periode observasi READY maksimum per indicator_id
            // untuk melindungi metadata dari penimpaan mundur (chronological guard).
            $maxReadyPeriods = $this->maxReadyPeriods($dataset);

            // Pre-fetch seluruh indikator eksisting ke memori untuk lookup O(1) bebas N+1 query.
            /** @var Collection<string, Indicator> $existingIndicators */
            $existingIndicators = Indicator::all()->keyBy(
                fn (Indicator $i): string => $this->indicatorLookupKey($i->org_unit_id, $i->name)
            );

            foreach ($rows as $row) {
                $unitName = $row['org_unit'];
                $label = trim((string) $row['label']);
                $isNko = $this->isNkoIndicator($label);

                if ($this->isBlankRow($row) && ! $row['is_group']) {
                    // Kop laporan / baris judul divisi: B terisi tetapi tanpa
                    // satu pun nilai KPI. Bukan indikator, jadi tidak ditulis.
                    // Baris GRUP tanpa nilai tetap ditulis: ia wadah hierarki
                    // yang dibutuhkan anak-anaknya, bukan baris kosong.
                    $counts['excluded']++;

                    continue;
                }

                if (! isset($units[$unitName])) {
                    // Nama unit = judul sheet, verbatim (unique di skema).
                    $unit = OrgUnit::firstOrCreate(['name' => $unitName]);
                    $units[$unitName] = $unit->id;
                    $indicatorByRow[$unitName] = [];

                    if ($unit->wasRecentlyCreated) {
                        $counts['org_units']++;
                    }
                }

                $orgUnitId = $units[$unitName];
                $identity = $this->indicatorIdentity($label);
                $parentId = $row['parent_row'] !== null
                    ? ($indicatorByRow[$unitName][$row['parent_row']] ?? null)
                    : null;

                // Identitas indikator = (org_unit_id, name). parent_row yang
                // tidak ketemu dibiarkan null, BUKAN ditebak dari bobot atau
                // dari baris di atasnya.
                //
                // Baris NKO tidak punya jenjang: `is_group` false, tanpa induk,
                // dan metadata indikator lain dikosongkan. F40/F21=100 di
                // workbook adalah konstanta skala NKO, jadi bobot WAJIB null
                // (tidak boleh terbaca sebagai bobot indikator).
                $indicatorAttributes = [
                    'parent_id' => $isNko ? null : $parentId,
                    'aggregation_code' => $isNko ? null : $row['aggregation_code'],
                    'polarity' => $isNko ? null : $row['polarity'],
                    'formula' => $isNko ? null : $row['formula'],
                    'satuan' => $isNko ? null : $row['satuan'],
                    // Normalisasi satuan masih blocker bisnis (blm ada
                    // daftar otoritatif) -> dibiarkan null, bukan dikarang.
                    'satuan_normalized' => null,
                    'bobot' => $isNko ? null : $row['bobot'],
                    'bobot_value' => $isNko ? null : $row['bobot_value'],
                    'bobot_min' => $isNko ? null : $row['bobot_min'],
                    'bobot_max' => $isNko ? null : $row['bobot_max'],
                    'is_group' => $isNko ? false : $row['is_group'],
                    'pencapaian_formula_kind' => $isNko ? null : $row['pencapaian_formula_kind'],
                    'category' => $identity['category'],
                ];

                $lookupKey = $this->indicatorLookupKey($orgUnitId, $identity['name']);
                $indicator = $existingIndicators->get($lookupKey);

                if ($indicator === null) {
                    $indicator = Indicator::create([
                        'org_unit_id' => $orgUnitId,
                        'name' => $this->normalizeIndicatorName($identity['name']),
                        ...$indicatorAttributes,
                    ]);

                    $counts['indicators']++;
                    $existingIndicators->put($lookupKey, $indicator);
                } else {
                    // Chronological Guard: hanya perbarui metadata jika periode dataset
                    // yang sedang diimpor sama dengan atau lebih baru daripada periode
                    // observasi READY yang sudah ada. Jika mengimpor file lampau/revisi,
                    // jangan timpa metadata indikator periode terbaru.
                    $maxPeriod = $maxReadyPeriods[$indicator->id] ?? null;
                    $shouldUpdateMetadata = ($maxPeriod === null || $period >= $maxPeriod);

                    if ($shouldUpdateMetadata) {
                        $indicator->update($indicatorAttributes);
                    }
                }

                $indicatorByRow[$unitName][$row['row']] = $indicator->id;

                // Kunci unik (dataset, indicator, org_unit, period) = satu baris
                // per KPI per unit per periode. Impor ulang berkas yang sama
                // karena itu memperbarui, bukan menggandakan. NKO memakai
                // identitas kanonis, jadi idempotensinya sama seperti KPI lain.
                $observation = PerformanceObservation::updateOrCreate(
                    [
                        'dataset_id' => $dataset->id,
                        'indicator_id' => $indicator->id,
                        'org_unit_id' => $orgUnitId,
                        'period' => $period,
                    ],
                    [
                        'division_id' => $dataset->division_id,
                        ...$this->observationValues($row),
                        'source_sheet' => $unitName,
                        'source_row' => $row['row'],
                        'sheet_period' => $row['sheet_period'] ?? null,
                        'period_conflict' => $this->periodConflicts($row['sheet_period'] ?? null, $period),
                    ],
                );

                if ($observation->wasRecentlyCreated) {
                    $counts['observations']++;
                }
            }

            return $counts;
        });
    }

    /**
     * Peta indicator_id -> MAX(period) dari observasi dataset berstatus READY,
     * mengecualikan dataset yang sedang diproses. Dataset FAILED dan ARCHIVED
     * diabaikan dari penentuan batas waktu aktif.
     *
     * @return array<int, string>
     */
    private function maxReadyPeriods(Dataset $currentDataset): array
    {
        return DB::table('performance_observations as po')
            ->join('datasets as d', 'd.id', '=', 'po.dataset_id')
            ->where('d.status', Dataset::STATUS_READY)
            ->where('d.id', '<>', $currentDataset->id)
            ->groupBy('po.indicator_id')
            ->select('po.indicator_id', DB::raw('MAX(po.period) as max_period'))
            ->pluck('max_period', 'po.indicator_id')
            ->map(fn ($period): string => substr((string) $period, 0, 10))
            ->all();
    }

    /**
     * Baris tanpa satu pun nilai KPI. Dipakai untuk membuang baris kop laporan
     * (judul, nama direktorat, nama divisi) yang lolos filter label converter.
     */
    private function isBlankRow(array $row): bool
    {
        foreach (['target', 'realisasi', 'pencapaian', 'nilai', 'ket'] as $field) {
            if ($row[$field]['type'] !== PerformanceObservation::TYPE_EMPTY) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apakah periode form dan banner sheet berbeda.
     *
     * Periode form adalah periode EFEKTIF (yang tersimpan di `period`); banner
     * hanya provenance. Konflik dicatat, BUKAN menolak import.
     *
     * Banner adalah teks bebas ("S.D. AGUSTUS 2026"), jadi ia hanya dianggap
     * konflik bila bisa dipetakan ke bulan+tahun DAN hasilnya beda. Banner yang
     * tidak terbaca tidak diklaim konflik: tidak ada bukti perbedaan, dan
     * menandai konflik tanpa bukti sama saja mengarang.
     */
    private function periodConflicts(?string $sheetPeriod, string $effectivePeriod): bool
    {
        $banner = $this->parseSheetPeriod($sheetPeriod);

        if ($banner === null) {
            return false;
        }

        return $banner !== substr($effectivePeriod, 0, 7);
    }

    /**
     * Petakan banner periode sheet ke 'Y-m', atau null bila tidak terbaca.
     *
     * Nama bulan Indonesia dan Inggris diterima karena banner berasal dari
     * workbook yang bisa memakai keduanya. Pencocokan kata penuh (\b) supaya
     * "JANUARI" tidak salah dikenali sebagai "JAN".
     */
    private function parseSheetPeriod(?string $banner): ?string
    {
        if ($banner === null || trim($banner) === '') {
            return null;
        }

        $normalized = mb_strtoupper($banner);

        if (preg_match('/(\d{4})/', $normalized, $year) !== 1) {
            return null;
        }

        foreach (self::MONTH_NUMBERS as $name => $number) {
            if (preg_match('/\b'.$name.'\b/', $normalized) === 1) {
                return $year[1].'-'.$number;
            }
        }

        return null;
    }

    /**
     * Terjemahkan nilai bertipe converter ke kolom observation. Setiap tipe
     * mengisi kolomnya sendiri; tipe lain dibiarkan null sehingga representasi
     * sumber tidak pernah tertimpa.
     *
     * @return array<string, mixed>
     */
    private function observationValues(array $row): array
    {
        return [
            ...$this->typedColumns('target', $row['target']),
            ...$this->typedColumns('realisasi', $row['realisasi']),
            'pencapaian' => $this->number($row['pencapaian']),
            'nilai' => $this->number($row['nilai']),
            'ket' => $this->text($row['ket']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function typedColumns(string $prefix, array $typed): array
    {
        return [
            "{$prefix}_value" => $this->number($typed),
            "{$prefix}_text" => $this->text($typed),
            "{$prefix}_date" => $this->date($typed),
            "{$prefix}_type" => $typed['type'],
        ];
    }

    private function number(array $typed): ?float
    {
        return $typed['type'] === PerformanceObservation::TYPE_NUMERIC
            ? (float) $typed['value']
            : null;
    }

    private function text(array $typed): ?string
    {
        // Hanya representasi teks sumber yang masuk kolom _text. Tanggal tidak
        // punya teks mentah terpisah (serial sudah dikonversi converter), jadi
        // _text tetap null untuk baris tanggal.
        return $typed['type'] === PerformanceObservation::TYPE_TEXT
            ? (string) $typed['text']
            : null;
    }

    private function date(array $typed): ?string
    {
        if ($typed['type'] !== PerformanceObservation::TYPE_DATE) {
            return null;
        }

        if ($typed['date'] === null) {
            throw new RuntimeException(
                'Nilai bertipe date tanpa tanggal dari converter — periksa extractStructured().'
            );
        }

        return (string) $typed['date'];
    }
}
