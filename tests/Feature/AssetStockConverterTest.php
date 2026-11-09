<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetStockConverter;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * Konversi rekap aset KIN26 - STOP ONLY -> template impor generik.
 *
 * Fixture meniru layout sampel mentor: band header 3 level yang di-merge,
 * baris ULTG sebagai butir data, baris "Total UPT"/"TOTAL UIT JBT"/"SELISIH"
 * sebagai agregat yang harus dilewati, plus satu sel error #REF!.
 */
class AssetStockConverterTest extends TestCase
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

    public function test_mengekstrak_baris_aset_dan_melewati_agregat(): void
    {
        $rows = app(AssetStockConverter::class)->extract($this->makeAssetReport(), '2026-08-31');

        $this->assertSame([
            ['ASET | BANDUNG | BANDUNG BARAT | Trafo 500/150 KV | Bank', 2.0, '2026-08-31'],
            ['ASET | BANDUNG | BANDUNG BARAT | Trafo 500/150 KV | Unit/Set', 3.0, '2026-08-31'],
            ['ASET | BANDUNG | BANDUNG BARAT | Trafo 500/150 KV | MVA', 100.5, '2026-08-31'],
            ['ASET | BANDUNG | BANDUNG BARAT | Trafo Tenaga 150 kV | Trafo 150/70 KV | Unit', 1.0, '2026-08-31'],
            ['ASET | BANDUNG | BANDUNG SELATAN | Trafo 500/150 KV | Bank', 0.0, '2026-08-31'],
            ['ASET | BANDUNG | BANDUNG SELATAN | Trafo Tenaga 150 kV | Trafo 150/70 KV | Unit', 2.0, '2026-08-31'],
            ['ASET | BOGOR | BOGOR | Trafo 500/150 KV | Unit/Set', 7.0, '2026-08-31'],
        ], $rows);
    }

    public function test_subtype_label_does_not_cross_category_boundary(): void
    {
        $rows = app(AssetStockConverter::class)->extract($this->makeSparseSubtypeHeaderReport(), '2026-08-31');
        $metricNames = array_column($rows, 0);

        $this->assertContains(
            'ASET | BOGOR | BOGOR | SIRKIT | Unit',
            $metricNames,
        );
        $this->assertNotContains(
            'ASET | BOGOR | BOGOR | SIRKIT | CUT OFF CB | Unit',
            $metricNames,
        );
    }

    public function test_sel_error_dilewati_bukan_nol_dan_nilai_nol_tetap_diimpor(): void
    {
        $rows = app(AssetStockConverter::class)->extract($this->makeAssetReport(), '2026-08-31');

        // Atribut bernilai 0 adalah data sah, bukan sel kosong.
        $this->assertContains(['ASET | BANDUNG | BANDUNG SELATAN | Trafo 500/150 KV | Bank', 0.0, '2026-08-31'], $rows);

        // Sel #REF! dilewati; baris yang sama tetap menyumbang sel validnya.
        foreach ($rows as $row) {
            $this->assertFalse(
                $row[0] === 'ASET | BOGOR | BOGOR | Trafo 500/150 KV | Bank',
                'sel error tidak boleh diimpor (bahkan sebagai 0)',
            );
        }
        $this->assertContains(['ASET | BOGOR | BOGOR | Trafo 500/150 KV | Unit/Set', 7.0, '2026-08-31'], $rows);
    }

    public function test_gagal_bila_header_upt_ultg_tidak_ditemukan(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('RUSAK');
        $sheet->setCellValue('A1', 'Layout tanpa identitas UPT/ULTG');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Sheet 'RUSAK'");

        try {
            app(AssetStockConverter::class)->extract($this->saveFixture($spreadsheet), '2026-08-31');
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_hasil_konversi_bisa_diunggah_lewat_pipeline_impor(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        $division = Division::where('slug', 'managemen-aset')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $converter = app(AssetStockConverter::class);
        $path = $this->tempFile($converter->write(
            $converter->extract($this->makeAssetReport(), '2026-08-31')
        ));

        $file = new UploadedFile($path, 'rekap-aset.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($admin)->post(route('datasets.store'), [
            'file' => $file,
            'division_id' => $division->id,
        ]);

        $dataset = Dataset::sole();
        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertSame(7, $dataset->metrics()->count());

        $metric = $dataset->metrics()
            ->where('metric_name', 'ASET | BANDUNG | BANDUNG BARAT | Trafo 500/150 KV | MVA')
            ->sole();
        $this->assertSame(100.5, (float) $metric->value);
        $this->assertSame('2026-08-31', $metric->period->format('Y-m-d'));
    }

    /**
     * Fixture probe: sub-tipe DIAMETER terisi, lalu sub-tipe SIRKIT kosong.
     */
    private function makeSparseSubtypeHeaderReport(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('KIN26 - STOP ONLY');

        $sheet->setCellValue('A5', 'No');
        $sheet->setCellValue('B5', 'UPT');
        $sheet->setCellValue('C5', 'ULTG');
        $sheet->setCellValue('D5', 'DIAMETER');
        $sheet->mergeCells('D5:F5');
        $sheet->setCellValue('G5', 'SIRKIT');
        $sheet->setCellValue('D6', 'CUT OFF CB');
        $sheet->mergeCells('D6:F6');
        $sheet->setCellValue('D7', 'Bank');
        $sheet->setCellValue('E7', 'Unit/Set');
        $sheet->setCellValue('F7', 'MVA');
        $sheet->setCellValue('G7', 'Unit');

        $sheet->setCellValue('B8', 'BOGOR');
        $sheet->setCellValue('C8', 'BOGOR');
        $sheet->setCellValue('D8', 1);
        $sheet->setCellValue('E8', 2);
        $sheet->setCellValue('F8', 3);
        $sheet->setCellValue('G8', 4);

        return $this->saveFixture($spreadsheet);
    }

    /**
     * Fixture kecil yang meniru layout rekap aset mentor.
     */
    private function makeAssetReport(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('KIN26 - STOP ONLY');

        $sheet->setCellValue('A1', 'REKAPITULASI DATA ASET REGISTER UIT JBT');

        // Band header 3 level: kategori (merge) | sub-tipe | satuan.
        $sheet->setCellValue('A5', 'No');
        $sheet->setCellValue('B5', 'UPT');
        $sheet->setCellValue('C5', 'ULTG');
        $sheet->setCellValue('D5', 'Trafo 500/150 KV');
        $sheet->mergeCells('D5:F5');
        $sheet->setCellValue('G5', 'Trafo Tenaga 150 kV');
        $sheet->setCellValue('G6', 'Trafo 150/70 KV');
        $sheet->setCellValue('D7', 'Bank');
        $sheet->setCellValue('E7', 'Unit/Set');
        $sheet->setCellValue('F7', 'MVA');
        $sheet->setCellValue('G7', 'Unit');

        // Butir data per ULTG.
        $sheet->setCellValue('A8', 1);
        $sheet->setCellValue('B8', 'BANDUNG');
        $sheet->setCellValue('C8', 'BANDUNG BARAT');
        $sheet->setCellValue('D8', 2);
        $sheet->setCellValue('E8', 3);
        $sheet->setCellValue('F8', 100.5);
        $sheet->setCellValue('G8', 1);

        // Nilai 0 adalah data sah; sel kosong dilewati.
        $sheet->setCellValue('B9', 'BANDUNG');
        $sheet->setCellValue('C9', 'BANDUNG SELATAN');
        $sheet->setCellValue('D9', 0);
        $sheet->setCellValue('G9', 2);

        // Agregat: subtotal UPT, grand total, rekonsiliasi — semua di-skip.
        $sheet->setCellValue('C10', 'Total UPT');
        $sheet->setCellValue('D10', '=SUM(D8:D9)');
        $sheet->setCellValue('B11', 'TOTAL UIT JBT');
        $sheet->setCellValue('D11', 99);
        $sheet->setCellValue('C12', 'SELISIH');
        $sheet->setCellValue('D12', 5);

        // Sel error (#REF! seperti judul A2 di berkas riil): selnya dilewati,
        // sel valid pada baris yang sama tetap diimpor.
        $sheet->setCellValue('B13', 'BOGOR');
        $sheet->setCellValue('C13', 'BOGOR');
        $sheet->setCellValueExplicit('D13', '#REF!', DataType::TYPE_ERROR);
        $sheet->setCellValue('E13', 7);

        return $this->saveFixture($spreadsheet);
    }

    /**
     * Simpan fixture ke file sementara dan daftarkan untuk dibersihkan.
     */
    private function saveFixture(Spreadsheet $spreadsheet): string
    {
        $path = $this->tempFile(tempnam(sys_get_temp_dir(), 'aset_fixture_').'.xlsx');
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
