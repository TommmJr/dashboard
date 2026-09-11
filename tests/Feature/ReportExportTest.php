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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $management;

    private User $editor;

    private User $staffA;

    private User $staffB;

    private Division $divisionA;

    private Division $divisionB;

    private Dataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->divisionA = Division::factory()->create();
        $this->divisionB = Division::factory()->create();

        $this->admin = User::factory()->withRole(Role::ADMIN)->create();
        $this->management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $this->editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => $this->divisionA->id]);
        $this->staffA = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divisionA->id]);
        $this->staffB = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divisionB->id]);

        $this->dataset = Dataset::factory()->create([
            'division_id' => $this->divisionA->id,
            'user_id' => $this->staffA->id,
            'original_name' => 'laporan-q1.csv',
        ]);
    }

    // -------------------------------------------------------------------------
    // Staf bisa export dataset divisinya sendiri, isi baris & kolom benar
    // -------------------------------------------------------------------------

    public function test_staf_bisa_export_dataset_divisinya_sendiri(): void
    {
        PerformanceMetric::factory()->create([
            'dataset_id' => $this->dataset->id,
            'division_id' => $this->divisionA->id,
            'metric_name' => 'Metrik A',
            'value' => 99.50,
            'period' => '2026-01-15',
        ]);

        $response = $this->actingAs($this->staffA)
            ->get(route('datasets.export', $this->dataset));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Nama file sesuai: original_name tanpa ekstensi + -export.xlsx
        $this->assertStringContainsString(
            'laporan-q1-export.xlsx',
            $response->headers->get('content-disposition'),
        );

        // Parse the xlsx content and verify rows + columns
        $tempPath = tempnam(sys_get_temp_dir(), 'test_export_').'.xlsx';
        file_put_contents($tempPath, $response->streamedContent());

        $spreadsheet = IOFactory::load($tempPath);
        $sheet = $spreadsheet->getActiveSheet();

        // Header row
        $this->assertSame('No', $sheet->getCell('A1')->getValue());
        $this->assertSame('Metric Name', $sheet->getCell('B1')->getValue());
        $this->assertSame('Value', $sheet->getCell('C1')->getValue());
        $this->assertSame('Period', $sheet->getCell('D1')->getValue());

        // Data row
        $this->assertSame(1, (int) $sheet->getCell('A2')->getValue());
        $this->assertSame('Metrik A', $sheet->getCell('B2')->getValue());
        $this->assertSame(99.5, (float) $sheet->getCell('C2')->getValue());
        $this->assertSame('2026-01-15', $sheet->getCell('D2')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($tempPath);
    }

    // -------------------------------------------------------------------------
    // Staf ditolak 403 export dataset divisi lain
    // -------------------------------------------------------------------------

    public function test_staf_ditolak_403_export_dataset_divisi_lain(): void
    {
        $this->actingAs($this->staffB)
            ->get(route('datasets.export', $this->dataset))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Admin / Management / Editor Dashboard bisa export dataset divisi mana pun
    // -------------------------------------------------------------------------

    public function test_admin_management_editor_bisa_export_semua_divisi(): void
    {
        foreach ([$this->admin, $this->management, $this->editor] as $actor) {
            $this->actingAs($actor)
                ->get(route('datasets.export', $this->dataset))
                ->assertOk();
        }
    }

    // -------------------------------------------------------------------------
    // Dataset 0 metric → file valid berisi header saja
    // -------------------------------------------------------------------------

    public function test_dataset_tanpa_metric_menghasilkan_file_header_only(): void
    {
        // dataset has no metrics
        $response = $this->actingAs($this->admin)
            ->get(route('datasets.export', $this->dataset));

        $response->assertOk();

        $tempPath = tempnam(sys_get_temp_dir(), 'test_empty_').'.xlsx';
        file_put_contents($tempPath, $response->streamedContent());

        $spreadsheet = IOFactory::load($tempPath);
        $sheet = $spreadsheet->getActiveSheet();

        // Header still present
        $this->assertSame('No', $sheet->getCell('A1')->getValue());
        $this->assertSame('Metric Name', $sheet->getCell('B1')->getValue());

        // No data row
        $this->assertNull($sheet->getCell('A2')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($tempPath);
    }

    // -------------------------------------------------------------------------
    // Tidak ada entri audit log akibat export
    // -------------------------------------------------------------------------

    public function test_export_tidak_mencatat_audit_log(): void
    {
        $countBefore = AuditLog::count();

        $this->actingAs($this->admin)
            ->get(route('datasets.export', $this->dataset));

        $this->assertSame($countBefore, AuditLog::count());
    }
}
