<?php

namespace Tests\Feature;

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

class ReportDatasetSummaryExportTest extends TestCase
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

        $this->divisionA = Division::factory()->create(['name' => 'Divisi A']);
        $this->divisionB = Division::factory()->create(['name' => 'Divisi B']);

        $this->admin = User::factory()->withRole(Role::ADMIN)->create();
        $this->management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $this->editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => $this->divisionA->id]);
        $this->staffA = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divisionA->id]);
        $this->staffB = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divisionB->id]);
    }

    /** Scoping staf: hanya muncul dataset divisinya sendiri. */
    public function test_staf_hanya_melihat_dataset_divisinya_di_export(): void
    {
        Dataset::factory()->create(['division_id' => $this->divisionA->id, 'user_id' => $this->staffA->id, 'original_name' => 'file-a.xlsx']);
        Dataset::factory()->create(['division_id' => $this->divisionB->id, 'user_id' => $this->staffB->id, 'original_name' => 'file-b.xlsx']);

        $response = $this->actingAs($this->staffA)
            ->get(route('reports.dataset-summary'));

        $response->assertOk();

        $tempPath = tempnam(sys_get_temp_dir(), 'test_summary_').'.xlsx';
        file_put_contents($tempPath, $response->streamedContent());
        $spreadsheet = IOFactory::load($tempPath);
        $sheet = $spreadsheet->getActiveSheet();

        // Header
        $this->assertSame('Nama File', $sheet->getCell('A1')->getValue());
        $this->assertSame('Divisi', $sheet->getCell('B1')->getValue());
        $this->assertSame('Terakhir Diperbarui', $sheet->getCell('G1')->getValue());

        // Hanya 1 baris data (dataset divisi A saja)
        $this->assertSame('file-a.xlsx', $sheet->getCell('A2')->getValue());
        $this->assertNull($sheet->getCell('A3')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($tempPath);
    }

    /** Admin/management/editor melihat semua dataset lintas divisi. */
    public function test_admin_management_editor_melihat_semua_divisi(): void
    {
        Dataset::factory()->create(['division_id' => $this->divisionA->id, 'user_id' => $this->staffA->id]);
        Dataset::factory()->create(['division_id' => $this->divisionB->id, 'user_id' => $this->staffB->id]);

        foreach ([$this->admin, $this->management, $this->editor] as $actor) {
            $response = $this->actingAs($actor)
                ->get(route('reports.dataset-summary'));

            $response->assertOk();

            $tempPath = tempnam(sys_get_temp_dir(), 'test_summary_all_').'.xlsx';
            file_put_contents($tempPath, $response->streamedContent());
            $spreadsheet = IOFactory::load($tempPath);
            $sheet = $spreadsheet->getActiveSheet();

            // Harus ada 2 baris data
            $this->assertNotNull($sheet->getCell('A2')->getValue());
            $this->assertNotNull($sheet->getCell('A3')->getValue());
            $this->assertNull($sheet->getCell('A4')->getValue());

            $spreadsheet->disconnectWorksheets();
            @unlink($tempPath);
        }
    }

    /** Kolom Terakhir Diperbarui diisi MAX(updated_at) metric, atau '-' bila tidak ada. */
    public function test_kolom_terakhir_diperbarui_benar(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->divisionA->id,
            'user_id' => $this->staffA->id,
            'original_name' => 'data-kinerja.xlsx',
        ]);

        // Buat metric dengan updated_at spesifik
        $metric = PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $this->divisionA->id,
        ]);
        // Set updated_at manually
        $metric->timestamps = false;
        DB::table('performance_metrics')
            ->where('id', $metric->id)
            ->update(['updated_at' => '2026-09-10 14:30:00']);

        $response = $this->actingAs($this->admin)
            ->get(route('reports.dataset-summary'));

        $response->assertOk();

        $tempPath = tempnam(sys_get_temp_dir(), 'test_summary_upd_').'.xlsx';
        file_put_contents($tempPath, $response->streamedContent());
        $spreadsheet = IOFactory::load($tempPath);
        $sheet = $spreadsheet->getActiveSheet();

        // Kolom G = Terakhir Diperbarui — harus berisi tanggal (bukan '-')
        $cellVal = $sheet->getCell('G2')->getValue();
        $this->assertNotSame('-', $cellVal);
        $this->assertStringContainsString('10/09/2026', $cellVal);

        $spreadsheet->disconnectWorksheets();
        @unlink($tempPath);
    }

    /** Nama file berformat dataset-summary-{Y-m-d}.xlsx */
    public function test_nama_file_summary_benar(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.dataset-summary'));

        $response->assertOk();
        $this->assertStringContainsString(
            'dataset-summary-'.now()->format('Y-m-d').'.xlsx',
            $response->headers->get('content-disposition'),
        );
    }
}
