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
     * Snapshot ASET pada periode terbaru: baris READY berprefix
     * AssetStockConverter::PREFIX, dikelompokkan per UPT/ULTG.
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
            ->where('performance_metrics.metric_name', 'like', AssetStockConverter::PREFIX.'%');

        $period = $base()->max('performance_metrics.period');

        if ($period === null) {
            return ['period' => null, 'groups' => collect()];
        }

        $period = Carbon::parse($period)->format('Y-m-d');

        $groups = $base()
            ->where('performance_metrics.period', $period)
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
}
