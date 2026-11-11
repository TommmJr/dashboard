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
 * Widget grafik stok aset per UPT (server-side, snapshot, bar chart).
 *
 * Widget diuji terisolasi via `$this->blade()`, plus scoping divisi lewat route
 * dashboard. Sejak keputusan 2026-09-30 widget TIDAK lagi dipasang di layout
 * `staff_divisi` (datanya milik divisi Managemen Aset), sehingga mounting-nya
 * diuji lewat override config HANYA di dalam test. Kontrak scoping tetap
 * dikunci: role privileged (Admin, Management, Editor Dashboard) melihat
 * lintas divisi, Staf Divisi dibatasi ke divisinya sendiri.
 */
class AsetUptChartWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_merender_label_series_bar_dan_periode(): void
    {
        $html = $this->blade(
            '<x-widgets.aset-upt-chart :widget="$widget" :data="$data" />',
            [
                'widget' => ['key' => 'aset-upt-chart', 'label' => 'Stok Aset per UPT'],
                'data' => [
                    'period' => '2026-08-31',
                    'labels' => ['BEKASI', 'BOGOR'],
                    'series' => [
                        ['name' => 'Trafo 500/150 KV | Bank', 'data' => [12.0, 3.0]],
                    ],
                ],
            ]
        );

        $html->assertSee('Stok Aset per UPT', false)
            ->assertSee('Periode 2026-08-31', false)
            ->assertSee('BEKASI', false)
            ->assertSee('Trafo 500\\/150 KV | Bank', false)
            ->assertSee('asetUptChart-aset-upt-chart-', false)
            ->assertSee('min-h-[360px]', false)
            ->assertSee('x-init=', false)
            ->assertSee('"type":"bar"', false);
    }

    public function test_series_dibatasi_ke_fokus_tower_transmisi_trafo(): void
    {
        $division = Division::factory()->create(['name' => 'Data Aset']);

        // Campuran atribut dalam fokus (Tower/Transmisi/Trafo) dan di luar
        // fokus (SIRKIT, DIAMETER, Joint, Gardu Induk) — semuanya ada di
        // rekap 1b/OPR riil.
        $this->seedAsetRowsFor($division, '2026-08-31', [
            ['BEKASI', 'BEKASI', 'Tower 150 KV | SU', 10],
            ['BEKASI', 'BEKASI', 'Transmisi 500 KV | SU | kms', 20],
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', 30],
            ['BEKASI', 'BEKASI', 'SIRKIT | UPT', 40],
            ['BEKASI', 'BEKASI', 'DIAMETER | CUT OFF CB', 50],
            ['BEKASI', 'BEKASI', 'Joint | SK', 60],
            ['BEKASI', 'BEKASI', 'Gardu Induk Milik Unit Pengusahaan | GI/GITET | 150kV', 70],
        ]);

        $staf = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->mountAsetWidgetForStaffForTest();

        $response = $this->actingAs($staf)->get(route('dashboard'))->assertOk();

        // Dalam fokus: digambar sebagai series.
        $response->assertSee('Tower 150 KV | SU', false)
            ->assertSee('Transmisi 500 KV | SU | kms', false)
            ->assertSee('Trafo 500\\/150 KV | Bank', false);

        // Di luar fokus: TIDAK digambar, meski nilainya ada di dataset.
        $response->assertDontSee('SIRKIT | UPT', false)
            ->assertDontSee('DIAMETER | CUT OFF CB', false)
            ->assertDontSee('Joint | SK', false)
            ->assertDontSee('GI\\/GITET', false);
    }

    public function test_series_kosong_bila_tidak_ada_atribut_dalam_fokus(): void
    {
        $division = Division::factory()->create(['name' => 'Data Aset']);

        // Hanya atribut di luar fokus: widget harus empty state, bukan
        // menampilkan seluruh atribut seperti perilaku lama.
        $this->seedAsetRowsFor($division, '2026-08-31', [
            ['BEKASI', 'BEKASI', 'SIRKIT | UPT', 40],
        ]);

        $staf = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->mountAsetWidgetForStaffForTest();

        $this->actingAs($staf)->get(route('dashboard'))->assertOk()
            ->assertSee('Stok Aset per UPT', false)
            ->assertSee('Belum ada data aset.', false)
            ->assertDontSee('asetUptChart', false);
    }

    public function test_staf_divisi_lain_tidak_melihat_data_aset(): void
    {
        $asetDivision = Division::factory()->create(['name' => 'Data Aset']);
        $lainDivision = Division::factory()->create(['name' => 'Perencanaan Pengusahaan']);

        $this->seedAsetRowsFor($asetDivision, '2026-08-31', [
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', 5],
        ]);

        $this->mountAsetWidgetForStaffForTest();

        $stafAset = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $asetDivision->id]);
        $stafLain = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $lainDivision->id]);

        // Staf divisi pemilik data: melihat grafik beserta nilainya.
        $this->actingAs($stafAset)->get(route('dashboard'))->assertOk()
            ->assertSee('Stok Aset per UPT', false)
            ->assertSee('BEKASI', false)
            ->assertSee('"data":[5]', false);

        // Staf divisi lain: widget tetap dirender, tapi tanpa data Aset.
        $this->actingAs($stafLain)->get(route('dashboard'))->assertOk()
            ->assertSee('Stok Aset per UPT', false)
            ->assertSee('Belum ada data aset.', false)
            ->assertDontSee('asetUptChart', false);
    }

    public function test_role_privileged_melihat_lintas_divisi(): void
    {
        $asetDivision = Division::factory()->create(['name' => 'Data Aset']);

        $this->seedAsetRowsFor($asetDivision, '2026-08-31', [
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', 5],
        ]);

        $this->mountAsetWidgetForTest();

        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Stok Aset per UPT', false)
            ->assertSee('BEKASI', false)
            ->assertSee('"data":[5]', false);
    }

    public function test_chart_id_unik_bila_widget_dirender_berulang(): void
    {
        // Nilai sintetis; layout test memuat widget yang sama dua kali.
        $this->seedAsetRows('2026-08-31', [
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', 5],
        ]);
        config(['dashboard_layouts.layouts.admin' => ['aset-upt-chart', 'aset-upt-chart']]);

        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $content = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

        preg_match_all('/id="(asetUptChart-[^"]+)"/', $content, $matches);

        $this->assertCount(2, $matches[1]);
        $this->assertSame(2, count(array_unique($matches[1])));
    }

    public function test_paleta_mencakup_enam_series_berbeda(): void
    {
        $series = [];
        for ($i = 1; $i <= 6; $i++) {
            $series[] = ['name' => "Atribut {$i}", 'data' => [$i]];
        }

        $html = $this->blade(
            '<x-widgets.aset-upt-chart :widget="$widget" :data="$data" />',
            [
                'widget' => ['key' => 'aset-upt-chart', 'label' => 'Stok Aset per UPT'],
                'data' => ['period' => '2026-08-31', 'labels' => ['BEKASI'], 'series' => $series],
            ]
        );

        // Series ke-6 memakai warna ke-6 (melewati batas palet lama isi 4).
        $html->assertSee('"color":"#14a2ba"', false)
            ->assertSee('"color":"#f39233"', false);
    }

    public function test_menampilkan_status_kosong_tanpa_data(): void
    {
        $html = $this->blade(
            '<x-widgets.aset-upt-chart :widget="$widget" :data="$data" />',
            ['widget' => ['label' => 'Stok Aset per UPT'], 'data' => ['period' => null, 'labels' => [], 'series' => []]]
        );

        $html->assertSee('Belum ada data aset.', false)
            ->assertDontSee('asetUptChart', false);
    }

    public function test_widget_aset_tidak_tampil_di_layout_staff_divisi_default(): void
    {
        // Keputusan 2026-09-30: widget Aset dicabut dari layout staff_divisi
        // karena datanya milik divisi Managemen Aset. Staf divisi mana pun
        // (termasuk pemilik data) TIDAK melihat widget di layout default.
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => Division::factory()->create()->id,
        ]);

        $this->actingAs($staff)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Stok Aset per UPT')
            ->assertDontSee('asetUptChart', false);
    }

    public function test_widget_tampil_hanya_di_layout_staff_divisi_untuk_prototype(): void
    {
        foreach ([Role::ADMIN, Role::MANAGEMENT, Role::EDITOR_DASHBOARD] as $role) {
            $user = User::factory()->withRole($role)->create([
                'division_id' => null,
            ]);

            $this->actingAs($user)->get(route('dashboard'))->assertOk()
                ->assertDontSee('Stok Aset per UPT')
                ->assertDontSee('asetUptChart', false);
        }

        // Override config: widget dipasang di staff_divisi HANYA untuk test ini.
        $this->mountAsetWidgetForStaffForTest();

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => Division::factory()->create()->id,
        ]);

        // Staf divisi tanpa data Aset: widget dirender, isinya empty state,
        // bukan 500. Regresi yang dijaga: totalsByUpt() tidak boleh
        // menafsirkan division_id sebagai periode.
        $this->actingAs($staff)->get(route('dashboard'))->assertOk()
            ->assertSee('Stok Aset per UPT')
            ->assertSee('Belum ada data aset.', false);
    }

    public function test_pivot_controller_menjumlah_ultg_dan_mengisi_nol(): void
    {
        // Nilai sintetis (bukan angka bisnis): BEKASI 5+7=12 via roll-up
        // ULTG, BOGOR hanya punya MVA sehingga sel Bank-nya 0-fill.
        $this->seedAsetRows('2026-08-31', [
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', 5],
            ['BEKASI', 'CIKARANG', 'Trafo 500/150 KV | Bank', 7],
            ['BOGOR', 'BOGOR', 'Trafo 500/150 KV | MVA', 100],
        ]);
        $this->mountAsetWidgetForTest();

        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Stok Aset per UPT', false)
            ->assertSee('Periode 2026-08-31', false)
            ->assertSee('asetUptChart-aset-upt-chart-', false)
            ->assertSee('["BEKASI","BOGOR"]', false)
            ->assertSee('Trafo 500\\/150 KV | Bank', false)
            ->assertSee('Trafo 500\\/150 KV | MVA', false)
            ->assertSee('"data":[12,0]', false)
            ->assertSee('"data":[0,100]', false)
            ->assertSee('"type":"bar"', false);
    }

    public function test_pivot_controller_kosong_tanpa_baris_aset(): void
    {
        $this->mountAsetWidgetForTest();

        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Stok Aset per UPT', false)
            ->assertSee('Belum ada data aset.', false)
            ->assertDontSee('asetUptChart', false);
    }

    /**
     * Pasang widget Aset ke layout admin HANYA untuk test ini.
     * Production tetap unmounted (lihat config/dashboard_layouts.php).
     */
    private function mountAsetWidgetForTest(): void
    {
        config(['dashboard_layouts.layouts.admin' => ['aset-upt-chart']]);
    }

    /**
     * Pasang widget Aset ke layout staff_divisi HANYA untuk test ini.
     *
     * Sejak 2026-09-30 widget Aset dicabut dari layout staff_divisi, sehingga
     * kontrak scoping-nya diuji via override config ini, bukan mounting nyata.
     */
    private function mountAsetWidgetForStaffForTest(): void
    {
        config(['dashboard_layouts.layouts.staff_divisi' => ['kpi-summary', 'trend-chart', 'aset-upt-chart']]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: float}>  $rows
     */
    private function seedAsetRows(string $period, array $rows): void
    {
        $this->seedAsetRowsFor(Division::factory()->create(), $period, $rows);
    }

    /**
     * Baris Aset milik divisi tertentu (untuk uji scoping divisi).
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: float}>  $rows
     */
    private function seedAsetRowsFor(Division $division, string $period, array $rows): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);

        foreach ($rows as [$upt, $ultg, $attribute, $value]) {
            PerformanceMetric::factory()->create([
                'metric_name' => "ASET | {$upt} | {$ultg} | {$attribute}",
                'dataset_id' => $dataset->id,
                'division_id' => $division->id,
                'period' => $period,
                'value' => $value,
            ]);
        }
    }
}
