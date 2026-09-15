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
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Import 1-klik laporan KPI mentah dari mentor (web, tanpa command line):
 * file dipecah per unit oleh KpiReportConverter::extractPerUnit(), tiap unit
 * dicocokkan ke divisi lewat NAMA SHEET (case-insensitive exact, pola sama
 * dengan DatasetBulkImportTest), lalu diimpor lewat pipeline biasa.
 *
 * Hanya untuk user tanpa division_id (admin); periode wajib karena label
 * periode di sheet mentor tidak konsisten (stale).
 */
class DatasetKpiReportImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_import_sukses_multi_unit_tanpa_command_line(): void
    {
        $tjbt = Division::factory()->create(['name' => 'TJBT']);
        $tjbb = Division::factory()->create(['name' => 'TJBB']);
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $response = $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ]);

        $response->assertRedirect(route('datasets.create'));
        $this->assertSame(2, Dataset::count());

        $datasetTjbt = Dataset::where('original_name', 'TJBT.xlsx')->sole();
        $this->assertSame(Dataset::STATUS_READY, $datasetTjbt->status->value);
        $this->assertSame($tjbt->id, $datasetTjbt->division_id);
        $this->assertSame(5, $datasetTjbt->metrics()->count());

        $nko = $datasetTjbt->metrics()->where('metric_name', 'TJBT | NKO')->sole();
        $this->assertSame(105.5, (float) $nko->value);
        $this->assertSame('2026-08-31', $nko->period->format('Y-m-d'));

        $datasetTjbb = Dataset::where('original_name', 'TJBB.xlsx')->sole();
        $this->assertSame($tjbb->id, $datasetTjbb->division_id);
        $this->assertSame('TJBB | NKO', $datasetTjbb->metrics()->where('metric_name', 'TJBB | NKO')->sole()->metric_name);

        $response->assertSessionHas('success', fn ($msg) => str_contains($msg, '2 unit'));
    }

    public function test_unit_tak_dikenal_dilewati_dan_dilaporkan(): void
    {
        // Prinsip yang sama dengan bulkStore(): nama unit tidak sama persis
        // dengan divisi manapun -> dilewati, TIDAK ditebak/dipaksa cocok.
        Division::factory()->create(['name' => 'TJBT']);
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $response = $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ]);

        $response->assertRedirect(route('datasets.create'));
        $this->assertSame(1, Dataset::count());
        $this->assertSame('TJBT.xlsx', Dataset::sole()->original_name);
        $this->assertSame(['TJBB'], session('kpi_unmatched'));
    }

    public function test_satu_sheet_korup_tidak_menghentikan_sheet_lain(): void
    {
        // Sheet RUSAK tanpa header TARGET/REALISASI/NILAI: dilaporkan, sementara
        // TJBT dan TJBB tetap terimpor.
        Division::factory()->create(['name' => 'TJBT']);
        Division::factory()->create(['name' => 'TJBB']);
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $response = $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(withBrokenSheet: true),
            'period' => '2026-08-31',
        ]);

        $response->assertRedirect(route('datasets.create'));
        $this->assertSame(2, Dataset::count());
        $this->assertSame(Dataset::STATUS_READY, Dataset::where('original_name', 'TJBT.xlsx')->sole()->status->value);
        $this->assertSame(Dataset::STATUS_READY, Dataset::where('original_name', 'TJBB.xlsx')->sole()->status->value);
        $this->assertCount(1, session('kpi_failed'));
        $this->assertStringContainsString('RUSAK', session('kpi_failed')[0]);
    }

    public function test_file_korup_ditolak_dengan_pesan_awam(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        // ZIP valid tapi bukan struktur XLSX (pola sama dengan
        // DatasetBulkImportTest::test_berkas_valid_tetap_terimpor_walau_ada_berkas_lain_gagal).
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
        $response->assertSessionHas('error', fn ($msg) => str_contains($msg, 'File tidak dapat dibaca'));
    }

    public function test_tanpa_periode_ditolak_validasi(): void
    {
        // Periode wajib: label periode di sheet mentor stale (progress.md),
        // tidak reliable untuk auto-detect.
        Division::factory()->create(['name' => 'TJBT']);
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
        ])->assertSessionHasErrors('period');

        $this->assertSame(0, Dataset::count());
    }

    public function test_staf_ditolak_403(): void
    {
        $division = Division::factory()->create(['name' => 'TJBT']);
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

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

    /**
     * Fixture yang meniru layout laporan KPI mentor: satu sheet per unit,
     * header TARGET/REALISASI/NILAI di baris 7, NKO dengan formula, plus
     * sheet REKAP lintas-unit. Opsional sheet RUSAK tanpa header nilai.
     */
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

    /**
     * Header TARGET/REALISASI/PENCAPAIAN/NILAI di kolom K/L/M/N (baris 7).
     */
    private function setValueHeaders(Worksheet $sheet): void
    {
        $sheet->setCellValue('K7', 'TARGET');
        $sheet->setCellValue('L7', 'REALISASI');
        $sheet->setCellValue('M7', 'PENCAPAIAN');
        $sheet->setCellValue('N7', 'NILAI');
    }
}
