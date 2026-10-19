<?php

namespace App\Http\Controllers;

use App\Enums\DatasetStatus;
use App\Http\Controllers\Concerns\ScopesKpiUnits;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\PerformanceMetric;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetStockReport;
use App\Services\KpiReportConverter;
use App\Services\StructuredNkoReader;
use App\Services\TargetRealisasiPerIndicatorReader;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Halaman dashboard berbasis widget per role (Dashboard Builder Fase 1).
 *
 * Layout dibaca dari config/dashboard_layouts.php berdasarkan slug role
 * (fallback 'default' untuk role null / slug tak dikenal). Katalog widget
 */
class DashboardController extends Controller
{
    use ScopesKpiUnits;

    /**
     * Prefix atribut yang ditampilkan pada grafik Aset per UPT.
     *
     * Sesuai fokus prototype yang disetujui mentor (2026-09-25): Tower,
     * Transmisi, dan Trafo. Sengaja daftar prefix eksplisit, BUKAN
     * str_contains: nama atribut lain di rekap bisa memuat kata-kata ini di
     * tengah (mis. "DIAMETER | CUT OFF CB") sehingga pencocokan substring
     * meloloskan atribut di luar fokus.
     *
     * @var list<string>
     */
    private const ASET_ATTRIBUTE_PREFIXES = ['Tower ', 'Transmisi ', 'Trafo '];

    public function __construct(
        private readonly StructuredNkoReader $structuredNko,
        private readonly TargetRealisasiPerIndicatorReader $targetRealisasiPanel,
    ) {}

    /**
     * Rakit daftar widget terurut untuk user aktif, lalu render view.
     */
    public function __invoke(Request $request): View
    {
        $config = config('dashboard_layouts');
        $user = Auth::user();
        $user->loadMissing('role', 'division');
        $roleSlug = $user?->role?->slug;

        // Staff resolution is per-division: a staff member only gets the
        // full Kinerja widgets when their division is explicitly mapped.
        // Other roles keep their role-keyed layout.
        $layoutKey = $roleSlug;
        if ($roleSlug === Role::STAFF_DIVISI) {
            $divisionSlug = $user?->division?->slug;
            $layoutKey = $divisionSlug !== null && $divisionSlug !== ''
                ? "staff_divisi:{$divisionSlug}"
                : 'staff_divisi';
        }

        if (isset($config['layouts'][$layoutKey])) {
            $layout = $config['layouts'][$layoutKey];
        } elseif ($roleSlug === Role::STAFF_DIVISI) {
            $layout = $config['layouts']['staff_divisi'];
        } else {
            $layout = $config['default'];
        }

        // Filter dashboard datang dari query string (form GET, bukan fetch):
        // halaman dimuat ulang sehingga widget SERVER-SIDE (nko-ranking) dan
        // widget CLIENT-SIDE (chart) memakai rentang yang sama. Tanggal tidak
        // valid diabaikan, bukan error — ini halaman GET, bukan endpoint API.
        $filters = [
            'start_date' => $this->dateOrNull($request->query('start_date')),
            'end_date' => $this->dateOrNull($request->query('end_date')),
            'units' => array_values(array_filter(
                (array) $request->query('unit', []),
                fn ($unit): bool => is_string($unit) && $unit !== '',
            )),
        ];

        if ($filters['start_date'] !== null && $filters['end_date'] !== null
            && $filters['start_date'] > $filters['end_date']) {
            $filters['start_date'] = null;
            $filters['end_date'] = null;
        }

        $widgets = collect($layout)
            ->map(fn (string $key) => isset($config['widgets'][$key])
                ? ['key' => $key] + $config['widgets'][$key]
                : null)
            ->filter()
            ->filter(fn (array $widget) => $widget['permission'] === null
                || Gate::allows($widget['permission']))
            ->map(function (array $widget, int $key) use ($user, $filters) {
                // Instance = indeks layout: unik per render dalam satu
                // response meski widget key yang sama tampil berulang.
                $widget['instance'] = $key;
                $widget['data'] = $this->dataFor($widget['key'], $user, $filters);

                // Daftar unit lengkap (di luar cap 50) hanya untuk dropdown
                // kinerja-detail. Ditempel di wrapper widget, BUKAN di payload:
                // payload reader dikunci karakterisasi (4 key).
                if ($widget['key'] === 'kinerja-detail') {
                    $widget['units'] = $this->kinerjaDetailUnits ?? [];
                }

                return $widget;
            })
            ->values()
            ->all();

        return view('dashboard.index', [
            'widgets' => $widgets,
            'filters' => $filters,
            'availableUnits' => $this->availableUnits($user),
        ]);
    }

