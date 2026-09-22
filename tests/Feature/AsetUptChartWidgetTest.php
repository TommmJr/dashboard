<?php

namespace Tests\Feature;

use App\Models\Division;
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
 * Render diuji terisolasi via $this->blade(), tanpa route baru.
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
                'widget' => ['label' => 'Stok Aset per UPT'],
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
            ->assertSee('asetUptChart', false)
            ->assertSee('"type":"bar"', false);
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
}
