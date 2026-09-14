<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Upload massal (multi-divisi): satu request, banyak file, masing-masing
 * dicocokkan ke divisi lewat NAMA FILE (tanpa ekstensi), sama persis dengan
 * Division::name. Lahir dari alur KpiReportConverter::writeSplit() - satu
 * berkas per unit, nama berkas = kode unit.
 *
 * Hanya untuk user tanpa division_id (admin); staf ditolak 403 karena bulk
 * tidak relevan untuk mereka (sudah otomatis ke divisinya sendiri).
 */
class DatasetBulkImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function makeXlsx(string $filename, array $rows = [
        ['metric_name', 'value', 'period'],
        ['Penjualan', '100', '2026-08-31'],
    ]): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 1], $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'bulk_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_admin_upload_massal_mencocokkan_tiap_berkas_ke_divisinya(): void
    {
        $tjbt = Division::factory()->create(['name' => 'TJBT']);
        $tjbb = Division::factory()->create(['name' => 'TJBB']);
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $this->actingAs($admin)->post(route('datasets.bulk-store'), [
            'files' => [
                $this->makeXlsx('TJBT.xlsx'),
                $this->makeXlsx('TJBB.xlsx'),
            ],
        ])->assertRedirect(route('datasets.index'));

        $this->assertSame(2, Dataset::count());
        $this->assertSame($tjbt->id, Dataset::where('original_name', 'TJBT.xlsx')->value('division_id'));
        $this->assertSame($tjbb->id, Dataset::where('original_name', 'TJBB.xlsx')->value('division_id'));
    }

    public function test_pencocokan_nama_berkas_tidak_case_sensitive(): void
    {
        $tjbt = Division::factory()->create(['name' => 'TJBT']);
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $this->actingAs($admin)->post(route('datasets.bulk-store'), [
            'files' => [$this->makeXlsx('tjbt.xlsx')],
        ]);

        $this->assertSame($tjbt->id, Dataset::sole()->division_id);
    }

    public function test_berkas_tanpa_divisi_cocok_dilewati_dan_dilaporkan(): void
    {
        Division::factory()->create(['name' => 'TJBT']);
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $response = $this->actingAs($admin)->post(route('datasets.bulk-store'), [
            'files' => [$this->makeXlsx('UNIT_TIDAK_DIKENAL.xlsx')],
        ]);

        $response->assertRedirect(route('datasets.index'));
        $this->assertSame(0, Dataset::count());
        $this->assertSame(['UNIT_TIDAK_DIKENAL.xlsx'], session('bulk_unmatched'));
    }

    public function test_berkas_valid_tetap_terimpor_walau_ada_berkas_lain_gagal(): void
    {
        // Regresi: satu berkas korup tidak boleh menghentikan berkas lain
        // dalam batch yang sama.
        Division::factory()->create(['name' => 'TJBT']);
        Division::factory()->create(['name' => 'TJBB']);
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        // ZIP valid tapi bukan struktur XLSX (pola sama dengan
        // DatasetImportTest::test_file_korup_ditandai_failed_bukan_menggantung).
        $corruptPath = tempnam(sys_get_temp_dir(), 'corrupt_').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($corruptPath, \ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'bukan spreadsheet');
        $zip->close();
        $corrupt = new UploadedFile($corruptPath, 'TJBB.xlsx', 'application/zip', null, true);

        $response = $this->actingAs($admin)->post(route('datasets.bulk-store'), [
            'files' => [
                $this->makeXlsx('TJBT.xlsx'),
                $corrupt,
            ],
        ]);

        $response->assertRedirect(route('datasets.index'));
        $this->assertSame(2, Dataset::count());
        $this->assertSame(Dataset::STATUS_READY, Dataset::where('original_name', 'TJBT.xlsx')->first()->status->value);
        $this->assertSame(Dataset::STATUS_FAILED, Dataset::where('original_name', 'TJBB.xlsx')->first()->status->value);
        $this->assertNotEmpty(session('bulk_failed'));
    }

    public function test_staf_ditolak_403_upload_massal(): void
    {
        $division = Division::factory()->create(['name' => 'TJBT']);
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->actingAs($staff)->post(route('datasets.bulk-store'), [
            'files' => [$this->makeXlsx('TJBT.xlsx')],
        ])->assertForbidden();

        $this->assertSame(0, Dataset::count());
    }

    public function test_editor_dashboard_ditolak_403_tanpa_permission_dataset_import(): void
    {
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => null]);

        $this->actingAs($editor)->post(route('datasets.bulk-store'), [
            'files' => [$this->makeXlsx('TJBT.xlsx')],
        ])->assertForbidden();
    }

    public function test_validasi_gagal_tanpa_files(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $this->actingAs($admin)->post(route('datasets.bulk-store'), [])
            ->assertSessionHasErrors('files');
    }
}
