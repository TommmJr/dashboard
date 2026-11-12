<?php

namespace App\Services;

use App\Models\Dataset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * Snapshot ASET pada periode terbaru: baris READY berprefix
     * AssetStockConverter::PREFIX dari dataset terbaru per divisi (lihat
     * latestDatasetIds), dikelompokkan per UPT/ULTG.
     *
     * Format metric_name diasumsikan keluaran converter:
     * "ASET | {UPT} | {ULTG} | {atribut}" (atribut sendiri boleh memuat
     * " | " sebagai pemisah level header, jadi pecah maksimal 4 bagian).
     *
     * @return array{period: ?string, groups: Collection<int, object{upt: string, ultg: string, metrics: Collection<int, object{attribute: string, value: float}}>}}
     */
    public function latestSnapshot(): array
    {
        $base = fn () => DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.metric_name', 'like', AssetStockConverter::PREFIX.'%')
            ->where('performance_metrics.metric_name', 'not like', AssetStockConverter::PREFIX.'UIT |%');

        $period = $base()->max('performance_metrics.period');

        if ($period === null) {
            return ['period' => null, 'groups' => collect()];
        }

        $period = Carbon::parse($period)->format('Y-m-d');

        $groups = $base()
            ->where('performance_metrics.period', $period)
            ->whereIn('performance_metrics.dataset_id', $this->latestDatasetIds($period))
            ->select('performance_metrics.metric_name', 'performance_metrics.value')
            ->orderBy('performance_metrics.metric_name')
            ->get()
            ->map(function (object $row): object {
                [$domain, $upt, $ultg, $attribute] = explode(' | ', $row->metric_name, 4) + [null, null, null, null];

                return (object) [
                    'upt' => $upt,
                    'ultg' => $ultg,
                    'attribute' => $attribute,
                    'value' => (float) $row->value,
                ];
            })
            ->filter(fn (object $row): bool => in_array($row->upt, self::CANONICAL_UPTS, true))
            ->groupBy(fn (object $row): string => $row->upt.' | '.$row->ultg)
            ->map(fn (Collection $items): object => (object) [
                'upt' => $items->first()->upt,
                'ultg' => $items->first()->ultg,
                'metrics' => $items->map(fn (object $item): object => (object) [
                    'attribute' => $item->attribute,
                    'value' => $item->value,
                ])->values(),
            ])
            ->values();

        return ['period' => $period, 'groups' => $groups];
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
     * Baris agregat source (Total UPT / TOTAL UIT JBT) tidak ikut
     * terjumlah karena converter memang tidak mengimpornya. Total nol
     * tetap dipertahankan sebagai grup (nol = data, bukan absen).
     *
     * @param  string|null  $period  Periode Y-m-d; null = periode terbaru.
     * @param  int|null  $divisionId  Batasi ke divisi pemilik data; null = lintas divisi.
     * @return array{period: ?string, totals: Collection<int, object{upt: string, attribute: string, total: float}>}
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
            return ['period' => null, 'totals' => collect()];
        }

        $period = Carbon::parse($period)->format('Y-m-d');

        $totals = $base()
            ->where('performance_metrics.period', $period)
            ->whereIn('performance_metrics.dataset_id', $this->latestDatasetIds($period, $divisionId))
            ->select('performance_metrics.metric_name', 'performance_metrics.value')
            ->orderBy('performance_metrics.metric_name')
            ->get()
            ->map(function (object $row): object {
                [$domain, $upt, $ultg, $attribute] = explode(' | ', $row->metric_name, 4) + [null, null, null, null];

                return (object) [
                    'upt' => $upt,
                    'attribute' => $attribute,
                    'value' => (float) $row->value,
                ];
            })
            ->filter(fn (object $row): bool => in_array($row->upt, self::CANONICAL_UPTS, true))
            ->groupBy(fn (object $row): string => $row->upt."\0".$row->attribute)
            ->map(fn (Collection $items): object => (object) [
                'upt' => $items->first()->upt,
                'attribute' => $items->first()->attribute,
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
     * @return array{period: ?string, totals: Collection<int, object{metric_name: string, kelompok: ?string, satuan: ?string, attribute: ?string, total: float}>}
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
            return ['period' => null, 'totals' => collect()];
        }

        $period = Carbon::parse($period)->format('Y-m-d');

        $totals = $base()
            ->where('performance_metrics.period', $period)
            ->whereIn('performance_metrics.dataset_id', $this->latestDatasetIds($period, $divisionId))
            ->select('performance_metrics.metric_name', 'performance_metrics.value')
            ->orderBy('performance_metrics.metric_name')
            ->get()
            ->map(function (object $row): object {
                [$domain, $level, $kelompok, $satuan] = explode(' | ', $row->metric_name, 4) + [null, null, null, null];

                return (object) [
                    'metric_name' => $row->metric_name,
                    'kelompok' => $kelompok,
                    'satuan' => $satuan,
                    'attribute' => $satuan ? "{$kelompok} | {$satuan}" : $kelompok,
                    'value' => (float) $row->value,
                ];
            })
            ->groupBy(fn (object $row): string => $row->metric_name)
            ->map(fn (Collection $items): object => (object) [
                'metric_name' => $items->first()->metric_name,
                'kelompok' => $items->first()->kelompok,
                'satuan' => $items->first()->satuan,
                'attribute' => $items->first()->attribute,
                'total' => (float) $items->sum(fn (object $item): float => $item->value),
            ])
            ->values();

        return ['period' => $period, 'totals' => $totals];
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
