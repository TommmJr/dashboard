<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceObservation;
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
     * Baris yang TIDAK boleh jadi indikator/observation meski extractStructured()
     * mengeluarkannya sebagai baris biasa.
     *
     * - "NILAI KINERJA ORGANISASI (NKO)": hasil turunan kinerja organisasi per
     *   unit, bukan KPI. Converter sengaja tetap mengeluarkannya (baris 4A), jadi
     *   penyaringnya ada di sini. F40=100 adalah konstanta skala NKO, bukan bobot
     *   indikator, jadi baris ini tidak boleh ikut terhitung sebagai KPI.
     *
     * Total bobot ("TOTAL BOBOT") tidak perlu disebut di sini: blocklist
     * NON_INDICATOR_LABELS di converter sudah menolaknya lebih dulu.
     */
    private const EXCLUDED_LABEL_PATTERN = '/NILAI KINERJA ORGANISASI|\(NKO\)|\bNKO\b/iu';

    /**
     * Konstanta turunan dari extractStructured() yang tidak boleh dihitung
     * sebagai nilai KPI bila muncul sebagai label indikator nyata. Saat ini
     * tidak ada di workbook terverifikasi; dijaga supaya varian berkas lama
     * tidak menyeretnya masuk.
     */
    private const TOTAL_BOBOT_PATTERN = '/TOTAL\s+BOBOT/iu';

    public function __construct(private readonly KpiReportConverter $converter) {}

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

            foreach ($rows as $row) {
                $unitName = $row['org_unit'];
                $label = trim((string) $row['label']);

                if ($this->isExcluded($label)) {
                    $counts['excluded']++;

                    continue;
                }

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
                $parentId = $row['parent_row'] !== null
                    ? ($indicatorByRow[$unitName][$row['parent_row']] ?? null)
                    : null;

                // Identitas indikator = (org_unit_id, name). parent_row yang
                // tidak ketemu dibiarkan null, BUKAN ditebak dari bobot atau
                // dari baris di atasnya.
                $indicator = Indicator::updateOrCreate(
                    ['org_unit_id' => $orgUnitId, 'name' => $label],
                    [
                        'parent_id' => $parentId,
                        'aggregation_code' => $row['aggregation_code'],
                        'polarity' => $row['polarity'],
                        'formula' => $row['formula'],
                        'satuan' => $row['satuan'],
                        // Normalisasi satuan masih blocker bisnis (blm ada
                        // daftar otoritatif) -> dibiarkan null, bukan dikarang.
                        'satuan_normalized' => null,
                        'bobot' => $row['bobot'],
                        'bobot_value' => $row['bobot_value'],
                        'bobot_min' => $row['bobot_min'],
                        'bobot_max' => $row['bobot_max'],
                        'is_group' => $row['is_group'],
                        'pencapaian_formula_kind' => $row['pencapaian_formula_kind'],
                    ],
                );

                if ($indicator->wasRecentlyCreated) {
                    $counts['indicators']++;
                }

                $indicatorByRow[$unitName][$row['row']] = $indicator->id;

                // Kunci unik (dataset, indicator, org_unit, period) = satu baris
                // per KPI per unit per periode. Impor ulang berkas yang sama
                // karena itu memperbarui, bukan menggandakan.
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

    private function isExcluded(string $label): bool
    {
        if (preg_match(self::EXCLUDED_LABEL_PATTERN, $label) === 1) {
            return true;
        }

        return preg_match(self::TOTAL_BOBOT_PATTERN, $label) === 1;
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
