<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Widget peringkat NKO per unit (server-side, periode terbaru).
 *
 * NKO bukan besaran aditif, jadi widget ini TIDAK memakai SUM(value) seperti
 * chart tren. Hanya baris berakhiran '| NKO' yang dihitung.
 */
class NkoRankingWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Default produksi kini 'structured' (Fase 4.3 Tahap B). Test ini
        // mengunci jalur legacy, jadi mode legacy ditetapkan eksplisit.
        config(['kpi.read_model' => 'legacy']);

        $this->seed(RolePermissionSeeder::class);

        // Test ini mengunci kontrak peringkat (urutan, periode terbaru, dataset
        // archived, cap) memakai nama unit sebagai stand-in. Batas unit TAMPIL
        // (keputusan 2026-10-12, `kpi.visible_units`) dimatikan supaya stand-in
        // non-TJBT tetap valid; batas itu diuji di `KpiUnitScopeTest`.
        config(['kpi.visible_units' => null]);
    }

    public function test_management_melihat_peringkat_nko_urut_menurun(): void
    {
        $division = $this->division('Pemilik Data');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->nko($division, 'TJBT', 105.5, dataset: $dataset);
        $this->nko($division, 'TRANS', 100.74, dataset: $dataset);
        $this->nko($division, 'RST', 99.5, dataset: $dataset);

        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Peringkat NKO')
            ->assertSee('105,50')
            ->assertSee('100,74')
            ->assertSee('99,50')
            ->assertSeeInOrder(['TJBT', 'TRANS', 'RST']);
    }

    public function test_staff_divisi_melihat_widget_peringkat_divisinya_sendiri(): void
    {
        // REVISI 2026-10-01 (membatalkan larangan 2026-09-30): nko-ranking
        // dipasang di staff_divisi. Yang tampil = peringkat ANTAR-UNIT di dalam
        // divisi staf (bermakna); peringkat divisi lain tetap tidak terlihat
        // karena widget sudah di-scope ke division_id user.
        // Revisi 2026-10-05: nko-ranking sekarang hanya muncul untuk divisi
        // Perencanaan Pengusahaan (layout per-divisi), tidak untuk staf divisi lain.
        // slug harus di-set eksplisit: factory menurunkan slug dari nama default-nya,
        // sehingga cukup memberi 'name' tidak cukup. Pengusahaan = override slug.
        // REVISI 2026-11-09: default layout staff pengusahaan kini memakai kpi-rag-status
        // dan nko-ranking dilepas ke katalog. Konfigurasikan layout eksplisit agar
        // widget peringkat terpasang saat menguji isolasi data antar-divisi staf.
        config(['dashboard_layouts.layouts.staff_divisi:perencanaan-pengusahaan' => ['nko-ranking']]);

        $own = Division::factory()->create(['name' => 'Perencanaan Pengusahaan', 'slug' => 'perencanaan-pengusahaan']);
        $other = Division::factory()->create(['name' => 'Divisi Lain']);
        // $other intentionally slug acak (bukan perencanaan-pengusahaan) supaya staf
        // Pengusahaan tidak melihat datanya.
        $this->nko($own, 'TJBT', 105.5);
        $this->nko($other, 'TRANS', 99.0);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $own->id]);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Peringkat NKO')
            ->assertSee('105,50')
            ->assertSee('TJBT')
            ->assertDontSee('99.00')
            ->assertDontSee('TRANS');
    }

    public function test_management_melihat_widget_peringkat(): void
    {
        $this->nko($this->division('TJBT'), 'TJBT', 105.5);

        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Peringkat NKO');
    }

    public function test_hanya_periode_terbaru_yang_ditampilkan(): void
    {
        $division = $this->division('TJBT');
        $this->nko($division, 'TJBT', 90.0, '2026-07-31');
        $this->nko($division, 'TJBT', 105.5, '2026-08-31');

        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('105,50')
            ->assertDontSee('90.00');
    }

    public function test_dataset_archived_tidak_dihitung(): void
    {
        $division = $this->division('TJBT');
        $dataset = Dataset::factory()->archived()->create(['division_id' => $division->id]);

        PerformanceMetric::factory()->create([
            'metric_name' => 'TJBT | NKO',
            'value' => 105.5,
            'period' => '2026-08-31',
            'division_id' => $division->id,
            'dataset_id' => $dataset->id,
        ]);

        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Belum ada data NKO')
            ->assertDontSee('105.50');
    }

    public function test_metrik_biasa_tidak_ikut_terhitung_sebagai_nko(): void
    {
        $division = $this->division('TJBT');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        PerformanceMetric::factory()->create([
            'metric_name' => 'TJBT | Faktor Ketersediaan Trafo',
            'value' => 20.01,
            'period' => '2026-08-31',
            'division_id' => $division->id,
            'dataset_id' => $dataset->id,
        ]);

        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Belum ada data NKO');
    }

    public function test_peringkat_mengikuti_filter_tanggal(): void
    {
        // Filter tanggal menggeser "periode terbaru" ke dalam rentang.
        // NKO bukan besaran aditif, jadi rentang tidak dijumlahkan.
        $division = $this->division('TJBT');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->nko($division, 'TJBT', 90.0, '2026-07-31', $dataset);
        $this->nko($division, 'TJBT', 105.5, '2026-08-31', $dataset);

        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        // Tanpa filter: periode terbaru = Agustus.
        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('105,50');

        // Rentang Juli saja: peringkat memakai Juli, bukan Agustus.
        $this->actingAs($management)
            ->get(route('dashboard', ['start_date' => '2026-07-01', 'end_date' => '2026-07-31']))
            ->assertOk()
            ->assertSee('90,00')
            ->assertDontSee('105.50');
    }

    private function division(string $unit): Division
    {
        return Division::factory()->create(['name' => $unit]);
    }

    private function nko(
        Division $division,
        string $unit,
        float $value,
        string $period = '2026-08-31',
        ?Dataset $dataset = null,
    ): void {
        $dataset ??= Dataset::factory()->create(['division_id' => $division->id]);

        PerformanceMetric::factory()->create([
            'metric_name' => $unit.' | NKO',
            'value' => $value,
            'period' => $period,
            'division_id' => $division->id,
            'dataset_id' => $dataset->id,
        ]);
    }
}
