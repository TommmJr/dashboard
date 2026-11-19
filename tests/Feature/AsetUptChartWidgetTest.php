<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
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
            ->assertSee('height: 360px', false)
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

        // Staf divisi lain: widget TIDAK dirender sama sekali (fail-closed, dashboard bersih murni).
        $this->actingAs($stafLain)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Stok Aset per UPT', false)
            ->assertDontSee('Belum ada data aset.', false)
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

        // Semua series memakai palet tenang PLN_CHART_PALETTE (#0b6175) tanpa warna kontras mencolok (#e11d48).
        $html->assertSee('"color":"#0b6175"', false)
            ->assertDontSee('"color":"#e11d48"', false);
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
            'division_id' => Division::factory()->create(['name' => 'Data Aset'])->id,
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
            'division_id' => Division::factory()->create(['name' => 'Data Aset'])->id,
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

    public function test_aset_upt_chart_mengembalikan_summary_table_rows_dan_series_by_category(): void
    {
        $division = Division::factory()->create(['name' => 'Data Aset']);
        $this->seedAsetRowsFor($division, '2026-08-31', [
            ['BEKASI', 'BEKASI', 'Tower 150 KV | SU', 10],
            ['BEKASI', 'BEKASI', 'Transmisi 150 KV | SU | kms', 25.5],
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | MVA', 1000],
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', 4],
            ['BOGOR', 'BOGOR', 'Tower 500 KV | SU', 20],
            ['BOGOR', 'BOGOR', 'Transmisi 500 KV | SU | kms', 50.2],
            ['BOGOR', 'BOGOR', 'Trafo 150/20 KV | MVA', 500],
        ]);

        $staf = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);
        $this->mountAsetWidgetForStaffForTest();

        $response = $this->actingAs($staf)->get(route('dashboard'))->assertOk();
        $widgets = collect($response->viewData('widgets') ?? []);
        $widget = $widgets->firstWhere('key', 'aset-upt-chart');
        $this->assertNotNull($widget);

        $data = $widget['data'] ?? [];
        $this->assertSame(30.0, $data['summary']['total_tower']);
        $this->assertSame(75.7, $data['summary']['total_transmisi']);
        $this->assertSame(1500.0, $data['summary']['total_trafo']);

        $this->assertCount(8, $data['table_rows']);
        $tableUpts = collect($data['table_rows'])->keyBy('upt');
        $this->assertSame(10.0, $tableUpts['BEKASI']['tower']);
        $this->assertSame(25.5, $tableUpts['BEKASI']['transmisi']);
        $this->assertSame(1000.0, $tableUpts['BEKASI']['trafo']);

        $this->assertSame(20.0, $tableUpts['BOGOR']['tower']);
        $this->assertSame(50.2, $tableUpts['BOGOR']['transmisi']);
        $this->assertSame(500.0, $tableUpts['BOGOR']['trafo']);

        $this->assertArrayHasKey('tower', $data['series_by_category']);
        $this->assertArrayHasKey('transmisi', $data['series_by_category']);
        $this->assertArrayHasKey('trafo', $data['series_by_category']);
        $this->assertCount(2, $data['series_by_category']['tower']);
        $this->assertCount(2, $data['series_by_category']['transmisi']);
        $this->assertCount(2, $data['series_by_category']['trafo']);
    }

    public function test_blade_merender_scorecard_filter_tab_dan_tabel_matriks(): void
    {
        $html = $this->blade(
            '<x-widgets.aset-upt-chart :widget="$widget" :data="$data" />',
            [
                'widget' => ['key' => 'aset-upt-chart', 'label' => 'Stok Aset per UPT'],
                'data' => [
                    'period' => '2026-08-31',
                    'labels' => ['BANDUNG', 'BEKASI'],
                    'series' => [
                        ['name' => 'Tower 150 KV | SU', 'data' => [100.0, 200.0]],
                        ['name' => 'Transmisi 150 KV | SU | kms', 'data' => [50.5, 75.2]],
                        ['name' => 'Trafo 500/150 KV | MVA', 'data' => [1000.0, 1500.0]],
                    ],
                    'summary' => [
                        'total_tower' => 300.0,
                        'total_transmisi' => 125.7,
                        'total_trafo' => 2500.0,
                    ],
                    'table_rows' => [
                        ['upt' => 'BANDUNG', 'tower' => 100.0, 'transmisi' => 50.5, 'trafo' => 1000.0],
                        ['upt' => 'BEKASI', 'tower' => 200.0, 'transmisi' => 75.2, 'trafo' => 1500.0],
                    ],
                    'series_by_category' => [
                        'tower' => [['name' => 'Tower 150 KV | SU', 'data' => [100.0, 200.0]]],
                        'transmisi' => [['name' => 'Transmisi 150 KV | SU | kms', 'data' => [50.5, 75.2]]],
                        'trafo' => [['name' => 'Trafo 500/150 KV | MVA', 'data' => [1000.0, 1500.0]]],
                    ],
                ],
            ]
        );

        $html->assertSee('Konstruksi Tower', false)
            ->assertSee('Jaringan Transmisi', false)
            ->assertSee('Kapasitas Trafo', false)
            ->assertSee('300', false)
            ->assertSee('125,7', false)
            ->assertSee('2.500', false)
            ->assertDontSee('data-category-filter="all"', false)
            ->assertDontSee('>Semua<', false)
            ->assertSee('data-category-filter="tower"', false)
            ->assertSee('data-category-filter="transmisi"', false)
            ->assertSee('data-category-filter="trafo"', false)
            ->assertSee('Sebaran 8 Wilayah UPT', false)
            ->assertSee('Unit Pelaksana Transmisi', false)
            ->assertSee('BANDUNG', false)
            ->assertSee('BEKASI', false)
            ->assertDontSee('Rekapitulasi Matriks Fisik per UPT', false)
            ->assertDontSee('Total Rekapitulasi', false)
            ->assertSee('Total Induk UIT JBT', false)
            ->assertDontSee("type: 'scroll'", false)
            ->assertSee('containLabel: true', false);
    }

    public function test_blade_tahan_terhadap_payload_lama_tanpa_summary(): void
    {
        $html = $this->blade(
            '<x-widgets.aset-upt-chart :widget="$widget" :data="$data" />',
            [
                'widget' => ['key' => 'aset-upt-chart', 'label' => 'Stok Aset per UPT'],
                'data' => [
                    'period' => '2026-08-31',
                    'labels' => ['BEKASI'],
                    'series' => [
                        ['name' => 'Tower 150 KV | SU', 'data' => [50.0]],
                    ],
                ],
            ]
        );

        $html->assertSee('Stok Aset per UPT', false)
            ->assertSee('Konstruksi Tower', false)
            ->assertSee('50', false)
            ->assertDontSee('data-category-filter="all"', false)
            ->assertSee('data-category-filter="tower"', false);
    }

    public function test_aset_upt_chart_mengambil_scorecard_dan_total_tabel_dari_totals_by_uit(): void
    {
        $division = Division::factory()->create(['name' => 'Data Aset']);
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);

        // 1. Seed Tab 1b (UPT level)
        foreach ([
            ['BEKASI', 'BEKASI', 'Tower 150 KV | SU', 10],
            ['BEKASI', 'BEKASI', 'Transmisi 150 KV | SU | kms', 25.5],
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | MVA', 1000],
            ['BOGOR', 'BOGOR', 'Tower 500 KV | SU', 20],
            ['BOGOR', 'BOGOR', 'Transmisi 500 KV | SU | kms', 50.2],
            ['BOGOR', 'BOGOR', 'Trafo 150/20 KV | MVA', 500],
        ] as [$upt, $ultg, $attr, $val]) {
            PerformanceMetric::factory()->create([
                'metric_name' => "ASET | {$upt} | {$ultg} | {$attr}",
                'dataset_id' => $dataset->id,
                'division_id' => $division->id,
                'period' => '2026-08-31',
                'value' => $val,
            ]);
        }

        // 2. Seed Tab 1a (UIT level) dengan nilai agregat register resmi
        $this->seedAsetUitRowsFor($division, '2026-08-31', [
            ['Tower 500 KV | SU', 6336.0],
            ['Transmisi 500 KV | SU | kms', 2850.5],
            ['Trafo 500/150 KV | MVA', 20500.0],
            ['Trafo 500/150 KV | Bank', 54.0], // Bank tidak boleh dijumlahkan ke MVA
        ], $dataset);

        $staf = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);
        $this->mountAsetWidgetForStaffForTest();

        $response = $this->actingAs($staf)->get(route('dashboard'))->assertOk();
        $widgets = collect($response->viewData('widgets') ?? []);
        $widget = $widgets->firstWhere('key', 'aset-upt-chart');
        $this->assertNotNull($widget);

        $data = $widget['data'] ?? [];
        // Nilai summary harus mengambil dari Tab 1a (UIT), bukan akumulasi parsial Tab 1b
        $this->assertSame(6336.0, $data['summary']['total_tower']);
        $this->assertSame(2850.5, $data['summary']['total_transmisi']);
        $this->assertSame(20500.0, $data['summary']['total_trafo']);

        // Verifikasi render HTML
        $response->assertSee('Konstruksi Tower', false)
            ->assertSee('6.336', false)
            ->assertSee('Jaringan Transmisi', false)
            ->assertSee('2.850,5', false)
            ->assertSee('Kapasitas Trafo', false)
            ->assertSee('20.500', false)
            ->assertSee('Total Induk UIT JBT', false)
            ->assertDontSee('Total Rekapitulasi', false)
            ->assertDontSee('data-category-filter="all"', false);
    }

    public function test_blade_tab_trafo_hanya_merender_series_mva(): void
    {
        $html = $this->blade(
            '<x-widgets.aset-upt-chart :widget="$widget" :data="$data" />',
            [
                'widget' => ['key' => 'aset-upt-chart', 'label' => 'Stok Aset per UPT'],
                'data' => [
                    'period' => '2026-08-31',
                    'labels' => ['BANDUNG', 'BEKASI'],
                    'series' => [
                        ['name' => 'Trafo 500/150 KV | MVA', 'data' => [1000.0, 1500.0]],
                        ['name' => 'Trafo 500/150 KV | Bank', 'data' => [4.0, 6.0]],
                    ],
                    'summary' => [
                        'total_tower' => 0.0,
                        'total_transmisi' => 0.0,
                        'total_trafo' => 2500.0,
                    ],
                    'table_rows' => [
                        ['upt' => 'BANDUNG', 'tower' => 0.0, 'transmisi' => 0.0, 'trafo' => 1000.0],
                        ['upt' => 'BEKASI', 'tower' => 0.0, 'transmisi' => 0.0, 'trafo' => 1500.0],
                    ],
                    'series_by_category' => [
                        'tower' => [],
                        'transmisi' => [],
                        'trafo' => [
                            ['name' => 'Trafo 500/150 KV | MVA', 'data' => [1000.0, 1500.0]],
                        ],
                    ],
                ],
            ]
        );

        // Chart config seriesByCategory.trafo hanya berisi MVA
        $html->assertSee('Trafo 500\\/150 KV | MVA', false)
            ->assertDontSee('data-category-filter="all"', false)
            ->assertSee('data-category-filter="trafo"', false);
    }

    public function test_blade_tabel_rekapitulasi_menampilkan_delapan_upt_kanonis_dan_total_uit_jbt(): void
    {
        $tableRows = [
            ['upt' => 'BANDUNG', 'tower' => 100.0, 'transmisi' => 50.0, 'trafo' => 1000.0],
            ['upt' => 'BEKASI', 'tower' => 120.0, 'transmisi' => 60.0, 'trafo' => 1200.0],
            ['upt' => 'BOGOR', 'tower' => 110.0, 'transmisi' => 55.0, 'trafo' => 1100.0],
            ['upt' => 'CIREBON', 'tower' => 90.0, 'transmisi' => 45.0, 'trafo' => 900.0],
            ['upt' => 'KARAWANG', 'tower' => 80.0, 'transmisi' => 40.0, 'trafo' => 800.0],
            ['upt' => 'PURWOKERTO', 'tower' => 70.0, 'transmisi' => 35.0, 'trafo' => 700.0],
            ['upt' => 'SALATIGA', 'tower' => 60.0, 'transmisi' => 30.0, 'trafo' => 600.0],
            ['upt' => 'SEMARANG', 'tower' => 50.0, 'transmisi' => 25.0, 'trafo' => 500.0],
        ];

        $html = $this->blade(
            '<x-widgets.aset-upt-chart :widget="$widget" :data="$data" />',
            [
                'widget' => ['key' => 'aset-upt-chart', 'label' => 'Stok Aset per UPT'],
                'data' => [
                    'period' => '2026-08-31',
                    'labels' => array_column($tableRows, 'upt'),
                    'series' => [
                        ['name' => 'Tower 150 KV | SU', 'data' => array_column($tableRows, 'tower')],
                    ],
                    'summary' => [
                        'total_tower' => 680.0,
                        'total_transmisi' => 340.0,
                        'total_trafo' => 6800.0,
                    ],
                    'table_rows' => $tableRows,
                    'series_by_category' => [
                        'tower' => [['name' => 'Tower 150 KV | SU', 'data' => array_column($tableRows, 'tower')]],
                        'transmisi' => [],
                        'trafo' => [],
                    ],
                ],
            ]
        );

        foreach (['BANDUNG', 'BEKASI', 'BOGOR', 'CIREBON', 'KARAWANG', 'PURWOKERTO', 'SALATIGA', 'SEMARANG'] as $upt) {
            $html->assertSee($upt, false);
        }

        $html->assertSee('Total Induk UIT JBT', false)
            ->assertSee('id="aset-upt-ranking"', false)
            ->assertDontSee('Total Rekapitulasi', false)
            ->assertSee('680', false)
            ->assertSee('340', false)
            ->assertSee('6.800', false)
            ->assertSee('17,6%', false);
    }

    public function test_blade_menampilkan_hirarki_kepemilikan_uit_jbt_dan_provenance_tab_tanpa_double_counting(): void
    {
        $html = $this->blade(
            '<x-widgets.aset-upt-chart :widget="$widget" :data="$data" />',
            [
                'widget' => ['key' => 'aset-upt-chart', 'label' => 'Stok Aset per UPT'],
                'data' => [
                    'period' => '2026-08-31',
                    'period_uit' => '2026-08-31',
                    'period_upt' => '2026-08-31',
                    'labels' => ['BANDUNG', 'BEKASI'],
                    'series' => [
                        ['name' => 'Tower 150 KV | SU', 'data' => [100.0, 200.0]],
                    ],
                    'summary' => [
                        'total_tower' => 6336.0,
                        'total_transmisi' => 2850.5,
                        'total_trafo' => 20500.0,
                    ],
                    'table_rows' => [
                        ['upt' => 'BANDUNG', 'tower' => 100.0, 'transmisi' => 50.0, 'trafo' => 1000.0],
                        ['upt' => 'BEKASI', 'tower' => 200.0, 'transmisi' => 75.0, 'trafo' => 1500.0],
                    ],
                    'series_by_category' => [
                        'tower' => [['name' => 'Tower 150 KV | SU', 'data' => [100.0, 200.0]]],
                        'transmisi' => [],
                        'trafo' => [],
                    ],
                ],
            ]
        );

        $html->assertSee('Unit Induk Transmisi Jawa Bagian Tengah', false)
            ->assertSee('Total Induk UIT JBT', false)
            ->assertSee('Sebaran 8 Wilayah UPT', false)
            ->assertSee('Unit Pelaksana Transmisi', false)
            ->assertDontSee('Tab 1a', false)
            ->assertDontSee('Tab 1b', false)
            ->assertDontSee('belum diverifikasi PIC MANSET', false);
    }

    /**
     * Baris Aset level UIT (Tab 1a) milik divisi tertentu.
     *
     * @param  list<array{0: string, 1: float}>  $rows
     */
    private function seedAsetUitRowsFor(Division $division, string $period, array $rows, ?Dataset $dataset = null): Dataset
    {
        $dataset ??= Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);

        foreach ($rows as [$attribute, $value]) {
            PerformanceMetric::factory()->create([
                'metric_name' => "ASET | UIT | {$attribute}",
                'dataset_id' => $dataset->id,
                'division_id' => $division->id,
                'period' => $period,
                'value' => $value,
            ]);
        }

        return $dataset;
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

    public function test_header_dashboard_staf_managemen_aset_menampilkan_uit_jbt_8_upt_tanpa_tjbt(): void
    {
        $division = Division::factory()->create([
            'name' => 'Managemen Aset',
            'slug' => 'managemen-aset',
        ]);

        $staf = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $division->id,
        ]);

        config(['kpi.visible_units' => ['TJBT']]);

        $response = $this->actingAs($staf)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Managemen Aset')
            ->assertSee('UIT JBT')
            ->assertSee('8 UPT')
            ->assertDontSee('TJBT');
    }

    public function test_widget_aset_menampilkan_kesegaran_data_terbaru_berdampingan_dengan_prototipe_demo_dan_tab_provenance(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 16, 14, 25, 0, 'Asia/Jakarta'));

        try {
            $manset = Division::factory()->create([
                'name' => 'Managemen Aset',
                'slug' => 'managemen-aset',
            ]);
            $pengusahaan = Division::factory()->create([
                'name' => 'Perencanaan Pengusahaan',
                'slug' => 'perencanaan-pengusahaan',
            ]);

            $stafAset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
                'division_id' => $manset->id,
            ]);

            $asetDataset = Dataset::factory()->create([
                'division_id' => $manset->id,
                'status' => Dataset::STATUS_READY,
                'created_at' => Carbon::create(2026, 11, 16, 14, 25, 0, 'Asia/Jakarta'),
                'updated_at' => Carbon::create(2026, 11, 16, 14, 25, 0, 'Asia/Jakarta'),
            ]);
            PerformanceMetric::factory()->create([
                'metric_name' => 'ASET | BANDUNG | BANDUNG BARAT | Tower 150 KV | SU',
                'dataset_id' => $asetDataset->id,
                'division_id' => $manset->id,
                'period' => '2026-11-30',
                'value' => 120,
            ]);
            $this->seedAsetUitRowsFor($manset, '2026-11-30', [
                ['Tower 150 KV | SU', 120],
            ], $asetDataset);

            // Dataset divisi lain tidak memengaruhi kesegaran Aset
            $kpiDataset = Dataset::factory()->create([
                'division_id' => $pengusahaan->id,
                'status' => Dataset::STATUS_READY,
                'created_at' => Carbon::create(2026, 11, 16, 15, 0, 0, 'Asia/Jakarta'),
                'updated_at' => Carbon::create(2026, 11, 16, 15, 0, 0, 'Asia/Jakarta'),
            ]);
            PerformanceMetric::factory()->create([
                'metric_name' => 'TJBT | NKO',
                'dataset_id' => $kpiDataset->id,
                'division_id' => $pengusahaan->id,
                'period' => '2026-11-30',
                'value' => 105.5,
            ]);

            $this->actingAs($stafAset)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('id="aset-freshness-indicator"', false)
                ->assertSee('Sumber data:')
                ->assertSee('November 2026')
                ->assertDontSee('Prototipe Demo')
                ->assertDontSee('Tab 1a')
                ->assertDontSee('Tab 1b')
                ->assertSee('Diperbarui 16 Nov 2026, 14.25 WIB')
                ->assertDontSee('Diperbarui 16 Nov 2026, 15.00 WIB')
                ->assertDontSee('Data periode berjalan belum diperbarui');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_widget_aset_menampilkan_penanda_stale_saat_periode_aset_tertinggal_dan_aman_saat_kosong(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 16, 20, 37, 0, 'Asia/Jakarta'));

        try {
            $manset = Division::factory()->create([
                'name' => 'Managemen Aset',
                'slug' => 'managemen-aset',
            ]);
            $stafAset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
                'division_id' => $manset->id,
            ]);

            $asetDataset = Dataset::factory()->create([
                'division_id' => $manset->id,
                'status' => Dataset::STATUS_READY,
                'created_at' => Carbon::create(2026, 11, 14, 20, 37, 0, 'Asia/Jakarta'),
                'updated_at' => Carbon::create(2026, 11, 14, 20, 37, 0, 'Asia/Jakarta'),
            ]);
            PerformanceMetric::factory()->create([
                'metric_name' => 'ASET | BEKASI | BEKASI | Tower 150 KV | SU',
                'dataset_id' => $asetDataset->id,
                'division_id' => $manset->id,
                'period' => '2026-10-31',
                'value' => 88,
            ]);

            $staleHtml = $this->actingAs($stafAset)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('id="aset-freshness-indicator"', false)
                ->assertSee('Sumber data:')
                ->assertSee('Oktober 2026')
                ->assertDontSee('Prototipe Demo')
                ->assertDontSee('Tab 1a')
                ->assertDontSee('Tab 1b')
                ->assertSee('Diperbarui 14 Nov 2026, 20.37 WIB')
                ->assertSee('Data periode berjalan belum diperbarui')
                ->getContent();

            $visibleStaleHtml = (string) preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $staleHtml);
            $this->assertStringNotContainsString('—', $visibleStaleHtml);

            $asetDataset->update(['status' => Dataset::STATUS_ARCHIVED]);

            $html = $this->actingAs($stafAset)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('id="aset-freshness-indicator"', false)
                ->assertSee('Belum ada data aset.')
                ->assertSee('Belum ada data aktif')
                ->assertSee('Belum ada dataset aset aktif')
                ->getContent();

            $visibleHtml = (string) preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
            $this->assertStringNotContainsString('—', $visibleHtml);
            $this->assertDoesNotMatchRegularExpression('/>\s*(?:-|–|—)+\s*</', $visibleHtml);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_kesegaran_aset_memakai_created_at_desc_id_desc_dan_terisolasi_ke_slug_managemen_aset(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 17, 8, 30, 0, 'Asia/Jakarta'));

        try {
            $manset = Division::factory()->create([
                'name' => 'Managemen Aset',
                'slug' => 'managemen-aset',
            ]);
            $otherDivision = Division::factory()->create([
                'name' => 'Engineer',
                'slug' => 'engineer',
            ]);

            $asetDataset = Dataset::factory()->create([
                'division_id' => $manset->id,
                'status' => Dataset::STATUS_READY,
                'created_at' => Carbon::create(2026, 11, 12, 9, 37, 0, 'Asia/Jakarta'),
                'updated_at' => Carbon::create(2026, 11, 17, 8, 20, 0, 'Asia/Jakarta'),
            ]);
            PerformanceMetric::factory()->create([
                'metric_name' => 'ASET | BEKASI | BEKASI | Tower 150 KV | SU',
                'dataset_id' => $asetDataset->id,
                'division_id' => $manset->id,
                'period' => '2026-10-31',
                'value' => 88,
            ]);

            // Dataset ber-prefix ASET milik divisi lain tidak boleh memalsukan kesegaran Aset untuk privileged role
            $foreignDataset = Dataset::factory()->create([
                'division_id' => $otherDivision->id,
                'status' => Dataset::STATUS_READY,
                'created_at' => Carbon::create(2026, 11, 17, 8, 25, 0, 'Asia/Jakarta'),
                'updated_at' => Carbon::create(2026, 11, 17, 8, 25, 0, 'Asia/Jakarta'),
            ]);
            PerformanceMetric::factory()->create([
                'metric_name' => 'ASET | BANDUNG | BANDUNG BARAT | Tower 150 KV | SU',
                'dataset_id' => $foreignDataset->id,
                'division_id' => $otherDivision->id,
                'period' => '2026-11-30',
                'value' => 99,
            ]);

            $stafAset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
                'division_id' => $manset->id,
            ]);
            $admin = User::factory()->withRole(Role::ADMIN)->create([
                'division_id' => null,
            ]);
            $this->mountAsetWidgetForTest();

            foreach ([$stafAset, $admin] as $actor) {
                $this->actingAs($actor)
                    ->get(route('dashboard'))
                    ->assertOk()
                    ->assertSee('id="aset-freshness-indicator"', false)
                    ->assertSee('Diperbarui 12 Nov 2026, 09.37 WIB')
                    ->assertSee('Data periode berjalan belum diperbarui')
                    ->assertDontSee('Diperbarui 17 Nov 2026, 08.20 WIB')
                    ->assertDontSee('Diperbarui 17 Nov 2026, 08.25 WIB');
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_widget_aset_membedakan_null_tidak_tercatat_dari_nol_dan_menyediakan_filter_periode_historis_ultg_serta_rasio_kapasitas(): void
    {
        $manset = Division::factory()->create([
            'name' => 'Managemen Aset',
            'slug' => 'managemen-aset',
        ]);
        $foreignDiv = Division::factory()->create([
            'name' => 'Operasi Sistem',
            'slug' => 'operasi-sistem',
        ]);

        $stafAset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $manset->id,
        ]);

        // Periode historis Agustus 2026
        $this->seedAsetRowsFor($manset, '2026-08-31', [
            ['BANDUNG', 'BANDUNG BARAT', 'Tower 500 KV | SU', 85.0],
        ]);

        // Periode terbaru Oktober 2026 dengan kombinasi nilai > 0, nilai eksplisit 0.0, dan UPT kosong (null)
        $this->seedAsetRowsFor($manset, '2026-10-31', [
            ['BANDUNG', 'BANDUNG BARAT', 'Tower 500 KV | SU', 120.0],
            ['BANDUNG', 'BANDUNG SELATAN', 'Transmisi 150 KV | SU | kms', 300.0],
            ['BANDUNG', 'BANDUNG SELATAN', 'Transmisi 150 KV | SK | kms', 100.0],
            ['BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | MVA', 1000.0],
            ['BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | Bank', 6.0],
            ['BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | Unit/Set', 2.0],
            ['BEKASI', 'BEKASI', 'Tower 150 KV | SU', 0.0],
        ]);

        // Dataset divisi asing pada periode lebih baru tidak boleh bocor ke staf aset
        $this->seedAsetRowsFor($foreignDiv, '2026-12-31', [
            ['BOGOR', 'BOGOR', 'Tower 500 KV | SU', 9999.0],
        ]);

        $response = $this->actingAs($stafAset)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="aset-widget-period-select"', false)
            ->assertSee('data-auto-submit', false)
            ->assertDontSee('id="aset-widget-capacity-ratios"', false)
            ->assertDontSee('id="aset-widget-ultg-drilldown"', false)
            ->assertSee('Oktober 2026')
            ->assertSee('Agustus 2026')
            ->assertSee('Tidak tercatat')
            ->assertDontSee('2026-12-31')
            ->assertDontSee('font-mono', false);

        $widgets = collect($response->viewData('widgets') ?? []);
        $widget = $widgets->firstWhere('key', 'aset-upt-chart');
        $this->assertNotNull($widget);

        $data = $widget['data'];
        $this->assertSame('2026-10-31', $data['period']);
        $this->assertSame(['2026-10-31', '2026-08-31'], $data['available_periods']);

        $tableByUpt = collect($data['table_rows'])->keyBy('upt');
        $this->assertSame(120.0, $tableByUpt['BANDUNG']['tower']);
        $this->assertSame(400.0, $tableByUpt['BANDUNG']['transmisi']);
        $this->assertSame(1000.0, $tableByUpt['BANDUNG']['trafo']);
        // BEKASI memiliki baris Tower eksplisit 0.0, sedangkan Transmisi & Trafo tidak tercatat (null)
        $this->assertSame(0.0, $tableByUpt['BEKASI']['tower']);
        $this->assertNull($tableByUpt['BEKASI']['transmisi']);
        $this->assertNull($tableByUpt['BEKASI']['trafo']);
        // BOGOR sama sekali tidak tercatat pada divisi managemen-aset
        $this->assertNull($tableByUpt['BOGOR']['tower']);

        $this->assertCount(2, $data['ultg_by_upt']['BANDUNG']);
        $this->assertSame(500.0, $data['capacity_ratios']['trafo']['overall']['avg_mva_per_unit']);
        $this->assertSame(75.0, $data['capacity_ratios']['transmisi']['overall']['su_pct']);
        $this->assertSame(25.0, $data['capacity_ratios']['transmisi']['overall']['sk_pct']);

        // Filter periode historis valid (?period=2026-08-31)
        $historicalResponse = $this->actingAs($stafAset)
            ->get(route('dashboard', ['period' => '2026-08-31']))
            ->assertOk();
        $historicalWidget = collect($historicalResponse->viewData('widgets') ?? [])->firstWhere('key', 'aset-upt-chart');
        $this->assertSame('2026-08-31', $historicalWidget['data']['period']);
        $historicalTable = collect($historicalWidget['data']['table_rows'])->keyBy('upt');
        $this->assertSame(85.0, $historicalTable['BANDUNG']['tower']);

        // Filter periode asing/invalid fallback ke periode terbaru milik divisi (2026-10-31)
        $fallbackResponse = $this->actingAs($stafAset)
            ->get(route('dashboard', ['period' => '2026-12-31']))
            ->assertOk();
        $fallbackWidget = collect($fallbackResponse->viewData('widgets') ?? [])->firstWhere('key', 'aset-upt-chart');
        $this->assertSame('2026-10-31', $fallbackWidget['data']['period']);
    }

    public function test_header_eksekutif_menampilkan_ringkasan_otomatis_sumber_dan_cta_layar_penuh(): void
    {
        $manset = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $stafAset = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $manset->id]);

        $dataset = Dataset::factory()->create([
            'division_id' => $manset->id,
            'status' => Dataset::STATUS_READY,
            'original_name' => 'REKAP ASET UIT JBT',
        ]);

        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | BEKASI | BEKASI | Trafo 500/150 KV | MVA',
            'dataset_id' => $dataset->id,
            'division_id' => $manset->id,
            'period' => '2026-10-31',
            'value' => 7480.0,
        ]);
        $this->seedAsetUitRowsFor($manset, '2026-10-31', [
            ['Trafo 500/150 KV | MVA', 50112.0],
            ['Transmisi 500 KV | SU | kms', 13259.4],
            ['Tower 500 KV | SU', 19447.0],
        ], $dataset);

        $this->actingAs($stafAset)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="aset-executive-header"', false)
            ->assertSee('Ringkasan Aset UIT JBT')
            ->assertSee('id="aset-auto-summary"', false)
            ->assertSee('id="aset-source-name"', false)
            ->assertSee('REKAP ASET UIT JBT')
            ->assertSee('id="aset-widget-fullscreen-link"', false)
            ->assertSee('Buka Layar Penuh')
            ->assertSee('fullscreen=1', false);
    }

    public function test_hero_strip_menampilkan_bank_dan_unit_trafo_induk(): void
    {
        $manset = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $stafAset = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $manset->id]);

        $dataset = Dataset::factory()->create([
            'division_id' => $manset->id,
            'status' => Dataset::STATUS_READY,
            'original_name' => 'REKAP ASET UIT JBT',
        ]);

        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | BEKASI | BEKASI | Trafo 500/150 KV | MVA',
            'dataset_id' => $dataset->id,
            'division_id' => $manset->id,
            'period' => '2026-10-31',
            'value' => 7480.0,
        ]);
        $this->seedAsetUitRowsFor($manset, '2026-10-31', [
            ['Trafo 500/150 KV | MVA', 50112.0],
            ['Trafo 500/150 KV | Bank', 653.0],
            ['Trafo 500/150 KV | Unit/Set', 569.0],
            ['Transmisi 500 KV | SU | kms', 13259.4],
            ['Tower 500 KV | SU', 19447.0],
        ], $dataset);

        $this->actingAs($stafAset)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="aset-hero-strip"', false)
            ->assertSee('50.112,0')
            ->assertSee('653 Bank')
            ->assertSee('569 Unit')
            ->assertSee('13.259,4')
            ->assertSee('19.447');
    }

    public function test_empat_kartu_pintas_mengarah_ke_section_detail_aset(): void
    {
        $manset = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $stafAset = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $manset->id]);

        $this->seedAsetRowsFor($manset, '2026-10-31', [
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | MVA', 7480.0],
        ]);

        $this->actingAs($stafAset)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="aset-explore-shortcuts"', false)
            ->assertSee('Bedah Trafo & Tegangan')
            ->assertSee('Distribusi Daya (MVA)')
            ->assertSee('Jaringan Transmisi (kms)')
            ->assertSee('Sebaran Tower (Unit)')
            ->assertSee('data-shortcut-section="trafo"', false)
            ->assertSee('data-shortcut-section="mva"', false)
            ->assertSee('data-shortcut-section="transmisi"', false)
            ->assertSee('data-shortcut-section="tower"', false);
    }

    public function test_widget_aset_bebas_jargon_dan_placeholder_strip(): void
    {
        $manset = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $stafAset = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $manset->id]);

        $dataset = Dataset::factory()->create([
            'division_id' => $manset->id,
            'status' => Dataset::STATUS_READY,
            'original_name' => 'Sinkronisasi Google Sheets (Tab 1b + Tab 1a)',
        ]);

        foreach ([
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | MVA', 7480.0],
            ['BEKASI', 'BEKASI', 'SIRKIT | UPT', 40.0],
            ['BEKASI', 'BEKASI', 'DIAMETER | CUT OFF CB', 50.0],
            ['BEKASI', 'BEKASI', 'Joint | SK', 60.0],
            ['BEKASI', 'BEKASI', 'Gardu Induk Milik Unit Pengusahaan | GI/GITET | 150kV', 70.0],
        ] as [$upt, $ultg, $attr, $val]) {
            PerformanceMetric::factory()->create([
                'metric_name' => "ASET | {$upt} | {$ultg} | {$attr}",
                'dataset_id' => $dataset->id,
                'division_id' => $manset->id,
                'period' => '2026-10-31',
                'value' => $val,
            ]);
        }

        $html = $this->actingAs($stafAset)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Sumber data:')
            ->assertSee('Rekap Aset UIT JBT')
            ->assertDontSee('Tab 1a')
            ->assertDontSee('Tab 1b')
            ->assertDontSee('READY')
            ->assertDontSee('Prototipe Demo')
            ->assertDontSee('font-mono', false)
            ->assertDontSee('SIRKIT | UPT', false)
            ->assertDontSee('DIAMETER | CUT OFF CB', false)
            ->assertDontSee('Joint | SK', false)
            ->assertDontSee('GI/GITET', false)
            ->getContent();

        $visibleHtml = (string) preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $this->assertStringNotContainsString('—', $visibleHtml);
        $this->assertDoesNotMatchRegularExpression('/>\s*(?:-|–|—)+\s*</', $visibleHtml);
    }

    public function test_staf_pengusahaan_tidak_menerima_nama_sumber_dataset_aset(): void
    {
        $manset = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $pengusahaan = Division::factory()->create(['name' => 'Perencanaan Pengusahaan', 'slug' => 'perencanaan-pengusahaan']);

        $dataset = Dataset::factory()->create([
            'division_id' => $manset->id,
            'status' => Dataset::STATUS_READY,
            'original_name' => 'REKAP ASET UIT JBT RAHASIA',
        ]);

        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | BEKASI | BEKASI | Trafo 500/150 KV | MVA',
            'dataset_id' => $dataset->id,
            'division_id' => $manset->id,
            'period' => '2026-10-31',
            'value' => 7480.0,
        ]);

        $stafPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $pengusahaan->id]);

        $this->actingAs($stafPengusahaan)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('REKAP ASET UIT JBT RAHASIA')
            ->assertDontSee('Sumber data:')
            ->assertDontSee('id="aset-executive-header"', false);
    }
}
