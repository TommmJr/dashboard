<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Memverifikasi otorisasi dan scoping data widget pada DashboardController (recent-audit dan nko-ranking).
 *
 * Sesuai prinsip backend-first authorization dan User Roles matriks baris 70:
 * - recent-audit: Admin dan Management (audit.view_all) melihat seluruh audit log termasuk login.
 *   Editor Dashboard (audit.view saja) hanya melihat perubahan data (Dataset & PerformanceMetric).
 * - nko-ranking: Admin, Management, dan Editor Dashboard melihat ranking lintas divisi.
 *   Staf divisi hanya melihat peringkat unit dari divisinya sendiri.
 */
class DashboardWidgetScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_recent_audit_widget_shows_all_entries_to_admin_and_management(): void
    {
        $division = Division::factory()->create(['name' => 'Divisi Renus']);
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $dataset = Dataset::factory()->create([
            'status' => Dataset::STATUS_READY,
            'division_id' => $division->id,
        ]);

        AuditLog::record(
            AuditLog::ACTION_UPDATE,
            $dataset,
            ['status' => Dataset::STATUS_READY],
            ['status' => Dataset::STATUS_ARCHIVED],
        );
        AuditLog::record(AuditLog::ACTION_LOGIN, null, null, null, $dataset->user_id);

        foreach ([$admin, $management] as $user) {
            config(['dashboard_layouts.layouts.'.$user->role->slug => ['recent-audit']]);

            $response = $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk();

            $response->assertSee('Audit Terbaru');
            $response->assertSee('Dataset #'.$dataset->id);
            $response->assertSee('Aktivitas akun');
            $response->assertSee('Masuk');
        }
    }

    public function test_recent_audit_widget_restricts_editor_to_dataset_and_metric_changes(): void
    {
        config(['dashboard_layouts.layouts.editor_dashboard' => ['recent-audit']]);

        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $division = Division::factory()->create();
        $dataset = Dataset::factory()->create([
            'status' => Dataset::STATUS_READY,
            'division_id' => $division->id,
        ]);

        AuditLog::record(
            AuditLog::ACTION_UPDATE,
            $dataset,
            ['status' => Dataset::STATUS_READY],
            ['status' => Dataset::STATUS_ARCHIVED],
        );
        AuditLog::record(AuditLog::ACTION_LOGIN, null, null, null, $dataset->user_id);

        $response = $this->actingAs($editor)
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertSee('Audit Terbaru');
        $response->assertSee('Dataset #'.$dataset->id);
        $response->assertDontSee('Aktivitas akun');
        $response->assertDontSee('Masuk');
    }

    public function test_admin_summary_uses_operational_counts_and_failed_dataset_details(): void
    {
        $division = Division::factory()->create(['name' => 'Divisi Operasional']);
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $ready = Dataset::factory()->create([
            'status' => Dataset::STATUS_READY,
            'division_id' => $division->id,
        ]);
        $failed = Dataset::factory()->create([
            'status' => Dataset::STATUS_FAILED,
            'division_id' => $division->id,
            'original_name' => 'dataset-gagal-ujian.xlsx',
        ]);

        AuditLog::record(
            AuditLog::ACTION_UPDATE,
            $failed,
            ['status' => Dataset::STATUS_READY],
            ['status' => Dataset::STATUS_FAILED],
        );

        $response = $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertSee('Total Dataset')
            ->assertSee('Aktivitas Audit Hari Ini')
            ->assertSee('Total Pengguna')
            ->assertSee('Aksi Cepat')
            ->assertSee('dataset-gagal-ujian.xlsx')
            ->assertSee($division->name)
            ->assertSee('1 dataset gagal diproses dan memerlukan peninjauan segera.');

        $this->assertSame(Dataset::STATUS_READY, $ready->fresh()->status->value);
    }

    public function test_nko_ranking_widget_shows_all_units_to_privileged_roles(): void
    {
        $divA = Division::factory()->create(['name' => 'Divisi Pengusahaan']);
        $divB = Division::factory()->create(['name' => 'Divisi Lain']);

        $datasetA = Dataset::factory()->create(['status' => Dataset::STATUS_READY, 'division_id' => $divA->id]);
        $datasetB = Dataset::factory()->create(['status' => Dataset::STATUS_READY, 'division_id' => $divB->id]);

        PerformanceMetric::factory()->create([
            'dataset_id' => $datasetA->id,
            'division_id' => $divA->id,
            'metric_name' => 'UNIT_ALPHA | NKO',
            'value' => 102.50,
            'period' => '2026-08-01',
        ]);

        PerformanceMetric::factory()->create([
            'dataset_id' => $datasetB->id,
            'division_id' => $divB->id,
            'metric_name' => 'UNIT_BETA | NKO',
            'value' => 98.40,
            'period' => '2026-08-01',
        ]);

        foreach ([Role::ADMIN, Role::MANAGEMENT, Role::EDITOR_DASHBOARD] as $roleSlug) {
            config(['dashboard_layouts.layouts.'.$roleSlug => ['nko-ranking']]);
            $user = User::factory()->withRole($roleSlug)->create(['division_id' => $divA->id]);

            $response = $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk();

            $response->assertSee('UNIT_ALPHA');
            $response->assertSee('UNIT_BETA');
        }
    }

    public function test_nko_ranking_widget_scopes_to_own_division_for_staff(): void
    {
        config(['dashboard_layouts.layouts.staff_divisi' => ['nko-ranking']]);

        $divA = Division::factory()->create(['name' => 'Divisi Pengusahaan']);
        $divB = Division::factory()->create(['name' => 'Divisi Aset']);

        $datasetA = Dataset::factory()->create(['status' => Dataset::STATUS_READY, 'division_id' => $divA->id]);
        $datasetB = Dataset::factory()->create(['status' => Dataset::STATUS_READY, 'division_id' => $divB->id]);

        PerformanceMetric::factory()->create([
            'dataset_id' => $datasetA->id,
            'division_id' => $divA->id,
            'metric_name' => 'UNIT_ALPHA | NKO',
            'value' => 102.50,
            'period' => '2026-08-01',
        ]);

        PerformanceMetric::factory()->create([
            'dataset_id' => $datasetB->id,
            'division_id' => $divB->id,
            'metric_name' => 'UNIT_BETA | NKO',
            'value' => 98.40,
            'period' => '2026-08-01',
        ]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $divA->id]);

        $response = $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertSee('UNIT_ALPHA');
        $response->assertDontSee('UNIT_BETA');
    }

    public function test_nko_ranking_widget_returns_empty_collection_for_division_without_nko(): void
    {
        config(['dashboard_layouts.layouts.staff_divisi' => ['nko-ranking']]);

        $divA = Division::factory()->create(['name' => 'Divisi Pengusahaan']);
        $divEmpty = Division::factory()->create(['name' => 'Divisi Kosong']);

        $datasetA = Dataset::factory()->create(['status' => Dataset::STATUS_READY, 'division_id' => $divA->id]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $datasetA->id,
            'division_id' => $divA->id,
            'metric_name' => 'UNIT_ALPHA | NKO',
            'value' => 102.50,
            'period' => '2026-08-01',
        ]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $divEmpty->id]);

        $response = $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertDontSee('UNIT_ALPHA');
        $response->assertSee('Belum ada data NKO');
    }
}