    /**
     * Terima hanya tanggal Y-m-d; selain itu null. Dipakai untuk query string
     * halaman dashboard agar nilai rusak tidak pernah sampai ke query DB.
     */
    private function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * Semua unit yang punya baris NKO di divisi user (untuk dropdown filter).
     * Ditampilkan SERVER-SIDE agar dropdown tetap lengkap meski filter aktif
     * (kalau diambil dari payload API, unit yang ter-filter akan menghilang
     * dari daftar pilihannya sendiri).
     *
     * Flag-gated sejak Unit 5B: default 'legacy' -> query lama persis.
     * Branch 'structured' membaca nama unit dari org_units.name.
     *
     * @return list<string>
     */
    private function availableUnits(?User $user): array
    {
        $isPrivileged = $this->isPrivileged($user);

        if ($this->kpiReadModelStructured()) {
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

    private function isPrivileged(?User $user): bool
    {
        return $user !== null && (
            $user->hasRole(Role::ADMIN)
            || $user->hasRole(Role::MANAGEMENT)
            || $user->hasRole(Role::EDITOR_DASHBOARD)
        );
    }

    /**
     * Data server-side ringan per widget. Widget client-side (kpi-summary,
     * trend-chart) mengambil data sendiri via /api/performance-data -> null.
     *
     * @param  array{start_date: ?string, end_date: ?string}  $filters
     */
    private function dataFor(string $key, ?User $user = null, array $filters = []): mixed
    {
        return match ($key) {
            'nko-ranking' => $this->nkoRanking($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null),
            'aset-upt-chart' => $this->asetUptChart($user),
            'recent-audit' => $this->recentAudit($user),
            'failed-datasets' => $this->failedDatasets(),
            'admin-summary' => $this->adminSummary(),
            'admin-quick-actions' => null,
            'dataset-status' => $this->datasetStatus($user),
            'kinerja-realisasi' => $this->kinerjaRealisasi($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null),
            'kinerja-detail' => $this->kinerjaDetail($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null, $filters['units'] ?? []),
            'target-vs-realisasi' => $this->targetVsRealisasi($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null),
            'target-realisasi-per-indikator' => $this->targetRealisasiPerIndikator($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null),
            'pencapaian-nko' => $this->pencapaianNko($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null),
            'nko-donut' => $this->nkoDonut($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null),
            default => null,
        };
    }

    private function adminSummary(): array
    {
        return [
            'total_datasets' => Dataset::query()->count(),
            'failed_datasets' => Dataset::query()
                ->where('status', DatasetStatus::FAILED)
                ->count(),
            'audit_today' => AuditLog::query()
                ->whereDate('created_at', today())
                ->count(),
            'total_users' => User::query()->count(),
        ];
    }

    /**
     * Data ringkasan dan detail dataset gagal untuk dashboard admin.
     *
     * @return array{count: int, rows: Collection<int, Dataset>}
     */
    private function failedDatasets(): array
    {
        return [
            'count' => Dataset::query()
                ->where('status', DatasetStatus::FAILED)
                ->count(),
            'rows' => Dataset::query()
                ->with(['division:id,name', 'user:id,name'])
                ->where('status', DatasetStatus::FAILED)
                ->latest()
                ->limit(3)
                ->get(),
        ];
    }

    /**
     * Lima entri audit log terbaru, discope sesuai hak akses user:
     * - Pemegang audit.view_all (Admin & Management): seluruh entri termasuk aktivitas login/user.
     * - Tanpa audit.view_all (mis. Editor Dashboard): dibatasi ke entri Dataset & PerformanceMetric.
     *
     * @return Collection<int, AuditLog>
     */
    private function recentAudit(?User $user): Collection
    {
        $dataOnly = ! ($user?->can('audit.view_all') ?? false);

        return AuditLog::query()
            ->with('user:id,name')
            ->when($dataOnly, fn ($query) => $query->whereIn('auditable_type', [
                (new Dataset)->getMorphClass(),
                (new PerformanceMetric)->getMorphClass(),
            ]))
            ->latest('created_at')
            ->limit(5)
            ->get();
    }

    /**
     * Data grafik stok aset per UPT untuk periode terbaru.
     *
     * Bentuk siap render ECharts bar: labels = UPT, satu series per atribut
     * (atribut tidak dicampur dalam satu series karena satuannya beda:
     * Bank, MVA, kms). Snapshot, bukan time-series — tanpa line/trend.
     *
     * Series dibatasi ke prefix ASET_ATTRIBUTE_PREFIXES (Tower, Transmisi,
     * Trafo) sesuai fokus prototype; atribut di luar fokus tetap tersimpan
     * sebagai data, hanya tidak digambar.
     *
     * Scoping divisi: hanya role privileged (Admin, Management, Editor
     * Dashboard) yang melihat lintas divisi. Staf Divisi dibatasi ke
     * divisinya sendiri; divisi selain pemilik data Aset mendapat data
     * kosong sehingga widget menampilkan empty state, bukan kebocoran data.
     * Pola ini sama dengan nkoRanking() dan PerformanceChartController.
     *
     * @return array{period: ?string, labels: list<string>, series: list<array{name: string, data: list<float>}>}
     */
    private function asetUptChart(?User $user): array
    {
        $isPrivileged = $user && (
            $user->hasRole(Role::ADMIN)
            || $user->hasRole(Role::MANAGEMENT)
            || $user->hasRole(Role::EDITOR_DASHBOARD)
        );

        $divisionId = $isPrivileged ? null : $user?->division_id;

        $report = app(AssetStockReport::class)->totalsByUpt(divisionId: $divisionId);

        $upts = $report['totals']->pluck('upt')->unique()->values();

        // Batasi ke fokus prototype: hanya kelompok Tower, Transmisi, dan
        // Trafo. Atribut di luar itu (SIRKIT, DIAMETER, Joint, Gardu Induk)
        // tetap tersimpan sebagai data, hanya tidak digambar sebagai series.
        // Kalau tidak ada satu pun yang cocok, series dikosongkan supaya
        // widget menampilkan empty state — bukan diam-diam menampilkan
        // seluruh atribut seperti perilaku lama.
        $allAttributes = $report['totals']->pluck('attribute')->unique()->values();
        $attributes = $allAttributes->filter(
            fn (string $attr): bool => Str::startsWith($attr, self::ASET_ATTRIBUTE_PREFIXES)
        )->values();

        $byKey = $report['totals']->keyBy(fn (object $total): string => $total->upt."\0".$total->attribute);

        $series = $attributes->map(fn (string $attribute): array => [
            'name' => $attribute,
            'data' => $upts->map(fn (string $upt): float => (float) ($byKey[$upt."\0".$attribute]->total ?? 0))->all(),
        ])->values()->all();

        return ['period' => $report['period'], 'labels' => $upts->all(), 'series' => $series];
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
     * @return Collection<int, object>
     */
    private function nkoRanking(?User $user, ?string $startDate = null, ?string $endDate = null): Collection
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
     * 'legacy' -> query lama persis. Lihat kpiReadModelStructured().
     *
     * Caller yang sengaja dipin ke versi legacy memanggil
     * latestLegacyReadyPeriod() secara eksplisit: R5 datasetStatus, R8
     * targetVsRealisasi, dan R7 kinerjaDetail (yang masih butuh baris
     * performance_metrics untuk target/realisasi) — lihat catatan pada helper
     * itu.
     */
    private function latestReadyPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
        return $this->kpiReadModelStructured()
            ? $this->latestStructuredReadyPeriod($user, $startDate, $endDate)
            : $this->latestLegacyReadyPeriod($user, $startDate, $endDate);
    }

    /**
     * Apakah reader yang SUDAH dimigrasi harus membaca tabel terstruktur.
     *
     * Bukan switch global: R5 (datasetStatus) dan R8 (targetVsRealisasi) tetap
     * legacy berapa pun nilainya, karena keduanya menunggu keputusan produk.
     * Reader lain (R1, R2, R3, R4, R6, R7, R9, R10) ikut flag; R9 mengikuti R6
     * karena mendelegasi ke sana.
     */
    private function kpiReadModelStructured(): bool
    {
        return config('kpi.read_model') === 'structured';
    }

    /**
     * Periode terbaru dari tabel legacy performance_metrics.
     *
     * Dipakai eksplisit oleh reader yang BELUM dimigrasi. Caller tersebut
     * menggabungkan periode ini dengan agregasi performance_metrics, jadi
     * membiarkannya mengikuti flag 'structured' akan membuat mereka membaca
     * periode terstruktur sementara datanya tetap legacy -> widget kosong.
     * Karena itu mereka dipin ke sini, bukan ke latestReadyPeriod().
     */
    private function latestLegacyReadyPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
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
    private function latestStructuredReadyPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
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
     * Ringkasan dataset organisasi: periode data terbaru yang aktif, jumlah
     * dataset per status, danjumlah baris aktif periode terbaru.
     */
    private function datasetStatus(?User $user): array
    {
        $isPrivileged = $this->isPrivileged($user);
        $scope = fn ($query) => $query->when(! $isPrivileged, fn ($q) => $q->where('division_id', $user?->division_id));

        // Periode terbaru ditentukan TANPA filter unit (milik divisi); hanya
        // hitungan baris yang dibatasi ke unit TAMPIL. Akibatnya latest_rows
        // bisa 0 bila periode terbaru tidak memuat unit tampil.
        $latestPeriod = $this->latestLegacyReadyPeriod($user);
        $latestRows = 0;
        if ($latestPeriod !== null) {
            $latestRows = $this->countVisibleUnitRows(DB::table('performance_metrics')
                ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
                ->where('datasets.status', Dataset::STATUS_READY)
                ->where('performance_metrics.period', $latestPeriod)
                ->when(! $isPrivileged, fn ($q) => $q->where('performance_metrics.division_id', $user?->division_id)));
        }

        return [
            'latest_period' => $latestPeriod,
            'ready' => $scope(Dataset::query())->where('status', Dataset::STATUS_READY)->count(),
            'archived' => $scope(Dataset::query())->where('status', Dataset::STATUS_ARCHIVED)->count(),
            'failed' => $scope(Dataset::query())->where('status', DatasetStatus::FAILED)->count(),
            'latest_rows' => $latestRows,
            'fresh' => $latestPeriod !== null && $latestPeriod >= now()->subDays(45)->toDateString(),
        ];
    }

    /**
     * Per unit: berapa indikator yang realisasi >= target (pencapaian >=100%),
     * rata-rata pencapaian, dan status kategori. Sumber: baris '| target'
     * dan '| realisasi' pada periode terbaru di divisi user.
     *
     * Sejak migrasi R6 reader ini flag-gated; default 'legacy' -> perilaku
     * lama persis. R9 (pencapaianNko) mendelegasi ke sini, jadi ikut flag.
     */
    private function kinerjaRealisasi(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        if ($this->kpiReadModelStructured()) {
            return $this->kinerjaRealisasiStructured($user, $startDate, $endDate);
        }

        $period = $this->latestLegacyReadyPeriod($user, $startDate, $endDate);

        if ($period === null) {
            return ['period' => null, 'rows' => [], 'has_data' => false];
        }

        $isPrivileged = $this->isPrivileged($user);
        $pairs = [];

        DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.period', $period)
            ->where(function ($query) {
                $query->where('performance_metrics.metric_name', 'like', '%| target')
                    ->orWhere('performance_metrics.metric_name', 'like', '%| realisasi');
            })
            ->when(! $isPrivileged, fn ($q) => $q->where('performance_metrics.division_id', $user?->division_id))
            ->select('performance_metrics.metric_name', 'performance_metrics.value')
            ->get()
            ->each(function (object $row) use (&$pairs) {
                $parts = explode(' | ', $row->metric_name);
                if (count($parts) !== 3) {
                    return;
                }
                [$unit, $indikator, $jenis] = $parts;
                $pairs[$unit][$indikator][strtolower($jenis)] = (float) $row->value;
            });

        return $this->kinerjaRealisasiRows($period, $pairs);
    }

    /**
     * Agregasi target/realisasi per unit, dipakai bersama jalur legacy dan
     * terstruktur supaya aturan pencapaian/status/urutan tidak bisa menyimpang
     * antara dua sumber.
     *
     * @param  array<string, array<string, array{target?: ?float, realisasi?: ?float, pencapaian?: float}>>  $pairs
     * @return array{period: string, rows: list<array<string, mixed>>, has_data: bool}
     */
    private function kinerjaRealisasiRows(string $period, array $pairs): array
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
            $pencapaianSum = 0;
            $counted = 0;

            foreach ($indikators as $vals) {
                $target = $vals['target'] ?? null;
                $realisasi = $vals['realisasi'] ?? null;

                // Skor eksplisit (PENCAPAIAN Excel untuk target bertipe
                // tanggal) menang; tanpa skor, pakai rasio numerik seperti
                // biasa. Jalur legacy tidak pernah mengisi 'pencapaian',
                // jadi perhitungannya tetap persis sama.
                $p = $vals['pencapaian'] ?? null;

                if ($p === null && $target !== null && $target > 0 && $realisasi !== null) {
                    $p = ($realisasi / $target) * 100;
                }

                if ($p !== null) {
                    $total++;
                    $pencapaianSum += $p;
                    $counted++;
                    if ($p >= 100) {
                        $achieved++;
                    }
                }
            }

            if ($total > 0) {
                $avg = $pencapaianSum / $counted;
                $rows[] = [
                    'unit' => $unit,
                    'achieved' => $achieved,
                    'total' => $total,
                    'avg' => round($avg, 1),
                    'status' => $avg >= 100 ? 'Tercapai' : ($avg >= 95 ? 'Hampir Tercapai' : 'Perlu Peningkatan'),
                ];
            }
        }

        usort($rows, fn ($a, $b) => $b['avg'] <=> $a['avg']);

        return ['period' => $period, 'rows' => $rows, 'has_data' => $rows !== []];
    }

