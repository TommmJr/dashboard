<?php

namespace App\Support;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Models\Division;
use App\Services\AssetStockConverter;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penyusun metadata kesegaran dataset (Data Freshness & Stale-Period Notice)
 * untuk header eksekutif KPI L1 dan domain Manajemen Aset.
 */
final class DatasetFreshness
{
    public const TIMEZONE = 'Asia/Jakarta';

    /** @var array<int, string> */
    private const MONTHS_LONG = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    /** @var array<int, string> */
    private const MONTHS_SHORT = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    /**
     * Hitung metadata kesegaran dataset KPI aktif (terisolasi strictly ke divisi perencanaan-pengusahaan).
     *
     * @return array{
     *     has_dataset: bool,
     *     last_updated_label: string,
     *     last_updated_iso: ?string,
     *     latest_period: ?string,
     *     latest_period_label: string,
     *     is_stale: bool,
     *     stale_message: ?string
     * }
     */
    public static function forKpi(): array
    {
        $divisionId = Division::query()
            ->where('slug', 'perencanaan-pengusahaan')
            ->value('id');

        if ($divisionId === null) {
            return self::empty('Belum ada data aktif', 'Belum ada dataset aktif untuk periode berjalan');
        }

        $kpiDivisionId = (int) $divisionId;

        $dataset = Dataset::query()
            ->where('division_id', $kpiDivisionId)
            ->where('status', DatasetStatus::READY)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('performance_metrics')
                    ->whereColumn('performance_metrics.dataset_id', 'datasets.id')
                    ->where('performance_metrics.metric_name', 'like', AssetStockConverter::PREFIX.'%');
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'created_at', 'updated_at']);

        if ($dataset === null) {
            return self::empty('Belum ada data aktif', 'Belum ada dataset aktif untuk periode berjalan');
        }

        $obsPeriod = DB::table('performance_observations')
            ->join('datasets', 'datasets.id', '=', 'performance_observations.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('datasets.division_id', $kpiDivisionId)
            ->where('performance_observations.division_id', $kpiDivisionId)
            ->max('performance_observations.period');

        $metricPeriod = DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('datasets.division_id', $kpiDivisionId)
            ->where('performance_metrics.division_id', $kpiDivisionId)
            ->where('performance_metrics.metric_name', 'not like', AssetStockConverter::PREFIX.'%')
            ->max('performance_metrics.period');

        $latestPeriod = self::maxPeriod($obsPeriod, $metricPeriod);

        return self::build($dataset, $latestPeriod, 'Data periode berjalan belum tersedia');
    }

    /**
     * Hitung metadata kesegaran dataset Manajemen Aset (terisolasi dari dataset KPI).
     *
     * @return array{
     *     has_dataset: bool,
     *     last_updated_label: string,
     *     last_updated_iso: ?string,
     *     latest_period: ?string,
     *     latest_period_label: string,
     *     is_stale: bool,
     *     stale_message: ?string
     * }
     */
    public static function forAsset(?int $divisionId, ?string $resolvedPeriod = null): array
    {
        $dataset = Dataset::query()
            ->where('status', DatasetStatus::READY)
            ->when($divisionId !== null, fn ($query) => $query->where('division_id', $divisionId))
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('performance_metrics')
                    ->whereColumn('performance_metrics.dataset_id', 'datasets.id')
                    ->where('performance_metrics.metric_name', 'like', AssetStockConverter::PREFIX.'%');
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'created_at', 'updated_at']);

        if ($dataset === null) {
            return self::empty('Belum ada data aktif', 'Belum ada dataset aset aktif');
        }

        $latestPeriod = $resolvedPeriod;
        if ($latestPeriod === null) {
            $rawMax = DB::table('performance_metrics')
                ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
                ->where('datasets.status', Dataset::STATUS_READY)
                ->where('performance_metrics.metric_name', 'like', AssetStockConverter::PREFIX.'%')
                ->when($divisionId !== null, fn ($query) => $query->where('datasets.division_id', $divisionId))
                ->max('performance_metrics.period');

            $latestPeriod = is_string($rawMax) && $rawMax !== '' ? $rawMax : null;
        }

        return self::build($dataset, $latestPeriod, 'Data periode berjalan belum tersedia');
    }

    /**
     * Payload fail-closed saat belum ada dataset READY pada divisi terkait.
     *
     * @return array{
     *     has_dataset: bool,
     *     last_updated_label: string,
     *     last_updated_iso: ?string,
     *     latest_period: ?string,
     *     latest_period_label: string,
     *     is_stale: bool,
     *     stale_message: ?string
     * }
     */
    public static function empty(
        string $emptyLabel = 'Belum ada data aktif',
        string $staleMessage = 'Belum ada dataset aktif untuk periode berjalan',
    ): array {
        return [
            'has_dataset' => false,
            'last_updated_label' => $emptyLabel,
            'last_updated_iso' => null,
            'latest_period' => null,
            'latest_period_label' => 'Belum tersedia',
            'is_stale' => true,
            'stale_message' => $staleMessage,
        ];
    }

    /**
     * Format tanggal & jam dalam WIB (contoh: "16 Nov 2026, 20.15 WIB").
     */
    public static function formatDateTimeWib(CarbonInterface $dateTime): string
    {
        $wib = $dateTime->copy()->timezone(self::TIMEZONE);
        $month = self::MONTHS_SHORT[$wib->month] ?? $wib->format('M');

        return sprintf('%d %s %d, %s WIB', $wib->day, $month, $wib->year, $wib->format('H.i'));
    }

    /**
     * Format periode Y-m-d ke label Bulan Tahun Bahasa Indonesia.
     */
    public static function formatMonthYear(?string $period): string
    {
        if ($period === null || trim($period) === '') {
            return 'Belum tersedia';
        }

        try {
            $dt = Carbon::parse($period, self::TIMEZONE);
            $month = self::MONTHS_LONG[$dt->month] ?? $dt->format('M');

            return $month.' '.$dt->year;
        } catch (\Throwable) {
            return 'Belum tersedia';
        }
    }

    public static function isPeriodBehindCurrentMonth(?string $period): bool
    {
        if ($period === null || trim($period) === '') {
            return true;
        }

        try {
            $periodMonth = Carbon::parse($period, self::TIMEZONE)->startOfMonth();
            $currentMonth = Carbon::now(self::TIMEZONE)->startOfMonth();

            return $periodMonth->lt($currentMonth);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return array{
     *     has_dataset: bool,
     *     last_updated_label: string,
     *     last_updated_iso: ?string,
     *     latest_period: ?string,
     *     latest_period_label: string,
     *     is_stale: bool,
     *     stale_message: ?string
     * }
     */
    private static function build(Dataset $dataset, ?string $latestPeriod, string $staleMessage): array
    {
        $timestamp = $dataset->updated_at ?? $dataset->created_at;
        $wib = $timestamp instanceof CarbonInterface
            ? $timestamp->copy()->timezone(self::TIMEZONE)
            : Carbon::now(self::TIMEZONE);

        $isStale = self::isPeriodBehindCurrentMonth($latestPeriod);

        return [
            'has_dataset' => true,
            'last_updated_label' => 'Diperbarui '.self::formatDateTimeWib($wib),
            'last_updated_iso' => $wib->toIso8601String(),
            'latest_period' => $latestPeriod,
            'latest_period_label' => self::formatMonthYear($latestPeriod),
            'is_stale' => $isStale,
            'stale_message' => $isStale ? $staleMessage : null,
        ];
    }

    private static function maxPeriod(mixed $first, mixed $second): ?string
    {
        $candidates = array_values(array_filter(
            [$first, $second],
            fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (string $a, string $b): int => strcmp($b, $a));

        return $candidates[0];
    }
}
