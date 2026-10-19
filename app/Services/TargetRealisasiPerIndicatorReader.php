<?php

namespace App\Services;

use App\Models\PerformanceObservation;
use Illuminate\Support\Facades\DB;

/**
 * Panel Target vs Realisasi PER INDIKATOR untuk satu unit tampil.
 *
 * Sumber: performance_observations JOIN indicators/org_units pada dataset
 * TERBARU (StructuredNkoReader::latestDatasetIds) untuk satu periode.
 *
 * ISOLASI (keputusan user 2026-10-19): reader ini SELALU membaca tabel
 * terstruktur dan TIDAK membaca `config('kpi.read_model')`. Widget lama
 * (`targetVsRealisasi` / R8) dan kontrak R5/R6/R8 tidak dipakai maupun
 * diubah. Kalau lapisan terstruktur tidak punya baris, hasilnya kosong dan
 * widget menampilkan empty state.
 *
 * Aturan yang SENGAJA tidak dilakukan:
 * - tidak menjumlah/menggabungkan indikator atau satuan (C-1 tetap terbuka);
 * - tidak menghitung ulang skor dari target/realisasi — `pencapaian` dipakai
 *   apa adanya, hanya SKALANYA yang ditandai.
 *
 * Skala skor (evidence-based, tanpa menebak dari besar-kecilnya angka):
 * - `indicators.pencapaian_formula_kind` = polarity_ratio/average/date_delta
 *   -> formula workbook menghasilkan RASIO 0..1,1 -> `ratio`.
 * - formula tidak terklasifikasi (NULL) TAPI nilai > 1,1 -> `percent`
 *   (batas atas rasio 1,1 terdokumentasi di formula workbook, jadi nilai di
 *   atasnya tidak mungkin rasio; 110 pada id 410 = 110%).
 * - selain itu -> `unverified`: rasio 1,0485 dan persen 1,0485 tidak bisa
 *   dibedakan dari data tersimpan, jadi JANGAN dikonversi (id 403).
 *
 * ponytail: klasifikasi skala bergantung pada kolom formula_kind yang baru
 * terisi pada impor berikutnya (dataset #49 belum di-re-import). Baris lama
 * akan tampil "skor tidak terverifikasi" sampai re-import; itu disengaja,
 * bukan bug. Ganti ke kolom skala eksplisit bila importer menyimpannya.
 */
class TargetRealisasiPerIndicatorReader
{
    public function __construct(private readonly StructuredNkoReader $structuredNko) {}

    /**
     * @param  string|null  $divisionId  null = lintas divisi (role privileged)
     * @param  list<string>|null  $visibleUnits  null = tanpa pembatasan unit
     * @return array{
     *     period: string,
     *     units: list<string>,
     *     sections: list<array{unit: string, indicator_id: int|null, name: string|null, panels: list<array<string, mixed>>}>
     * }
     */
    public function panels(?string $divisionId, string $period, ?array $visibleUnits = null): array
    {
        $latestIds = $this->structuredNko->latestDatasetIds($divisionId, $period);

        if ($latestIds === []) {
            return ['period' => $period, 'units' => [], 'sections' => []];
        }

        $rows = DB::table('performance_observations as po')
            ->join('indicators as i', 'i.id', '=', 'po.indicator_id')
            ->join('org_units as u', 'u.id', '=', 'po.org_unit_id')
            ->whereIn('po.dataset_id', $latestIds)
            ->where('po.period', $period)
            ->when($divisionId !== null, fn ($q) => $q->where('po.division_id', $divisionId))
            ->where(function ($q) {
                $q->whereNull('i.category')
                    ->orWhere('i.category', '<>', KpiReportConverter::INDICATOR_CATEGORY_NKO);
            })
            ->when($visibleUnits !== null, fn ($q) => $q->whereIn('u.name', $visibleUnits))
            ->orderBy('po.org_unit_id')
            ->orderBy('i.id')
            ->select(
                'po.indicator_id',
                'po.target_type',
                'po.realisasi_type',
                'po.target_value',
                'po.realisasi_value',
                'po.target_date',
                'po.realisasi_date',
                'po.pencapaian',
                'po.period_conflict',
                'i.name',
                'i.parent_id',
                'i.is_group',
                'i.satuan',
                'i.pencapaian_formula_kind',
                'u.name as unit',
            )
            ->get();

        $groups = $rows->filter(fn (object $row): bool => (bool) $row->is_group)
            ->keyBy(fn (object $row): int => (int) $row->indicator_id);

        $sections = [];
        $units = [];

        foreach ($rows as $row) {
            $panel = $this->panel($row);

            if ($panel === null) {
                continue;
            }

            $unit = (string) $row->unit;
            $units[$unit] = true;

            $parentId = $row->parent_id === null ? null : (int) $row->parent_id;
            $group = $parentId !== null ? $groups->get($parentId) : null;
            $key = $unit."\0".($group?->indicator_id ?? '');

            if (! isset($sections[$key])) {
                $sections[$key] = [
                    'unit' => $unit,
                    'indicator_id' => $group?->indicator_id === null ? null : (int) $group->indicator_id,
                    'name' => $group?->name === null ? null : (string) $group->name,
                    'panels' => [],
                ];
            }

            $sections[$key]['panels'][] = $panel;
        }

        return [
            'period' => $period,
            'units' => array_keys($units),
            'sections' => array_values($sections),
        ];
    }

