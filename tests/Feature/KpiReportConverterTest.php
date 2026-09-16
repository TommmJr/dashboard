<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiReportConverter;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * Konversi laporan KPI hierarkis -> template impor generik.
 *
 * Fixture meniru layout sampel mentor: satu sheet per unit, baris NKO
 * nomor barisnya beda-beda, nilai NILAI/NKO berupa formula (hasil hitung
 * tersimpan sebagai cached value), plus sheet REKAP lintas-unit.
 */
class KpiReportConverterTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_mengekstrak_indikator_nko_target_dan_realisasi_dari_semua_unit(): void
    {
        $path = $this->makeKpiReport();

        $rows = app(KpiReportConverter::class)->extract($path, '2026-08-31');

        $this->assertSame([
            ['TJBT | Faktor Ketersediaan Trafo dan Transmisi', 41.0, '2026-08-31'],
            ['TJBT | a. Faktor Ketersediaan Trafo | target', 99.5, '2026-08-31'],
            ['TJBT | a. Faktor Ketersediaan Trafo | realisasi', 99.75, '2026-08-31'],
            ['TJBT | c. Indikator Nol', 0.0, '2026-08-31'],
            ['TJBT | TOTAL BOBOT', 94.5, '2026-08-31'],
            ['TJBT | NKO', 105.5, '2026-08-31'],
            ['TJBB | Indikator TJBB', 50.5, '2026-08-31'],
            ['TJBB | NKO', 100.0, '2026-08-31'],
        ], $rows);
    }

    public function test_filter_unit_hanya_mengambil_unit_yang_diminta(): void
    {
        $path = $this->makeKpiReport();

        $rows = app(KpiReportConverter::class)->extract($path, '2026-08-31', ['TJBB']);

        $this->assertSame([
            ['TJBB | Indikator TJBB', 50.5, '2026-08-31'],
            ['TJBB | NKO', 100.0, '2026-08-31'],
        ], $rows);
    }

    public function test_indikator_bernilai_nol_tetap_tertulis_di_file_keluaran(): void
    {
        $path = $this->tempFile(app(KpiReportConverter::class)->write([
            ['TJBT | Indikator Nol', 0.0, '2026-08-31'],
        ]));

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('metric_name', $sheet->getCell('A1')->getValue());
        $this->assertSame('value', $sheet->getCell('B1')->getValue());
        $this->assertSame('period', $sheet->getCell('C1')->getValue());
        $this->assertSame('TJBT | Indikator Nol', $sheet->getCell('A2')->getValue());
        $this->assertSame(0.0, (float) $sheet->getCell('B2')->getValue());
        $this->assertSame('2026-08-31', $sheet->getCell('C2')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_hasil_konversi_bisa_diunggah_lewat_pipeline_impor(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        $division = Division::where('slug', 'tjbt')->sole();
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $division->id,
        ]);

        $converter = app(KpiReportConverter::class);
        $rows = $converter->extract($this->makeKpiReport(), '2026-08-31', ['TJBT']);
        $path = $this->tempFile($converter->write($rows));

        $file = new UploadedFile($path, 'kinerja-tjbt.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($staff)->post(route('datasets.store'), ['file' => $file]);

        $dataset = Dataset::sole();
        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertSame(6, $dataset->metrics()->count());

        $nko = $dataset->metrics()->where('metric_name', 'TJBT | NKO')->sole();
        $this->assertSame(105.5, (float) $nko->value);
        $this->assertSame('2026-08-31', $nko->period->format('Y-m-d'));
    }

    public function test_perintah_konversi_menulis_file_keluaran(): void
    {
        $output = $this->tempFile(sys_get_temp_dir().DIRECTORY_SEPARATOR.'kpi_out_'.uniqid().'.xlsx');

        $this->artisan('kpi:convert', [
            'file' => $this->makeKpiReport(),
            '--period' => '2026-08-31',
            '--unit' => ['TJBT'],
            '--output' => $output,
        ])->assertSuccessful();

        $this->assertFileExists($output);

        $reader = IOFactory::createReaderForFile($output);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($output);
        $sheet = $spreadsheet->getActiveSheet();

        // 6 baris data + 1 header.
        $this->assertSame(7, $sheet->getHighestRow());
        $this->assertSame('TJBT | NKO', $sheet->getCell('A7')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_perintah_konversi_menolak_periode_tidak_valid(): void
    {
        $this->artisan('kpi:convert', [
            'file' => $this->makeKpiReport(),
            '--period' => '2026-13-40',
        ])->assertFailed();
    }

    public function test_mengikuti_posisi_kolom_dari_header_bukan_posisi_tetap(): void
    {
        // Regresi: sheet TRANS di file mentor menggeser TARGET/REALISASI/NILAI
        // satu kolom ke kiri (J/K/M) dibanding 14 sheet lain (K/L/N). Membaca
        // posisi tetap menghasilkan angka salah tanpa error sama sekali.
        $rows = app(KpiReportConverter::class)->extract($this->makeShiftedKpiReport(), '2026-08-31');

        $this->assertSame([
            ['TRANS | Indikator TRANS', 40.74, '2026-08-31'],
            ['TRANS | Indikator TRANS | target', 50.98, '2026-08-31'],
            ['TRANS | Indikator TRANS | realisasi', 54.76, '2026-08-31'],
            ['TRANS | NKO', 100.74, '2026-08-31'],
        ], $rows);
    }

    public function test_gagal_bila_header_kolom_tidak_ditemukan(): void
    {
        // Layout berubah total: jangan menebak, jangan diam-diam menghasilkan
        // data kosong atau salah.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Sheet 'RUSAK'");

        app(KpiReportConverter::class)->extract($this->makeReportWithoutValueHeaders(), '2026-08-31');
    }

    public function test_perintah_konversi_gagal_bila_layout_laporan_berubah(): void
    {
        $this->artisan('kpi:convert', [
            'file' => $this->makeReportWithoutValueHeaders(),
            '--period' => '2026-08-31',
        ])->assertFailed();
    }

    public function test_hasil_konversi_muncul_di_widget_peringkat_nko(): void
    {
        // Rantai penuh: konversi -> upload -> widget. Mengunci kesepakatan
        // format metric_name antara converter ('{UNIT} | NKO') dan query
        // widget ('% | NKO') supaya perubahan salah satu sisi ketangkap.
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        $division = Division::where('slug', 'tjbt')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $converter = app(KpiReportConverter::class);
        $rows = $converter->extract($this->makeKpiReport(), '2026-08-31', ['TJBT']);
        $path = $this->tempFile($converter->write($rows));

        $file = new UploadedFile($path, 'kinerja-tjbt.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($admin)->post(route('datasets.store'), [
            'file' => $file,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Peringkat NKO Unit')
            ->assertSee('TJBT')
            ->assertSee('105.50');
    }

    /**
     * Fixture kecil yang meniru layout laporan KPI mentor.
     */
    private function makeKpiReport(): string
    {
        $spreadsheet = new Spreadsheet;

        // REKAP: ringkasan lintas-unit, harus dilewati converter.
        $rekap = $spreadsheet->getActiveSheet();
        $rekap->setTitle('REKAP');
        $rekap->setCellValue('B3', 'NILAI KINERJA ORGANISASI');
        $rekap->setCellValue('N3', 999);

        $tjbt = $spreadsheet->createSheet();
        $tjbt->setTitle('TJBT');
        $tjbt->setCellValue('B6', 'INDIKATOR KINERJA KUNCI');
        $tjbt->setCellValue('K6', 'S.D. AGUSTUS 2026');
        $this->setValueHeaders($tjbt, 'K', 'L', 'M', 'N');
        $tjbt->setCellValue('B8', 2); // legenda nomor kolom: bukan indikator
        $tjbt->setCellValue('N8', 9);
        $tjbt->setCellValue('B10', 'Faktor Ketersediaan Trafo dan Transmisi');
        $tjbt->setCellValue('F10', 20.5);
        $tjbt->setCellValue('N10', '=F10*2'); // formula -> cached value
        $tjbt->setCellValue('B11', 'a. Faktor Ketersediaan Trafo');
        $tjbt->setCellValue('K11', 99.5);
        $tjbt->setCellValue('L11', 99.75);
        $tjbt->setCellValue('B12', 'c. Indikator Nol');
        $tjbt->setCellValue('N12', 0);
        $tjbt->setCellValue('B39', 'TOTAL BOBOT');
        $tjbt->setCellValue('N39', 94.5);
        $tjbt->setCellValue('B40', 'NILAI KINERJA ORGANISASI (NKO)');
        $tjbt->setCellValue('F40', 105.5);
        $tjbt->setCellValue('N40', '=F40*1'); // formula -> cached value

        $tjbb = $spreadsheet->createSheet();
        $tjbb->setTitle('TJBB');
        $this->setValueHeaders($tjbb, 'K', 'L', 'M', 'N');
        $tjbb->setCellValue('B10', 'Indikator TJBB');
        $tjbb->setCellValue('N10', 50.5);
        $tjbb->setCellValue('B40', 'NILAI KINERJA ORGANISASI (NKO)');
        $tjbb->setCellValue('N40', 100);

        return $this->saveFixture($spreadsheet);
    }

    /**
     * Tulis baris header TARGET/REALISASI/PENCAPAIAN/NILAI pada kolom yang
     * diberikan (baris 7, sama seperti laporan mentor).
     */
    private function setValueHeaders(Worksheet $sheet, string $target, string $realisasi, string $pencapaian, string $nilai): void
    {
        $sheet->setCellValue($target.'7', 'TARGET');
        $sheet->setCellValue($realisasi.'7', 'REALISASI');
        $sheet->setCellValue($pencapaian.'7', 'PENCAPAIAN');
        $sheet->setCellValue($nilai.'7', 'NILAI');
    }

    /**
     * Fixture dengan layout bergeser (pola sheet TRANS): TARGET/REALISASI/NILAI
     * di J/K/M, bukan K/L/N. Kolom N diisi nilai berbeda supaya converter yang
     * membaca posisi tetap menghasilkan angka yang salah.
     */
    private function makeShiftedKpiReport(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('TRANS');
        $sheet->setCellValue('J6', 'S.D. FEBRUARI 2025');
        $sheet->setCellValue('J7', 'TARGET');
        $sheet->setCellValue('K7', 'REALISASI');
        $sheet->setCellValue('L7', 'PENCAPAIAN');
        $sheet->setCellValue('M7', 'NILAI');
        $sheet->setCellValue('N7', 'KET');
        $sheet->setCellValue('B10', 'Indikator TRANS');
        $sheet->setCellValue('J10', 50.98);
        $sheet->setCellValue('K10', 54.76);
        $sheet->setCellValue('M10', 40.74);
        $sheet->setCellValue('N10', 1.0074);
        $sheet->setCellValue('B27', 'NILAI KINERJA ORGANISASI (NKO)');
        $sheet->setCellValue('M27', 100.74);
        $sheet->setCellValue('N27', 1.0074);

        return $this->saveFixture($spreadsheet);
    }

    /**
     * Fixture tanpa header TARGET/REALISASI/NILAI: meniru layout yang berubah
     * sehingga konverter harus gagal, bukan menebak.
     */
    private function makeReportWithoutValueHeaders(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('RUSAK');
        $sheet->setCellValue('B2', 'Judul Tanpa Header Nilai');
        $sheet->setCellValue('B10', 'Indikator Tanpa Kolom');
        $sheet->setCellValue('N10', 40.74);

        return $this->saveFixture($spreadsheet);
    }

    /**
     * Simpan fixture ke file sementara dan daftarkan untuk dibersihkan.
     */
    private function saveFixture(Spreadsheet $spreadsheet): string
    {
        $path = $this->tempFile(tempnam(sys_get_temp_dir(), 'kpi_fixture_').'.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * Daftarkan file sementara supaya dibersihkan setelah test.
     */
    private function tempFile(string $path): string
    {
        $this->tempFiles[] = $path;

        return $path;
    }
}
