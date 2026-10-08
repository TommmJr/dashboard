<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceMetric;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiReportConverter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * KPI read-model TERSTRUKTUR diuji lewat HTTP NYATA.
 *
 * Sebelumnya R1/R3/R7/R10 terstruktur hanya diuji lewat method/reflection
 * controller (`KpiNkoParityReadinessTest`), sehingga routing + middleware
 * (auth, can:dashboard.view) dan resolusi layout per-role/per-divisi tidak
 * pernah benar-benar dieksekusi. File ini menutup celah itu: setiap test
 * memakai `actingAs()->get(route(...))` sehingga stack HTTP penuh berjalan.
 *
 * Pemetaan reader -> route:
 * - R1  : GET /api/performance-data  (name: performance.data)
 * - R3  : GET /dashboard  widget `nko-ranking`
 * - R7  : GET /dashboard  widget `kinerja-detail`
 * - R10 : GET /dashboard  widget `nko-donut`
 *
 * Keterbatasan yang DISADARI (bukan bug, lihat config/dashboard_layouts.php):
 * - R7/R10 hanya dipasang di layout `staff_divisi:perencanaan-pengusahaan`,
 *   jadi keduanya hanya bisa dicapai lewat HTTP oleh staf divisi itu.
 * - R3 tidak ada di layout `admin`; "lintas divisi" dibuktikan lewat role
 *   management (sama-sama privileged).
 * - Filter `unit[]` hanya diterapkan server-side pada R1; di /dashboard
 *   filter unit dijalankan di klien, jadi tidak diuji di sini.
 *
 * Fixture sengaja "hanya terstruktur" (observasi tanpa padanan
 * performance_metrics) supaya keluaran branch structured tidak ambigu
 * terhadap legacy. Untuk R7, baris target/realisasi tetap legacy (perilaku
 * yang dipertahankan), hanya kolom NKO yang berasal dari observasi.
 */
class StructuredNkoHttpTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // ------------------------------------------------------------------
    // A. Middleware: akses tanpa login
    // ------------------------------------------------------------------

    public function test_dashboard_menolak_tamu_lewat_middleware_auth(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_api_performance_data_menolak_tamu_sebagai_json_401(): void
    {
        $this->getJson(route('performance.data'))->assertUnauthorized();
    }

    public function test_api_performance_data_menolak_user_tanpa_permission(): void
    {
        // `can:dashboard.view` pada route API. User tanpa role punya permission kosong.
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('performance.data'))->assertForbidden();
    }

    // ------------------------------------------------------------------
    // B. R1 terstruktur (GET /api/performance-data)
    // ------------------------------------------------------------------

    public function test_r1_terstruktur_membaca_observasi_lewat_http(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Divisi A');
        $dataset = $this->readyDataset($division);
        $this->observe($dataset, $division, 'TJBT', 105.5);

        $response = $this->actingAs($this->management())
            ->getJson(route('performance.data'))
            ->assertOk();

        $this->assertSame([self::PERIOD], $response->json('labels'));
        $this->assertSame('TJBT', $response->json('series.0.name'));
        $this->assertEquals([105.5], $response->json('series.0.data'));
    }

    public function test_r1_terstruktur_staf_hanya_melihat_divisinya_sendiri(): void
    {
        config(['kpi.read_model' => 'structured']);

        $own = $this->division('Divisi A');
        $other = $this->division('Divisi B');
        $this->observe($this->readyDataset($own), $own, 'TJBT', 80.0);
        $this->observe($this->readyDataset($other), $other, 'GHOST', 105.5);

        $staff = $this->staffOf($own);

        $names = array_column(
            $this->actingAs($staff)->getJson(route('performance.data'))->assertOk()->json('series'),
            'name',
        );

        $this->assertSame(['TJBT'], $names);
    }

    public function test_r1_terstruktur_admin_melihat_semua_divisi(): void
    {
        config(['kpi.read_model' => 'structured']);

        $divA = $this->division('Divisi A');
        $divB = $this->division('Divisi B');
        $this->observe($this->readyDataset($divA), $divA, 'TJBT', 80.0);
        $this->observe($this->readyDataset($divB), $divB, 'GHOST', 105.5);

        $names = array_column(
            $this->actingAs($this->admin())->getJson(route('performance.data'))->assertOk()->json('series'),
            'name',
        );
        sort($names);

        $this->assertSame(['GHOST', 'TJBT'], $names);
    }

    public function test_r1_terstruktur_dataset_terbaru_menang(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Divisi A');

        // created_at dan id SENGAJA berlawanan arah: dataset ber-created_at
        // lebih baru dibuat LEBIH DULU (id lebih kecil). Kalau urutannya benar
        // (created_at DESC dulu, baru id DESC), yang menang 101.0.
        $newer = $this->readyDataset($division, now());
        $older = $this->readyDataset($division, now()->subDays(2));

        $this->observe($older, $division, 'TJBT', 105.5);
        $this->observe($newer, $division, 'TJBT', 101.0);

        $response = $this->actingAs($this->management())
            ->getJson(route('performance.data'))
            ->assertOk();

        $this->assertEquals([101.0], $response->json('series.0.data'));
    }

    public function test_r1_terstruktur_tie_created_at_dipecah_oleh_id_dataset(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Divisi A');
        $createdAt = now();

        // created_at identik: id lebih besar harus menang (created_at DESC, id DESC).
        $this->observe($this->readyDataset($division, $createdAt), $division, 'TJBT', 90.0);
        $this->observe($this->readyDataset($division, $createdAt), $division, 'TJBT', 120.0);

        $response = $this->actingAs($this->management())
            ->getJson(route('performance.data'))
            ->assertOk();

        $this->assertEquals([120.0], $response->json('series.0.data'));
    }

    public function test_r1_terstruktur_dataset_archived_dikecualikan(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Divisi A');
        $this->observe($this->readyDataset($division, now()->subDay()), $division, 'TJBT', 105.5);
        // Dataset ARCHIVED lebih baru: kalau ikut dihitung, 10.0 yang menang.
        $this->observe($this->archivedDataset($division, now()), $division, 'TJBT', 10.0);

        $response = $this->actingAs($this->management())
            ->getJson(route('performance.data'))
            ->assertOk();

        $this->assertEquals([105.5], $response->json('series.0.data'));
    }

    public function test_r1_terstruktur_isolasi_dataset_antar_divisi(): void
    {
        config(['kpi.read_model' => 'structured']);

        $divA = $this->division('Divisi A');
        $divB = $this->division('Divisi B');

        // Dataset A lebih lama; dataset B lebih baru dengan unit berbeda.
        $this->observe($this->readyDataset($divA, now()->subDays(2)), $divA, 'TJBT', 105.5);
        $this->observe($this->readyDataset($divB, now()), $divB, 'GHOST', 10.0);

        $series = collect(
            $this->actingAs($this->management())->getJson(route('performance.data'))->assertOk()->json('series'),
        )->keyBy('name');

        // Dataset B yang lebih baru TIDAK menekan dataset A: NKO A tetap tampil.
        $this->assertEquals([105.5], $series['TJBT']['data']);
        $this->assertEquals([10.0], $series['GHOST']['data']);
    }

    public function test_r1_terstruktur_filter_unit(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Divisi A');
        $dataset = $this->readyDataset($division);
        $this->observe($dataset, $division, 'TJBT', 105.5);
        $this->observe($dataset, $division, 'RST', 90.0);

        $names = array_column(
            $this->actingAs($this->management())
                ->getJson(route('performance.data', ['unit' => ['TJBT']]))
                ->assertOk()
                ->json('series'),
            'name',
        );

        $this->assertSame(['TJBT'], $names);
    }

    public function test_r1_terstruktur_filter_rentang_tanggal(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Divisi A');
        $dataset = $this->readyDataset($division);
        $this->observe($dataset, $division, 'TJBT', 90.0, '2026-06-01');
        $this->observe($dataset, $division, 'TJBT', 105.5, self::PERIOD);

        $response = $this->actingAs($this->management())
            ->getJson(route('performance.data', [
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-30',
            ]))
            ->assertOk();

        $this->assertSame(['2026-06-01'], $response->json('labels'));
        $this->assertEquals([90.0], $response->json('series.0.data'));
    }

    public function test_r1_rentang_tanggal_terbalik_ditolak_422(): void
    {
        config(['kpi.read_model' => 'structured']);

        $this->actingAs($this->management())
            ->getJson(route('performance.data', [
                'start_date' => '2026-08-01',
                'end_date' => '2026-06-01',
            ]))
            ->assertUnprocessable();
    }

    public function test_r1_observasi_tanpa_divisi_hanya_terlihat_oleh_privileged(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Divisi A');
        $dataset = $this->readyDataset($division);
        $this->observe($dataset, null, 'GHOST', 70.0);

        $staffNames = array_column(
            $this->actingAs($this->staffOf($division))->getJson(route('performance.data'))->assertOk()->json('series'),
            'name',
        );
        $this->assertNotContains('GHOST', $staffNames);

        $managementNames = array_column(
            $this->actingAs($this->management())->getJson(route('performance.data'))->assertOk()->json('series'),
            'name',
        );
        $this->assertContains('GHOST', $managementNames);
    }

    public function test_r1_tanpa_flag_tetap_membaca_legacy(): void
    {
        // Sengaja TIDAK menyalakan flag: default 'legacy' harus tetap utuh.
        $division = $this->division('Divisi A');
        $dataset = $this->readyDataset($division);
        $this->legacyMetric($dataset, $division, 'LEGACY'.KpiReportConverter::NKO_SUFFIX, 100.0);
        $this->observe($dataset, $division, 'STRUKTUR', 55.0);

        $names = array_column(
            $this->actingAs($this->management())->getJson(route('performance.data'))->assertOk()->json('series'),
            'name',
        );

        $this->assertSame(['LEGACY'], $names);
    }

    // ------------------------------------------------------------------
    // C. R3 terstruktur (GET /dashboard, widget nko-ranking)
    // ------------------------------------------------------------------

    public function test_r3_terstruktur_merender_peringkat_lewat_http(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Divisi A');
        $this->observe($this->readyDataset($division), $division, 'TJBT', 105.5);

        $this->actingAs($this->management())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Peringkat NKO Unit')
            ->assertSee('TJBT')
            ->assertSee('105,50')
            ->assertSee('Tercapai')
            ->assertDontSee('Belum ada data NKO');
    }

    public function test_r3_terstruktur_staf_pengusahaan_discope_ke_divisinya(): void
    {
        config(['kpi.read_model' => 'structured']);

        $own = $this->pengusahaanDivision();
        $other = $this->division('Divisi Lain');
        $this->observe($this->readyDataset($own), $own, 'TJBT', 80.0);
        $this->observe($this->readyDataset($other), $other, 'GHOST', 105.5);

        $this->actingAs($this->staffOf($own))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Peringkat NKO Unit')
            ->assertSee('TJBT')
            ->assertSee('80,00')
            ->assertDontSee('GHOST')
            ->assertDontSee('105.50');
    }

    public function test_r3_terstruktur_management_melihat_semua_divisi(): void
    {
        config(['kpi.read_model' => 'structured']);

        $divA = $this->division('Divisi A');
        $divB = $this->division('Divisi B');
        $this->observe($this->readyDataset($divA), $divA, 'TJBT', 80.0);
        $this->observe($this->readyDataset($divB), $divB, 'GHOST', 105.5);

        $this->actingAs($this->management())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('80,00')
            ->assertSee('105,50');
    }

    public function test_r3_terstruktur_dataset_terbaru_menang(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Divisi A');

        // Sama seperti R1: created_at lebih baru tapi id lebih kecil.
        $newer = $this->readyDataset($division, now());
        $older = $this->readyDataset($division, now()->subDays(2));

        $this->observe($older, $division, 'TJBT', 105.5);
        $this->observe($newer, $division, 'TJBT', 101.0);

        $this->actingAs($this->management())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('101,00')
            ->assertDontSee('105.50');
    }

    public function test_r3_terstruktur_dataset_archived_dikecualikan(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Divisi A');
        $this->observe($this->readyDataset($division, now()->subDay()), $division, 'TJBT', 105.5);
        $this->observe($this->archivedDataset($division, now()), $division, 'TJBT', 10.0);

        $this->actingAs($this->management())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('105,50')
            ->assertDontSee('10.00');
    }

    public function test_r3_terstruktur_filter_rentang_menggeser_periode(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Divisi A');
        $dataset = $this->readyDataset($division);
        $this->observe($dataset, $division, 'TJBT', 90.0, '2026-06-01');
        $this->observe($dataset, $division, 'TJBT', 105.5, self::PERIOD);

        $this->actingAs($this->management())
            ->get(route('dashboard', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30']))
            ->assertOk()
            ->assertSee('90,00')
            ->assertDontSee('105.50');
    }

    public function test_dashboard_rentang_terbalik_tidak_422(): void
    {
        // /dashboard BUKAN endpoint API: rentang terbalik di-null-kan, bukan 422.
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Divisi A');
        $this->observe($this->readyDataset($division), $division, 'TJBT', 105.5);

        $this->actingAs($this->management())
            ->get(route('dashboard', ['start_date' => '2026-08-01', 'end_date' => '2026-06-01']))
            ->assertOk()
            ->assertSee('105,50');
    }

    public function test_r3_tanpa_flag_tetap_membaca_legacy(): void
    {
        $division = $this->division('Divisi A');
        $dataset = $this->readyDataset($division);
        $this->legacyMetric($dataset, $division, 'LEGACY'.KpiReportConverter::NKO_SUFFIX, 100.0);
        $this->observe($dataset, $division, 'STRUKTUR', 55.0);

        $this->actingAs($this->management())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('LEGACY')
            ->assertDontSee('STRUKTUR');
    }

    // ------------------------------------------------------------------
    // D. R7 terstruktur (GET /dashboard, widget kinerja-detail)
    // ------------------------------------------------------------------

    public function test_r7_terstruktur_mengisi_kolom_nko_lewat_http(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->pengusahaanDivision();
        $dataset = $this->readyDataset($division);
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator A | target', 200.0);
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator A | realisasi', 100.0);
        $this->observe($dataset, $division, 'TJBT', 105.5);

        $this->actingAs($this->staffOf($division))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Detail Metrik KPI')
            ->assertSeeInOrder(['Indikator A', '200', '100', '50%', '105,5']);
    }

    public function test_r7_terstruktur_dataset_terbaru_menang(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->pengusahaanDivision();
        $legacy = $this->readyDataset($division, now()->subDays(3));
        $this->legacyMetric($legacy, $division, 'TJBT | Indikator A | target', 200.0);
        $this->legacyMetric($legacy, $division, 'TJBT | Indikator A | realisasi', 100.0);

        // created_at lebih baru tapi id lebih kecil.
        $newer = $this->readyDataset($division, now());
        $older = $this->readyDataset($division, now()->subDays(2));

        $this->observe($older, $division, 'TJBT', 105.5);
        $this->observe($newer, $division, 'TJBT', 101.0);

        $this->actingAs($this->staffOf($division))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('101')
            ->assertDontSee('105.50');
    }

    public function test_r7_terstruktur_discope_ke_divisi_staf(): void
    {
        config(['kpi.read_model' => 'structured']);

        $own = $this->pengusahaanDivision();
        $ownDataset = $this->readyDataset($own);
        $this->legacyMetric($ownDataset, $own, 'TJBT | Indikator A | target', 200.0);
        $this->legacyMetric($ownDataset, $own, 'TJBT | Indikator A | realisasi', 100.0);
        $this->observe($ownDataset, $own, 'TJBT', 80.0);

        $other = $this->division('Divisi Lain');
        $otherDataset = $this->readyDataset($other);
        $this->legacyMetric($otherDataset, $other, 'GHOST | Indikator B | target', 200.0);
        $this->legacyMetric($otherDataset, $other, 'GHOST | Indikator B | realisasi', 100.0);
        $this->observe($otherDataset, $other, 'GHOST', 105.5);

        $this->actingAs($this->staffOf($own))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Indikator A')
            ->assertDontSee('Indikator B')
            ->assertDontSee('GHOST')
            ->assertDontSee('105.50');
    }

    // ------------------------------------------------------------------
    // E. R10 terstruktur (GET /dashboard, widget nko-donut)
    // ------------------------------------------------------------------

    public function test_r10_terstruktur_merender_donut_lewat_http(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->pengusahaanDivision();
        $dataset = $this->readyDataset($division);
        $this->observe($dataset, $division, 'TJBT', 105.5);
        $this->observe($dataset, $division, 'RST', 97.0);
        $this->observe($dataset, $division, 'MES', 80.0);

        $this->actingAs($this->staffOf($division))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Distribusi NKO')
            ->assertSee('nkoDonut-nko-donut-', false)
            ->assertSee('"name":"Tercapai","value":1', false)
            ->assertSee('"name":"Hampir Tercapai","value":1', false)
            ->assertSee('"name":"Perlu Peningkatan","value":1', false)
            ->assertDontSee('Belum ada data NKO pada periode ini.');
    }

    public function test_r10_terstruktur_dataset_terbaru_menang(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->pengusahaanDivision();

        // created_at lebih baru tapi id lebih kecil.
        $newer = $this->readyDataset($division, now());
        $older = $this->readyDataset($division, now()->subDays(2));

        $this->observe($older, $division, 'TJBT', 80.0);
        $this->observe($newer, $division, 'TJBT', 101.0);

        $this->actingAs($this->staffOf($division))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('"name":"Tercapai","value":1', false)
            ->assertDontSee('"name":"Perlu Peningkatan"', false);
    }

    public function test_r10_terstruktur_filter_rentang_menggeser_periode(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->pengusahaanDivision();
        $dataset = $this->readyDataset($division);
        $this->observe($dataset, $division, 'TJBT', 80.0, '2026-06-01');
        $this->observe($dataset, $division, 'TJBT', 105.5, self::PERIOD);

        $this->actingAs($this->staffOf($division))
            ->get(route('dashboard', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30']))
            ->assertOk()
            ->assertSee('"name":"Perlu Peningkatan","value":1', false)
            ->assertDontSee('"name":"Tercapai"', false);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function division(string $name): Division
    {
        return Division::factory()->create(['name' => $name]);
    }

    /**
     * Divisi pemilik layout KPI lengkap (R7/R10 hanya dipasang di sini).
     */
    private function pengusahaanDivision(): Division
    {
        return Division::factory()->create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);
    }

    private function staffOf(Division $division): User
    {
        return User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);
    }

    private function management(): User
    {
        return User::factory()->withRole(Role::MANAGEMENT)->create();
    }

    private function admin(): User
    {
        return User::factory()->withRole(Role::ADMIN)->create();
    }

    private function readyDataset(Division $division, ?Carbon $createdAt = null): Dataset
    {
        $attributes = ['division_id' => $division->id];

        if ($createdAt !== null) {
            $attributes['created_at'] = $createdAt;
        }

        return Dataset::factory()->create($attributes);
    }

    private function archivedDataset(Division $division, ?Carbon $createdAt = null): Dataset
    {
        $attributes = ['division_id' => $division->id];

        if ($createdAt !== null) {
            $attributes['created_at'] = $createdAt;
        }

        return Dataset::factory()->archived()->create($attributes);
    }

    /**
     * Tulis baris NKO terstruktur seperti importer: satu indikator kanonis
     * berkategori 'nko' per unit. `$division === null` = observation yatim.
     */
    private function observe(Dataset $dataset, ?Division $division, string $unit, float $value, string $period = self::PERIOD): PerformanceObservation
    {
        $orgUnit = OrgUnit::firstOrCreate(['name' => $unit]);

        $indicator = Indicator::firstOrCreate(
            ['org_unit_id' => $orgUnit->id, 'name' => KpiReportConverter::NKO_LABEL],
            ['category' => KpiReportConverter::INDICATOR_CATEGORY_NKO, 'bobot' => null],
        );

        return PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $orgUnit->id,
            'division_id' => $division?->id,
            'period' => $period,
            'nilai' => $value,
            'source_sheet' => $unit,
        ]);
    }

    private function legacyMetric(Dataset $dataset, ?Division $division, string $metricName, float $value, string $period = self::PERIOD): PerformanceMetric
    {
        return PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $division?->id,
            'metric_name' => $metricName,
            'value' => $value,
            'period' => $period,
        ]);
    }
}
