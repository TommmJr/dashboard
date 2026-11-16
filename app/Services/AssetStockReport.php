<?php

namespace App\Services;

use App\Models\Dataset;
use App\Support\DatasetFreshness;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Query read-only domain Aset untuk persiapan fase visual.
 *
 * Sengaja TIDAK dipasang ke route/view/widget apa pun: bentuk visual
 * dashboard Aset masih menunggu jawaban mentor. Satu-satunya pemakai saat
 * ini adalah test level query.
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
     * Total per UPT untuk periode tertentu (default: terbaru): SUM(value)
     * GROUP BY UPT + atribut. ULTG di-roll-up karena rekap mentor
     * PER UPT (bukan per ULTG) dan stok fisik (unit, MVA, kms) bersifat
     * aditif selama tidak mencampur atribut berbeda satuan dalam satu
     * series — pemisahan per atribut dijaga sampai ke widget.
     *
     * Hanya dataset READY TERBARU per divisi pada periode itu yang dijumlahkan
     * (lihat latestDatasetIds); dataset lama yang digantikan tidak ikut.
     *
     * Baris agregat source (Total UPT / TOTAL UIT JBT) tidak ikut
     * terjumlah karena converter memang tidak mengimpornya. Total nol
     * tetap dipertahankan sebagai grup (nol = data, bukan absen).
     *
     * @param  string|null  $period  Periode Y-m-d; null = periode terbaru.
     * @param  int|null  $divisionId  Batasi ke divisi pemilik data; null = lintas divisi.
     * @return array{period: ?string, totals: Collection<int, object{upt: string, attribute: string, total: float}&stdClass>}
     */
    public function totalsByUpt(?string $period = null, ?int $divisionId = null): array
    {
        $base = fn () => DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.metric_name', 'like', AssetStockConverter::PREFIX.'%')
            ->where('performance_metrics.metric_name', 'not like', AssetStockConverter::PREFIX.'UIT |%')
            ->when($divisionId !== null, fn ($query) => $query->where('performance_metrics.division_id', $divisionId));

        $period ??= $base()->max('performance_metrics.period');

        if ($period === null) {
            /** @var Collection<int, object{upt: string, attribute: string, total: float}&stdClass> $empty */
            $empty = collect();

            return ['period' => null, 'totals' => $empty];
        }

        $period = Carbon::parse($period)->format('Y-m-d');

        $totals = $base()
            ->where('performance_metrics.period', $period)
            ->whereIn('performance_metrics.dataset_id', $this->latestDatasetIds($period, $divisionId))
            ->select('performance_metrics.metric_name', 'performance_metrics.value')
            ->orderBy('performance_metrics.metric_name')
            ->get()
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
            ->map(fn (Collection $items) => (object) [
                'upt' => (string) $items->first()->upt,
                'attribute' => (string) $items->first()->attribute,
                'total' => (float) $items->sum(fn (object $item): float => $item->value),
            ])
            ->values();

        return ['period' => $period, 'totals' => $totals];
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
        $base = fn () => DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.metric_name', 'like', AssetStockConverter::PREFIX.'UIT |%')
            ->when($divisionId !== null, fn ($query) => $query->where('performance_metrics.division_id', $divisionId));

        $period ??= $base()->max('performance_metrics.period');

        if ($period === null) {
            /** @var Collection<int, object{metric_name: string, kelompok: ?string, satuan: ?string, attribute: ?string, total: float}&stdClass> $empty */
            $empty = collect();

            return ['period' => null, 'totals' => $empty];
        }

        $period = Carbon::parse($period)->format('Y-m-d');

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
            ->map(fn (Collection $items) => (object) [
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
     * Rincian mendalam data stok aset untuk halaman /dashboard/asset-detail.
     *
     * Menyediakan breakdown Trafo per level tegangan (500/150 kV, 150/70 kV, 150/20 kV)
     * dengan pemisahan tegas antara Kapasitas MVA dan Jumlah Fisik (Bank / Unit),
     * saluran transmisi (SU vs SK kms), tower per tegangan, bay gardu,
     * serta matriks interaktif untuk filter Alpine.
     *
     * @param  string|null  $period  Periode Y-m-d; null = periode terbaru.
     * @param  int|null  $divisionId  Batasi ke divisi pemilik data; null = lintas divisi.
     * @return array<string, mixed>
     */
    public function detailedBreakdown(?string $period = null, ?int $divisionId = null): array
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

        // Map data UPT: [upt][attribute] => float
        $byUptAttr = [];
        foreach ($canonicalUpts as $upt) {
            $byUptAttr[$upt] = [];
        }
        foreach ($totals as $row) {
            if (isset($byUptAttr[$row->upt])) {
                $byUptAttr[$row->upt][$row->attribute] = (float) $row->total;
            }
        }

        // Helper cari nilai dari UPT & pola nama atribut (mendukung hierarki segmen ' | ' dan case-insensitive)
        $getUptVal = function (string $upt, string $prefix, ?string $contains = null, ?string $notContains = null) use ($byUptAttr): float {
            $val = 0.0;
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
                $val += $amount;
            }

            return $val;
        };

        // Helper cari nilai UIT (case-insensitive terhadap satuan Tab 1a seperti BANK/UNIT)
        $getUitVal = function (string $prefix, ?string $contains = null, ?string $notContains = null) use ($uitTotals): ?float {
            $matched = $uitTotals->filter(function (object $t) use ($prefix, $contains, $notContains) {
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
        };

        // 1. Bedah Trafo per Level Tegangan
        // Tegangan kanonis Trafo
        $trafoVoltages = [
            '500/150 kV' => 'Trafo 500/150 KV',
            '150/70 kV' => 'Trafo 150/70 KV',
            '150/20 kV' => 'Trafo 150/20 KV',
        ];

        $registerTrafoVoltage = function (?string $text) use (&$trafoVoltages): void {
            if ($text === null || $text === '') {
                return;
            }
            foreach (explode('|', $text) as $segment) {
                $segment = trim($segment);
                if (preg_match('/Trafo\s+([0-9]+\s*\/\s*[0-9]+)\s*k?v?\b/i', $segment, $matches)) {
                    $ratio = preg_replace('/\s+/', '', $matches[1]);
                    $key = $ratio.' kV';
                    if (! isset($trafoVoltages[$key])) {
                        $trafoVoltages[$key] = 'Trafo '.$ratio.' KV';
                    }
                }
            }
        };

        // Deteksi level tegangan tambahan dari Tab 1b (termasuk pola bersarang Trafo Tenaga) dan Tab 1a
        foreach ($totals as $row) {
            $registerTrafoVoltage($row->attribute ?? null);
        }
        foreach ($uitTotals as $row) {
            $registerTrafoVoltage($row->kelompok ?? null);
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

            foreach ($canonicalUpts as $upt) {
                $mva = $getUptVal($upt, $prefix, 'MVA');
                $bank = $getUptVal($upt, $prefix, 'Bank');
                // Unit / Set (bukan MVA dan bukan Bank)
                $unit = $getUptVal($upt, $prefix, 'Unit', 'MVA');
                if ($unit === 0.0) {
                    $unit = $getUptVal($upt, $prefix, 'Set', 'MVA');
                }

                $byUpt[$upt] = [
                    'mva' => round($mva, 1),
                    'bank' => round($bank, 0),
                    'unit' => round($unit, 0),
                ];

                $sumMva += $mva;
                $sumBank += $bank;
                $sumUnit += $unit;
            }

            $uitMva = $getUitVal($prefix, 'MVA');
            $uitBank = $getUitVal($prefix, 'Bank');
            $uitUnit = $getUitVal($prefix, 'Unit', 'MVA');
            if ($uitUnit === null) {
                $uitUnit = $getUitVal($prefix, 'Set', 'MVA');
            }

            $totalTrafoMvaUpt += $sumMva;
            $totalTrafoBankUpt += $sumBank;
            $totalTrafoUnitUpt += $sumUnit;

            $hasAnyData = $sumMva > 0 || $sumBank > 0 || $sumUnit > 0
                || ($uitMva ?? 0.0) > 0 || ($uitBank ?? 0.0) > 0 || ($uitUnit ?? 0.0) > 0;
            if (! $hasAnyData) {
                continue;
            }

            $hasUptBank = $totals->contains(function (object $row) use ($prefix): bool {
                $attr = (string) ($row->attribute ?? '');

                return (stripos($attr, $prefix) === 0 || stripos($attr, ' | '.$prefix) !== false)
                    && stripos($attr, 'Bank') !== false;
            });

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

            foreach ($canonicalUpts as $upt) {
                $su = $getUptVal($upt, $prefix, 'SU');
                $sk = $getUptVal($upt, $prefix, 'SK');
                $tot = $su + $sk;

                $byUpt[$upt] = [
                    'su_kms' => round($su, 1),
                    'sk_kms' => round($sk, 1),
                    'total_kms' => round($tot, 1),
                ];

                $sumSu += $su;
                $sumSk += $sk;
            }

            $uitSu = $getUitVal($prefix, 'SU');
            $uitSk = $getUitVal($prefix, 'SK');
            $uitTot = ($uitSu !== null || $uitSk !== null) ? (($uitSu ?? 0.0) + ($uitSk ?? 0.0)) : null;

            $totalTransmisiKmsUpt += ($sumSu + $sumSk);

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
            ];
        }

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
                $su = $getUptVal($upt, $prefix, 'SU');
                $sk = $getUptVal($upt, $prefix, 'SK');
                $tot = $su + $sk;

                $byUpt[$upt] = [
                    'su_unit' => round($su, 0),
                    'sk_unit' => round($sk, 0),
                    'total_unit' => round($tot, 0),
                ];

                $sumSu += $su;
                $sumSk += $sk;
            }

            $uitSu = $getUitVal($prefix, 'SU');
            $uitSk = $getUitVal($prefix, 'SK');
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
                $val = $getUptVal($upt, $prefix);
                $byUpt[$upt] = round($val, 0);
                $sum += $val;
            }

            $uitVal = $getUitVal($prefix);

            $bayGroups[] = [
                'label' => $label,
                'prefix' => $prefix,
                'by_upt' => $byUpt,
                'total_upt' => round($sum, 0),
                'uit_total' => $uitVal !== null ? round($uitVal, 0) : null,
            ];
        }

        // 5. Matriks Lengkap untuk Filter Interaktif Alpine.js
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
                $val = (float) ($byUptAttr[$upt][$attr] ?? 0.0);
                $uptValues[$upt] = $val;
                $attrTotal += $val;
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
        $uitTowerTotal = $getUitVal('Tower ');
        $uitTransmisiTotal = $getUitVal('Transmisi ', 'kms');
        $uitTrafoMvaTotal = $getUitVal('Trafo ', 'MVA');
        $uitTrafoBankTotal = $getUitVal('Trafo ', 'Bank');
        $uitTrafoUnitTotal = $getUitVal('Trafo ', 'Unit', 'MVA') ?? $getUitVal('Trafo ', 'Set', 'MVA');

        $summaryTotals = [
            'total_tower' => $uitTowerTotal ?? $totalTowerUnitUpt,
            'total_transmisi' => round($uitTransmisiTotal ?? $totalTransmisiKmsUpt, 1),
            'total_trafo_mva' => round($uitTrafoMvaTotal ?? $totalTrafoMvaUpt, 1),
            'total_trafo_bank' => round($uitTrafoBankTotal ?? $totalTrafoBankUpt, 0),
            'total_trafo_unit' => round($uitTrafoUnitTotal ?? $totalTrafoUnitUpt, 0),
        ];

        // 7. Series untuk Visual ECharts
        // MVA Series per UPT
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
            'voltage_breakdown' => array_map(function ($g) use ($canonicalUpts) {
                return [
                    'name' => $g['label'].' (MVA)',
                    'data' => array_map(fn ($upt) => $g['by_upt'][$upt]['mva'] ?? 0.0, $canonicalUpts),
                ];
            }, $trafoGroups),
        ];

        $uitHasAuthoritativeTotals = $uitTowerTotal !== null || $uitTransmisiTotal !== null || $uitTrafoMvaTotal !== null;

        return [
            'period' => $periodResolved,
            'period_uit' => $uitReport['period'] ?? $periodResolved,
            'period_upt' => $periodResolved,
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
        ];
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
            ->when($divisionId !== null, fn ($query) => $query->where('pm.division_id', $divisionId))
            ->whereNotExists(function ($query) use ($prefix, $period) {
                $query->select(DB::raw(1))
                    ->from('performance_metrics as newer')
                    ->join('datasets as nd', 'nd.id', '=', 'newer.dataset_id')
                    ->where('nd.status', Dataset::STATUS_READY)
                    ->where('newer.metric_name', 'like', $prefix)
                    ->where('newer.period', $period)
                    ->whereColumn('nd.division_id', 'd.division_id')
                    ->where(function ($better) {
                        // created_at sama tetap terurut total lewat tie-break id.
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
}
