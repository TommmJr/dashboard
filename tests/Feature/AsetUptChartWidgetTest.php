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

        $html->assertSee('Total Tower', false)
            ->assertSee('Total Jalur Transmisi', false)
            ->assertSee('Total Kapasitas Trafo', false)
            ->assertSee('300', false)
            ->assertSee('125,7', false)
            ->assertSee('2.500', false)
            ->assertDontSee('data-category-filter="all"', false)
            ->assertDontSee('>Semua<', false)
            ->assertSee('data-category-filter="tower"', false)
            ->assertSee('data-category-filter="transmisi"', false)
            ->assertSee('data-category-filter="trafo"', false)
            ->assertSee('Rekapitulasi Matriks Fisik per UPT', false)
            ->assertSee('BANDUNG', false)
            ->assertSee('BEKASI', false)
            ->assertDontSee('Total Rekapitulasi', false)
            ->assertSee('TOTAL UIT JBT', false)
            ->assertSee("type: 'scroll'", false)
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
            ->assertSee('Total Tower', false)
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
        $response->assertSee('Total Tower', false)
            ->assertSee('6.336', false)
            ->assertSee('Total Jalur Transmisi', false)
            ->assertSee('2.850,5', false)
            ->assertSee('Total Kapasitas Trafo', false)
            ->assertSee('20.500', false)
            ->assertSee('TOTAL UIT JBT', false)
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

        $html->assertSee('TOTAL UIT JBT', false)
            ->assertDontSee('Total Rekapitulasi', false)
            ->assertSee('680', false)
            ->assertSee('340', false)
            ->assertSee('6.800', false);
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
}
