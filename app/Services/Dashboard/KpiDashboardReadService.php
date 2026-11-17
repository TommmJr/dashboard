<?php

namespace App\Services\Dashboard;

use App\Http\Controllers\Concerns\ScopesKpiUnits;
use App\Models\Dataset;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiReportConverter;
use App\Services\StructuredNkoReader;
use App\Services\TargetRealisasiPerIndicatorReader;
use App\Support\KpiScoreNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service pembacaan metrik KPI untuk dashboard dan halaman rincian KPI
 * (jalur legacy performance_metrics & read model terstruktur performance_observations).
 */
class KpiDashboardReadService
{
    use ScopesKpiUnits;

    /** Batas skor indikator R6/R9 dalam persen (keputusan Pak Gama 2026-10-25, mengikuti Excel). */
    private const KPI_SCORE_MIN = KpiScoreNormalizer::SCORE_MIN;

    private const KPI_SCORE_MAX = KpiScoreNormalizer::SCORE_MAX;

    /**
     * Batas atas skor PENCAPAIAN Excel dalam skala rasio. Nilai di atasnya tidak
     * mungkin rasio, jadi dibaca sebagai persen (mis. observasi #410 = 110.0).
     */
    private const KPI_SCORE_RATIO_MAX = KpiScoreNormalizer::RATIO_MAX;

    /**
     * Jumlah pasangan/injeksi R6 legacy yang dilewati karena sumber dataset
     * atau pencocokan performance_observations ambigu (> 1 kandidat). Disimpan
     * di properti agar payload R6 tetap terkunci 3 key.
     */
    private int $kinerjaRealisasiAmbiguousCount = 0;

    /**
     * DETAIL, bukan tabelnya: daftar lengkap unit pada periode terpilih,
     * termasuk unit yang barisnya terpotong cap 50. Diambil dari `$rows` yang
     * sudah jadi supaya dropdown tidak butuh query kedua dan tidak menambah
     * key level-atas pada payload (payload tetap terkunci 4 key).
     *
     * @var list<string>|null
     */
    private ?array $kinerjaDetailUnits = null;

    public function __construct(
        private readonly StructuredNkoReader $structuredNko,
        private readonly TargetRealisasiPerIndicatorReader $targetRealisasiPanel,
    ) {}

    public function getKinerjaRealisasiAmbiguousCount(): int
    {
        return $this->kinerjaRealisasiAmbiguousCount;
    }

    /**
     * @return list<string>|null
     */
    public function getKinerjaDetailUnits(): ?array
    {
        return $this->kinerjaDetailUnits;
    }

    /**
     * Susun payload terstruktur untuk mode Visualisasi Grafik Layar Penuh di halaman Rincian KPI.
     *
     * @param  array<string, mixed>  $targetRealisasiData
     * @param  array<string, mixed>  $kinerjaDetailData
     * @param  list<string>  $selectedUnits
     * @return array{
     *     period: string|null,
     *     unitLabel: string,
     *     ragThresholds: array{tercapai_min: float, hampir_min: float},
     *     indicators: list<array<string, mixed>>
     * }
     */
    public function kpiFullscreenChartData(array $targetRealisasiData, array $kinerjaDetailData, array $selectedUnits): array
    {
        $rag = KpiScoreNormalizer::ragThresholds();
        $period = $kinerjaDetailData['period'] ?? ($targetRealisasiData['period'] ?? null);

        $sections = $targetRealisasiData['sections'] ?? [];
        $availableSectionUnits = array_values(array_unique(array_filter(
            array_map(static fn (array $s): string => (string) ($s['unit'] ?? ''), $sections),
            static fn (string $u): bool => $u !== '',
        )));

        $activeUnits = $selectedUnits !== []
            ? array_values(array_intersect($selectedUnits, $availableSectionUnits))
            : [];

        $panelRows = [];
        $indicatorIds = [];

        foreach ($sections as $section) {
            $unit = (string) ($section['unit'] ?? '');
            if ($activeUnits !== [] && ! in_array($unit, $activeUnits, true)) {
                continue;
            }

            foreach ($section['panels'] ?? [] as $panel) {
                $panelRows[] = [
                    'unit' => $unit,
                    'group' => (string) ($section['name'] ?? 'Umum'),
                    'panel' => $panel,
                ];
                if (isset($panel['indicator_id'])) {
                    $indicatorIds[] = (int) $panel['indicator_id'];
                }
            }
        }

        $indicatorMeta = $indicatorIds !== []
            ? DB::table('indicators')
                ->whereIn('id', array_values(array_unique($indicatorIds)))
                ->select('id', 'polarity', 'bobot')
                ->get()
                ->keyBy('id')
            : collect();

        $tierToStatus = static function (string $tier): string {
            return match ($tier) {
                KpiScoreNormalizer::TIER_TERCAPAI => 'Tercapai',
                KpiScoreNormalizer::TIER_HAMPIR => 'Hampir Tercapai',
                KpiScoreNormalizer::TIER_PERLU => 'Perlu Peningkatan',
                default => 'Belum Terverifikasi',
            };
        };

        $indicators = [];

        foreach ($panelRows as $idx => $entry) {
            $panel = $entry['panel'];
            $unit = $entry['unit'];
            $id = $panel['indicator_id'] ?? $idx;
            $meta = isset($panel['indicator_id']) ? $indicatorMeta->get((int) $panel['indicator_id']) : null;

            $polaritas = match ($meta?->polarity) {
                KpiReportConverter::POLARITY_LOWER_IS_BETTER => 'Negatif',
                KpiReportConverter::POLARITY_HIGHER_IS_BETTER => 'Positif',
                default => 'Positif',
            };

            $bobot = ($meta?->bobot !== null && (string) $meta->bobot !== '')
                ? (string) $meta->bobot
                : 'Tidak tercatat';

            $mode = (string) ($panel['mode'] ?? 'numeric');
            $targetNum = match ($mode) {
                'numeric' => isset($panel['numeric']['target']) ? (float) $panel['numeric']['target'] : null,
                'score_only' => isset($panel['partial_numeric']['target']) ? (float) $panel['partial_numeric']['target'] : null,
                default => null,
            };
            $realisasiNum = match ($mode) {
                'numeric' => isset($panel['numeric']['realisasi']) ? (float) $panel['numeric']['realisasi'] : null,
                'score_only' => isset($panel['partial_numeric']['realisasi']) ? (float) $panel['partial_numeric']['realisasi'] : null,
                default => null,
            };

            $rawPct = $panel['score']['percent'] ?? null;
            $scale = $panel['score']['scale'] ?? null;
            $pct = ($rawPct !== null && $scale !== 'unverified') ? round((float) $rawPct, 1) : null;

            $tier = $pct === null ? 'unverified' : KpiScoreNormalizer::ragTier($pct);
            $satuan = KpiScoreNormalizer::normalizeUnit($panel['satuan'] ?? null) ?? 'Tidak tercatat';

            $cmp = KpiScoreNormalizer::describeComparison([
                'target' => $targetNum,
                'realisasi' => $realisasiNum,
                'satuan' => $panel['satuan'] ?? null,
                'polarity' => $meta?->polarity ?? ($panel['polarity'] ?? null),
                'target_type' => $panel['target_type'] ?? ($mode === 'date' ? 'date' : 'numeric'),
                'realisasi_type' => $panel['realisasi_type'] ?? ($mode === 'date' ? 'date' : 'numeric'),
                'target_text' => $panel['target_text'] ?? null,
                'realisasi_text' => $panel['realisasi_text'] ?? null,
                'delta_days' => $panel['date']['delta_days'] ?? null,
                'skor_kpi' => $pct,
                'tier' => $tier,
            ]);

            if ($mode === 'date') {
                $targetDisplay = (string) ($panel['date']['target'] ?? 'Tidak ada target');
                $realisasiDisplay = (string) ($panel['date']['realisasi'] ?? 'Belum tersedia');
            } else {
                $targetDisplay = $targetNum !== null
                    ? KpiScoreNormalizer::formatNumber($targetNum, 1)
                    : ($cmp['target_override'] ?? 'Tidak tercatat');
                $realisasiDisplay = $realisasiNum !== null
                    ? KpiScoreNormalizer::formatNumber($realisasiNum, 1)
                    : ($cmp['realisasi_override'] ?? 'Tidak tercatat');
            }

            $indicators[] = [
                'key' => $unit.'|'.$id.'|'.$idx,
                'indicator_id' => $panel['indicator_id'] ?? null,
                'unit' => $unit,
                'group' => $entry['group'],
                'indikator' => (string) ($panel['name'] ?? ''),
                'satuan' => $satuan,
                'polaritas' => $polaritas,
                'bobot' => $bobot,
                'mode' => $mode,
                'target_num' => $targetNum,
                'realisasi_num' => $realisasiNum,
                'target_display' => $targetDisplay,
                'realisasi_display' => $realisasiDisplay,
                'indicator_hint' => $cmp['indicator_hint'],
                'target_hint' => $cmp['target_hint'],
                'realisasi_hint' => $cmp['realisasi_hint'],
                'delta_display' => $cmp['diff_label'],
                'pencapaian' => $pct,
                'pencapaian_display' => $pct !== null ? KpiScoreNormalizer::formatNumber($pct, 1, '%') : 'Belum tersedia',
                'tier' => $tier,
                'status' => $tierToStatus($tier),
            ];
        }

        if ($indicators === [] && ! empty($kinerjaDetailData['rows'])) {
            foreach ($kinerjaDetailData['rows'] as $idx => $row) {
                $pctRaw = array_key_exists('skor_kpi', $row) ? $row['skor_kpi'] : ($row['pencapaian'] ?? null);
                $pct = $pctRaw !== null ? round((float) $pctRaw, 1) : null;
                $tier = $pct === null ? 'unverified' : KpiScoreNormalizer::ragTier($pct);
                $isDate = ($row['target_display'] ?? null) !== null || ($row['realisasi_display'] ?? null) !== null;
                $targetNum = (! $isDate && isset($row['target']) && $row['target'] !== null) ? (float) $row['target'] : null;
                $realisasiNum = (! $isDate && isset($row['realisasi']) && $row['realisasi'] !== null) ? (float) $row['realisasi'] : null;
                $satuan = KpiScoreNormalizer::normalizeUnit($row['satuan'] ?? null) ?? 'Tidak tercatat';
                $cmp = KpiScoreNormalizer::describeComparison([
                    'target' => $targetNum,
                    'realisasi' => $realisasiNum,
                    'satuan' => $row['satuan'] ?? null,
                    'polarity' => $row['polarity'] ?? null,
                    'target_type' => $row['target_type'] ?? ($isDate ? 'date' : 'numeric'),
                    'realisasi_type' => $row['realisasi_type'] ?? ($isDate ? 'date' : 'numeric'),
                    'target_text' => $row['target_text'] ?? null,
                    'realisasi_text' => $row['realisasi_text'] ?? null,
                    'target_date' => $row['target_date'] ?? ($row['target_display'] ?? null),
                    'realisasi_date' => $row['realisasi_date'] ?? ($row['realisasi_display'] ?? null),
                    'skor_kpi' => $pct,
                    'tier' => $tier,
                ]);

                $indicators[] = [
                    'key' => ($row['unit'] ?? '').'|'.$idx,
                    'indicator_id' => null,
                    'unit' => (string) ($row['unit'] ?? ''),
                    'group' => 'Umum',
                    'indikator' => (string) ($row['indikator'] ?? ''),
                    'satuan' => $satuan,
                    'polaritas' => ($row['polarity'] ?? null) === KpiReportConverter::POLARITY_LOWER_IS_BETTER ? 'Negatif' : 'Positif',
                    'bobot' => 'Tidak tercatat',
                    'mode' => $isDate ? 'date' : 'numeric',
                    'target_num' => $targetNum,
                    'realisasi_num' => $realisasiNum,
                    'target_display' => (string) ($row['target_display'] ?? $cmp['target_override'] ?? KpiScoreNormalizer::formatNumber($targetNum, 1)),
                    'realisasi_display' => (string) ($row['realisasi_display'] ?? $cmp['realisasi_override'] ?? KpiScoreNormalizer::formatNumber($realisasiNum, 1)),
                    'indicator_hint' => $cmp['indicator_hint'],
                    'target_hint' => $cmp['target_hint'],
                    'realisasi_hint' => $cmp['realisasi_hint'],
                    'delta_display' => $cmp['diff_label'],
                    'pencapaian' => $pct,
                    'pencapaian_display' => $pct !== null ? KpiScoreNormalizer::formatNumber($pct, 1, '%') : 'Belum tersedia',
                    'tier' => $tier,
                    'status' => $tierToStatus($tier),
                ];
            }
        }

        if ($activeUnits !== []) {
            $unitLabel = implode(', ', $activeUnits);
        } elseif (! empty($this->visibleUnits())) {
            $unitLabel = implode(', ', $this->visibleUnits());
        } elseif (! empty($targetRealisasiData['units'])) {
            $unitLabel = implode(', ', $targetRealisasiData['units']);
        } else {
            $unitLabel = 'Semua Unit';
        }

        return [
            'period' => $period,
            'unitLabel' => $unitLabel,
            'ragThresholds' => $rag,
            'indicators' => $indicators,
        ];
    }

