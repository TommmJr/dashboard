<?php

namespace Tests\Feature;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Command aset:csv-to-xlsx — konversi verbatim CSV -> XLSX (Faza A).
 *
 * Kontrak koersi sel dikunci di sini supaya perilaku verbatim tidak
 * bergeser diam-diam: numerik polos + pemisah ribuan -> float; "-" dan
 * kosong -> sel kosong; sel error dan E-notation -> string (TIDAK
 * dikoersi — representasi asli terjaga); quoted field dengan newline
 * dibaca sebagai satu record (parser RFC 4180).
 */
class ConvertAssetCsvTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }

        parent::tearDown();
    }

    public function test_perintah_mengonversi_csv_menjadi_xlsx_verbatim(): void
    {
        $source = $this->tempDir();
        $output = $this->tempDir();

        file_put_contents(
            $source.DIRECTORY_SEPARATOR.'rekap.csv',
            "No,UPT,\"Daya (MVA)\",CATATAN,KOORDINAT\n"
            ."1,BANDUNG,\"1,860.0\",#DIV/0!,1.23E+5\n"
            ."2,BEKASI,\"- 15\",\"-\",OK\n"
            ."3,BANDUNG,\"catatan dua\nbaris\",-,x\n"
        );

        // Nama panjang: judul sheet dipangkas 31 karakter tanpa prefix workbook.
        file_put_contents(
            $source.DIRECTORY_SEPARATOR.'DATA ASET REGISTER UIT JBT - 2b. TRF NAMA FILE YANG BENAR BENAR PANJANG MELEBIHI BATAS.csv',
            "UPT\nBANDUNG\n"
        );

        $this->artisan('aset:csv-to-xlsx', ['source' => $source, '--output' => $output])
            ->assertSuccessful();

        $xlsx = $output.DIRECTORY_SEPARATOR.'rekap.xlsx';
        $this->assertFileExists($xlsx);

        $spreadsheet = $this->load($xlsx);
        $sheet = $spreadsheet->getActiveSheet();

        // 3 record data + header; kolom terjauh E.
        $this->assertSame(4, $sheet->getHighestRow());
        $this->assertSame('E', $sheet->getHighestColumn());

        // Koersi float: polos, pemisah ribuan, dan spasi dibuang.
        $this->assertSame(1.0, $sheet->getCell('A2')->getValue());
        $this->assertSame(1860.0, $sheet->getCell('C2')->getValue());
        $this->assertSame(-15.0, $sheet->getCell('C3')->getValue());

        // Sel error dan E-notation tetap string (tidak dikoersi float).
        $this->assertSame('#DIV/0!', $sheet->getCell('D2')->getValue());
        $this->assertSame('1.23E+5', $sheet->getCell('E2')->getValue());

        // "-" menjadi sel kosong.
        $this->assertNull($sheet->getCell('D3')->getValue());
        $this->assertNull($sheet->getCell('D4')->getValue());

        // Quoted field dengan newline dibaca sebagai satu record.
        $this->assertSame("catatan dua\nbaris", $sheet->getCell('C4')->getValue());

        $spreadsheet->disconnectWorksheets();

        // Judul sheet dipangkas 31 karakter.
        $long = $output.DIRECTORY_SEPARATOR.'DATA ASET REGISTER UIT JBT - 2b. TRF NAMA FILE YANG BENAR BENAR PANJANG MELEBIHI BATAS.xlsx';
        $this->assertFileExists($long);

        $longSpreadsheet = $this->load($long);
        $this->assertSame('2b. TRF NAMA FILE YANG BENAR BE', $longSpreadsheet->getActiveSheet()->getTitle());
        $longSpreadsheet->disconnectWorksheets();
    }

    public function test_perintah_menolak_sumber_tidak_ditemukan(): void
    {
        $this->artisan('aset:csv-to-xlsx', [
            'source' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'aset_tidak_ada_'.uniqid(),
        ])->assertFailed();
    }

    public function test_perintah_menolak_direktori_tanpa_csv(): void
    {
        $this->artisan('aset:csv-to-xlsx', [
            'source' => $this->tempDir(),
            '--output' => $this->tempDir(),
        ])->assertFailed();
    }

    /**
     * Direktori sementara yang dibersihkan setelah test.
     */
    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aset_csv_'.uniqid();
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * Hapus berkas lalu direktori (satu level).
     */
    private function removeDir(string $dir): void
    {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }

    /**
     * Muat XLSX read-only untuk verifikasi nilai.
     */
    private function load(string $path): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        return $reader->load($path);
    }
}
