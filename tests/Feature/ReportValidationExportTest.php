<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportValidationExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $management;

    private User $editor;

    private User $staffA;

    private User $staffB;

    private Division $divisionA;

    private Division $divisionB;

    protected function setUp(): void
    {
        parent::setUp();
        // Fitur Reports dinonaktifkan sementara 2026-09-15 - route /reports/* di-comment,
        // lihat routes/web.php dan progress.md. Aktifkan lagi bersama route-nya.
        $this->markTestSkipped('Reports feature dinonaktifkan sementara 2026-09-15 - lihat progress.md.');

        $this->seed(RolePermissionSeeder::class);

        $this->divisionA = Division::factory()->create();
        $this->divisionB = Division::factory()->create();

        $this->admin = User::factory()->withRole(Role::ADMIN)->create();
        $this->management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $this->editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => $this->divisionA->id]);
        $this->staffA = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divisionA->id]);
        $this->staffB = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divisionB->id]);
    }

    /** Staf hanya bisa export dataset divisinya sendiri. */
    public function test_staf_bisa_export_validasi_dataset_divisinya(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->divisionA->id,
            'user_id' => $this->staffA->id,
            'original_name' => 'laporan-q1.xlsx',
            'validation_report' => [
                'ok' => 1,
                'warning' => 1,
                'fatal' => 1,
                'issues' => [
                    ['row' => 2, 'severity' => 'WARNING', 'message' => 'Nilai outlier'],
                    ['row' => 3, 'severity' => 'FATAL', 'message' => 'Nilai bukan angka'],
                ],
            ],
        ]);

        $response = $this->actingAs($this->staffA)
            ->get(route('reports.validation-export', $dataset));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertStringContainsString(
            'laporan-q1-validation.xlsx',
            $response->headers->get('content-disposition'),
        );

        $tempPath = tempnam(sys_get_temp_dir(), 'test_val_').'.xlsx';
        file_put_contents($tempPath, $response->streamedContent());
        $spreadsheet = IOFactory::load($tempPath);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Baris', $sheet->getCell('A1')->getValue());
        $this->assertSame('Severity', $sheet->getCell('B1')->getValue());
        $this->assertSame('Pesan', $sheet->getCell('C1')->getValue());

        $this->assertSame(2, (int) $sheet->getCell('A2')->getValue());
        $this->assertSame('WARNING', $sheet->getCell('B2')->getValue());
        $this->assertSame('Nilai outlier', $sheet->getCell('C2')->getValue());

        $this->assertSame(3, (int) $sheet->getCell('A3')->getValue());
        $this->assertSame('FATAL', $sheet->getCell('B3')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($tempPath);
    }

    /** Staf ditolak 403 bila dataset bukan divisinya. */
    public function test_staf_ditolak_403_export_validasi_divisi_lain(): void
    {
        $dataset = Dataset::factory()->create(['division_id' => $this->divisionA->id]);

        $this->actingAs($this->staffB)
            ->get(route('reports.validation-export', $dataset))
            ->assertForbidden();
    }

    /** Admin, management, editor bisa export validasi dataset divisi mana pun. */
    public function test_admin_management_editor_bisa_export_validasi_semua_divisi(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->divisionA->id,
            'validation_report' => ['ok' => 0, 'warning' => 0, 'fatal' => 0, 'issues' => []],
        ]);

        foreach ([$this->admin, $this->management, $this->editor] as $actor) {
            $this->actingAs($actor)
                ->get(route('reports.validation-export', $dataset))
                ->assertOk();
        }
    }

    /** Dataset tanpa issues tetap menghasilkan file valid (header-only). */
    public function test_dataset_tanpa_issues_menghasilkan_header_only(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->divisionA->id,
            'validation_report' => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('reports.validation-export', $dataset));

        $response->assertOk();

        $tempPath = tempnam(sys_get_temp_dir(), 'test_val_empty_').'.xlsx';
        file_put_contents($tempPath, $response->streamedContent());
        $spreadsheet = IOFactory::load($tempPath);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Baris', $sheet->getCell('A1')->getValue());
        $this->assertNull($sheet->getCell('A2')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($tempPath);
    }

    /** Export yang truncated memuat baris catatan, bukan dikira lengkap. */
    public function test_export_terpotong_memuat_baris_catatan(): void
    {
        $issues = [];
        foreach (range(2, 51) as $row) {
            $issues[] = ['row' => $row, 'severity' => 'FATAL', 'message' => 'Nilai bukan angka'];
        }
        $dataset = Dataset::factory()->create([
            'division_id' => $this->divisionA->id,
            'validation_report' => [
                'ok' => 0,
                'warning' => 0,
                'fatal' => 60,
                'issues' => $issues,
                'issues_truncated' => true,
            ],
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('reports.validation-export', $dataset));

        $response->assertOk();

        $tempPath = tempnam(sys_get_temp_dir(), 'test_val_trunc_').'.xlsx';
        file_put_contents($tempPath, $response->streamedContent());
        $spreadsheet = IOFactory::load($tempPath);
        $sheet = $spreadsheet->getActiveSheet();

        // 50 baris detail + 1 baris kosong + 1 baris catatan (baris 53).
        $this->assertStringContainsString('hanya 50 masalah pertama', (string) $sheet->getCell('A53')->getValue());
        $this->assertStringContainsString('60', (string) $sheet->getCell('A53')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($tempPath);
    }
}