    /**
     * Semua unit yang punya baris NKO di divisi user (untuk dropdown filter).
     * Ditampilkan SERVER-SIDE agar dropdown tetap lengkap meski filter aktif
     * (kalau diambil dari payload API, unit yang ter-filter akan menghilang
     * dari daftar pilihannya sendiri).
     *
     * Flag-gated sejak Unit 5B: mode 'legacy' (override eksplisit) -> query lama persis.
     * Branch 'structured' membaca nama unit dari org_units.name.
     *
     * @return list<string>
     */
    public function availableUnits(?User $user): array
    {
        $isPrivileged = $this->isPrivileged($user);

        if ($this->kpiReadModelStructured()) {
            if (! $isPrivileged && $user?->division_id === null) {
                return [];
            }

            // Nama unit = identity asli importer terstruktur, jadi dibaca
            // langsung dari org_units.name; metric_name legacy TIDAK di-parse.
            //
            // Selisih semantik yang disadari: versi legacy menyaring unit yang
            // punya baris NKO, sedangkan tabel terstruktur sengaja tidak
            // menyimpan NKO sama sekali (StructuredKpiImporter mengecualikan
            // baris NKO). Jadi di sini unit = unit yang punya observasi pada
            // dataset READY. Untuk workbook asli keduanya sama (15 unit), tapi
            // ini BUKAN jaminan model.
            return $this->scopeUnits(DB::table('performance_observations')
                ->join('datasets', 'datasets.id', '=', 'performance_observations.dataset_id')
                ->join('org_units', 'org_units.id', '=', 'performance_observations.org_unit_id')
                ->where('datasets.status', Dataset::STATUS_READY)
                ->when(! $isPrivileged, fn ($query) => $query->where('performance_observations.division_id', $user?->division_id))
                ->distinct()
                ->pluck('org_units.name')
                ->unique()
                ->sort()
                ->values()
                ->all());
        }

        return $this->scopeUnits(DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.metric_name', 'like', '%'.KpiReportConverter::NKO_SUFFIX)
            ->when(! $isPrivileged, fn ($query) => $query->where('performance_metrics.division_id', $user?->division_id))
            ->distinct()
            ->pluck('performance_metrics.metric_name')
            ->map(fn (string $metric): string => explode(' | ', $metric, 2)[0])
            ->unique()
            ->sort()
            ->values()
            ->all());
    }

    public function isPrivileged(?User $user): bool
    {
        return $user !== null && (
            $user->hasRole(Role::ADMIN)
            || $user->hasRole(Role::MANAGEMENT)
            || $user->hasRole(Role::EDITOR_DASHBOARD)
        );
    }

    /**
     * Peringkat NKO per unit.
     *
     * Hanya baris berakhiran '| NKO' (dihasilkan KpiReportConverter) yang dihitung.
     * Aturan scoping divisi:
     * - Admin, Management, dan Editor Dashboard: lintas divisi.
     * - Staf Divisi / role lain: hanya divisinya sendiri.
     *
     * Filter tanggal (opsi 2026-10-01): bila rentang diberikan, periode yang
     * dipakai = periode TERAKHIR DI DALAM rentang itu. NKO adalah indeks, bukan
     * besaran aditif, jadi rentang tidak dijumlahkan/dirata-ratakan — hanya
     * menggeser "periode terbaru" ke dalam rentang. Filter unit sengaja TIDAK
     * diterapkan di sini (keputusan user 2026-10-01).
     *
     * @return Collection<int, \stdClass>
     */
    public function nkoRanking(?User $user, ?string $startDate = null, ?string $endDate = null): Collection
    {
        if ($this->kpiReadModelStructured()) {
            return $this->nkoRankingStructured($user, $startDate, $endDate);
        }

        $isPrivileged = $this->isPrivileged($user);

        $base = fn () => DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.metric_name', 'like', '%'.KpiReportConverter::NKO_SUFFIX)
            ->when(! $isPrivileged, fn ($query) => $query->where('performance_metrics.division_id', $user?->division_id))
            ->when($startDate !== null, fn ($query) => $query->where('performance_metrics.period', '>=', $startDate))
            ->when($endDate !== null, fn ($query) => $query->where('performance_metrics.period', '<=', $endDate));

        $latestPeriod = $base()->max('performance_metrics.period');

        if ($latestPeriod === null) {
            return collect();
        }

        return $base()
            ->where('performance_metrics.period', $latestPeriod)
            ->select('performance_metrics.metric_name', 'performance_metrics.value as nko')
            ->orderByDesc('performance_metrics.value')
            ->get()
            ->map(function (object $row): object {
                $row->unit = explode(' | ', $row->metric_name, 2)[0];

                return $row;
            })
            ->filter(fn (object $row): bool => $this->unitVisible($row->unit))
            ->values();
    }

