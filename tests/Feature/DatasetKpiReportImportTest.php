<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Import laporan KPI mentor sebagai satu dataset milik Perencanaan Pengusahaan.
 */
class DatasetKpiReportImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);
    }

    public function test_import_sukses_menggabungkan_semua_unit_ke_satu_dataset_owner(): void
    {
        $owner = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $response = $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ]);

        $response->assertRedirect(route('datasets.create'));
        $dataset = Dataset::sole();
        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertSame($owner->id, $dataset->division_id);
        $this->assertSame(7, $dataset->metrics()->count());
        $this->assertSame(105.5, (float) $dataset->metrics()->where('metric_name', 'TJBT | NKO')->sole()->value);
        $this->assertSame('TJBB | NKO', $dataset->metrics()->where('metric_name', 'TJBB | NKO')->sole()->metric_name);
        $response->assertSessionHas('success', fn ($message) => str_contains($message, '1 dataset'));
    }

    public function test_semua_unit_sheet_diperlakukan_sebagai_dimensi_laporan(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $response = $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ]);

        $response->assertRedirect(route('datasets.create'));
        $this->assertSame(1, Dataset::count());
        $this->assertNull(session('kpi_unmatched'));
    }

    public function test_satu_sheet_korup_tidak_menghentikan_sheet_lain(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $response = $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(withBrokenSheet: true),
            'period' => '2026-08-31',
        ]);

        $response->assertRedirect(route('datasets.create'));
        $this->assertSame(1, Dataset::count());
        $this->assertSame(7, Dataset::sole()->metrics()->count());
        $this->assertCount(1, session('kpi_failed'));
        $this->assertStringContainsString('RUSAK', session('kpi_failed')[0]);
    }

    public function test_file_korup_ditolak_dengan_pesan_awam(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $corruptPath = tempnam(sys_get_temp_dir(), 'corrupt_').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($corruptPath, \ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'bukan spreadsheet');
        $zip->close();

        $response = $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => new UploadedFile($corruptPath, 'korup.xlsx', 'application/zip', null, true),
            'period' => '2026-08-31',
        ]);

        $response->assertRedirect();
        $this->assertSame(0, Dataset::count());
        $response->assertSessionHas('error', fn ($message) => str_contains($message, 'File tidak dapat dibaca'));
    }

    public function test_tanpa_periode_ditolak_validasi(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
        ])->assertSessionHasErrors('period');

        $this->assertSame(0, Dataset::count());
    }

    public function test_staf_ditolak_403(): void
    {
        $owner = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $owner->id]);

        $this->actingAs($staff)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ])->assertForbidden();

        $this->assertSame(0, Dataset::count());
    }

    public function test_editor_dashboard_ditolak_403_tanpa_permission_dataset_import(): void
    {
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => null]);

        $this->actingAs($editor)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ])->assertForbidden();
    }

    private function makeKpiReport(bool $withBrokenSheet = false): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $rekap = $spreadsheet->getActiveSheet();
        $rekap->setTitle('REKAP');
        $rekap->setCellValue('B3', 'NILAI KINERJA ORGANISASI');
        $rekap->setCellValue('N3', 999);

        $tjbt = $spreadsheet->createSheet();
        $tjbt->setTitle('TJBT');
        $this->setValueHeaders($tjbt);
        $tjbt->setCellValue('B10', 'Faktor Ketersediaan Trafo dan Transmisi');
        $tjbt->setCellValue('N10', 41);
        $tjbt->setCellValue('B11', 'a. Faktor Ketersediaan Trafo');
        $tjbt->setCellValue('K11', 99.5);
        $tjbt->setCellValue('L11', 99.75);
        $tjbt->setCellValue('B12', 'c. Indikator Nol');
        $tjbt->setCellValue('N12', 0);
        $tjbt->setCellValue('B40', 'NILAI KINERJA ORGANISASI (NKO)');
        $tjbt->setCellValue('N40', 105.5);

        $tjbb = $spreadsheet->createSheet();
        $tjbb->setTitle('TJBB');
        $this->setValueHeaders($tjbb);
        $tjbb->setCellValue('B10', 'Indikator TJBB');
        $tjbb->setCellValue('N10', 50.5);
        $tjbb->setCellValue('B40', 'NILAI KINERJA ORGANISASI (NKO)');
        $tjbb->setCellValue('N40', 100);

        if ($withBrokenSheet) {
            $rusak = $spreadsheet->createSheet();
            $rusak->setTitle('RUSAK');
            $rusak->setCellValue('B2', 'Judul Tanpa Header Nilai');
            $rusak->setCellValue('N10', 40.74);
        }

        $path = tempnam(sys_get_temp_dir(), 'kpi_web_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'laporan-kpi-mentor.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function setValueHeaders(Worksheet $sheet): void
    {
        $sheet->setCellValue('K7', 'TARGET');
        $sheet->setCellValue('L7', 'REALISASI');
        $sheet->setCellValue('M7', 'PENCAPAIAN');
        $sheet->setCellValue('N7', 'NILAI');
    }
}
