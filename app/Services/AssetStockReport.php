<?php

namespace App\Services;

use App\Models\Dataset;
use App\Support\DatasetFreshness;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Query read-only domain Aset untuk widget ringkas dan halaman detail aset.
 */
class AssetStockReport
{
    /**
     * Daftar 8 UPT kanonis PLN UIT JBT.
     */
    public const CANONICAL_UPTS = [
        'BANDUNG',
        'BEKASI',
        'BOGOR',
        'CIREBON',
        'KARAWANG',
        'PURWOKERTO',
        'SALATIGA',
        'SEMARANG',
    ];

    /**
     * Daftar periode historis (Y-m-d) berurut menurun dari dataset READY domain Aset.
     *
     * @param  int|null  $divisionId  Batasi ke divisi pemilik data; null = lintas divisi.
     * @return list<string>
     */
    public function availablePeriods(?int $divisionId = null): array
    {
        $rawPeriods = DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.metric_name', 'like', AssetStockConverter::PREFIX.'%')
            ->when($divisionId !== null, fn (Builder $query) => $query->where('performance_metrics.division_id', $divisionId))
            ->select('performance_metrics.period')
            ->distinct()
            ->orderByDesc('performance_metrics.period')
            ->pluck('performance_metrics.period');

        $periods = [];
        $seen = [];

        foreach ($rawPeriods as $rawPeriod) {
            $normalized = Carbon::parse((string) $rawPeriod)->format('Y-m-d');
            if (! isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $periods[] = $normalized;
            }
        }

        return $periods;
    }

    /**
     * Validasi periode yang diminta terhadap periode READY yang tersedia; fallback ke terbaru.
     */
    public function resolveRequestedPeriod(?string $requestedPeriod, ?int $divisionId = null): ?string
    {
        $periods = $this->availablePeriods($divisionId);

        if (
            $requestedPeriod !== null
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedPeriod) === 1
            && in_array($requestedPeriod, $periods, true)
        ) {
            return $requestedPeriod;
        }

