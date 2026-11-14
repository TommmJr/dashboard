<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceMetric;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test fitur Edit/Update PerformanceMetric (jalur koreksi data MVP).
 *
 * Aturan bisnis yang dikunci:
 * - Staf divisi: hanya boleh edit metric divisinya sendiri.
 * - Admin: boleh edit metric divisi manapun.
 * - Editor & Management: read-only, tidak punya dataset.update → 403.
 * - division_id dan dataset_id tidak boleh dibypass lewat request.
 * - Audit log UPDATE tercatat otomatis via trait Auditable.
 */
class PerformanceMetricEditTest extends TestCase
{
    use RefreshDatabase;

    private Division $divisionA;

    private Division $divisionB;

    private User $staffA;

    private User $staffB;

    private User $admin;

    private User $editor;

    private User $management;

    private Dataset $datasetA;

    private PerformanceMetric $metricA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->divisionA = Division::factory()->create();
        $this->divisionB = Division::factory()->create();

        $this->staffA = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divisionA->id]);
        $this->staffB = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divisionB->id]);
        $this->admin = User::factory()->withRole(Role::ADMIN)->create();
        $this->editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => $this->divisionA->id]);
        $this->management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->datasetA = Dataset::factory()->create([
            'division_id' => $this->divisionA->id,
            'user_id' => $this->staffA->id,
        ]);

        $this->metricA = PerformanceMetric::factory()->create([
            'dataset_id' => $this->datasetA->id,
            'division_id' => $this->divisionA->id,
            'metric_name' => 'Metric Awal',
            'value' => '100.00',
            'period' => '2026-01-01',
        ]);
    }

    /** @test */
    public function test_staf_bisa_edit_metric_divisinya(): void
    {
        $this->actingAs($this->staffA)
            ->get(route('performance_metrics.edit', $this->metricA))
            ->assertOk()
            ->assertSee('Metric Awal');
    }

    /** @test */
    public function test_staf_bisa_update_metric_divisinya(): void
    {
        $this->actingAs($this->staffA)
            ->patch(route('performance_metrics.update', $this->metricA), [
                'metric_name' => 'Metric Baru',
                'value' => '200.50',
                'period' => '2026-02-01',
            ])
            ->assertRedirect(route('datasets.show', $this->datasetA->id));

        $this->assertDatabaseHas('performance_metrics', [
            'id' => $this->metricA->id,
            'metric_name' => 'Metric Baru',
            'period' => '2026-02-01',
        ]);
    }

    /** @test */
    public function test_staf_divisi_lain_403_edit(): void
    {
        $this->actingAs($this->staffB)
            ->get(route('performance_metrics.edit', $this->metricA))
            ->assertForbidden();
    }

    /** @test */
    public function test_staf_divisi_lain_403_update(): void
    {
        $this->actingAs($this->staffB)
            ->patch(route('performance_metrics.update', $this->metricA), [
                'metric_name' => 'Metric Baru',
                'value' => '200.50',
                'period' => '2026-02-01',
            ])
            ->assertForbidden();
    }

    /** @test */
    public function test_editor_403_edit(): void
    {
        $this->actingAs($this->editor)
            ->get(route('performance_metrics.edit', $this->metricA))
            ->assertForbidden();
    }

    /** @test */
    public function test_management_403_edit(): void
    {
        $this->actingAs($this->management)
            ->get(route('performance_metrics.edit', $this->metricA))
            ->assertForbidden();
    }

    /** @test */
    public function test_unauthenticated_redirect(): void
    {
        $this->get(route('performance_metrics.edit', $this->metricA))
            ->assertRedirect(route('login'));
    }

    /** @test */
    public function test_validasi_gagal_metric_name_kosong(): void
    {
        $this->actingAs($this->staffA)
            ->patch(route('performance_metrics.update', $this->metricA), [
                'metric_name' => '',
                'value' => '100',
                'period' => '2026-01-01',
            ])
            ->assertSessionHasErrors('metric_name');
    }

    /** @test */
    public function test_validasi_gagal_value_non_numeric(): void
    {
        $this->actingAs($this->staffA)
            ->patch(route('performance_metrics.update', $this->metricA), [
                'metric_name' => 'Metric',
                'value' => 'abc',
                'period' => '2026-01-01',
            ])
            ->assertSessionHasErrors('value');
    }

    /** @test */
    public function test_validasi_gagal_period_format_salah(): void
    {
        $this->actingAs($this->staffA)
            ->patch(route('performance_metrics.update', $this->metricA), [
                'metric_name' => 'Metric',
                'value' => '100',
                'period' => '31/01/2026',
            ])
            ->assertSessionHasErrors('period');
    }

    /** @test */
    public function test_perubahan_tersimpan_benar(): void
    {
        $this->actingAs($this->staffA)
            ->patch(route('performance_metrics.update', $this->metricA), [
                'metric_name' => 'Nama Baru',
                'value' => '999.99',
                'period' => '2026-06-15',
            ]);

        $fresh = $this->metricA->fresh();
        $this->assertSame('Nama Baru', $fresh->metric_name);
        $this->assertSame('999.99', $fresh->value);
        $this->assertSame('2026-06-15', $fresh->period->format('Y-m-d'));
    }

    /** @test */
    public function test_audit_log_update_tercatat(): void
    {
        $this->actingAs($this->staffA)
            ->patch(route('performance_metrics.update', $this->metricA), [
                'metric_name' => 'Audit Test',
                'value' => '123',
                'period' => '2026-03-01',
            ]);

        $log = AuditLog::where('action', AuditLog::ACTION_UPDATE)
            ->where('auditable_type', (new PerformanceMetric)->getMorphClass())
            ->where('auditable_id', $this->metricA->id)
            ->first();

        $this->assertNotNull($log, 'AuditLog UPDATE tidak ditemukan');
        $this->assertSame('Metric Awal', $log->old_values['metric_name']);
        $this->assertSame('Audit Test', $log->new_values['metric_name']);
    }

    /** @test */
    public function test_division_id_tidak_bisa_dibypass(): void
    {
        $otherDivision = Division::factory()->create();

        $this->actingAs($this->staffA)
            ->patch(route('performance_metrics.update', $this->metricA), [
                'metric_name' => 'Metric',
                'value' => '100',
                'period' => '2026-01-01',
                'division_id' => $otherDivision->id,
            ]);

        $this->assertSame($this->divisionA->id, $this->metricA->fresh()->division_id);
    }

    /** @test */
    public function test_dataset_id_tidak_bisa_dibypass(): void
    {
        $otherDataset = Dataset::factory()->create(['division_id' => $this->divisionA->id]);

        $this->actingAs($this->staffA)
            ->patch(route('performance_metrics.update', $this->metricA), [
                'metric_name' => 'Metric',
                'value' => '100',
                'period' => '2026-01-01',
                'dataset_id' => $otherDataset->id,
            ]);

        $this->assertSame($this->datasetA->id, $this->metricA->fresh()->dataset_id);
    }

    /** @test */
    public function test_admin_bisa_edit_metric_divisi_manapun(): void
    {
        $this->actingAs($this->admin)
            ->get(route('performance_metrics.edit', $this->metricA))
            ->assertOk();

        $this->actingAs($this->admin)
            ->patch(route('performance_metrics.update', $this->metricA), [
                'metric_name' => 'Admin Edit',
                'value' => '50',
                'period' => '2026-04-01',
            ])
            ->assertRedirect(route('datasets.show', $this->datasetA->id));

        $this->assertSame('Admin Edit', $this->metricA->fresh()->metric_name);
    }

    /** @test */
    public function test_validasi_gagal_duplikat_metric_name_period_dalam_dataset(): void
    {
        // Buat metric lain dalam dataset yang sama dengan nama+period berbeda
        PerformanceMetric::factory()->create([
            'dataset_id' => $this->datasetA->id,
            'division_id' => $this->divisionA->id,
            'metric_name' => 'Metric Duplikat',
            'period' => '2026-05-01',
        ]);

        // Coba ubah metricA agar nama+period sama dengan metric yang baru dibuat
        $this->actingAs($this->staffA)
            ->patch(route('performance_metrics.update', $this->metricA), [
                'metric_name' => 'Metric Duplikat',
                'value' => '100',
                'period' => '2026-05-01',
            ])
            ->assertSessionHasErrors('metric_name');
    }

    public function test_dataset_dengan_performance_observations_menyembunyikan_tombol_edit_dan_menolak_edit_serta_update(): void
    {
        $unit = OrgUnit::create([
            'code' => 'TJBT',
            'name' => 'TJBT',
            'level' => 'unit',
        ]);
        $indicator = Indicator::create([
            'org_unit_id' => $unit->id,
            'code' => 'IND-01',
            'name' => 'Indikator Uji',
            'unit' => '%',
            'is_group' => false,
        ]);

        PerformanceObservation::create([
            'dataset_id' => $this->datasetA->id,
            'division_id' => $this->divisionA->id,
            'org_unit_id' => $unit->id,
            'indicator_id' => $indicator->id,
            'period' => '2026-01-31',
            'target_value' => 100,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_value' => 95,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'pencapaian' => 0.95,
        ]);

        // 1. Halaman detail dataset menyembunyikan tombol Edit metric
        $this->actingAs($this->staffA)
            ->get(route('datasets.show', $this->datasetA))
            ->assertOk()
            ->assertDontSee(route('performance_metrics.edit', $this->metricA), false);

        // 2. GET edit dan PATCH update ditolak 403 dengan pesan koreksi via upload ulang
        $this->actingAs($this->staffA)
            ->get(route('performance_metrics.edit', $this->metricA))
            ->assertForbidden()
            ->assertSee('Koreksi data KPI dilakukan dengan upload ulang laporan.');

        $this->actingAs($this->admin)
            ->patch(route('performance_metrics.update', $this->metricA), [
                'metric_name' => 'Coba Ubah',
                'value' => '999',
                'period' => '2026-01-31',
            ])
            ->assertForbidden()
            ->assertSee('Koreksi data KPI dilakukan dengan upload ulang laporan.');

        $this->assertSame('Metric Awal', $this->metricA->fresh()->metric_name);
    }

    public function test_detail_dataset_merender_tabel_metrik_read_only_dan_tanpa_tombol_edit(): void
    {
        $response = $this->actingAs($this->staffA)
            ->get(route('datasets.show', $this->datasetA))
            ->assertOk()
            ->assertSee('Rincian Metrik Data')
            ->assertSee('Detektor, Bukan Generator')
            ->assertSee('Read-Only')
            ->assertSee('Metric Awal')
            ->assertDontSee(route('performance_metrics.edit', $this->metricA), false)
            ->assertDontSee('>Edit<', false)
            ->assertDontSee('id="asset-sync-reconciliation-inspector"', false)
            ->assertDontSee('Inspeksi & Analisis Rekonsiliasi Data Sync Aset');

        $this->assertFalse($response->viewData('isAssetDataset'));
        $this->assertNull($response->viewData('assetAnalysis'));
    }
}