    /**
     * Periode terbaru dengan data READY di divisi user (atau lintas divisi
     * untuk role privileged). Sama sumbernya dengan nkoRanking, tapi tanpa
     * filter nama NKO supaya widget Aset/Kinerja lain bisa memakai aturan
     * tanggal yang konsisten.
     *
     * Sejak Unit 5B reader ini flag-gated (config('kpi.read_model')); default
     * 'structured' sejak Fase 4.3 Tahap B, sedangkan 'legacy' (override eksplisit)
     * -> query lama persis. Lihat kpiReadModelStructured().
     *
     * Caller pada jalur legacy memanggil latestLegacyReadyPeriod() secara
     * eksplisit saat membaca baris performance_metrics (mis. R5/R6/R7 pada
     * cabang legacy, atau fallback R5 non-KPI) — lihat catatan pada
     * masing-masing reader.
     */
    public function latestReadyPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
        return $this->kpiReadModelStructured()
            ? $this->latestStructuredReadyPeriod($user, $startDate, $endDate)
            : $this->latestLegacyReadyPeriod($user, $startDate, $endDate);
    }

    /**
     * Apakah reader KPI yang flag-gated harus membaca tabel terstruktur.
     *
     * Reader aktif (R2-R7, R9, R10) mengikuti flag ini; R9 mengikuti R6 karena
     * mendelegasi ke sana, sedangkan `target-realisasi-per-indikator` selalu
     * membaca tabel terstruktur. R8 (`targetVsRealisasi`) telah dipensiunkan
     * dan dihapus pada Fase 4.3 Tahap C.
     */
    public function kpiReadModelStructured(): bool
    {
        return config('kpi.read_model') === 'structured';
    }

    /**
     * Periode terbaru dari tabel legacy performance_metrics.
     *
     * Dipakai eksplisit oleh cabang legacy reader R4/R5/R6/R7 (serta fallback
     * non-KPI pada R5 structured). Caller legacy menggabungkan periode ini
     * dengan agregasi performance_metrics, sehingga tidak boleh mengikuti
     * periode terstruktur saat mode rollback `KPI_READ_MODEL=legacy` aktif.
     */
    public function latestLegacyReadyPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
        $isPrivileged = $this->isPrivileged($user);

        $base = fn () => DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->when(! $isPrivileged, fn ($query) => $query->where('performance_metrics.division_id', $user?->division_id))
            ->when($startDate !== null, fn ($query) => $query->where('performance_metrics.period', '>=', $startDate))
            ->when($endDate !== null, fn ($query) => $query->where('performance_metrics.period', '<=', $endDate));

        return $base()->max('performance_metrics.period');
    }

    /**
     * Periode terbaru dari read model terstruktur (performance_observations).
     *
     * Bentuk query sengaja kembar dengan versi legacy: join datasets untuk
     * hormati status READY, scope division_id, dan rentang start/end. Tidak ada
     * konversi periode: nilai yang tersimpan dikembalikan apa adanya
     * (2026-08-01 tetap 2026-08-01, bukan digeser ke akhir bulan).
     */
    public function latestStructuredReadyPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
        if (! $this->isPrivileged($user) && $user?->division_id === null) {
            return null;
        }

        $isPrivileged = $this->isPrivileged($user);

        $base = fn () => DB::table('performance_observations')
            ->join('datasets', 'datasets.id', '=', 'performance_observations.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->when(! $isPrivileged, fn ($query) => $query->where('performance_observations.division_id', $user?->division_id))
            ->when($startDate !== null, fn ($query) => $query->where('performance_observations.period', '>=', $startDate))
            ->when($endDate !== null, fn ($query) => $query->where('performance_observations.period', '<=', $endDate));

        return $base()->max('performance_observations.period');
    }

    /**
     * Per unit: berapa indikator yang realisasi >= target (pencapaian >=100%),
     * rata-rata pencapaian, dan status kategori. Sumber: baris '| target'
     * dan '| realisasi' pada periode terbaru di divisi user, diselaraskan
     * dengan skor PENCAPAIAN Excel di performance_observations bila tersedia.
     *
     * Sejak migrasi R6 reader ini flag-gated; default 'structured' (Tahap B). R9
     * (pencapaianNko) mendelegasi ke sini, jadi ikut aturan yang sama.
     *
     * @return array{period: ?string, rows: list<array<string, mixed>>, has_data: bool}
     */
    public function kinerjaRealisasi(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        $this->kinerjaRealisasiAmbiguousCount = 0;

        if ($this->kpiReadModelStructured()) {
            return $this->kinerjaRealisasiStructured($user, $startDate, $endDate);
        }

        $period = $this->latestLegacyReadyPeriod($user, $startDate, $endDate)
            ?? $this->latestLegacyScoredObservationPeriod($user, $startDate, $endDate);

        if ($period === null) {
            return ['period' => null, 'rows' => [], 'has_data' => false];
        }

        $isPrivileged = $this->isPrivileged($user);
        $pairs = [];
        $pairDatasets = [];
        $pairKindCounts = [];
        $unitDatasets = [];

        DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.period', $period)
            ->where(function ($query) {
                $query->where('performance_metrics.metric_name', 'like', '%| target')
                    ->orWhere('performance_metrics.metric_name', 'like', '%| realisasi');
            })
            ->when(! $isPrivileged, fn ($q) => $q->where('performance_metrics.division_id', $user?->division_id))
            ->select('performance_metrics.dataset_id', 'performance_metrics.metric_name', 'performance_metrics.value')
            ->get()
            ->each(function (object $row) use (&$pairs, &$pairDatasets, &$pairKindCounts, &$unitDatasets) {
                $parts = explode(' | ', $row->metric_name);
                if (count($parts) !== 3) {
                    return;
                }
                [$unit, $indikator, $jenis] = $parts;
                $kind = strtolower($jenis);
                if ($kind !== 'target' && $kind !== 'realisasi') {
                    return;
                }
                $datasetId = (int) $row->dataset_id;
                $pairs[$unit][$indikator][$kind] = (float) $row->value;
                $pairDatasets[$unit][$indikator][$datasetId] = $datasetId;
                $pairKindCounts[$unit][$indikator][$kind] = ($pairKindCounts[$unit][$indikator][$kind] ?? 0) + 1;
                $unitDatasets[$unit][$datasetId] = $datasetId;
            });

        $pairs = $this->applyLegacyObservationScores(
            $pairs,
            $pairDatasets,
            $pairKindCounts,
            $unitDatasets,
            $period,
            $isPrivileged,
            $user,
        );

        return $this->kinerjaRealisasiRows($period, $pairs);
    }

    /**
     * Fallback periode R6 legacy ketika dataset READY hanya memiliki daun
     * berskor di performance_observations tanpa baris performance_metrics.
     */
    public function latestLegacyScoredObservationPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
        $isPrivileged = $this->isPrivileged($user);

        return DB::table('performance_observations')
            ->join('datasets', 'datasets.id', '=', 'performance_observations.dataset_id')
            ->join('indicators', 'indicators.id', '=', 'performance_observations.indicator_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('indicators.is_group', false)
            ->whereNotNull('performance_observations.pencapaian')
            ->when(! $isPrivileged, fn ($query) => $query->where('performance_observations.division_id', $user?->division_id))
            ->when($startDate !== null, fn ($query) => $query->where('performance_observations.period', '>=', $startDate))
            ->when($endDate !== null, fn ($query) => $query->where('performance_observations.period', '<=', $endDate))
            ->max('performance_observations.period');
    }

    /**
     * Selaraskan pasangan R6 legacy dengan skor Excel dari
     * performance_observations pada dataset READY yang sama:
     * - Pair yang berasal dari > 1 dataset atau memiliki duplikasi baris
     *   target/realisasi dikeluarkan (unset) dan dihitung sebagai ambigu.
     * - Pair dengan observation tunggal: hapus bila is_group = 1 atau
     *   pencapaian NULL; selain itu isi pencapaian via normalizeExcelScore().
     * - Pair tanpa observation cocok (dari 1 dataset konsisten): dipertahankan
     *   untuk fallback rasio dasar.
     * - Pair yang cocok ke > 1 observation dikeluarkan (unset) tanpa fallback
     *   rasio dan dihitung sebagai ambigu.
     * - Daun (is_group = 0) berskor tanpa pair legacy: diinjeksi sebagai entri
     *   skor saja bila kandidat dataset READY pada scope divisi/periode/unit
     *   tunggal; bila > 1 kandidat dataset, injeksi dilewati dan dihitung ambigu.
     *
     * @param  array<string, array<string, array{target?: float, realisasi?: float, pencapaian?: float}>>  $pairs
     * @param  array<string, array<string, array<int, int>>>  $pairDatasets
     * @param  array<string, array<string, array{target?: int, realisasi?: int}>>  $pairKindCounts
     * @param  array<string, array<int, int>>  $unitDatasets
     * @return array<string, array<string, array{target?: float, realisasi?: float, pencapaian?: float}>>
     */
    public function applyLegacyObservationScores(
        array $pairs,
        array $pairDatasets,
        array $pairKindCounts,
        array $unitDatasets,
        string $period,
        bool $isPrivileged,
        ?User $user,
    ): array {
        $byKey = [];
        $obsDatasetsByUnit = [];

        DB::table('performance_observations')
            ->join('datasets', 'datasets.id', '=', 'performance_observations.dataset_id')
            ->join('indicators', 'indicators.id', '=', 'performance_observations.indicator_id')
            ->join('org_units', 'org_units.id', '=', 'performance_observations.org_unit_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_observations.period', $period)
            ->when(! $isPrivileged, fn ($q) => $q->where('performance_observations.division_id', $user?->division_id))
            ->select(
                'performance_observations.dataset_id',
                'org_units.name as unit',
                'indicators.name as indikator',
                'indicators.is_group',
                'performance_observations.pencapaian',
            )
            ->get()
            ->each(function (object $obs) use (&$byKey, &$obsDatasetsByUnit) {
                $datasetId = (int) $obs->dataset_id;
                $byKey[$datasetId][$obs->unit][$obs->indikator][] = $obs;
                $obsDatasetsByUnit[$obs->unit][$datasetId] = $datasetId;
            });

        $legacyPairs = $pairs;

        foreach ($legacyPairs as $unit => $indikators) {
            foreach ($indikators as $indikator => $vals) {
                $datasetIds = array_values($pairDatasets[$unit][$indikator] ?? []);
                $hasDuplicateKind = ($pairKindCounts[$unit][$indikator]['target'] ?? 0) > 1
                    || ($pairKindCounts[$unit][$indikator]['realisasi'] ?? 0) > 1;

                if (count($datasetIds) !== 1 || $hasDuplicateKind) {
                    $this->kinerjaRealisasiAmbiguousCount++;
                    unset($pairs[$unit][$indikator]);

                    continue;
                }

                $datasetId = $datasetIds[0];
                $matches = $byKey[$datasetId][$unit][$indikator] ?? [];

                if ($matches === []) {
                    continue;
                }

                if (count($matches) > 1) {
                    $this->kinerjaRealisasiAmbiguousCount++;
                    unset($pairs[$unit][$indikator]);

                    continue;
                }

                $obs = $matches[0];
                if ((bool) $obs->is_group || $obs->pencapaian === null) {
                    unset($pairs[$unit][$indikator]);

                    continue;
                }

                $pairs[$unit][$indikator]['pencapaian'] = $this->normalizeExcelScore((float) $obs->pencapaian);
            }
        }

        foreach ($obsDatasetsByUnit as $unit => $datasetMap) {
            $hasInjectableOrAmbiguous = false;
            foreach ($datasetMap as $datasetId) {
                foreach ($byKey[$datasetId][$unit] ?? [] as $indikator => $matches) {
                    if (isset($legacyPairs[$unit][$indikator])) {
                        continue;
                    }
                    if (count($matches) > 1 || (! (bool) $matches[0]->is_group && $matches[0]->pencapaian !== null)) {
                        $hasInjectableOrAmbiguous = true;
                        break 2;
                    }
                }
            }

            if (! $hasInjectableOrAmbiguous) {
                continue;
            }

            $candidateDatasets = array_values($datasetMap + ($unitDatasets[$unit] ?? []));

            if (count($candidateDatasets) !== 1) {
                if (count($candidateDatasets) > 1) {
                    $this->kinerjaRealisasiAmbiguousCount++;
                }

                continue;
            }

            $unitDatasetId = $candidateDatasets[0];
            foreach ($byKey[$unitDatasetId][$unit] ?? [] as $indikator => $matches) {
                if (isset($legacyPairs[$unit][$indikator])) {
                    continue;
                }

                if (count($matches) > 1) {
                    $this->kinerjaRealisasiAmbiguousCount++;

                    continue;
                }

                $obs = $matches[0];
                if (! (bool) $obs->is_group && $obs->pencapaian !== null) {
                    $pairs[$unit][$indikator] = [
                        'pencapaian' => $this->normalizeExcelScore((float) $obs->pencapaian),
                    ];
                }
            }
        }

        return $pairs;
    }

    /**
     * Agregasi target/realisasi per unit, dipakai bersama jalur legacy dan
     * terstruktur supaya aturan pencapaian/status/urutan tidak bisa menyimpang
     * antara dua sumber.
     *
     * @param  array<string, array<string|int, array{target?: ?float, realisasi?: ?float, pencapaian?: float}>>  $pairs  pencapaian = persen
     * @return array{period: string, rows: list<array<string, mixed>>, has_data: bool}
     */
    public function kinerjaRealisasiRows(string $period, array $pairs): array
    {
        // Batas unit TAMPIL (keputusan 2026-10-12): unit di luar scope tidak
        // masuk tabel. Diterapkan SETELAH pairs terbentuk, jadi agregasi di
        // dalam unit yang tersisa tidak berubah. Menutup R6 (legacy+structured)
        // sekaligus R9 yang mendelegasi ke sini.
        $pairs = $this->scopeUnitMap($pairs);

        $rows = [];
        foreach ($pairs as $unit => $indikators) {
            $total = 0;
            $achieved = 0;
            $hampir = 0;
            $perlu = 0;
            $pencapaianSum = 0;
            $counted = 0;

            foreach ($indikators as $vals) {
                $target = $vals['target'] ?? null;
                $realisasi = $vals['realisasi'] ?? null;

                // Skor eksplisit (PENCAPAIAN Excel, sudah dalam persen) dipakai
                // jalur structured; jalur legacy tidak punya kolom skor, jadi
                // tetap menghitung rasio dari pasangan target/realisasi.
                $p = $vals['pencapaian'] ?? null;

                if ($p === null && $target !== null && $target > 0 && $realisasi !== null) {
                    $p = ($realisasi / $target) * 100;
                }

                if ($p !== null) {
                    // Batas skor Excel 0%..110% berlaku untuk semua baris,
                    // termasuk ATTB yang realisasinya diterima apa adanya.
                    $p = KpiScoreNormalizer::clamp($p);
                    $total++;
                    $pencapaianSum += $p;
                    $counted++;
                    $tier = KpiScoreNormalizer::ragTier($p);
                    if ($tier === KpiScoreNormalizer::TIER_TERCAPAI) {
                        $achieved++;
                    } elseif ($tier === KpiScoreNormalizer::TIER_HAMPIR) {
                        $hampir++;
                    } else {
                        $perlu++;
                    }
                }
            }

            if ($total > 0) {
                $avg = $pencapaianSum / $counted;
                $avgTier = KpiScoreNormalizer::ragTier($avg);
                $rows[] = [
                    'unit' => $unit,
                    'achieved' => $achieved,
                    'hampir' => $hampir,
                    'perlu' => $perlu,
                    'total' => $total,
                    'avg' => round($avg, 1),
                    'status' => $avgTier === KpiScoreNormalizer::TIER_TERCAPAI
                        ? 'Tercapai'
                        : ($avgTier === KpiScoreNormalizer::TIER_HAMPIR ? 'Hampir Tercapai' : 'Perlu Peningkatan'),
                ];
            }
        }

        usort($rows, fn ($a, $b) => $b['avg'] <=> $a['avg']);

        return ['period' => $period, 'rows' => $rows, 'has_data' => $rows !== []];
    }

    /**
     * Ringkasan performa untuk kartu 4-5 kpi-summary:
     * - Kartu 4: Rata-rata pencapaian indikator daun berskor (maks 110%, unweighted mean).
     * - Kartu 5: Indikator tercapai (achieved / total across all rows).
     *
     * @return array{has_data: bool, avg_pencapaian: ?float, achieved_count: ?int, total_count: ?int}
     */
    public function kpiSummary(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        $kinerja = $this->kinerjaRealisasi($user, $startDate, $endDate);
        $rows = $kinerja['rows'] ?? [];

        if (empty($rows)) {
            return [
                'has_data' => false,
                'avg_pencapaian' => null,
                'achieved_count' => null,
                'total_count' => null,
            ];
        }

        $achievedCount = (int) array_sum(array_column($rows, 'achieved'));
        $totalCount = (int) array_sum(array_column($rows, 'total'));
        $avgPencapaian = count($rows) > 0
            ? round(array_sum(array_column($rows, 'avg')) / count($rows), 1)
            : null;

        return [
            'has_data' => true,
            'avg_pencapaian' => $avgPencapaian,
            'achieved_count' => $achievedCount,
            'total_count' => $totalCount,
        ];
    }

    /**
     * Ubin status RAG indikator daun (Tercapai >= 100, Hampir 95..100, Perlu < 95).
     * Menjumlahkan achieved, hampir, perlu, dan total di SEMUA baris kinerjaRealisasi.
     *
     * @return array{period: ?string, has_data: bool, counts: array{tercapai: int, hampir: int, perlu: int, total: int}}
     */
    public function kpiRagStatus(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        $kinerja = $this->kinerjaRealisasi($user, $startDate, $endDate);
        $rows = $kinerja['rows'] ?? [];

        if (empty($rows)) {
            return [
                'period' => $kinerja['period'] ?? null,
                'has_data' => false,
                'counts' => [
                    'tercapai' => 0,
                    'hampir' => 0,
                    'perlu' => 0,
                    'total' => 0,
                ],
            ];
        }

        $tercapai = (int) array_sum(array_column($rows, 'achieved'));
        $hampir = (int) array_sum(array_column($rows, 'hampir'));
        $perlu = (int) array_sum(array_column($rows, 'perlu'));
        $total = (int) array_sum(array_column($rows, 'total'));

        return [
            'period' => $kinerja['period'] ?? null,
            'has_data' => $total > 0,
            'counts' => [
                'tercapai' => $tercapai,
                'hampir' => $hampir,
                'perlu' => $perlu,
                'total' => $total,
            ],
        ];
    }

    /**
     * Detail baris metrik per unit+indikator pada periode terbaru: target,
     * realisasi, pencapaian %, dan NKO unit. Cap 50 baris + flag truncated.
     *
     * `$units` = pilihan unit dari query (`?unit=TJBT`). Filter diterapkan
     * SETELAH baris jadi dan SEBELUM cap, jadi cap 50 + total_rows + truncated
     * dihitung per unit terpilih. Kosong atau tidak ada yang cocok di data ->
     * perilaku lama PERSIS (seluruh baris, cap global).
     *
     * @param  list<string>  $units
     * @return array{period: ?string, rows: list<array<string, mixed>>, truncated: bool, total_rows: int}
     */
    public function kinerjaDetail(?User $user, ?string $startDate = null, ?string $endDate = null, array $units = []): array
    {
        if ($this->kpiReadModelStructured()) {
            return $this->kinerjaDetailStructured($user, $startDate, $endDate, $units);
        }

        // Jalur legacy: periode maupun agregasinya tetap dari performance_metrics.
        $period = $this->latestLegacyReadyPeriod($user, $startDate, $endDate);

        if ($period === null) {
            $this->kinerjaDetailUnits = [];

            return ['period' => null, 'rows' => [], 'truncated' => false, 'total_rows' => 0];
        }

        $isPrivileged = $this->isPrivileged($user);
        $pairs = [];
        $dateCandidates = [];
        $nko = [];

        DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.period', $period)
            ->when(! $isPrivileged, fn ($q) => $q->where('performance_metrics.division_id', $user?->division_id))
            ->where(function ($query) {
                $query->where('performance_metrics.metric_name', 'like', '%| target')
                    ->orWhere('performance_metrics.metric_name', 'like', '%| realisasi')
                    ->orWhere('performance_metrics.metric_name', 'like', '%| NKO');
            })
            ->select('performance_metrics.dataset_id', 'performance_metrics.metric_name', 'performance_metrics.value')
            ->get()
            ->each(function (object $row) use (&$pairs, &$dateCandidates, &$nko) {
                $parts = explode(' | ', $row->metric_name);
                if (count($parts) === 2 && strtolower($parts[1]) === 'nko') {
                    $nko[$parts[0]] = (float) $row->value;

                    return;
                }
                if (count($parts) !== 3) {
                    return;
                }
                [$unit, $indikator, $jenis] = $parts;
                $kind = strtolower($jenis);
                $pairs[$unit][$indikator][$kind] = (float) $row->value;
                $dateCandidates[$unit][$indikator][$kind] = ['dataset_id' => $row->dataset_id];
            });

        // Batas unit TAMPIL (keputusan 2026-10-12): unit di luar scope dibuang
        // dari peta, sehingga baris, dropdown, dan cap 50 hanya menyangkut unit
        // yang boleh tampil. Diterapkan SETELAH periode dipilih.
        $pairs = $this->scopeUnitMap($pairs);
        $dateDisplays = $this->kinerjaDetailDateDisplays($dateCandidates, $period);

        $rows = [];
        foreach ($pairs as $unit => $indikators) {
            foreach ($indikators as $indikator => $vals) {
                $target = $vals['target'] ?? null;
                $realisasi = $vals['realisasi'] ?? null;
                $dateDisplay = $dateDisplays[$unit][$indikator] ?? [];
                $pencapaian = array_key_exists('pencapaian', $dateDisplay)
                    ? $dateDisplay['pencapaian']
                    : (($target !== null && $target > 0 && $realisasi !== null)
                        ? round($realisasi / $target * 100, 1) : null);
                $rows[] = [
                    'unit' => $unit,
                    'indikator' => $indikator,
                    'target' => $target,
                    'realisasi' => $realisasi,
                    'target_display' => $dateDisplay['target'] ?? null,
                    'realisasi_display' => $dateDisplay['realisasi'] ?? null,
                    'pencapaian' => $pencapaian,
                    'skor_kpi' => $pencapaian !== null ? round(KpiScoreNormalizer::clamp((float) $pencapaian), 1) : null,
                    'nko' => $nko[$unit] ?? null,
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$a['unit'], $a['indikator']] <=> [$b['unit'], $b['indikator']]);

        // Daftar unit lengkap (TJBT dulu) untuk dropdown, diambil SEBELUM
        // filter/cap supaya unit yang barisnya terpotong tetap bisa dipilih.
        $this->kinerjaDetailUnits = $this->kinerjaDetailUnitOrder(array_column($rows, 'unit'));

        // Filter HANYA bila unit yang diminta ada di data; kalau tidak ada yang
        // cocok, perilaku default (seluruh baris) yang berlaku.
        if ($units !== []) {
            $selected = array_values(array_intersect($units, $this->kinerjaDetailUnits));
            if ($selected !== []) {
                $rows = array_values(array_filter(
                    $rows,
                    fn (array $row): bool => in_array($row['unit'], $selected, true),
                ));
            }
        }

        $total = count($rows);
        $truncated = $total > 50;
        if ($truncated) {
            $rows = array_slice($rows, 0, 50);
        }

        return ['period' => $period, 'rows' => $rows, 'truncated' => $truncated, 'total_rows' => $total];
    }

    /**
     * Presentation-only dates for R7, matched by the exact legacy dataset and
     * the structured period/unit/indicator identity. No serial-number guessing.
     *
     * @param  array<string, array<string, array<string, array{dataset_id: int}>>>  $candidates
     * @return array<string, array<string, array{target?: string, realisasi?: string, pencapaian?: float|null}>>
     */
    public function kinerjaDetailDateDisplays(array $candidates, string $period): array
    {
        $datasetIds = collect($candidates)->flatMap(fn (array $indicators) => collect($indicators)
            ->flatMap(fn (array $values) => array_column($values, 'dataset_id')))
            ->unique()
            ->values();

        if ($datasetIds->isEmpty()) {
            return [];
        }

        $dates = [];
        DB::table('performance_observations')
            ->join('indicators', 'indicators.id', '=', 'performance_observations.indicator_id')
            ->join('org_units', 'org_units.id', '=', 'performance_observations.org_unit_id')
            ->whereIn('performance_observations.dataset_id', $datasetIds)
            ->where('performance_observations.period', $period)
            ->where(function ($query) {
                $query->where('performance_observations.target_type', PerformanceObservation::TYPE_DATE)
                    ->orWhere('performance_observations.realisasi_type', PerformanceObservation::TYPE_DATE);
            })
            ->select(
                'performance_observations.dataset_id',
                'org_units.name as unit',
                'indicators.name as indikator',
                'performance_observations.target_type',
                'performance_observations.target_date',
                'performance_observations.realisasi_type',
                'performance_observations.realisasi_date',
                'performance_observations.pencapaian',
            )
            ->get()
            ->each(function (object $observation) use (&$dates) {
                foreach (['target', 'realisasi'] as $kind) {
                    if ($observation->{$kind.'_type'} === PerformanceObservation::TYPE_DATE
                        && $observation->{$kind.'_date'} !== null) {
                        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $observation->{$kind.'_date'});
                        if ($date !== false) {
                            $dates[$observation->dataset_id][$observation->unit][$observation->indikator][$kind] = $date->format('d/m/Y');
                        }
                    }
                }

                // Skor workbook (rasio) untuk baris tanggal, skala persen seperti
                // consumer R6. Key tetap ADA walau skornya NULL: baris tanggal
                // tanpa skor tidak boleh jatuh ke rasio serial tanggal.
                if ($observation->target_type === PerformanceObservation::TYPE_DATE
                    || $observation->realisasi_type === PerformanceObservation::TYPE_DATE) {
                    $dates[$observation->dataset_id][$observation->unit][$observation->indikator]['pencapaian'] =
                        $observation->pencapaian !== null ? round((float) $observation->pencapaian * 100, 1) : null;
                }
            });

        $display = [];
        foreach ($candidates as $unit => $indicators) {
            foreach ($indicators as $indicator => $values) {
                foreach (['target', 'realisasi'] as $kind) {
                    $candidate = $values[$kind] ?? null;
                    if ($candidate !== null) {
                        $value = $dates[$candidate['dataset_id']][$unit][$indicator][$kind] ?? null;
                        if ($value !== null) {
                            $display[$unit][$indicator][$kind] = $value;
                        }
                    }
                }

                $scoreDataset = $values['target']['dataset_id'] ?? $values['realisasi']['dataset_id'] ?? null;
                if ($scoreDataset !== null && array_key_exists('pencapaian', $dates[$scoreDataset][$unit][$indicator] ?? [])) {
                    $display[$unit][$indicator]['pencapaian'] = $dates[$scoreDataset][$unit][$indicator]['pencapaian'];
                }
            }
        }

        return $display;
    }

    /**
     * Urutkan nama unit untuk dropdown: `TJBT` di depan bila ada, sisanya
     * alfabetis. Dedup dulu supaya satu unit tidak muncul dua kali.
     *
     * @param  list<string>  $units
     * @return list<string>
     */
    public function kinerjaDetailUnitOrder(array $units): array
    {
        $units = array_values(array_unique($units));
        sort($units);

        $index = array_search('TJBT', $units, true);
        if ($index !== false) {
            unset($units[$index]);
            $units = array_merge(['TJBT'], $units);
        }

        return $units;
    }

    /**
     * Period helper untuk reader terstruktur (termasuk panel per indikator).
     *
     * SENGAJA tidak memakai `latestReadyPeriod()`: pada read model
     * `legacy` helper itu memilih periode dari `performance_metrics`, sedangkan
     * reader panel `targetRealisasiPerIndikator` selalu membaca dataset
     * terstruktur READY berapa pun nilai flag. Mendelegasikan ke
     * `latestStructuredReadyPeriod()` supaya hanya ada satu implementasi query.
     */
    public function latestStructuredPanelPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
        return $this->latestStructuredReadyPeriod($user, $startDate, $endDate);
    }

    /**
     * Panel Target vs Realisasi PER INDIKATOR (keputusan Pak Gama 2026-10-19
     * 11:17 & Keputusan Bisnis 5 2026-10-25). Sumber dan aturan klasifikasi ada
     * di `TargetRealisasiPerIndicatorReader`; method ini hanya menyediakan
     * periode, scope divisi (RBAC), dan batas unit TAMPIL.
     *
     * Isolasi: TIDAK membaca `kpi.read_model`. Reader agregat lama
     * `targetVsRealisasi()`/R8 telah dipensiunkan dan dihapus pada Fase 4.3
     * Tahap C.
     *
     * @return array{
     *     period: string|null,
     *     units: list<string>,
     *     sections: list<array{unit: string, indicator_id: int|null, name: string|null, panels: list<array<string, mixed>>}>
     * }
     */
    public function targetRealisasiPerIndikator(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        $period = $this->latestStructuredPanelPeriod($user, $startDate, $endDate);

        if ($period === null) {
            return ['period' => null, 'units' => [], 'sections' => []];
        }

        return $this->targetRealisasiPanel->panels(
            $this->isPrivileged($user) ? null : $user?->division_id,
            $period,
            $this->visibleUnits(),
        );
    }

    /**
     * Bar sederhana: pencapaian rata-rata (%) per unit, agar satu periode
     * terbaca sebagai "berapa persen dari target tercapai" per unit.
     *
     * @return array{period: ?string, labels: list<string>, data: list<float>}
     */
    public function pencapaianNko(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        $rekap = $this->kinerjaRealisasi($user, $startDate, $endDate);

        return [
            'period' => $rekap['period'] ?? null,
            'labels' => array_map(fn ($row) => $row['unit'], $rekap['rows']),
            'data' => array_map(fn ($row) => $row['avg'], $rekap['rows']),
        ];
    }

    /**
     * Donut klasifikasi NKO per kategori pada periode aktif:
     * Tercapai (>=100), Hampir Tercapai (95-<100), Perlu Peningkatan (<95).
     * Persamaan kategori dengan nko-ranking agar konsisten.
     *
     * @return array{period: ?string, data: list<array{name: string, value: int}>}
     */
    public function nkoDonut(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        if ($this->kpiReadModelStructured()) {
            return $this->nkoDonutStructured($user, $startDate, $endDate);
        }

        $isPrivileged = $this->isPrivileged($user);
        $base = fn () => DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.metric_name', 'like', '%'.KpiReportConverter::NKO_SUFFIX)
            ->when(! $isPrivileged, fn ($q) => $q->where('performance_metrics.division_id', $user?->division_id))
            ->when($startDate !== null, fn ($q) => $q->where('performance_metrics.period', '>=', $startDate))
            ->when($endDate !== null, fn ($q) => $q->where('performance_metrics.period', '<=', $endDate));

        $period = $base()->max('performance_metrics.period');

        if ($period === null) {
            return ['period' => null, 'data' => []];
        }

        $counts = ['Tercapai' => 0, 'Hampir Tercapai' => 0, 'Perlu Peningkatan' => 0];

        $base()
            ->where('performance_metrics.period', $period)
            ->select('performance_metrics.metric_name', 'performance_metrics.value')
            ->get()
            ->each(function (object $row) use (&$counts) {
                // Batas unit TAMPIL (keputusan 2026-10-12): unit di luar scope
                // tidak dihitung. Diterapkan SETELAH periode (dataset terbaru)
                // dipilih, jadi klasifikasi periode aktif tidak berubah.
                if (! $this->unitVisible(explode(' | ', $row->metric_name, 2)[0])) {
                    return;
                }

                $v = (float) $row->value;
                $tier = KpiScoreNormalizer::ragTier($v);
                if ($tier === KpiScoreNormalizer::TIER_TERCAPAI) {
                    $counts['Tercapai']++;
                } elseif ($tier === KpiScoreNormalizer::TIER_HAMPIR) {
                    $counts['Hampir Tercapai']++;
                } else {
                    $counts['Perlu Peningkatan']++;
                }
            });

        $data = [];
        foreach ($counts as $name => $value) {
            if ($value > 0) {
                $data[] = ['name' => $name, 'value' => $value];
            }
        }

        return ['period' => $period, 'data' => $data];
    }

    /**
     * R3 branch terstruktur: peringkat NKO pada dataset terbaru.
     *
     * Semantik yang dipertahankan: periode = periode terbaru yang PUNYA baris
     * NKO di dalam rentang tanggal, urut menurun. Yang berbeda: satu unit
     * muncul sekali (dataset terbaru menang), bukan sekali per baris.
     *
     * `metric_name` disintesis dengan akhiran yang sama seperti jalur legacy
     * supaya bentuk objeknya identik dan view tidak perlu tahu sumbernya.
     *
     * @return Collection<int, \stdClass>
     */
    public function nkoRankingStructured(?User $user, ?string $startDate = null, ?string $endDate = null): Collection
    {
        if (! $this->isPrivileged($user) && $user?->division_id === null) {
            return collect();
        }

        $divisionId = $this->isPrivileged($user) ? null : $user?->division_id;
        $period = $this->structuredNkoPeriod($divisionId, $startDate, $endDate);

        if ($period === null) {
            return collect();
        }

        return $this->structuredNko->latestForPeriod($divisionId, $period)
            ->map(function (array $row): \stdClass {
                $item = new \stdClass;
                $item->metric_name = $row['unit'].KpiReportConverter::NKO_SUFFIX;
                $item->nko = $row['nko'];
                $item->unit = $row['unit'];

                return $item;
            })
            // Batas unit TAMPIL (keputusan 2026-10-12), sama seperti jalur
            // legacy. Diterapkan SETELAH periode + dataset terbaru dipilih,
            // jadi "latest dataset wins" tidak berubah.
            ->filter(fn (object $row): bool => $this->unitVisible($row->unit))
            ->values();
    }

    /**
     * R10 branch terstruktur: sebaran kategori NKO pada dataset terbaru.
     *
     * Ambang dan URUTAN kategori disalin persis dari jalur legacy
     * (>=100 / >=95 / else, dan urutan tetap Tercapai, Hampir Tercapai, Perlu
     * Peningkatan). Kategori bernilai nol tetap di-omit.
     *
     * @return array{period: ?string, data: list<array{name: string, value: int}>}
     */
    public function nkoDonutStructured(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        if (! $this->isPrivileged($user) && $user?->division_id === null) {
            return ['period' => null, 'data' => []];
        }

        $divisionId = $this->isPrivileged($user) ? null : $user?->division_id;
        $period = $this->structuredNkoPeriod($divisionId, $startDate, $endDate);

        if ($period === null) {
            return ['period' => null, 'data' => []];
        }

        $counts = ['Tercapai' => 0, 'Hampir Tercapai' => 0, 'Perlu Peningkatan' => 0];

        foreach ($this->structuredNko->latestForPeriod($divisionId, $period) as $row) {
            // Batas unit TAMPIL (keputusan 2026-10-12), sama seperti jalur
            // legacy. Diterapkan SETELAH dataset terbaru dipilih.
            if (! $this->unitVisible($row['unit'])) {
                continue;
            }

            $value = $row['nko'];
            $tier = KpiScoreNormalizer::ragTier($value);

            if ($tier === KpiScoreNormalizer::TIER_TERCAPAI) {
                $counts['Tercapai']++;
            } elseif ($tier === KpiScoreNormalizer::TIER_HAMPIR) {
                $counts['Hampir Tercapai']++;
            } else {
                $counts['Perlu Peningkatan']++;
            }
        }

        $data = [];
        foreach ($counts as $name => $value) {
            if ($value > 0) {
                $data[] = ['name' => $name, 'value' => $value];
            }
        }

        return ['period' => $period, 'data' => $data];
    }

    /**
     * R7 branch terstruktur: detail metrik KPI per unit/indikator dari
     * `performance_observations` pada dataset siap terbaru yang otoritatif.
     *
     * Baris grup/induk (`indicators.is_group = true`) dan baris ringkasan NKO
     * (`indicators.category = 'nko'`) tidak menjadi baris detail indikator;
     * NKO tetap ditempel per unit lewat kolom `nko` dari dataset terbaru.
     *
     * Skor `pencapaian`:
     * - Baris bertipe tanggal memakai skor workbook terstruktur (keputusan
     *   2026-10-21) dan tidak jatuh ke rasio serial tanggal saat skor NULL.
     * - Pasangan target/realisasi numerik tetap memakai `realisasi / target * 100`
     *   sampai ada keputusan eksplisit untuk mengubah rumus numerik R7
     *   (keputusan 2026-10-25 di `.ai/decisions.md` baru mencakup R6/R9).
     * - Baris daun (`is_group = 0`) tanpa pasangan target/realisasi numerik
     *   yang memiliki skor workbook valid memakai `normalizeExcelScore()`.
     *
     * @param  list<string>  $units
     * @return array{period: ?string, rows: list<array<string, mixed>>, truncated: bool, total_rows: int}
     */
    public function kinerjaDetailStructured(?User $user, ?string $startDate = null, ?string $endDate = null, array $units = []): array
    {
        $period = $this->latestStructuredReadyPeriod($user, $startDate, $endDate);

        if ($period === null) {
            $this->kinerjaDetailUnits = [];

            return ['period' => null, 'rows' => [], 'truncated' => false, 'total_rows' => 0];
        }

        $isPrivileged = $this->isPrivileged($user);
        $divisionId = $isPrivileged ? null : $user?->division_id;
        $latestIds = $this->structuredNko->latestDatasetIds($divisionId, $period);

        if ($latestIds === []) {
            $this->kinerjaDetailUnits = [];

            return ['period' => $period, 'rows' => [], 'truncated' => false, 'total_rows' => 0];
        }

        $nko = $this->structuredNkoValues($divisionId, $period);
        $rowsByKey = [];

        DB::table('performance_observations')
            ->join('indicators', 'indicators.id', '=', 'performance_observations.indicator_id')
            ->join('org_units', 'org_units.id', '=', 'performance_observations.org_unit_id')
            ->where('performance_observations.period', $period)
            ->where('indicators.is_group', false)
            ->where(function ($query) {
                $query->whereNull('indicators.category')
                    ->orWhere('indicators.category', '<>', KpiReportConverter::INDICATOR_CATEGORY_NKO);
            })
            ->when($divisionId !== null, fn ($q) => $q->where('performance_observations.division_id', $divisionId))
            ->whereIn('performance_observations.dataset_id', $latestIds)
            ->where(function ($query) {
                $query->whereNotNull('performance_observations.target_value')
                    ->orWhereNotNull('performance_observations.realisasi_value')
                    ->orWhere('performance_observations.target_type', PerformanceObservation::TYPE_DATE)
                    ->orWhere('performance_observations.realisasi_type', PerformanceObservation::TYPE_DATE)
                    ->orWhereNotNull('performance_observations.pencapaian');
            })
            ->orderBy('performance_observations.dataset_id')
            ->orderBy('indicators.id')
            ->select(
                'org_units.name as unit',
                'indicators.name as indikator',
                'indicators.pencapaian_formula_kind',
                'performance_observations.target_value',
                'performance_observations.target_date',
                'performance_observations.target_type',
                'performance_observations.realisasi_value',
                'performance_observations.realisasi_date',
                'performance_observations.realisasi_type',
                'performance_observations.pencapaian',
            )
            ->get()
            ->each(function (object $obs) use (&$rowsByKey, $nko) {
                $unit = (string) $obs->unit;

                if (! $this->unitVisible($unit)) {
                    return;
                }

                $indikator = (string) $obs->indikator;
                $target = $obs->target_value !== null ? (float) $obs->target_value : null;
                $realisasi = $obs->realisasi_value !== null ? (float) $obs->realisasi_value : null;

                $targetDisplay = null;
                if ($obs->target_type === PerformanceObservation::TYPE_DATE && $obs->target_date !== null) {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $obs->target_date);
                    if ($date !== false) {
                        $targetDisplay = $date->format('d/m/Y');
                    }
                }

                $realisasiDisplay = null;
                if ($obs->realisasi_type === PerformanceObservation::TYPE_DATE && $obs->realisasi_date !== null) {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $obs->realisasi_date);
                    if ($date !== false) {
                        $realisasiDisplay = $date->format('d/m/Y');
                    }
                }

                $isDateRow = $obs->target_type === PerformanceObservation::TYPE_DATE
                    || $obs->realisasi_type === PerformanceObservation::TYPE_DATE;

                if ($isDateRow) {
                    $pencapaian = $obs->pencapaian !== null
                        ? round($this->normalizeExcelScore((float) $obs->pencapaian), 1)
                        : null;
                } elseif ($target !== null && $target > 0 && $realisasi !== null) {
                    $pencapaian = round($realisasi / $target * 100, 1);
                } elseif ($obs->pencapaian !== null) {
                    $pencapaian = round($this->normalizeExcelScore((float) $obs->pencapaian), 1);
                } else {
                    $pencapaian = null;
                }

                $skorKpi = null;
                if ($obs->pencapaian !== null) {
                    $scaled = KpiScoreNormalizer::scoreScale(
                        (float) $obs->pencapaian,
                        $obs->pencapaian_formula_kind,
                    )['percent'];
                    if ($scaled !== null) {
                        $skorKpi = round(KpiScoreNormalizer::clamp($scaled), 1);
                    }
                } elseif ($pencapaian !== null) {
                    $skorKpi = round(KpiScoreNormalizer::clamp((float) $pencapaian), 1);
                }

                $rowsByKey[$unit][$indikator] = [
                    'unit' => $unit,
                    'indikator' => $indikator,
                    'target' => $target,
                    'realisasi' => $realisasi,
                    'target_display' => $targetDisplay,
                    'realisasi_display' => $realisasiDisplay,
                    'pencapaian' => $pencapaian,
                    'skor_kpi' => $skorKpi,
                    'nko' => $nko[$unit] ?? null,
                ];
            });

        $rows = [];
        foreach ($rowsByKey as $indikators) {
            foreach ($indikators as $row) {
                $rows[] = $row;
            }
        }

        usort($rows, fn ($a, $b) => [$a['unit'], $a['indikator']] <=> [$b['unit'], $b['indikator']]);

        $this->kinerjaDetailUnits = $this->kinerjaDetailUnitOrder(array_column($rows, 'unit'));

        if ($units !== []) {
            $selected = array_values(array_intersect($units, $this->kinerjaDetailUnits));
            if ($selected !== []) {
                $rows = array_values(array_filter(
                    $rows,
                    fn (array $row): bool => in_array($row['unit'], $selected, true),
                ));
            }
        }

        $total = count($rows);
        $truncated = $total > 50;
        if ($truncated) {
            $rows = array_slice($rows, 0, 50);
        }

        return ['period' => $period, 'rows' => $rows, 'truncated' => $truncated, 'total_rows' => $total];
    }

    /**
     * Tambah metadata tampilan (satuan, polaritas, teks/tanggal sumber) di lapisan
     * view/halaman tanpa mengubah kontrak struktur 9-kolom reader R7.
     *
     * @param  array{period: string|null, rows: list<array<string, mixed>>, truncated: bool, total_rows: int}  $data
     * @return array{period: string|null, rows: list<array<string, mixed>>, truncated: bool, total_rows: int}
     */
    public function enrichKinerjaDetailDisplayMeta(array $data, ?User $user): array
    {
        $period = $data['period'] ?? null;
        if ($period === null || ($data['rows'] ?? []) === []) {
            return $data;
        }

        $divisionId = $this->isPrivileged($user) ? null : $user?->division_id;
        $latestIds = $this->structuredNko->latestDatasetIds($divisionId, $period);

        if ($latestIds === []) {
            return $data;
        }

        $metaByKey = [];
        DB::table('performance_observations')
            ->join('indicators', 'indicators.id', '=', 'performance_observations.indicator_id')
            ->join('org_units', 'org_units.id', '=', 'performance_observations.org_unit_id')
            ->where('performance_observations.period', $period)
            ->where('indicators.is_group', false)
            ->when($divisionId !== null, fn ($q) => $q->where('performance_observations.division_id', $divisionId))
            ->whereIn('performance_observations.dataset_id', $latestIds)
            ->orderBy('performance_observations.dataset_id')
            ->orderBy('indicators.id')
            ->select(
                'org_units.name as unit',
                'indicators.name as indikator',
                'indicators.satuan',
                'indicators.polarity',
                'performance_observations.target_type',
                'performance_observations.target_text',
                'performance_observations.target_date',
                'performance_observations.realisasi_type',
                'performance_observations.realisasi_text',
                'performance_observations.realisasi_date',
            )
            ->get()
            ->each(function (object $obs) use (&$metaByKey) {
                $key = $obs->unit.'|'.$obs->indikator;
                $metaByKey[$key] = [
                    'satuan' => $obs->satuan !== null ? (string) $obs->satuan : null,
                    'polarity' => $obs->polarity !== null ? (string) $obs->polarity : null,
                    'target_type' => (string) $obs->target_type,
                    'realisasi_type' => (string) $obs->realisasi_type,
                    'target_text' => $obs->target_text !== null ? (string) $obs->target_text : null,
                    'realisasi_text' => $obs->realisasi_text !== null ? (string) $obs->realisasi_text : null,
                    'target_date' => $obs->target_date !== null ? substr((string) $obs->target_date, 0, 10) : null,
                    'realisasi_date' => $obs->realisasi_date !== null ? substr((string) $obs->realisasi_date, 0, 10) : null,
                ];
            });

        $data['rows'] = array_map(function (array $row) use ($metaByKey): array {
            $meta = $metaByKey[($row['unit'] ?? '').'|'.($row['indikator'] ?? '')] ?? [];

            return array_merge($row, $meta);
        }, $data['rows']);

        return $data;
    }

    /**
     * R6 branch terstruktur: pencapaian target/realisasi per unit pada dataset
     * TERBARU periode terbaru di divisi user.
     *
     * Sumbernya performance_observations, bukan baris '| target'/'| realisasi'
     * performance_metrics. Karena dataset terbaru yang otoritatif, satu
     * (unit, indikator) hanya punya satu nilai per kolom — bukan
     * last-writer-wins seperti jalur legacy. Aturan pencapaian/status/urutan
     * tetap dipakai bersama lewat kinerjaRealisasiRows() supaya dua jalur tidak
     * bisa menyimpang.
     *
     * Skor Excel (keputusan Pak Gama 2026-10-25, melanjutkan keputusan
     * 2026-10-19 untuk target tanggal): SEMUA baris daun (`is_group = 0`) yang
     * punya skor di kolom PENCAPAIAN memakai skor itu, apa pun tipe
     * target/realisasinya (termasuk target teks dan tanggal). Skor sudah
     * menerapkan polaritas, penalti tanggal, dan batas 0..1,1 di workbook, jadi
     * rumusnya TIDAK diimplementasi ulang; skalanya dinormalisasi ke persen oleh
     * normalizeExcelScore(). Baris induk tidak dihitung. Baris tanpa skor Excel
     * (pencapaian NULL) dilewati walau memiliki target/realisasi numerik: tidak
     * ada dasar untuk membuat skor pengganti di luar sumber. Tanggal mentah
     * tidak pernah dipakai sebagai skor.
     *
     * Divergensi yang tetap disengaja: baris tanpa skor Excel tidak dihitung,
     * sedangkan jalur legacy menghitung rasio dari pasangan target/realisasi
     * dan tidak mengenal skor, polaritas, maupun baris induk.
     *
     * @return array{period: ?string, rows: list<array<string, mixed>>, has_data: bool}
     */
    public function kinerjaRealisasiStructured(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        $period = $this->latestStructuredReadyPeriod($user, $startDate, $endDate);

        if ($period === null) {
            return ['period' => null, 'rows' => [], 'has_data' => false];
        }

        $divisionId = $this->isPrivileged($user) ? null : $user?->division_id;
        $latestIds = $this->structuredNko->latestDatasetIds($divisionId, $period);

        $pairs = [];

        DB::table('performance_observations')
            ->join('indicators', 'indicators.id', '=', 'performance_observations.indicator_id')
            ->join('org_units', 'org_units.id', '=', 'performance_observations.org_unit_id')
            ->where('performance_observations.period', $period)
            // Baris induk/kelompok tidak ikut dihitung (keputusan 2026-10-25);
            // hanya daun yang memiliki skor Excel (pencapaian NOT NULL).
            ->where('indicators.is_group', false)
            ->when($divisionId !== null, fn ($q) => $q->where('performance_observations.division_id', $divisionId))
            ->whereIn('performance_observations.dataset_id', $latestIds)
            ->whereNotNull('performance_observations.pencapaian')
            ->select(
                'org_units.name as unit',
                'indicators.id as indicator_id',
                'performance_observations.pencapaian',
            )
            ->get()
            ->each(function (object $row) use (&$pairs) {
                $pairs[$row->unit][$row->indicator_id]['pencapaian'] = $this->normalizeExcelScore((float) $row->pencapaian);
            });

        return $this->kinerjaRealisasiRows($period, $pairs);
    }

    /**
     * Skor PENCAPAIAN tersimpan campuran: hampir semua rasio (1,0143), tetapi
     * observasi #410 tersimpan sebagai persen (110,0). Batas atas rasio di
     * workbook 1,1, jadi nilai di atasnya tidak mungkin rasio dan dibaca
     * sebagai persen; selain itu dikali 100. Hasil belum dibatasi 0..110.
     *
     * Keterbatasan yang diketahui: persen asli <= 1,1 (mis. 1,0%) tak bisa
     * dibedakan dari rasio dan dibaca sebagai rasio; tidak ada kasus nyata.
     */
    public function normalizeExcelScore(float $raw): float
    {
        return KpiScoreNormalizer::normalize($raw);
    }

    /**
     * Peta unit -> NKO dari dataset terbaru, untuk kolom `nko` R7.
     *
     * @return array<string, float>
     */
    public function structuredNkoValues(?string $divisionId, string $period): array
    {
        $values = [];

        foreach ($this->structuredNko->latestForPeriod($divisionId, $period) as $row) {
            $values[$row['unit']] = $row['nko'];
        }

        return $values;
    }

    /**
     * Periode terbaru di dalam rentang yang punya baris NKO terstruktur.
     */
    public function structuredNkoPeriod(?string $divisionId, ?string $startDate, ?string $endDate): ?string
    {
        return $this->structuredNko->latestNkoPeriod($divisionId, $startDate, $endDate);
    }
}
