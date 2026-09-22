<?php

namespace Tests\Feature;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Command aset:convert — rekap aset KIN26 - STOP ONLY -> template generik.
 *
 * Mirror pola ConvertKpiReport: --period wajib Y-m-d (anti silent-rolling),
 * file keluaran diunggah manual lewat form datasets.store (tidak ada route
 * baru, tidak menyentuh DatasetController).
 */
class ConvertAssetReportTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_perintah_konversi_menulis_file_keluaran(): void
    {
        $output = $this->tempFile(sys_get_temp_dir().DIRECTORY_SEPARATOR.'aset_out_'.uniqid().'.xlsx');

        $this->artisan('aset:convert', [
            'file' => $this->makeMiniRekap(),
            '--period' => '2026-08-31',
            '--output' => $output,
        ])->assertSuccessful();

        $this->assertFileExists($output);

        $reader = IOFactory::createReaderForFile($output);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($output);
        $sheet = $spreadsheet->getActiveSheet();

        // 1 baris data + 1 header.
        $this->assertSame(2, $sheet->getHighestRow());
        $this->assertSame('ASET | BANDUNG | BANDUNG BARAT | Trafo 500/150 KV | Bank', $sheet->getCell('A2')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_perintah_konversi_menolak_periode_tidak_valid(): void
    {
        $this->artisan('aset:convert', [
            'file' => $this->makeMiniRekap(),
            '--period' => '2026-13-40',
        ])->assertFailed();
    }

    public function test_perintah_konversi_menolak_tanpa_periode(): void
    {
        $this->artisan('aset:convert', [
            'file' => $this->makeMiniRekap(),
        ])->assertFailed();
    }

    /**
     * Rekap mini: header identitas + satu kolom atribut + satu baris data.
     */
    private function makeMiniRekap(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('KIN26 - STOP ONLY');
        $sheet->setCellValue('A5', 'No');
        $sheet->setCellValue('B5', 'UPT');
        $sheet->setCellValue('C5', 'ULTG');
        $sheet->setCellValue('D5', 'Trafo 500/150 KV');
        $sheet->setCellValue('D7', 'Bank');
        $sheet->setCellValue('B8', 'BANDUNG');
        $sheet->setCellValue('C8', 'BANDUNG BARAT');
        $sheet->setCellValue('D8', 2);

        $path = $this->tempFile(tempnam(sys_get_temp_dir(), 'aset_cmd_').'.xlsx');
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
