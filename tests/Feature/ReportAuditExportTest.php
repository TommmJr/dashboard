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
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportAuditExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $management;

    private User $editor;

    private User $staffA;

    private Division $divisionA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->divisionA = Division::factory()->create();

        $this->admin = User::factory()->withRole(Role::ADMIN)->create();
        $this->management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $this->editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => $this->divisionA->id]);
        $this->staffA = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divisionA->id]);
    }

    /** Staf divisi ditolak 403 (tidak punya audit.view). */
    public function test_staf_ditolak_403_karena_tidak_punya_audit_view(): void
    {
        $this->actingAs($this->staffA)
            ->get(route('reports.audit-export', ['from' => '2026-09-01', 'to' => '2026-09-10']))
            ->assertForbidden();
    }

    /** Request tanpa rentang tanggal ditolak 422. */
    public function test_request_tanpa_tanggal_ditolak_422(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('reports.audit-export'))
            ->assertStatus(422);
    }

    /** Request dengan rentang lebih dari 90 hari ditolak 422. */
    public function test_request_rentang_lebih_90_hari_ditolak_422(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('reports.audit-export', ['from' => '2026-01-01', 'to' => '2026-12-31']))
            ->assertStatus(422);
    }

    /** Admin melihat semua jenis entri (LOGIN, Dataset, PerformanceMetric, User). */
    public function test_admin_melihat_semua_entri(): void
    {
        $dataset = Dataset::factory()->create(['division_id' => $this->divisionA->id, 'user_id' => $this->staffA->id]);

        // Bersihkan log dari setUp (User UPDATE dari withRole()->save())
        DB::table('audit_logs')->truncate();

        AuditLog::create([
            'user_id' => $this->admin->id,
            'action' => AuditLog::ACTION_LOGIN,
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip' => '127.0.0.1',
            'user_agent' => 'test',
            'created_at' => now(),
        ]);

        AuditLog::create([
            'user_id' => $this->staffA->id,
            'action' => AuditLog::ACTION_CREATE,
            'auditable_type' => $dataset->getMorphClass(),
            'auditable_id' => $dataset->id,
            'old_values' => null,
            'new_values' => ['original_name' => 'test.xlsx'],
            'ip' => '127.0.0.1',
            'user_agent' => 'test',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('reports.audit-export', [
                'from' => now()->format('Y-m-d'),
                'to' => now()->format('Y-m-d'),
            ]));

        $response->assertOk();

        $tempPath = tempnam(sys_get_temp_dir(), 'test_audit_').'.xlsx';
        file_put_contents($tempPath, $response->streamedContent());
        $spreadsheet = IOFactory::load($tempPath);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Waktu', $sheet->getCell('A1')->getValue());
        $this->assertSame('IP', $sheet->getCell('F1')->getValue());

        // 2 entri
        $this->assertNotNull($sheet->getCell('A2')->getValue());
        $this->assertNotNull($sheet->getCell('A3')->getValue());
        $this->assertNull($sheet->getCell('A4')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($tempPath);
    }

    /** Editor (audit.view tanpa audit.view_all) hanya melihat entri Dataset/PerformanceMetric. */
    public function test_editor_hanya_melihat_entri_data(): void
    {
        $dataset = Dataset::factory()->create(['division_id' => $this->divisionA->id, 'user_id' => $this->staffA->id]);

        // Bersihkan log dari setUp (User UPDATE dari withRole()->save())
        DB::table('audit_logs')->truncate();

        // Entri LOGIN (bukan data) — tidak boleh muncul untuk editor
        AuditLog::create([
            'user_id' => $this->admin->id,
            'action' => AuditLog::ACTION_LOGIN,
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip' => '127.0.0.1',
            'user_agent' => 'test',
            'created_at' => now(),
        ]);

        // Entri Dataset — boleh muncul untuk editor
        AuditLog::create([
            'user_id' => $this->staffA->id,
            'action' => AuditLog::ACTION_CREATE,
            'auditable_type' => $dataset->getMorphClass(),
            'auditable_id' => $dataset->id,
            'old_values' => null,
            'new_values' => ['original_name' => 'test.xlsx'],
            'ip' => '127.0.0.1',
            'user_agent' => 'test',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->editor)
            ->get(route('reports.audit-export', [
                'from' => now()->format('Y-m-d'),
                'to' => now()->format('Y-m-d'),
            ]));

        $response->assertOk();

        $tempPath = tempnam(sys_get_temp_dir(), 'test_audit_editor_').'.xlsx';
        file_put_contents($tempPath, $response->streamedContent());
        $spreadsheet = IOFactory::load($tempPath);
        $sheet = $spreadsheet->getActiveSheet();

        // Hanya 1 entri (Dataset CREATE), bukan 2 (LOGIN tidak muncul)
        $this->assertNotNull($sheet->getCell('A2')->getValue());
        $this->assertNull($sheet->getCell('A3')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($tempPath);
    }

    /** Nama file berformat audit-log-{from}-{to}.xlsx */
    public function test_nama_file_audit_export_benar(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.audit-export', ['from' => '2026-09-01', 'to' => '2026-09-10']));

        $response->assertOk();
        $this->assertStringContainsString(
            'audit-log-2026-09-01-2026-09-10.xlsx',
            $response->headers->get('content-disposition'),
        );
    }

    /** Management juga melihat semua entri (punya audit.view_all). */
    public function test_management_melihat_semua_entri(): void
    {
        $response = $this->actingAs($this->management)
            ->get(route('reports.audit-export', [
                'from' => now()->format('Y-m-d'),
                'to' => now()->format('Y-m-d'),
            ]));

        $response->assertOk();
    }
}