    /**
     * Satu baris observasi -> satu panel, atau null bila tidak cukup data.
     *
     * @return array<string, mixed>|null
     */
    private function panel(object $row): ?array
    {
        // Baris grup = judul seksi, bukan indikator individual.
        if ((bool) $row->is_group) {
            return null;
        }

        $targetType = (string) $row->target_type;
        $realisasiType = (string) $row->realisasi_type;

        $base = [
            'indicator_id' => (int) $row->indicator_id,
            'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
            'name' => (string) $row->name,
            'satuan' => $row->satuan === null ? null : (string) $row->satuan,
            'period_conflict' => (bool) $row->period_conflict,
            'numeric' => null,
            'date' => null,
            'score' => $this->score($row),
        ];

        if ($targetType === PerformanceObservation::TYPE_NUMERIC
            && $realisasiType === PerformanceObservation::TYPE_NUMERIC
            && $row->target_value !== null
            && $row->realisasi_value !== null) {
            return array_merge($base, [
                'mode' => 'numeric',
                'numeric' => [
                    'target' => (float) $row->target_value,
                    'realisasi' => (float) $row->realisasi_value,
                ],
            ]);
        }

        // Tanggal: serial mentah TIDAK pernah masuk nilai bar. Yang dipakai
        // hanya kolom *_date (hasil konversi importer), bukan *_value.
        if ($targetType === PerformanceObservation::TYPE_DATE
            || $realisasiType === PerformanceObservation::TYPE_DATE) {
            if ($row->target_date === null && $row->realisasi_date === null) {
                return null;
            }

            return array_merge($base, [
                'mode' => 'date',
                'date' => [
                    'target' => $this->date($row->target_date),
                    'realisasi' => $this->date($row->realisasi_date),
                    'delta_days' => $this->deltaDays($row->target_date, $row->realisasi_date),
                ],
            ]);
        }

        // Skor saja: target/realisasi tidak numerik, tapi workbook punya skor.
        if ($base['score'] !== null) {
            return array_merge($base, ['mode' => 'score_only']);
        }

        return null;
    }

    /**
     * Penanda skala eksplisit; `percent` null bila skala tidak terverifikasi.
     *
     * @return array{raw: float, scale: string, percent: float|null}|null
     */
    private function score(object $row): ?array
    {
        if ($row->pencapaian === null) {
            return null;
        }

        $raw = (float) $row->pencapaian;
        $kind = $row->pencapaian_formula_kind;

        $ratioKinds = [
            KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
            KpiReportConverter::PENCAPAIAN_FORMULA_AVERAGE,
            KpiReportConverter::PENCAPAIAN_FORMULA_DATE_DELTA,
        ];

        if (in_array($kind, $ratioKinds, true)) {
            return ['raw' => $raw, 'scale' => 'ratio', 'percent' => $raw * 100];
        }

        // Nilai di atas batas atas rasio workbook (1,1) tidak mungkin rasio.
        if ($raw > 1.1) {
            return ['raw' => $raw, 'scale' => 'percent', 'percent' => $raw];
        }

        return ['raw' => $raw, 'scale' => 'unverified', 'percent' => null];
    }

    private function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    private function deltaDays(mixed $target, mixed $realisasi): ?int
    {
        if ($target === null || $realisasi === null) {
            return null;
        }

        $targetAt = strtotime(substr((string) $target, 0, 10));
        $realisasiAt = strtotime(substr((string) $realisasi, 0, 10));

        if ($targetAt === false || $realisasiAt === false) {
            return null;
        }

        return (int) round(($realisasiAt - $targetAt) / 86400);
    }
}