    /**
     * DETAIL, bukan tabelnya: daftar lengkap unit pada periode terpilih,
     * termasuk unit yang barisnya terpotong cap 50. Diambil dari `$rows` yang
     * sudah jadi supaya dropdown tidak butuh query kedua dan tidak menambah
     * key level-atas pada payload (payload tetap terkunci 4 key).
     */
    private ?array $kinerjaDetailUnits = null;

    /**
     * Detail baris metrik per unit+indikator pada periode terbaru: target,
     * realisasi, pencapaian %, dan NKO unit. Cap 50 baris + flag truncated.
     *
     * `$units` = pilihan unit dari query (`?unit=TJBT`). Filter diterapkan
     * SETELAH baris jadi dan SEBELUM cap, jadi cap 50 + total_rows + truncated
     * dihitung per unit terpilih. Kosong atau tidak ada yang cocok di data ->
     * perilaku lama PERSIS (seluruh baris, cap global).
     */
    private function kinerjaDetail(?User $user, ?string $startDate = null, ?string $endDate = null, array $units = []): array
    {
        if ($this->kpiReadModelStructured()) {
            return $this->kinerjaDetailStructured($user, $startDate, $endDate);
        }

        // Reader ini belum dimigrasi: periode maupun agregasinya tetap legacy.
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
                $rows[] = [
                    'unit' => $unit,
                    'indikator' => $indikator,
                    'target' => $target,
                    'realisasi' => $realisasi,
                    'target_display' => $dateDisplays[$unit][$indikator]['target'] ?? null,
                    'realisasi_display' => $dateDisplays[$unit][$indikator]['realisasi'] ?? null,
                    'pencapaian' => ($target !== null && $target > 0 && $realisasi !== null)
                        ? round($realisasi / $target * 100, 1) : null,
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
     * @return array<string, array<string, array{target?: string, realisasi?: string}>>
     */
    private function kinerjaDetailDateDisplays(array $candidates, string $period): array
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
    private function kinerjaDetailUnitOrder(array $units): array
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
     * Period helper khusus reader panel per indikator.
     *
     * SENGAJA tidak memakai `latestReadyPeriod()`: saat ini (read model
     * default `legacy`) helper itu memilih periode dari
     * `performance_metrics`. Reader panel membaca dataset terstruktur READY,
     * jadi periodenya harus berasal dari sumber yang sama. Filter divisi
     * mengikuti `latestStructuredReadyPeriod()`.
     */
    private function latestStructuredPanelPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
        $query = DB::table('performance_observations')
            ->join('datasets', 'datasets.id', '=', 'performance_observations.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY);

        if (! $this->isPrivileged($user)) {
            $query->where('performance_observations.division_id', $user?->division_id);
        }

        if ($startDate !== null) {
            $query->where('performance_observations.period', '>=', $startDate);
        }

        if ($endDate !== null) {
            $query->where('performance_observations.period', '<=', $endDate);
        }

        return $query->max('performance_observations.period');
    }

    /**
     * Panel Target vs Realisasi PER INDIKATOR (keputusan Pak Gama 2026-10-19
     * 11:17). Sumber dan aturan klasifikasi ada di
     * `TargetRealisasiPerIndicatorReader`; method ini hanya menyediakan
     * periode, scope divisi (RBAC), dan batas unit TAMPIL.
     *
     * Isolasi: TIDAK membaca `kpi.read_model` dan TIDAK memakai
     * `targetVsRealisasi()`/R8. Widget lama tidak berubah.
     */
    private function targetRealisasiPerIndikator(?User $user, ?string $startDate = null, ?string $endDate = null): array
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
     * Bar grouped Target vs Realisasi per unit untuk periode terbaru.
     * Agregasi dari baris '| target' dan '| realisasi' yang punya dataset
     * READY di divisi user. Satu bar per unit,dua seri: Target, Realisasi.
     */
    private function targetVsRealisasi(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        // Reader ini belum dimigrasi (C-1 belum diputuskan): tetap legacy.
        $period = $this->latestLegacyReadyPeriod($user, $startDate, $endDate);

        if ($period === null) {
            return ['period' => null, 'labels' => [], 'target' => [], 'realisasi' => []];
        }

        $isPrivileged = $this->isPrivileged($user);
        $unitTotals = [];

        DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.period', $period)
            ->where(function ($query) {
                $query->where('performance_metrics.metric_name', 'like', '%| target')
                    ->orWhere('performance_metrics.metric_name', 'like', '%| realisasi');
            })
            ->when(! $isPrivileged, fn ($q) => $q->where('performance_metrics.division_id', $user?->division_id))
            ->select('performance_metrics.metric_name', 'performance_metrics.value')
            ->get()
            ->each(function (object $row) use (&$unitTotals) {
                $parts = explode(' | ', $row->metric_name);
                if (count($parts) !== 3) {
                    return;
                }
                [$unit, $indikator, $jenis] = $parts;
                $jenis = strtolower($jenis);
                if ($jenis === 'target') {
                    $unitTotals[$unit]['target'] = ($unitTotals[$unit]['target'] ?? 0.0) + (float) $row->value;
                } elseif ($jenis === 'realisasi') {
                    $unitTotals[$unit]['realisasi'] = ($unitTotals[$unit]['realisasi'] ?? 0.0) + (float) $row->value;
                }
            });

        // Batas unit TAMPIL (keputusan 2026-10-12). HANYA cakupan unit yang
        // disaring: agregasi C-1 di dalam unit (penjumlahan lintas indikator)
        // TIDAK diubah — unit di luar scope dibuang sebagai satu kesatuan,
        // bukan nilai per indikatornya. Periode sudah dipilih di atas.
        $unitTotals = $this->scopeUnitMap($unitTotals);

        $labels = array_values(array_keys($unitTotals));
        $target = array_map(fn ($unit) => $unitTotals[$unit]['target'] ?? 0.0, $labels);
        $realisasi = array_map(fn ($unit) => $unitTotals[$unit]['realisasi'] ?? 0.0, $labels);

        return ['period' => $period, 'labels' => $labels, 'target' => $target, 'realisasi' => $realisasi];
    }

    /**
     * Bar sederhana: pencapaian rata-rata (%) per unit, agar satu periode
     * terbaca sebagai "berapa persen dari target tercapai" per unit.
     */
    private function pencapaianNko(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        $rekap = $this->kinerjaRealisasi($user, $startDate, $endDate);

        return [
            'period' => $rekap['period'] ?? null,
            'labels' => array_map(fn ($row) => $row['unit'], $rekap['rows']),
            'data' => array_map(fn ($row) => $row['avg'], $rekap['rows']),
        ];
    }

    /**
     *Donut klasifikasi NKO per kategori pada periode aktif:
     * Tercapai (>=100), Hampir Tercapai (95-<100), Perlu Peningkatan (<95).
     * Persamaan kategori dengan nko-ranking agar konsisten.
     */
    private function nkoDonut(?User $user, ?string $startDate = null, ?string $endDate = null): array
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
                if ($v >= 100) {
                    $counts['Tercapai']++;
                } elseif ($v >= 95) {
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

    // ------------------------------------------------------------------
    // Branch terstruktur. Hanya aktif bila
    // config('kpi.read_model') === 'structured'; default tetap 'legacy'.
    // ------------------------------------------------------------------

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
     * @return Collection<int, object>
     */
    private function nkoRankingStructured(?User $user, ?string $startDate = null, ?string $endDate = null): Collection
    {
        $divisionId = $this->isPrivileged($user) ? null : $user?->division_id;
        $period = $this->structuredNkoPeriod($divisionId, $startDate, $endDate);

        if ($period === null) {
            return collect();
        }

        return $this->structuredNko->latestForPeriod($divisionId, $period)
            ->map(fn (array $row): object => (object) [
                'metric_name' => $row['unit'].KpiReportConverter::NKO_SUFFIX,
                'nko' => $row['nko'],
                'unit' => $row['unit'],
            ])
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
     */
    private function nkoDonutStructured(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
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

            if ($value >= 100) {
                $counts['Tercapai']++;
            } elseif ($value >= 95) {
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
     * R7 branch terstruktur.
     *
     * Baris detail (target/realisasi/pencapaian) tetap dihitung dari
     * `performance_metrics` pada periode legacy: struktur observasi belum
     * terbukti setara untuk kolom-kolom itu, dan mengalihkannya sekarang akan
     * mengubah angka. Yang dialihkan HANYA kolom `nko`, yang sudah punya
     * sumber terstruktur.
     *
     * NKO diambil dari DATASET TERBARU (bukan unit mana pun yang punya baris
     * NKO): dataset terbaru adalah versi otoritatif, jadi unit yang hanya ada
     * di dataset lama tidak lagi menempelkan NKO basi.
     */
    private function kinerjaDetailStructured(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        $period = $this->latestLegacyReadyPeriod($user, $startDate, $endDate);

        if ($period === null) {
            return ['period' => null, 'rows' => [], 'truncated' => false, 'total_rows' => 0];
        }

        $isPrivileged = $this->isPrivileged($user);
        $divisionId = $isPrivileged ? null : $user?->division_id;
        $pairs = [];
        $dateCandidates = [];

        DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.period', $period)
            ->when(! $isPrivileged, fn ($q) => $q->where('performance_metrics.division_id', $user?->division_id))
            ->where(function ($query) {
                $query->where('performance_metrics.metric_name', 'like', '%| target')
                    ->orWhere('performance_metrics.metric_name', 'like', '%| realisasi');
            })
            ->select('performance_metrics.dataset_id', 'performance_metrics.metric_name', 'performance_metrics.value')
            ->get()
            ->each(function (object $row) use (&$pairs, &$dateCandidates) {
                $parts = explode(' | ', $row->metric_name);

                if (count($parts) !== 3) {
                    return;
                }

                [$unit, $indikator, $jenis] = $parts;
                $kind = strtolower($jenis);
                $pairs[$unit][$indikator][$kind] = (float) $row->value;
                $dateCandidates[$unit][$indikator][$kind] = ['dataset_id' => $row->dataset_id];
            });

        // Batas unit TAMPIL (keputusan 2026-10-12), sama seperti jalur legacy:
        // baris (target/realisasi) tetap dihitung dari performance_metrics,
        // hanya unit di luar scope yang dibuang. NKO sudah dibaca dari dataset
        // terbaru sebelum penyaringan ini, jadi "latest wins" tidak berubah.
        $pairs = $this->scopeUnitMap($pairs);

        $nko = $this->structuredNkoValues($divisionId, $period);
        $dateDisplays = $this->kinerjaDetailDateDisplays($dateCandidates, $period);

        $rows = [];
        foreach ($pairs as $unit => $indikators) {
            foreach ($indikators as $indikator => $vals) {
                $target = $vals['target'] ?? null;
                $realisasi = $vals['realisasi'] ?? null;
                $rows[] = [
                    'unit' => $unit,
                    'indikator' => $indikator,
                    'target' => $target,
                    'realisasi' => $realisasi,
                    'target_display' => $dateDisplays[$unit][$indikator]['target'] ?? null,
                    'realisasi_display' => $dateDisplays[$unit][$indikator]['realisasi'] ?? null,
                    'pencapaian' => ($target !== null && $target > 0 && $realisasi !== null)
                        ? round($realisasi / $target * 100, 1) : null,
                    'nko' => $nko[$unit] ?? null,
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$a['unit'], $a['indikator']] <=> [$b['unit'], $b['indikator']]);

        $total = count($rows);
        $truncated = $total > 50;
        if ($truncated) {
            $rows = array_slice($rows, 0, 50);
        }

        return ['period' => $period, 'rows' => $rows, 'truncated' => $truncated, 'total_rows' => $total];
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
     * Target bertipe tanggal (keputusan Pak Gama 2026-10-19) memakai SKOR
     * yang sudah dihitung workbook di kolom PENCAPAIAN, bukan rasionya sendiri:
     * skor itu sudah menerapkan penalti 0,01 per 7 hari dan batas 0..1,1
     * (contoh TJBT baris 33 = 1,0142857). Skor disimpan sebagai rasio, sedangkan
     * kinerjaRealisasiRows() menyusun persen, jadi dikali 100 di sini dan
     * rumusnya TIDAK diimplementasi ulang. Baris tanggal tanpa skor Excel
     * dilewati: tidak ada dasar untuk mengarang pencapaian, dan tanggal mentah
     * tidak boleh dipakai sebagai skor.
     *
     * Divergensi yang tetap disengaja: target/realisasi bertipe teks atau
     * kosong (tanpa nilai numerik maupun tanggal) tidak dihitung, sedangkan
     * jalur legacy meng-cast baris target apa pun ke float.
     */
    private function kinerjaRealisasiStructured(?User $user, ?string $startDate = null, ?string $endDate = null): array
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
            ->when($divisionId !== null, fn ($q) => $q->where('performance_observations.division_id', $divisionId))
            ->whereIn('performance_observations.dataset_id', $latestIds)
            ->where(function ($query) {
                $query->whereNotNull('performance_observations.target_value')
                    ->orWhereNotNull('performance_observations.realisasi_value')
                    ->orWhere(function ($date) {
                        // Target tanggal ikut dihitung bila workbook sudah
                        // menyimpan skor pencapaiannya.
                        $date->where('performance_observations.target_type', PerformanceObservation::TYPE_DATE)
                            ->whereNotNull('performance_observations.pencapaian');
                    });
            })
            ->select(
                'org_units.name as unit',
                'indicators.name as indikator',
                'performance_observations.target_value',
                'performance_observations.realisasi_value',
                'performance_observations.target_type',
                'performance_observations.pencapaian',
            )
            ->get()
            ->each(function (object $row) use (&$pairs) {
                if ($row->target_value !== null) {
                    $pairs[$row->unit][$row->indikator]['target'] = (float) $row->target_value;
                }
                if ($row->realisasi_value !== null) {
                    $pairs[$row->unit][$row->indikator]['realisasi'] = (float) $row->realisasi_value;
                }
                if ($row->target_type === PerformanceObservation::TYPE_DATE && $row->pencapaian !== null) {
                    // Kolom PENCAPAIAN workbook = rasio; consumer R6 = persen.
                    $pairs[$row->unit][$row->indikator]['pencapaian'] = (float) $row->pencapaian * 100;
                }
            });

        return $this->kinerjaRealisasiRows($period, $pairs);
    }

    /**
     * Peta unit -> NKO dari dataset terbaru, untuk kolom `nko` R7.
     *
     * @return array<string, float>
     */
    private function structuredNkoValues(?string $divisionId, string $period): array
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
    private function structuredNkoPeriod(?string $divisionId, ?string $startDate, ?string $endDate): ?string
    {
        return $this->structuredNko->latestNkoPeriod($divisionId, $startDate, $endDate);
    }
}
