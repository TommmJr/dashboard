<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetStockConverter;
use App\Services\AssetStockReport;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Round-trip pipeline Aset dengan nilai SINTETIS non-nol.
 *
 * Alur: fixture worksheet buatan -> AssetStockConverter -> upload via
 * datasets.store -> AssetStockReport. Nilai 5/7 di sini BUKAN angka bisnis
 * nyata — test ini membuktikan pipeline mampu membawa nilai asli dengan
 * benar, bukan memvalidasi angka Aset produksi (workbook riil saat ini
 * cached zero dan belum bisa dipakai sebagai acuan).
 */
class AssetPipelineRoundTripTest extends TestCase
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

    public function test_nilai_sintetis_non_nol_keluar_utuh_di_query(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        $asetDivision = Division::where('slug', 'managemen-aset')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $converter = app(AssetStockConverter::class);
        $path = $this->tempFile($converter->write(
            $converter->extract($this->makeSyntheticRekap(), '2026-08-31')
        ));

        $this->actingAs($admin)->post(route('datasets.store'), [
            'file' => new UploadedFile($path, 'rekap-sintetis.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'division_id' => $asetDivision->id,
        ])->assertRedirect();

        $dataset = Dataset::sole();
        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);

        $persisted = $dataset->metrics()
            ->orderBy('metric_name')
            ->pluck('value', 'metric_name')
            ->map(fn ($value): float => (float) $value)
            ->all();

        // Nilai sintetis per-ULTG masuk (5, 7) utuh — bukan nol, dan subtotal dilewati.
        $this->assertSame([
            'ASET | BEKASI | BEKASI | Trafo 500/150 KV | Bank' => 5.0,
            'ASET | BEKASI | CIKARANG | Trafo 500/150 KV | Bank' => 7.0,
        ], $persisted);

        $report = app(AssetStockReport::class)->totalsByUpt();

        $this->assertSame('2026-08-31', $report['period']);
        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 12.0],
        ], $report['totals']->map(fn (object $t): array => [
            'upt' => $t->upt,
            'attribute' => $t->attribute,
            'total' => $t->total,
        ])->all());
    }

    /**
     * Rekap buatan: dua baris ULTG bernilai sintetis + satu subtotal yang
     * harus dilewati converter. Jelas bukan data produksi.
     */
    private function makeSyntheticRekap(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('KIN26 - STOP ONLY');
        $sheet->setCellValue('A5', 'No');
        $sheet->setCellValue('B5', 'UPT');
        $sheet->setCellValue('C5', 'ULTG');
        $sheet->setCellValue('D5', 'Trafo 500/150 KV');
        $sheet->setCellValue('D7', 'Bank');
        $sheet->setCellValue('A8', 1);
        $sheet->setCellValue('B8', 'BEKASI');
        $sheet->setCellValue('C8', 'BEKASI');
        $sheet->setCellValue('D8', 5);
        $sheet->setCellValue('B9', 'BEKASI');
        $sheet->setCellValue('C9', 'CIKARANG');
        $sheet->setCellValue('D9', 7);
        $sheet->setCellValue('C10', 'Total UPT');
        $sheet->setCellValue('D10', '=SUM(D8:D9)');

        $path = $this->tempFile(tempnam(sys_get_temp_dir(), 'aset_rt_').'.xlsx');
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
