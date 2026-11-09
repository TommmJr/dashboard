<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private Division $pengusahaan;

    private Division $aset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->pengusahaan = Division::create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);

        $this->aset = Division::create([
            'name' => 'Managemen Aset',
            'slug' => 'managemen-aset',
        ]);
    }

    public function test_guest_diarahkan_ke_login_saat_mengakses_kpi_detail(): void
    {
        $this->get('/dashboard/kpi-detail')
            ->assertRedirect(route('login'));
    }

    public function test_staf_perencanaan_pengusahaan_dapat_mengakses_halaman_kpi_detail_dan_melihat_semua_baris_tanpa_potongan_10(): void
    {
        $role = Role::where('slug', Role::STAFF_DIVISI)->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $role->id,
            'division_id' => $this->pengusahaan->id,
        ]);

        $dataset = Dataset::factory()->create([
            'division_id' => $this->pengusahaan->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $unit = OrgUnit::create(['name' => 'TJBT']);

        for ($i = 1; $i <= 12; $i++) {
            $indicator = Indicator::create([
                'org_unit_id' => $unit->id,
                'name' => sprintf('Indikator Uji %02d', $i),
                'satuan' => '%',
                'is_group' => false,
                'pencapaian_formula_kind' => 'polarity_ratio',
            ]);

            PerformanceObservation::create([
                'dataset_id' => $dataset->id,
                'indicator_id' => $indicator->id,
                'org_unit_id' => $unit->id,
                'division_id' => $this->pengusahaan->id,
                'period' => '2026-08-31',
                'target_type' => PerformanceObservation::TYPE_NUMERIC,
                'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
                'target_value' => 100.0,
                'realisasi_value' => 105.0,
                'pencapaian' => 1.05,
            ]);
        }

        $response = $this->actingAs($user)->get(route('dashboard.kpi-detail'));

        $response->assertOk()
            ->assertSee('Rincian Kinerja KPI')
            ->assertSee('Target dan Realisasi per Indikator')
            ->assertSee('Detail Metrik KPI')
            ->assertSee('Indikator Uji 01')
            ->assertSee('Indikator Uji 12')
            ->assertSee('Menampilkan 12 dari 12 baris.')
            ->assertSee('id="kinerja-detail-unit"', false)
            ->assertDontSee('Lihat semua (12 baris)');
    }

    public function test_staf_divisi_non_pengusahaan_ditolak_403_saat_mengakses_kpi_detail(): void
    {
        $role = Role::where('slug', Role::STAFF_DIVISI)->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $role->id,
            'division_id' => $this->aset->id,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.kpi-detail'))
            ->assertForbidden();
    }

    public function test_filter_tanggal_rusak_atau_terbalik_diabaikan_dengan_aman(): void
    {
        $role = Role::where('slug', Role::MANAGEMENT)->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $role->id,
            'division_id' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.kpi-detail', [
                'start_date' => '2026-12-31',
                'end_date' => '2026-01-01',
            ]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('dashboard.kpi-detail', [
                'start_date' => 'bukan-tanggal',
                'end_date' => '2026-99-99',
            ]))
            ->assertOk();
    }
}
