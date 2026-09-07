<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Memverifikasi scoping data performance per divisi (audit #3).
 *
 * - Admin & management: boleh lihat agregat semua divisi.
 * - Staf divisi & editor: hanya divisinya sendiri.
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

        // Data di Divisi A
        PerformanceMetric::create([
            'metric_name' => 'Pendapatan A',
            'value' => 100,
            'period' => '2026-01-15',
            'division_id' => $divA->id,
        ]);
        PerformanceMetric::create([
            'metric_name' => 'Pendapatan A2',
            'value' => 200,
            'period' => '2026-02-15',
            'division_id' => $divA->id,
        ]);

        // Data di Divisi B
        PerformanceMetric::create([
            'metric_name' => 'Pendapatan B',
            'value' => 999,
            'period' => '2026-01-15',
            'division_id' => $divB->id,
        ]);

        return [$divA, $divB];
    }

    public function test_admin_melihat_agregat_semua_divisi(): void
    {
        [$divA, $divB] = $this->seedDivisionsAndMetrics();
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)
            ->getJson(route('performance.data'))
            ->assertOk();

        $sum = (float) $response->json('kpi.total_value');
        // Admin melihat 100+200+999 = 1299
        $this->assertEquals(1299.0, $sum);
    }

    public function test_management_melihat_agregat_semua_divisi(): void
    {
        [$divA, $divB] = $this->seedDivisionsAndMetrics();
        $mgmt = User::factory()->withRole(Role::MANAGEMENT)->create();

        $response = $this->actingAs($mgmt)
            ->getJson(route('performance.data'))
            ->assertOk();

        $sum = (float) $response->json('kpi.total_value');
        $this->assertEquals(1299.0, $sum);
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

        $sum = (float) $response->json('kpi.total_value');

        // Staf A hanya boleh melihat data divisi A: 100 + 200 = 300
        // TIDAK boleh melihat 999 dari divisi B.
        $this->assertEquals(300.0, $sum);
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

        // Response JSON tidak mengandung label metric divisi B.
        $this->assertStringNotContainsString('Pendapatan B', json_encode($response->json()));
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
        $this->assertEquals(0.0, (float) $response->json('kpi.total_value'));
    }
}
