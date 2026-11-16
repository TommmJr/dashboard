<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Test Karakterisasi: Perilaku Staf Tanpa Divisi pada Jalur Dataset.
 *
 * Menguji dan mengunci perilaku aman DatasetController & PerformanceMetricController
 * terhadap akun staf non-privileged yang tidak memiliki division_id (division_id = null).
 *
 * Staf tanpa divisi ditolak 403 pada seluruh aksi dataset (store, show, archive, destroy, export, update metric).
 */
class DatasetUploadStaffWithoutDivisionCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private User $staffNoDiv;

    private Division $divisionA;

    private Division $divisionB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->divisionA = Division::factory()->create(['name' => 'Divisi Perencanaan']);
        $this->divisionB = Division::factory()->create(['name' => 'Divisi Pemeliharaan']);

        $this->staffNoDiv = User::factory()
            ->withRole(Role::STAFF_DIVISI)
            ->create(['division_id' => null]);
    }

    private function makeXlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 1], $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'imp_char_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile(
            $path,
            'laporan.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    public function test_staf_tanpa_divisi_ditolak_403_saat_mengunggah_dataset_ke_divisi_mana_pun(): void
    {
        $file = $this->makeXlsx([
            ['metric_name', 'value', 'period'],
            ['Realisasi Beban', '125.5', '2026-08-31'],
        ]);

        // POST datasets: staf tanpa divisi ditolak fail-closed 403 Forbidden
        // dan tidak boleh membuat dataset ke divisi mana pun
        $response = $this->actingAs($this->staffNoDiv)
            ->post(route('datasets.store'), [
                'file' => $file,
                'division_id' => $this->divisionA->id,
            ]);

        $response->assertForbidden();

        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, PerformanceMetric::count());
    }

    public function test_staf_tanpa_divisi_ditolak_403_saat_mengunggah_dataset_tanpa_division_id(): void
    {
        $file = $this->makeXlsx([
            ['metric_name', 'value', 'period'],
            ['Realisasi Beban', '125.5', '2026-08-31'],
        ]);

        $response = $this->actingAs($this->staffNoDiv)
            ->post(route('datasets.store'), [
                'file' => $file,
            ]);

        $response->assertForbidden();

        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, PerformanceMetric::count());
    }

    public function test_karakterisasi_staf_tanpa_divisi_ditolak_403_melihat_detail_dataset(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->divisionA->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $this->actingAs($this->staffNoDiv)
            ->get(route('datasets.show', $dataset))
            ->assertForbidden();
    }

    public function test_karakterisasi_staf_tanpa_divisi_ditolak_403_mengarsipkan_dataset(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->divisionA->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $this->actingAs($this->staffNoDiv)
            ->patch(route('datasets.archive', $dataset))
            ->assertForbidden();

        $this->assertSame(DatasetStatus::READY, $dataset->fresh()->status);
    }

    public function test_karakterisasi_staf_tanpa_divisi_ditolak_403_menghapus_dataset(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->divisionA->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $this->actingAs($this->staffNoDiv)
            ->delete(route('datasets.destroy', $dataset))
            ->assertForbidden();

        $this->assertDatabaseHas('datasets', ['id' => $dataset->id]);
    }

    public function test_karakterisasi_staf_tanpa_divisi_ditolak_403_mengubah_metric(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->divisionA->id,
            'status' => Dataset::STATUS_READY,
        ]);
        $metric = PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $this->divisionA->id,
            'value' => 100.0,
            'period' => '2026-08-31',
        ]);

        $this->actingAs($this->staffNoDiv)
            ->patch(route('performance_metrics.update', $metric), [
                'value' => 999.0,
                'period' => '2026-08-31',
            ])
            ->assertForbidden();

        $this->assertSame(100.0, (float) $metric->fresh()->value);
    }

    public function test_staf_tanpa_divisi_ditolak_403_saat_mengimpor_laporan_kpi(): void
    {
        Division::firstOrCreate(
            ['slug' => 'perencanaan-pengusahaan'],
            ['name' => 'Perencanaan Pengusahaan'],
        );

        $file = $this->makeXlsx([
            ['metric_name', 'value', 'period'],
            ['Realisasi Beban', '125.5', '2026-08-31'],
        ]);

        $response = $this->actingAs($this->staffNoDiv)
            ->post(route('datasets.kpi-import'), [
                'file' => $file,
                'period' => '2026-08-31',
            ]);

        $response->assertForbidden();

        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, PerformanceMetric::count());
    }

    public function test_staf_tanpa_divisi_tidak_melihat_dropdown_divisi_pada_form_upload(): void
    {
        $response = $this->actingAs($this->staffNoDiv)
            ->get(route('datasets.create'));

        $response->assertOk();
        $response->assertViewHas('divisions', fn ($divisions) => $divisions->isEmpty());
        $response->assertDontSee('Divisi Pemilik Data');
    }

    public function test_karakterisasi_staf_tanpa_divisi_melihat_daftar_dataset_kosong(): void
    {
        Dataset::factory()->create([
            'division_id' => $this->divisionA->id,
            'status' => Dataset::STATUS_READY,
        ]);
        Dataset::factory()->create([
            'division_id' => $this->divisionB->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $response = $this->actingAs($this->staffNoDiv)
            ->get(route('datasets.index'));

        $response->assertOk();
        $response->assertViewHas('datasets', function ($datasets) {
            return $datasets->total() === 0;
        });
    }
}
