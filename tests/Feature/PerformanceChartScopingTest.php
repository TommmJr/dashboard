<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiReportConverter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Memverifikasi scoping data performance per divisi (audit #3).
 *
 * - Admin & management: boleh lihat agregat semua divisi.
 * - Staf divisi & editor: hanya divisinya sendiri.
 *
 * Basis agregasi NKO (bukan SUM seluruh value) — lihat PerformanceChartDataTest.
 */
class PerformanceChartScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function seedDivisionsAndMetrics(): array
    {
        $divA = Division::create(['name' => 'Divisi A', 'slug' => 'div-a']);
        $divB = Division::create(['name' => 'Divisi B', 'slug' => 'div-b']);

        $nko = function (Division $division, string $period, float $value): void {
            PerformanceMetric::create([
                'metric_name' => $division->name.KpiReportConverter::NKO_SUFFIX,
                'value' => $value,
                'period' => $period,
                'division_id' => $division->id,
            ]);
        };

        $nko($divA, '2026-01-15', 100);
        $nko($divA, '2026-02-15', 105);

        $nko($divB, '2026-01-15', 95);
        $nko($divB, '2026-02-15', 95);

        return [$divA, $divB];
    }

    public function test_admin_melihat_agregat_semua_divisi(): void
    {
        $this->seedDivisionsAndMetrics();
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)
            ->getJson(route('performance.data'))
            ->assertOk();

        // Admin melihat kedua divisi: rata-rata NKO periode terbaru
        // (105 + 95) / 2 = 100.
        $this->assertEquals(100.0, (float) $response->json('kpi.average_nko'));

        $seriesNames = collect($response->json('series'))->pluck('name');
        $this->assertContains('Divisi A', $seriesNames);
        $this->assertContains('Divisi B', $seriesNames);
    }

    public function test_management_melihat_agregat_semua_divisi(): void
    {
        $this->seedDivisionsAndMetrics();
        $mgmt = User::factory()->withRole(Role::MANAGEMENT)->create();

        $response = $this->actingAs($mgmt)
            ->getJson(route('performance.data'))
            ->assertOk();

        $this->assertEquals(100.0, (float) $response->json('kpi.average_nko'));
    }

    public function test_staf_divisi_hanya_lihat_datanya_sendiri(): void
    {
        [$divA, $divB] = $this->seedDivisionsAndMetrics();

        $staffA = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $divA->id,
        ]);

        $response = $this->actingAs($staffA)
            ->getJson(route('performance.data'))
            ->assertOk();

        // Staf A hanya boleh melihat NKO divisi A: periode terbaru = 105.
        // TIDAK boleh terpengaruh 95 dari divisi B.
        $this->assertEquals(105.0, (float) $response->json('kpi.average_nko'));

        $seriesNames = collect($response->json('series'))->pluck('name');
        $this->assertContains('Divisi A', $seriesNames);
        $this->assertNotContains('Divisi B', $seriesNames);
    }

    public function test_staf_divisi_tidak_boleh_lihat_data_divisi_lain(): void
    {
        // Eksplisit memverifikasi bahwa data divisi B TIDAK bocor ke staf A.
        [$divA, $divB] = $this->seedDivisionsAndMetrics();

        $staffA = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $divA->id,
        ]);

        $response = $this->actingAs($staffA)
            ->getJson(route('performance.data'))
            ->assertOk();

        $this->assertStringNotContainsString('Divisi B', json_encode($response->json()));
    }

    public function test_user_tanpa_division_melihat_data_kosong(): void
    {
        // User non-privileged tanpa division_id => agregat kosong.
        $this->seedDivisionsAndMetrics();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create([
            'division_id' => null,
        ]);

        $response = $this->actingAs($editor)
            ->getJson(route('performance.data'))
            ->assertOk();

        // labels dan series harus array kosong, KPI nol.
        $this->assertSame([], $response->json('labels'));
        $this->assertSame([], $response->json('series'));
        $this->assertEquals(0.0, (float) $response->json('kpi.average_nko'));
    }
}