        return $periods[0] ?? null;
    }

    /**
     * Total per UPT untuk periode tertentu (default: terbaru): SUM(value)
     * GROUP BY UPT + atribut. ULTG di-roll-up karena rekap mentor
     * PER UPT (bukan per ULTG) dan stok fisik (unit, MVA, kms) bersifat
     * aditif selama tidak mencampur atribut berbeda satuan dalam satu
     * series — pemisahan per atribut dijaga sampai ke widget.
     *
     * Hanya dataset READY TERBARU per divisi pada periode itu yang dijumlahkan
     * (lihat latestDatasetIds); dataset lama yang digantikan tidak ikut.
     *
     * @param  string|null  $period  Periode Y-m-d; null = periode terbaru.
     * @param  int|null  $divisionId  Batasi ke divisi pemilik data; null = lintas divisi.
     * @return array{period: ?string, totals: Collection<int, object{upt: string, attribute: string, total: float}&stdClass>}
     */
    public function totalsByUpt(?string $period = null, ?int $divisionId = null): array
    {
        $period ??= $this->baseUptQuery($divisionId)->max('performance_metrics.period');

        if ($period === null) {
            /** @var Collection<int, object{upt: string, attribute: string, total: float}&stdClass> $empty */
            $empty = collect();

            return ['period' => null, 'totals' => $empty];
        }

        $period = Carbon::parse((string) $period)->format('Y-m-d');

        $totals = $this->fetchUptRows($period, $divisionId)
            ->map(function (object $row): object {
                [$domain, $upt, $ultg, $attribute] = explode(' | ', (string) $row->metric_name, 4) + [null, null, null, null];

                return (object) [
                    'upt' => (string) ($upt ?? ''),
                    'attribute' => (string) ($attribute ?? ''),
                    'value' => (float) $row->value,
                ];
            })
            ->filter(fn (object $row): bool => in_array($row->upt, self::CANONICAL_UPTS, true) && $row->attribute !== '')
            ->groupBy(fn (object $row): string => $row->upt."\0".$row->attribute)
            ->map(fn (Collection $items): object => (object) [
                'upt' => (string) $items->first()->upt,
                'attribute' => (string) $items->first()->attribute,
                'total' => (float) $items->sum(fn (object $item): float => $item->value),
            ])
            ->values();

        return ['period' => $period, 'totals' => $totals];
    }

    /**
     * Breakdown aset hingga level ULTG yang dikelompokkan di bawah masing-masing 8 UPT kanonis.
     *
     * Membedakan secara tegas antara 0.0 (data nol yang memang tercatat di DB) dan
     * null (atribut/kategori tidak tercatat pada ULTG bersangkutan).
     *
     * @param  string|null  $period  Periode Y-m-d; null = periode terbaru.
     * @param  int|null  $divisionId  Batasi ke divisi pemilik data; null = lintas divisi.
     * @return array{
     *     period: ?string,
     *     totals: Collection<int, object{upt: string, ultg: string, attribute: string, total: float}&stdClass>,
     *     by_upt: array<string, list<array{
     *         ultg: string,
     *         tower: ?float,
     *         transmisi: ?float,
     *         trafo_mva: ?float,
     *         trafo_bank: ?float,
     *         trafo_unit: ?float,
     *         attributes: array<string, float>
     *     }>>
     * }
     */
    public function totalsByUltg(?string $period = null, ?int $divisionId = null): array
    {
        $byUptEmpty = array_fill_keys(self::CANONICAL_UPTS, []);
        $period ??= $this->baseUptQuery($divisionId)->max('performance_metrics.period');

        if ($period === null) {
            /** @var Collection<int, object{upt: string, ultg: string, attribute: string, total: float}&stdClass> $empty */
            $empty = collect();

            return [
                'period' => null,
                'totals' => $empty,
                'by_upt' => $byUptEmpty,
            ];
        }

        $period = Carbon::parse((string) $period)->format('Y-m-d');

        $totals = $this->fetchUptRows($period, $divisionId)
            ->map(function (object $row): object {
                [$domain, $upt, $ultg, $attribute] = explode(' | ', (string) $row->metric_name, 4) + [null, null, null, null];

                return (object) [
                    'upt' => (string) ($upt ?? ''),
                    'ultg' => (string) ($ultg ?? ''),
                    'attribute' => (string) ($attribute ?? ''),
                    'value' => (float) $row->value,
                ];
            })
            ->filter(
                fn (object $row): bool => in_array($row->upt, self::CANONICAL_UPTS, true)
                    && $row->ultg !== ''
                    && $row->attribute !== ''
            )
            ->groupBy(fn (object $row): string => $row->upt."\0".$row->ultg."\0".$row->attribute)
            ->map(fn (Collection $items): object => (object) [
                'upt' => (string) $items->first()->upt,
                'ultg' => (string) $items->first()->ultg,
                'attribute' => (string) $items->first()->attribute,
                'total' => (float) $items->sum(fn (object $item): float => $item->value),
            ])
            ->values();

        $byUpt = $byUptEmpty;

        foreach ($totals->groupBy('upt') as $upt => $uptRows) {
            $ultgGroups = $uptRows->groupBy('ultg')->sortKeys();
            $ultgEntries = [];

            foreach ($ultgGroups as $ultg => $rows) {
                $attributes = [];
                foreach ($rows as $row) {
                    $attributes[$row->attribute] = (float) $row->total;
                }
                ksort($attributes);

                $ultgEntries[] = $this->buildUltgSummary((string) $ultg, $attributes);
            }

            $byUpt[(string) $upt] = $ultgEntries;
        }

        return [
            'period' => $period,
            'totals' => $totals,
            'by_upt' => $byUpt,
        ];
    }

    /**
     * Total metrik tingkat UIT JBT untuk periode tertentu (default: terbaru).
     *
     * Format metric_name keluaran AssetUitReader:
     * "ASET | UIT | {kelompok} | {satuan}"
     *
     * @param  string|null  $period  Periode Y-m-d; null = periode terbaru.
     * @param  int|null  $divisionId  Batasi ke divisi pemilik data; null = lintas divisi.
     * @return array{period: ?string, totals: Collection<int, object{metric_name: string, kelompok: ?string, satuan: ?string, attribute: ?string, total: float}&stdClass>}
     */
    public function totalsByUit(?string $period = null, ?int $divisionId = null): array
    {
        $base = fn (): Builder => DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.metric_name', 'like', AssetStockConverter::PREFIX.'UIT |%')
            ->when($divisionId !== null, fn (Builder $query) => $query->where('performance_metrics.division_id', $divisionId));

        $period ??= $base()->max('performance_metrics.period');

        if ($period === null) {
            /** @var Collection<int, object{metric_name: string, kelompok: ?string, satuan: ?string, attribute: ?string, total: float}&stdClass> $empty */
            $empty = collect();

            return ['period' => null, 'totals' => $empty];
        }

        $period = Carbon::parse((string) $period)->format('Y-m-d');

        $totals = $base()
            ->where('performance_metrics.period', $period)
            ->whereIn('performance_metrics.dataset_id', $this->latestDatasetIds($period, $divisionId))
            ->select('performance_metrics.metric_name', 'performance_metrics.value')
            ->orderBy('performance_metrics.metric_name')
            ->get()
            ->map(function (object $row): object {
                $metricName = (string) $row->metric_name;
                [$domain, $level, $kelompok, $satuan] = explode(' | ', $metricName, 4) + [null, null, null, null];

                return (object) [
                    'metric_name' => $metricName,
                    'kelompok' => $kelompok,
                    'satuan' => $satuan,
                    'attribute' => $satuan !== null && $satuan !== '' ? "{$kelompok} | {$satuan}" : $kelompok,
                    'value' => (float) $row->value,
                ];
            })
            ->groupBy(fn (object $row): string => $row->metric_name)
            ->map(fn (Collection $items): object => (object) [
                'metric_name' => (string) $items->first()->metric_name,
                'kelompok' => $items->first()->kelompok,
                'satuan' => $items->first()->satuan,
                'attribute' => $items->first()->attribute,
                'total' => (float) $items->sum(fn (object $item): float => $item->value),
            ])
            ->values();

        return ['period' => $period, 'totals' => $totals];
    }

    /**
     * Rasio kapasitas utilitas (rata-rata MVA/Unit & MVA/Bank trafo, proporsi SK vs SU transmisi).
     *
     * @return array{
     *     trafo: array{
     *         overall: array{mva: ?float, unit: ?float, bank: ?float, avg_mva_per_unit: ?float, avg_mva_per_bank: ?float},
     *         by_voltage: list<array{voltage: string, label: string, mva: ?float, unit: ?float, bank: ?float, avg_mva_per_unit: ?float, avg_mva_per_bank: ?float}>,
     *         by_upt: array<string, array{mva: ?float, unit: ?float, bank: ?float, avg_mva_per_unit: ?float}>
     *     },
     *     transmisi: array{
     *         overall: array{su_kms: ?float, sk_kms: ?float, total_kms: ?float, su_pct: ?float, sk_pct: ?float},
     *         by_voltage: list<array{voltage: string, label: string, su_kms: ?float, sk_kms: ?float, total_kms: ?float, su_pct: ?float, sk_pct: ?float}>,
     *         by_upt: array<string, array{su_kms: ?float, sk_kms: ?float, total_kms: ?float, su_pct: ?float, sk_pct: ?float}>
     *     }
     * }
     */
    public function capacityRatios(?string $period = null, ?int $divisionId = null): array
    {
        $core = $this->assembleGroupData($period, $divisionId);

        return $this->buildCapacityRatios(
            $core['trafo_groups'],
            $core['transmisi_groups'],
            $core['uit_totals'],
        );
    }

    /**
     * Rincian mendalam data stok aset untuk halaman /dashboard/asset-detail.
     *
     * Menyediakan breakdown Trafo per level tegangan (500/150 kV, 150/70 kV, 150/20 kV)
     * dengan pemisahan tegas antara Kapasitas MVA dan Jumlah Fisik (Bank / Unit),
     * saluran transmisi (SU vs SK kms), tower per tegangan, bay gardu,
     * rasio kapasitas utilitas, drill-down ULTG, serta matriks interaktif Alpine.
     *
     * @param  string|null  $period  Periode Y-m-d; null = periode terbaru.
     * @param  int|null  $divisionId  Batasi ke divisi pemilik data; null = lintas divisi.
     * @return array<string, mixed>
     */
    public function detailedBreakdown(?string $period = null, ?int $divisionId = null): array
    {
        $core = $this->assembleGroupData($period, $divisionId);
        $periodResolved = $core['period'];
        $uitReport = $core['uit_report'];
        $totals = $core['upt_totals'];
        $uitTotals = $core['uit_totals'];
        $byUptAttr = $core['by_upt_attr'];
        $trafoGroups = $core['trafo_groups'];
        $transmisiGroups = $core['transmisi_groups'];
        $canonicalUpts = self::CANONICAL_UPTS;

        // 3. Breakdown Tower per Tegangan (SU vs SK unit)
        $towerVoltages = [
            '500 kV' => 'Tower 500 KV',
            '150 kV' => 'Tower 150 KV',
            '70 kV' => 'Tower 70 KV',
        ];

        $towerGroups = [];
        $totalTowerUnitUpt = 0.0;

        foreach ($towerVoltages as $voltageKey => $prefix) {
            $byUpt = [];
            $sumSu = 0.0;
            $sumSk = 0.0;

            foreach ($canonicalUpts as $upt) {
                $su = $this->extractUptValue($byUptAttr, $upt, $prefix, 'SU');
                $sk = $this->extractUptValue($byUptAttr, $upt, $prefix, 'SK');

                $suRounded = $su !== null ? round($su, 0) : null;
                $skRounded = $sk !== null ? round($sk, 0) : null;
                $totRounded = ($suRounded !== null || $skRounded !== null)
                    ? round(($suRounded ?? 0.0) + ($skRounded ?? 0.0), 0)
                    : null;

                $byUpt[$upt] = [
                    'su_unit' => $suRounded,
                    'sk_unit' => $skRounded,
                    'total_unit' => $totRounded,
                ];

                $sumSu += $su ?? 0.0;
                $sumSk += $sk ?? 0.0;
            }

            $uitSu = $this->extractUitValue($uitTotals, $prefix, 'SU');
            $uitSk = $this->extractUitValue($uitTotals, $prefix, 'SK');
            $uitTot = ($uitSu !== null || $uitSk !== null) ? (($uitSu ?? 0.0) + ($uitSk ?? 0.0)) : null;

            $totalTowerUnitUpt += ($sumSu + $sumSk);

            $towerGroups[] = [
                'voltage' => $voltageKey,
                'label' => $prefix,
                'by_upt' => $byUpt,
                'total_upt' => [
                    'su_unit' => round($sumSu, 0),
                    'sk_unit' => round($sumSk, 0),
                    'total_unit' => round($sumSu + $sumSk, 0),
                ],
                'uit_total' => [
                    'su_unit' => $uitSu !== null ? round($uitSu, 0) : null,
                    'sk_unit' => $uitSk !== null ? round($uitSk, 0) : null,
                    'total_unit' => $uitTot !== null ? round($uitTot, 0) : null,
                ],
            ];
        }

        // 4. Breakdown Bay & Gardu Induk
        $bayPrefixes = [
            'BAY 500 KV' => 'Bay 500 kV',
            'BAY 150 KV' => 'Bay 150 kV',
            'Gardu Induk' => 'Gardu Induk (GI/GITET)',
        ];

        $bayGroups = [];
        foreach ($bayPrefixes as $prefix => $label) {
            $byUpt = [];
            $sum = 0.0;

            foreach ($canonicalUpts as $upt) {
                $val = $this->extractUptValue($byUptAttr, $upt, $prefix);
                $byUpt[$upt] = $val !== null ? round($val, 0) : null;
                $sum += $val ?? 0.0;
            }

            $uitVal = $this->extractUitValue($uitTotals, $prefix);

            $bayGroups[] = [
                'label' => $label,
                'prefix' => $prefix,
                'by_upt' => $byUpt,
                'total_upt' => round($sum, 0),
                'uit_total' => $uitVal !== null ? round($uitVal, 0) : null,
            ];
        }

        // 5. Matriks Lengkap untuk Filter Interaktif Alpine.js (pertahankan null vs 0.0)
        $rawAttributes = $totals->pluck('attribute')->unique()->sort()->values();
        $interactiveMatrix = [];

        foreach ($rawAttributes as $attr) {
            $group = 'lainnya';
            $groupLabel = 'Lainnya';
            $unit = 'Unit';

            if (str_starts_with($attr, 'Trafo ')) {
                $group = 'trafo';
                $groupLabel = 'Trafo & Gardu';
                $unit = str_contains($attr, 'MVA') ? 'MVA' : (str_contains($attr, 'Bank') ? 'Bank' : 'Unit');
            } elseif (str_starts_with($attr, 'Transmisi ')) {
                $group = 'transmisi';
                $groupLabel = 'Transmisi SU/SK';
                $unit = 'kms';
            } elseif (str_starts_with($attr, 'Tower ')) {
                $group = 'tower';
                $groupLabel = 'Tower';
                $unit = 'Unit';
            } elseif (str_contains($attr, 'BAY') || str_contains($attr, 'Gardu') || str_contains($attr, 'GI/GITET')) {
                $group = 'trafo';
                $groupLabel = 'Trafo & Gardu';
                $unit = 'Bay/Unit';
            }

            $uptValues = [];
            $attrTotal = 0.0;
            foreach ($canonicalUpts as $upt) {
                $val = array_key_exists($attr, $byUptAttr[$upt]) ? (float) $byUptAttr[$upt][$attr] : null;
                $uptValues[$upt] = $val;
                $attrTotal += $val ?? 0.0;
            }

            $interactiveMatrix[] = [
                'attribute' => $attr,
                'group' => $group,
                'group_label' => $groupLabel,
                'unit' => $unit,
                'values' => $uptValues,
                'total' => round($attrTotal, 1),
            ];
        }

        // 6. UIT Summary Totals
        $uitTowerTotal = $this->extractUitValue($uitTotals, 'Tower ');
        $uitTransmisiTotal = $this->extractUitValue($uitTotals, 'Transmisi ', 'kms');
        $uitTrafoMvaTotal = $this->extractUitValue($uitTotals, 'Trafo ', 'MVA');
        $uitTrafoBankTotal = $this->extractUitValue($uitTotals, 'Trafo ', 'Bank');
        $uitTrafoUnitTotal = $this->extractUitTrafoUnitValue($uitTotals);

        $summaryTotals = [
            'total_tower' => $uitTowerTotal ?? $totalTowerUnitUpt,
            'total_transmisi' => round($uitTransmisiTotal ?? $core['total_transmisi_kms_upt'], 1),
            'total_trafo_mva' => round($uitTrafoMvaTotal ?? $core['total_trafo_mva_upt'], 1),
            'total_trafo_bank' => round($uitTrafoBankTotal ?? $core['total_trafo_bank_upt'], 0),
            'total_trafo_unit' => round($uitTrafoUnitTotal ?? $core['total_trafo_unit_upt'], 0),
        ];

        // 7. Series untuk Visual ECharts (tetap numerik agar bar chart tidak pecah)
        $mvaSeriesData = [];
        $unitSeriesData = [];
        foreach ($canonicalUpts as $upt) {
            $uptMva = 0.0;
            $uptUnit = 0.0;
            foreach ($trafoGroups as $g) {
                $uptMva += $g['by_upt'][$upt]['mva'] ?? 0.0;
                $uptUnit += ($g['by_upt'][$upt]['bank'] ?? 0.0) + ($g['by_upt'][$upt]['unit'] ?? 0.0);
            }
            $mvaSeriesData[] = round($uptMva, 1);
            $unitSeriesData[] = round($uptUnit, 0);
        }

        $chartConfig = [
            'upts' => $canonicalUpts,
            'mva_series' => $mvaSeriesData,
            'unit_series' => $unitSeriesData,
            'voltage_breakdown' => array_map(static function (array $g) use ($canonicalUpts): array {
                return [
                    'name' => $g['label'].' (MVA)',
                    'data' => array_map(static fn (string $upt): float => (float) ($g['by_upt'][$upt]['mva'] ?? 0.0), $canonicalUpts),
                ];
            }, $trafoGroups),
        ];

        $uitHasAuthoritativeTotals = $uitTowerTotal !== null || $uitTransmisiTotal !== null || $uitTrafoMvaTotal !== null;
        $ultgReport = $this->totalsByUltg($periodResolved, $divisionId);
        $capacityRatios = $this->buildCapacityRatios($trafoGroups, $transmisiGroups, $uitTotals);

        return [
            'period' => $periodResolved,
            'period_uit' => $uitReport['period'] ?? $periodResolved,
            'period_upt' => $periodResolved,
            'available_periods' => $this->availablePeriods($divisionId),
            'freshness' => DatasetFreshness::forAsset($divisionId, $periodResolved),
            'ownership' => [
                'parent_code' => 'UIT JBT',
                'parent_name' => 'Unit Induk Transmisi Jawa Bagian Tengah (UIT JBT)',
                'parent_source' => $uitHasAuthoritativeTotals ? 'Rekap Induk UIT (Tab 1a)' : 'Agregasi 8 UPT (Tab 1b)',
                'distribution_scope' => 'Distribusi Wilayah 8 UPT Kanonis (Tab 1b)',
                'has_uit_totals' => $uitHasAuthoritativeTotals,
            ],
            'canonical_upts' => $canonicalUpts,
            'trafo_groups' => $trafoGroups,
            'transmisi_groups' => $transmisiGroups,
            'tower_groups' => $towerGroups,
            'bay_groups' => $bayGroups,
            'summary_totals' => $summaryTotals,
            'interactive_matrix' => $interactiveMatrix,
            'chart_config' => $chartConfig,
            'ultg_by_upt' => $ultgReport['by_upt'],
            'capacity_ratios' => $capacityRatios,
        ];
    }

    /**
     * Kueri dasar baris metrik UPT (mengecualikan rekap induk UIT).
     */
    private function baseUptQuery(?int $divisionId): Builder
    {
        return DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.metric_name', 'like', AssetStockConverter::PREFIX.'%')
            ->where('performance_metrics.metric_name', 'not like', AssetStockConverter::PREFIX.'UIT |%')
            ->when($divisionId !== null, fn (Builder $query) => $query->where('performance_metrics.division_id', $divisionId));
    }

    /**
     * Ambil baris metrik UPT dari dataset READY terbaru per divisi pada periode tertentu.
     *
     * @return Collection<int, stdClass>
     */
    private function fetchUptRows(string $period, ?int $divisionId): Collection
    {
        return $this->baseUptQuery($divisionId)
            ->where('performance_metrics.period', $period)
            ->whereIn('performance_metrics.dataset_id', $this->latestDatasetIds($period, $divisionId))
            ->select('performance_metrics.metric_name', 'performance_metrics.value')
            ->orderBy('performance_metrics.metric_name')
            ->get();
    }

    /**
     * Susun ringkasan kategori stok untuk satu ULTG dari peta atributnya.
     *
     * @param  array<string, float>  $attributes
     * @return array{
     *     ultg: string,
     *     tower: ?float,
     *     transmisi: ?float,
     *     trafo_mva: ?float,
     *     trafo_bank: ?float,
     *     trafo_unit: ?float,
     *     attributes: array<string, float>
     * }
     */
    private function buildUltgSummary(string $ultg, array $attributes): array
    {
        $tower = $this->sumMatchingAttributes($attributes, fn (string $attr): bool => stripos($attr, 'Tower ') === 0, 0);
        $transmisi = $this->sumMatchingAttributes($attributes, fn (string $attr): bool => stripos($attr, 'Transmisi ') === 0, 1);
        $trafoMva = $this->sumMatchingAttributes(
            $attributes,
            fn (string $attr): bool => stripos($attr, 'Trafo ') === 0 && stripos($attr, 'MVA') !== false,
            1
        );
        $trafoBank = $this->sumMatchingAttributes(
            $attributes,
            fn (string $attr): bool => stripos($attr, 'Trafo ') === 0 && stripos($attr, 'Bank') !== false,
            0
        );
        $trafoUnit = $this->sumMatchingAttributes(
            $attributes,
            fn (string $attr): bool => stripos($attr, 'Trafo ') === 0
                && (stripos($attr, 'Unit') !== false || stripos($attr, 'Set') !== false)
                && stripos($attr, 'MVA') === false
                && stripos($attr, 'Bank') === false,
            0
        );

        return [
            'ultg' => $ultg,
            'tower' => $tower,
            'transmisi' => $transmisi,
            'trafo_mva' => $trafoMva,
            'trafo_bank' => $trafoBank,
            'trafo_unit' => $trafoUnit,
            'attributes' => $attributes,
        ];
    }

    /**
     * @param  array<string, float>  $attributes
     * @param  callable(string): bool  $predicate
     */
    private function sumMatchingAttributes(array $attributes, callable $predicate, int $precision): ?float
    {
        $sum = 0.0;
        $matched = false;

        foreach ($attributes as $attr => $value) {
            if (! $predicate($attr)) {
                continue;
            }
            $matched = true;
            $sum += $value;
        }

        return $matched ? round($sum, $precision) : null;
    }

    /**
     * Kumpulkan grup Trafo dan Transmisi beserta total UPT/UIT sebagai inti bersama
     * untuk `capacityRatios()` dan `detailedBreakdown()`.
     *
     * @return array{
     *     period: ?string,
     *     uit_report: array{period: ?string, totals: Collection<int, object{metric_name: string, kelompok: ?string, satuan: ?string, attribute: ?string, total: float}&stdClass>},
     *     upt_totals: Collection<int, object{upt: string, attribute: string, total: float}&stdClass>,
     *     uit_totals: Collection<int, object{metric_name: string, kelompok: ?string, satuan: ?string, attribute: ?string, total: float}&stdClass>,
     *     by_upt_attr: array<string, array<string, float>>,
     *     trafo_groups: list<array<string, mixed>>,
     *     transmisi_groups: list<array<string, mixed>>,
     *     total_trafo_mva_upt: float,
     *     total_trafo_bank_upt: float,
     *     total_trafo_unit_upt: float,
     *     total_transmisi_kms_upt: float
     * }
     */
    private function assembleGroupData(?string $period, ?int $divisionId): array
    {
        $uptReport = $this->totalsByUpt($period, $divisionId);
        $periodResolved = $uptReport['period'];

        $uitReport = $this->totalsByUit($periodResolved, $divisionId);
        if ($uitReport['totals']->isEmpty() && $periodResolved !== null) {
            $uitReport = $this->totalsByUit(null, $divisionId);
        }

        $canonicalUpts = self::CANONICAL_UPTS;
        $totals = $uptReport['totals'];
        $uitTotals = $uitReport['totals'];

        $byUptAttr = array_fill_keys($canonicalUpts, []);
        foreach ($totals as $row) {
            if (isset($byUptAttr[$row->upt])) {
                $byUptAttr[$row->upt][$row->attribute] = (float) $row->total;
            }
        }

        // 1. Bedah Trafo per Level Tegangan
        $trafoVoltages = [
            '500/150 kV' => 'Trafo 500/150 KV',
            '150/70 kV' => 'Trafo 150/70 KV',
            '150/20 kV' => 'Trafo 150/20 KV',
        ];

        $registerTrafoVoltage = static function (?string $text) use (&$trafoVoltages): void {
            if ($text === null || $text === '') {
                return;
            }
            foreach (explode('|', $text) as $segment) {
                $segment = trim($segment);
                if (preg_match('/Trafo\s+([0-9]+\s*\/\s*[0-9]+)\s*k?v?\b/i', $segment, $matches) === 1) {
                    $ratio = preg_replace('/\s+/', '', $matches[1]) ?? $matches[1];
                    $key = $ratio.' kV';
                    if (! isset($trafoVoltages[$key])) {
                        $trafoVoltages[$key] = 'Trafo '.$ratio.' KV';
                    }
                }
            }
        };

        foreach ($totals as $row) {
            $registerTrafoVoltage($row->attribute);
        }
        foreach ($uitTotals as $row) {
            $registerTrafoVoltage($row->kelompok);
        }

        $trafoGroups = [];
        $totalTrafoMvaUpt = 0.0;
        $totalTrafoBankUpt = 0.0;
        $totalTrafoUnitUpt = 0.0;

        foreach ($trafoVoltages as $voltageKey => $prefix) {
            $byUpt = [];
            $sumMva = 0.0;
            $sumBank = 0.0;
            $sumUnit = 0.0;
            $hasMva = false;
            $hasBank = false;
            $hasUnit = false;

            foreach ($canonicalUpts as $upt) {
                $mva = $this->extractUptValue($byUptAttr, $upt, $prefix, 'MVA');
                $bank = $this->extractUptValue($byUptAttr, $upt, $prefix, 'Bank');
                $unit = $this->extractUptValue($byUptAttr, $upt, $prefix, 'Unit', 'MVA')
                    ?? $this->extractUptValue($byUptAttr, $upt, $prefix, 'Set', 'MVA');

                $mvaRounded = $mva !== null ? round($mva, 1) : null;
                $bankRounded = $bank !== null ? round($bank, 0) : null;
                $unitRounded = $unit !== null ? round($unit, 0) : null;

                $byUpt[$upt] = [
                    'mva' => $mvaRounded,
                    'bank' => $bankRounded,
                    'unit' => $unitRounded,
                    'avg_mva_per_unit' => $this->safeDivide($mvaRounded, $unitRounded, 1),
                ];

                if ($mva !== null) {
                    $hasMva = true;
                    $sumMva += $mva;
                }
                if ($bank !== null) {
                    $hasBank = true;
                    $sumBank += $bank;
                }
                if ($unit !== null) {
                    $hasUnit = true;
                    $sumUnit += $unit;
                }
            }

            $uitMva = $this->extractUitValue($uitTotals, $prefix, 'MVA');
            $uitBank = $this->extractUitValue($uitTotals, $prefix, 'Bank');
            $uitUnit = $this->extractUitValue($uitTotals, $prefix, 'Unit', 'MVA')
                ?? $this->extractUitValue($uitTotals, $prefix, 'Set', 'MVA');

            $totalTrafoMvaUpt += $sumMva;
            $totalTrafoBankUpt += $sumBank;
            $totalTrafoUnitUpt += $sumUnit;

            $hasAnyData = $hasMva || $hasBank || $hasUnit
                || $uitMva !== null || $uitBank !== null || $uitUnit !== null;

            if (! $hasAnyData) {
                continue;
            }

            $hasUptBank = $totals->contains(function (object $row) use ($prefix): bool {
                $attr = (string) ($row->attribute ?? '');

                return (stripos($attr, $prefix) === 0 || stripos($attr, ' | '.$prefix) !== false)
                    && stripos($attr, 'Bank') !== false;
            });

            $effectiveMva = $uitMva !== null ? round($uitMva, 1) : ($hasMva ? round($sumMva, 1) : null);
            $effectiveBank = $uitBank !== null ? round($uitBank, 0) : ($hasBank ? round($sumBank, 0) : null);
            $effectiveUnit = $uitUnit !== null ? round($uitUnit, 0) : ($hasUnit ? round($sumUnit, 0) : null);

            $trafoGroups[] = [
                'voltage' => $voltageKey,
                'label' => $prefix,
                'has_upt_bank' => $hasUptBank,
                'by_upt' => $byUpt,
                'total_upt' => [
                    'mva' => round($sumMva, 1),
                    'bank' => round($sumBank, 0),
                    'unit' => round($sumUnit, 0),
                ],
                'uit_total' => [
                    'mva' => $uitMva !== null ? round($uitMva, 1) : null,
                    'bank' => $uitBank !== null ? round($uitBank, 0) : null,
                    'unit' => $uitUnit !== null ? round($uitUnit, 0) : null,
                ],
                'avg_mva_per_unit' => $this->safeDivide($effectiveMva, $effectiveUnit, 1),
                'avg_mva_per_bank' => $this->safeDivide($effectiveMva, $effectiveBank, 1),
            ];
        }

        // 2. Breakdown Saluran Transmisi (SU vs SK kms)
        $transmisiVoltages = [
            '500 kV' => 'Transmisi 500 KV',
            '150 kV' => 'Transmisi 150 KV',
            '70 kV' => 'Transmisi 70 KV',
        ];

        $transmisiGroups = [];
        $totalTransmisiKmsUpt = 0.0;

        foreach ($transmisiVoltages as $voltageKey => $prefix) {
            $byUpt = [];
            $sumSu = 0.0;
            $sumSk = 0.0;
            $hasSu = false;
            $hasSk = false;

            foreach ($canonicalUpts as $upt) {
                $su = $this->extractUptValue($byUptAttr, $upt, $prefix, 'SU');
                $sk = $this->extractUptValue($byUptAttr, $upt, $prefix, 'SK');

                $suRounded = $su !== null ? round($su, 1) : null;
                $skRounded = $sk !== null ? round($sk, 1) : null;
                $totRounded = ($suRounded !== null || $skRounded !== null)
                    ? round(($suRounded ?? 0.0) + ($skRounded ?? 0.0), 1)
                    : null;
                [$suPct, $skPct] = $this->computeTransmissionPercentages($suRounded, $skRounded, $totRounded);

                $byUpt[$upt] = [
                    'su_kms' => $suRounded,
                    'sk_kms' => $skRounded,
                    'total_kms' => $totRounded,
                    'su_pct' => $suPct,
                    'sk_pct' => $skPct,
                ];

                if ($su !== null) {
                    $hasSu = true;
                    $sumSu += $su;
                }
                if ($sk !== null) {
                    $hasSk = true;
                    $sumSk += $sk;
                }
            }

            $uitSu = $this->extractUitValue($uitTotals, $prefix, 'SU');
            $uitSk = $this->extractUitValue($uitTotals, $prefix, 'SK');
            $uitTot = ($uitSu !== null || $uitSk !== null) ? (($uitSu ?? 0.0) + ($uitSk ?? 0.0)) : null;

            $totalTransmisiKmsUpt += ($sumSu + $sumSk);

            $effectiveSu = $uitSu !== null ? round($uitSu, 1) : ($hasSu ? round($sumSu, 1) : null);
            $effectiveSk = $uitSk !== null ? round($uitSk, 1) : ($hasSk ? round($sumSk, 1) : null);
            $effectiveTotal = ($effectiveSu !== null || $effectiveSk !== null)
                ? round(($effectiveSu ?? 0.0) + ($effectiveSk ?? 0.0), 1)
                : null;
            [$groupSuPct, $groupSkPct] = $this->computeTransmissionPercentages($effectiveSu, $effectiveSk, $effectiveTotal);

            $transmisiGroups[] = [
                'voltage' => $voltageKey,
                'label' => $prefix,
                'by_upt' => $byUpt,
                'total_upt' => [
                    'su_kms' => round($sumSu, 1),
                    'sk_kms' => round($sumSk, 1),
                    'total_kms' => round($sumSu + $sumSk, 1),
                ],
                'uit_total' => [
                    'su_kms' => $uitSu !== null ? round($uitSu, 1) : null,
                    'sk_kms' => $uitSk !== null ? round($uitSk, 1) : null,
                    'total_kms' => $uitTot !== null ? round($uitTot, 1) : null,
                ],
                'su_pct' => $groupSuPct,
                'sk_pct' => $groupSkPct,
            ];
        }

        return [
            'period' => $periodResolved,
            'uit_report' => $uitReport,
            'upt_totals' => $totals,
            'uit_totals' => $uitTotals,
            'by_upt_attr' => $byUptAttr,
            'trafo_groups' => $trafoGroups,
            'transmisi_groups' => $transmisiGroups,
            'total_trafo_mva_upt' => $totalTrafoMvaUpt,
            'total_trafo_bank_upt' => $totalTrafoBankUpt,
            'total_trafo_unit_upt' => $totalTrafoUnitUpt,
            'total_transmisi_kms_upt' => $totalTransmisiKmsUpt,
        ];
    }

    /**
     * Bangun struktur rasio kapasitas utilitas (Trafo MVA/Unit & MVA/Bank serta proporsi SK/SU transmisi).
     *
     * @param  list<array<string, mixed>>  $trafoGroups
     * @param  list<array<string, mixed>>  $transmisiGroups
     * @param  Collection<int, object{metric_name: string, kelompok: ?string, satuan: ?string, attribute: ?string, total: float}&stdClass>  $uitTotals
     * @return array{
     *     trafo: array{
     *         overall: array{mva: ?float, unit: ?float, bank: ?float, avg_mva_per_unit: ?float, avg_mva_per_bank: ?float},
     *         by_voltage: list<array{voltage: string, label: string, mva: ?float, unit: ?float, bank: ?float, avg_mva_per_unit: ?float, avg_mva_per_bank: ?float}>,
     *         by_upt: array<string, array{mva: ?float, unit: ?float, bank: ?float, avg_mva_per_unit: ?float}>
     *     },
     *     transmisi: array{
     *         overall: array{su_kms: ?float, sk_kms: ?float, total_kms: ?float, su_pct: ?float, sk_pct: ?float},
     *         by_voltage: list<array{voltage: string, label: string, su_kms: ?float, sk_kms: ?float, total_kms: ?float, su_pct: ?float, sk_pct: ?float}>,
     *         by_upt: array<string, array{su_kms: ?float, sk_kms: ?float, total_kms: ?float, su_pct: ?float, sk_pct: ?float}>
     *     }
     * }
     */
    private function buildCapacityRatios(array $trafoGroups, array $transmisiGroups, Collection $uitTotals): array
    {
        $trafoByVoltage = [];
        $uptTrafoMvaSum = null;
        $uptTrafoUnitSum = null;
        $uptTrafoBankSum = null;

        $trafoByUpt = [];
        foreach (self::CANONICAL_UPTS as $upt) {
            $uptMva = null;
            $uptUnit = null;
            $uptBank = null;

            foreach ($trafoGroups as $group) {
                $cell = $group['by_upt'][$upt] ?? null;
                if ($cell === null) {
                    continue;
                }
                if ($cell['mva'] !== null) {
                    $uptMva = ($uptMva ?? 0.0) + (float) $cell['mva'];
                }
                if ($cell['unit'] !== null) {
                    $uptUnit = ($uptUnit ?? 0.0) + (float) $cell['unit'];
                }
                if ($cell['bank'] !== null) {
                    $uptBank = ($uptBank ?? 0.0) + (float) $cell['bank'];
                }
            }

            $uptMvaRounded = $uptMva !== null ? round($uptMva, 1) : null;
            $uptUnitRounded = $uptUnit !== null ? round($uptUnit, 0) : null;
            $uptBankRounded = $uptBank !== null ? round($uptBank, 0) : null;

            $trafoByUpt[$upt] = [
                'mva' => $uptMvaRounded,
                'unit' => $uptUnitRounded,
                'bank' => $uptBankRounded,
                'avg_mva_per_unit' => $this->safeDivide($uptMvaRounded, $uptUnitRounded, 1),
            ];

            if ($uptMvaRounded !== null) {
                $uptTrafoMvaSum = ($uptTrafoMvaSum ?? 0.0) + $uptMvaRounded;
            }
            if ($uptUnitRounded !== null) {
                $uptTrafoUnitSum = ($uptTrafoUnitSum ?? 0.0) + $uptUnitRounded;
            }
            if ($uptBankRounded !== null) {
                $uptTrafoBankSum = ($uptTrafoBankSum ?? 0.0) + $uptBankRounded;
            }
        }

        foreach ($trafoGroups as $group) {
            $groupMva = $this->resolveEffectiveGroupMetric($group, 'mva');
            $groupUnit = $this->resolveEffectiveGroupMetric($group, 'unit');
            $groupBank = $this->resolveEffectiveGroupMetric($group, 'bank');

            $trafoByVoltage[] = [
                'voltage' => (string) $group['voltage'],
                'label' => (string) $group['label'],
                'mva' => $groupMva,
                'unit' => $groupUnit,
                'bank' => $groupBank,
                'avg_mva_per_unit' => $group['avg_mva_per_unit'],
                'avg_mva_per_bank' => $group['avg_mva_per_bank'],
            ];
        }

        $uitTrafoMva = $this->extractUitValue($uitTotals, 'Trafo ', 'MVA');
        $uitTrafoBank = $this->extractUitValue($uitTotals, 'Trafo ', 'Bank');
        $uitTrafoUnit = $this->extractUitTrafoUnitValue($uitTotals);

        $overallTrafoMva = $uitTrafoMva !== null ? round($uitTrafoMva, 1) : ($uptTrafoMvaSum !== null ? round($uptTrafoMvaSum, 1) : null);
        $overallTrafoUnit = $uitTrafoUnit !== null ? round($uitTrafoUnit, 0) : ($uptTrafoUnitSum !== null ? round($uptTrafoUnitSum, 0) : null);
        $overallTrafoBank = $uitTrafoBank !== null ? round($uitTrafoBank, 0) : ($uptTrafoBankSum !== null ? round($uptTrafoBankSum, 0) : null);

        $transmisiByVoltage = [];
        $uptSuTotalSum = null;
        $uptSkTotalSum = null;

        $transmisiByUpt = [];
        foreach (self::CANONICAL_UPTS as $upt) {
            $uptSu = null;
            $uptSk = null;

            foreach ($transmisiGroups as $group) {
                $cell = $group['by_upt'][$upt] ?? null;
                if ($cell === null) {
                    continue;
                }
                if ($cell['su_kms'] !== null) {
                    $uptSu = ($uptSu ?? 0.0) + (float) $cell['su_kms'];
                }
                if ($cell['sk_kms'] !== null) {
                    $uptSk = ($uptSk ?? 0.0) + (float) $cell['sk_kms'];
                }
            }

            $uptSuRounded = $uptSu !== null ? round($uptSu, 1) : null;
            $uptSkRounded = $uptSk !== null ? round($uptSk, 1) : null;
            $uptTotal = ($uptSuRounded !== null || $uptSkRounded !== null)
                ? round(($uptSuRounded ?? 0.0) + ($uptSkRounded ?? 0.0), 1)
                : null;
            [$suPct, $skPct] = $this->computeTransmissionPercentages($uptSuRounded, $uptSkRounded, $uptTotal);

            $transmisiByUpt[$upt] = [
                'su_kms' => $uptSuRounded,
                'sk_kms' => $uptSkRounded,
                'total_kms' => $uptTotal,
                'su_pct' => $suPct,
                'sk_pct' => $skPct,
            ];

            if ($uptSuRounded !== null) {
                $uptSuTotalSum = ($uptSuTotalSum ?? 0.0) + $uptSuRounded;
            }
            if ($uptSkRounded !== null) {
                $uptSkTotalSum = ($uptSkTotalSum ?? 0.0) + $uptSkRounded;
            }
        }

        foreach ($transmisiGroups as $group) {
            $groupSu = $this->resolveEffectiveGroupMetric($group, 'su_kms');
            $groupSk = $this->resolveEffectiveGroupMetric($group, 'sk_kms');
            $groupTotal = ($groupSu !== null || $groupSk !== null)
                ? round(($groupSu ?? 0.0) + ($groupSk ?? 0.0), 1)
                : null;

            $transmisiByVoltage[] = [
                'voltage' => (string) $group['voltage'],
                'label' => (string) $group['label'],
                'su_kms' => $groupSu,
                'sk_kms' => $groupSk,
                'total_kms' => $groupTotal,
                'su_pct' => $group['su_pct'],
                'sk_pct' => $group['sk_pct'],
            ];
        }

        $uitSu = $this->extractUitValue($uitTotals, 'Transmisi ', 'SU');
        $uitSk = $this->extractUitValue($uitTotals, 'Transmisi ', 'SK');

        $overallSu = $uitSu !== null ? round($uitSu, 1) : ($uptSuTotalSum !== null ? round($uptSuTotalSum, 1) : null);
        $overallSk = $uitSk !== null ? round($uitSk, 1) : ($uptSkTotalSum !== null ? round($uptSkTotalSum, 1) : null);
        $overallTotalKms = ($overallSu !== null || $overallSk !== null)
            ? round(($overallSu ?? 0.0) + ($overallSk ?? 0.0), 1)
            : null;
        [$overallSuPct, $overallSkPct] = $this->computeTransmissionPercentages($overallSu, $overallSk, $overallTotalKms);

        return [
            'trafo' => [
                'overall' => [
                    'mva' => $overallTrafoMva,
                    'unit' => $overallTrafoUnit,
                    'bank' => $overallTrafoBank,
                    'avg_mva_per_unit' => $this->safeDivide($overallTrafoMva, $overallTrafoUnit, 1),
                    'avg_mva_per_bank' => $this->safeDivide($overallTrafoMva, $overallTrafoBank, 1),
                ],
                'by_voltage' => $trafoByVoltage,
                'by_upt' => $trafoByUpt,
            ],
            'transmisi' => [
                'overall' => [
                    'su_kms' => $overallSu,
                    'sk_kms' => $overallSk,
                    'total_kms' => $overallTotalKms,
                    'su_pct' => $overallSuPct,
                    'sk_pct' => $overallSkPct,
                ],
                'by_voltage' => $transmisiByVoltage,
                'by_upt' => $transmisiByUpt,
            ],
        ];
    }

    /**
     * Ambil nilai efektif kelompok (prioritas UIT, fallback jumlah UPT jika ada minimal 1 baris).
     *
     * @param  array<string, mixed>  $group
     */
    private function resolveEffectiveGroupMetric(array $group, string $metricKey): ?float
    {
        if (isset($group['uit_total'][$metricKey]) && $group['uit_total'][$metricKey] !== null) {
            return (float) $group['uit_total'][$metricKey];
        }

        foreach ($group['by_upt'] as $cell) {
            if (($cell[$metricKey] ?? null) !== null) {
                return (float) $group['total_upt'][$metricKey];
            }
        }

        return null;
    }

    /**
     * Pembagian aman terhadap pembagi null atau <= 0.
     */
    private function safeDivide(?float $numerator, ?float $denominator, int $precision = 1): ?float
    {
        if ($numerator === null || $denominator === null || $denominator <= 0.0) {
            return null;
        }

        return round($numerator / $denominator, $precision);
    }

    /**
     * Hitung persentase SU vs SK dari total panjang saluran transmisi.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function computeTransmissionPercentages(?float $suKms, ?float $skKms, ?float $totalKms): array
    {
        if ($totalKms === null || $totalKms <= 0.0) {
            return [null, null];
        }

        return [
            round((($suKms ?? 0.0) / $totalKms) * 100, 1),
            round((($skKms ?? 0.0) / $totalKms) * 100, 1),
        ];
    }

    /**
     * Cari nilai UPT berdasarkan pola nama atribut; mengembalikan null jika tidak tercatat.
     *
     * @param  array<string, array<string, float>>  $byUptAttr
     */
    private function extractUptValue(
        array $byUptAttr,
        string $upt,
        string $prefix,
        ?string $contains = null,
        ?string $notContains = null,
    ): ?float {
        $val = 0.0;
        $matched = false;

        foreach ($byUptAttr[$upt] ?? [] as $attr => $amount) {
            $matchesPrefix = stripos($attr, $prefix) === 0 || stripos($attr, ' | '.$prefix) !== false;
            if (! $matchesPrefix) {
                continue;
            }
            if ($contains !== null && stripos($attr, $contains) === false) {
                continue;
            }
            if ($notContains !== null && stripos($attr, $notContains) !== false) {
                continue;
            }
            $matched = true;
            $val += $amount;
        }

        return $matched ? $val : null;
    }

    /**
     * Cari nilai UIT (case-insensitive terhadap satuan Tab 1a seperti BANK/UNIT).
     *
     * @param  Collection<int, object{metric_name: string, kelompok: ?string, satuan: ?string, attribute: ?string, total: float}&stdClass>  $uitTotals
     */
    private function extractUitValue(
        Collection $uitTotals,
        string $prefix,
        ?string $contains = null,
        ?string $notContains = null,
    ): ?float {
        $matched = $uitTotals->filter(function (object $t) use ($prefix, $contains, $notContains): bool {
            $kelompok = (string) ($t->kelompok ?? '');
            $name = $kelompok.' | '.($t->satuan ?? '').' | '.($t->attribute ?? '');
            if (stripos($kelompok, $prefix) !== 0 && stripos($name, $prefix) !== 0) {
                return false;
            }
            if ($contains !== null && stripos($name, $contains) === false) {
                return false;
            }
            if ($notContains !== null && stripos($name, $notContains) !== false) {
                return false;
            }

            return true;
        });

        if ($matched->isEmpty()) {
            return null;
        }

        return (float) $matched->sum('total');
    }

    /**
     * Cari total fisik unit/set Trafo tingkat UIT tanpa mencampur MVA maupun Bank.
     *
     * @param  Collection<int, object{metric_name: string, kelompok: ?string, satuan: ?string, attribute: ?string, total: float}&stdClass>  $uitTotals
     */
    private function extractUitTrafoUnitValue(Collection $uitTotals): ?float
    {
        $matched = $uitTotals->filter(function (object $t): bool {
            $kelompok = (string) ($t->kelompok ?? '');
            $name = $kelompok.' | '.($t->satuan ?? '').' | '.($t->attribute ?? '');
            if (stripos($kelompok, 'Trafo ') !== 0 && stripos($name, 'Trafo ') !== 0) {
                return false;
            }

            return (stripos($name, 'Unit') !== false || stripos($name, 'Set') !== false)
                && stripos($name, 'MVA') === false
                && stripos($name, 'Bank') === false;
        });

        if ($matched->isEmpty()) {
            return null;
        }

        return (float) $matched->sum('total');
    }

    /**
     * ID dataset READY TERBARU per divisi untuk satu periode, dihitung hanya
     * dari dataset yang punya baris ASET pada periode itu.
     *
     * Satu dataset adalah satu versi: unggahan ulang pada divisi + periode
     * yang sama menggantikan dataset lama, bukan menambahnya. Tanpa batas
     * ini SUM(value) menghitung ganda. Aturan "terbaru" sama dengan
     * StructuredNkoReader::latestDatasetIds (created_at, tie-break id),
     * tetapi sumbernya performance_metrics karena data Aset tidak punya
     * baris performance_observations. Scope versi memakai datasets.division_id
     * (NOT NULL), bukan performance_metrics.division_id yang nullable.
     *
     * @return list<int>
     */
    private function latestDatasetIds(string $period, ?int $divisionId = null): array
    {
        $prefix = AssetStockConverter::PREFIX.'%';

        return DB::table('performance_metrics as pm')
            ->join('datasets as d', 'd.id', '=', 'pm.dataset_id')
            ->where('d.status', Dataset::STATUS_READY)
            ->where('pm.metric_name', 'like', $prefix)
            ->where('pm.period', $period)
            ->when($divisionId !== null, fn (Builder $query) => $query->where('pm.division_id', $divisionId))
            ->whereNotExists(function (Builder $query) use ($prefix, $period): void {
                $query->select(DB::raw(1))
                    ->from('performance_metrics as newer')
                    ->join('datasets as nd', 'nd.id', '=', 'newer.dataset_id')
                    ->where('nd.status', Dataset::STATUS_READY)
                    ->where('newer.metric_name', 'like', $prefix)
                    ->where('newer.period', $period)
                    ->whereColumn('nd.division_id', 'd.division_id')
                    ->where(function (Builder $better): void {
                        // created_at sama tetap terurut total lewat tie-break id.
                        $better->whereColumn('nd.created_at', '>', 'd.created_at')
                            ->orWhere(function (Builder $tie): void {
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
}
