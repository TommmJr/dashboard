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
 * Widget BELUM dipasang ke layout role mana pun — test mengunci itu agar
 * user nyata tidak melihat grafik berisi data nol sebelum M2 dibuka.
 * Render diuji terisolasi via $this->blade(), tanpa route baru; pivot
 * controller (`asetUptChart()`) diuji lewat route dashboard dengan override
 * config layout HANYA di dalam test (production tetap unmounted).
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
            ->assertSee('"type":"bar"', false);
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

    public function test_widget_belum_tampil_di_layout_role_mana_pun(): void
    {
        foreach ([Role::ADMIN, Role::MANAGEMENT, Role::EDITOR_DASHBOARD, Role::STAFF_DIVISI] as $role) {
            $user = User::factory()->withRole($role)->create([
                'division_id' => $role === Role::STAFF_DIVISI ? Division::factory()->create()->id : null,
            ]);

            $this->actingAs($user)->get(route('dashboard'))->assertOk()
                ->assertDontSee('Stok Aset per UPT')
                ->assertDontSee('asetUptChart', false);
        }
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
     * @param  list<array{0: string, 1: string, 2: string, 3: float}>  $rows
     */
    private function seedAsetRows(string $period, array $rows): void
    {
        $division = Division::factory()->create();
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
