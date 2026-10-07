<?php

namespace App\Services;

use App\Models\Dataset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pembacaan NKO dari read model TERSTRUKTUR
 *
 * Sumber kebenaran NKO ada di tabel terstruktur, bukan di
 * performance_metrics:
 *
 *   indicators.category = 'nko'    -> satu indikator NKO per org_unit
 *   performance_observations.nilai -> nilai NKO MENTAH dari workbook
 *
 * Helper ini TIDAK membaca baris NKO legacy sama sekali. Jalur legacy tetap
 * hidup berdampingan sampai exit criteria deprecation terpenuhi.
 *
 * Aturan "data terbaru" (ratifikasi Pak Gama: "data terbaru yang
 * divisualisasikan") diterjemahkan teknis sebagai:
 *
 *   READY + non-ARCHIVED + divisi sama + periode efektif sama,
 *   urut datasets.created_at DESC, lalu datasets.id DESC.
 *
 * created_at di sini HANYA kriteria urut teknis, BUKAN klaim waktu terbit PLN.
 * Non-ARCHIVED tidak perlu filter terpisah: status adalah satu enum, jadi
 * `= READY` sudah mengecualikan ARCHIVED.
 *
 * Konsekuensi yang disengaja: pemilihan dataset terbaru mempersempit hasil ke
 * SATU dataset per (divisi, periode). Kalau beberapa dataset READY memuat unit
 * + periode yang sama, hanya yang terbaru yang tampil — legacy menampilkan
 * semuanya (R1 lewat MAX, R3 apa adanya). Divergensi ini diuji eksplisit,
 * bukan diklaim setara.
 */
class StructuredNkoReader
{
    private const NKO_CATEGORY = KpiReportConverter::INDICATOR_CATEGORY_NKO;

    /**
     * Baris NKO per unit pada dataset TERBARU, untuk satu divisi + periode.
     *
     * Dipakai R3/R10 yang bekerja pada satu periode. Urutan menurun mengikuti
     * nilai NKO supaya bentuknya sama dengan jalur legacy.
     *
     * @return Collection<int, array{unit: string, nko: float, dataset_id: int}>
     */
    public function latestForPeriod(?string $divisionId, string $period): Collection
    {
        return $this->nkoQuery($divisionId, $period)
            ->orderByDesc('performance_observations.nilai')
            ->get()
            ->map(fn (object $row): array => [
                'unit' => (string) $row->unit,
                'nko' => (float) $row->nilai,
                'dataset_id' => (int) $row->dataset_id,
            ]);
    }

    /**
     * Baris NKO per unit pada dataset TERBARU, per periode, untuk semua periode
     * dalam rentang. Dipakai R1 yang keluarannya deret waktu (satu titik per
     * periode), bukan satu periode saja.
     *
     * Pemilihan dataset terbaru dilakukan PER PERIODE: dataset yang lebih baru
     * pada Agustus tidak boleh menghapus titik Juli hanya karena lebih baru.
     *
     * @return Collection<int, array{period: string, unit: string, nko: float}>
     */
    public function latestPerPeriod(?string $divisionId, ?string $startDate = null, ?string $endDate = null): Collection
    {
        $rows = collect();

        foreach ($this->periodsWithNko($divisionId, $startDate, $endDate) as $period) {
            $rows = $rows->concat(
                $this->nkoQuery($divisionId, $period)
                    ->get()
                    ->map(fn (object $row): array => [
                        'period' => $period,
                        'unit' => (string) $row->unit,
                        'nko' => (float) $row->nilai,
                    ]),
            );
        }

        return $rows;
    }

    /**
     * Periode NKO terbaru di dalam rentang, tanpa memperhatikan versi dataset.
     *
     * Bentuk ini sengaja kembar dengan legacy: legacy memilih periode lewat
     * `max(period)` pada basis NKO, SEBELUM agregasi. Jadi pemilihan periode
     * tidak boleh ikut tersaring aturan dataset terbaru; kalau ikut, satu
     * periode bisa hilang hanya karena versi terbarunya kebetulan tanpa NKO.
     */
    public function latestNkoPeriod(?string $divisionId, ?string $startDate = null, ?string $endDate = null): ?string
    {
        $period = DB::table('performance_observations')
            ->join('indicators', 'indicators.id', '=', 'performance_observations.indicator_id')
            ->join('datasets', 'datasets.id', '=', 'performance_observations.dataset_id')
            ->where('indicators.category', self::NKO_CATEGORY)
            ->where('datasets.status', Dataset::STATUS_READY)
            ->when($divisionId !== null, fn ($q) => $q->where('performance_observations.division_id', $divisionId))
            ->when($startDate !== null, fn ($q) => $q->where('performance_observations.period', '>=', $startDate))
            ->when($endDate !== null, fn ($q) => $q->where('performance_observations.period', '<=', $endDate))
            ->max('performance_observations.period');

        return $period === null ? null : (string) $period;
    }

    /**
     * ID dataset TERBARU per divisi untuk satu periode, tanpa memandang jenis
     * indikator. Pemilihan dataset tidak boleh bergantung pada ada/tidaknya
     * baris NKO: satu dataset adalah satu versi, isinya apa pun.
     *
     * Dipakai juga oleh reader yang butuh "dataset otoritatif" (mis. R7).
     *
     * @return list<int>
     */
    public function latestDatasetIds(?string $divisionId, string $period): array
    {
        return DB::table('performance_observations as po')
            ->join('datasets as d', 'd.id', '=', 'po.dataset_id')
            ->where('d.status', Dataset::STATUS_READY)
            ->where('po.period', $period)
            ->when($divisionId !== null, fn ($q) => $q->where('po.division_id', $divisionId))
            ->whereNotExists(function ($q) use ($period, $divisionId) {
                $q->select(DB::raw(1))
                    ->from('performance_observations as newer')
                    ->join('datasets as nd', 'nd.id', '=', 'newer.dataset_id')
                    ->where('nd.status', Dataset::STATUS_READY)
                    ->where('newer.period', $period)
                    ->where(function ($sameScope) {
                        // Kesetaraan divisi NULL-safe. `newer.division_id = po.division_id`
                        // bernilai UNKNOWN saat salah satu sisi NULL, jadi baris tanpa
                        // divisi tidak pernah dianggap tersupersede dan SEMUA dataset-nya
                        // lolos sebagai "terbaru". NULL harus jadi satu scope yang setara.
                        // Ditulis sebagai OR + IS NULL supaya portabel MySQL 8 dan SQLite
                        // (operator `<=>` MySQL tidak ada di SQLite).
                        $sameScope->whereColumn('newer.division_id', 'po.division_id')
                            ->orWhere(function ($bothNull) {
                                $bothNull->whereNull('newer.division_id')
                                    ->whereNull('po.division_id');
                            });
                    })
                    ->when($divisionId !== null, fn ($sub) => $sub->where('newer.division_id', $divisionId))
                    ->where(function ($better) {
                        // Dua kolom-ke-kolom, lalu tie-break id: created_at yang
                        // sama tetap terurut total, jadi tidak ada ambiguitas.
                        $better->whereColumn('nd.created_at', '>', 'd.created_at')
                            ->orWhere(function ($tie) {
                                $tie->whereColumn('nd.created_at', '=', 'd.created_at')
                                    ->whereColumn('nd.id', '>', 'd.id');
                            });
                    });
            })
            ->distinct()
            ->pluck('d.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Periode-periode yang punya baris NKO di divisi ini, urut menaik.
     *
     * @return list<string>
     */
    private function periodsWithNko(?string $divisionId, ?string $startDate, ?string $endDate): array
    {
        return DB::table('performance_observations')
            ->join('indicators', 'indicators.id', '=', 'performance_observations.indicator_id')
            ->where('indicators.category', self::NKO_CATEGORY)
            ->whereNotNull('performance_observations.dataset_id')
            ->when($divisionId !== null, fn ($q) => $q->where('performance_observations.division_id', $divisionId))
            ->when($startDate !== null, fn ($q) => $q->where('performance_observations.period', '>=', $startDate))
            ->when($endDate !== null, fn ($q) => $q->where('performance_observations.period', '<=', $endDate))
            ->distinct()
            ->orderBy('performance_observations.period')
            ->pluck('performance_observations.period')
            ->map(fn ($period): string => (string) $period)
            ->all();
    }

    /**
     * Query baris NKO pada dataset TERBARU untuk satu periode.
     *
     * `dataset_id` wajib NOT NULL: baris tanpa dataset tidak punya otoritas
     * versi, jadi tidak bisa ikut aturan "data terbaru". Baris legacy tanpa
     * dataset tetap tampil di jalur legacy — itu divergensi yang disengaja.
     */
    private function nkoQuery(?string $divisionId, string $period)
    {
        $latestIds = $this->latestDatasetIds($divisionId, $period);

        return DB::table('performance_observations')
            ->join('indicators', 'indicators.id', '=', 'performance_observations.indicator_id')
            ->join('org_units', 'org_units.id', '=', 'performance_observations.org_unit_id')
            ->where('indicators.category', self::NKO_CATEGORY)
            ->where('performance_observations.period', $period)
            ->whereNotNull('performance_observations.dataset_id')
            ->when($divisionId !== null, fn ($q) => $q->where('performance_observations.division_id', $divisionId))
            ->whereIn('performance_observations.dataset_id', $latestIds)
            ->select(
                'org_units.name as unit',
                'performance_observations.nilai',
                'performance_observations.dataset_id',
            );
    }
}
