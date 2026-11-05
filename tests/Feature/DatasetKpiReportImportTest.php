<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceMetric;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use App\Services\StructuredKpiImporter;
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
        // Legacy tetap baris-demi-baris: sheet RUSAK dilewati, 7 baris sisanya
        // masuk seperti sebelum dual-write.
        $this->assertSame(7, Dataset::sole()->metrics()->count());
        $this->assertStringContainsString('RUSAK', session('kpi_failed')[0]);

        // Sisi terstruktur ALL-OR-NOTHING. extractStructured() milik Unit 4A
        // sengaja gagal keras saat layout sheet tak dikenali (lebih baik gagal
        // terang daripada menulis sebagian), jadi satu sheet korup membatalkan
        // seluruh penulisan terstruktur — termasuk baris dari sheet yang sehat.
        // Yang penting: data legacy tidak ikut hilang, dan kegagalannya
        // dilaporkan ke pengguna, bukan ditelan senyap.
        $this->assertSame(0, Indicator::count());
        $this->assertSame(0, PerformanceObservation::count());

        $this->assertCount(2, session('kpi_failed'));
        $this->assertStringContainsString(
            'penulisan tabel terstruktur gagal',
            implode(' | ', session('kpi_failed')),
        );
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

    public function test_staf_pemilik_import_sukses(): void
    {
        $owner = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $owner->id]);

        $this->actingAs($staff)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ])->assertRedirect(route('datasets.create'));

        $this->assertSame(1, Dataset::count());
        $this->assertSame($owner->id, Dataset::sole()->division_id);
    }

    public function test_staf_divisi_lain_tetap_ditolak_403(): void
    {
        // Staf divisi bukan pemilik data KPI (mis. Managemen Aset) tidak boleh
        // memasukkan data ke divisi yang bukan miliknya.
        $other = Division::firstOrCreate(
            ['slug' => 'managemen-aset'],
            ['name' => 'Managemen Aset'],
        );
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $other->id]);

        $this->actingAs($staff)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ])->assertForbidden();

        $this->assertSame(0, Dataset::count());
    }

    public function test_staf_tanpa_divisi_ditolak_403_dan_tidak_meninggalkan_data(): void
    {
        // Staf non-privileged tanpa divisi tidak boleh memicu impor KPI.
        $staffNoDiv = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => null]);

        $this->actingAs($staffNoDiv)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ])->assertForbidden();

        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, PerformanceMetric::count());
        $this->assertSame(0, OrgUnit::count());
        $this->assertSame(0, Indicator::count());
        $this->assertSame(0, PerformanceObservation::count());
    }

    public function test_editor_dashboard_ditolak_403_tanpa_permission_dataset_import(): void
    {
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => null]);

        $this->actingAs($editor)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ])->assertForbidden();

        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, PerformanceMetric::count());
        $this->assertSame(0, OrgUnit::count());
        $this->assertSame(0, Indicator::count());
        $this->assertSame(0, PerformanceObservation::count());
    }

    public function test_management_ditolak_403_tanpa_permission_dataset_import(): void
    {
        // Management: penonton utama dashboard, tidak punya dataset.import.
        $management = User::factory()->withRole(Role::MANAGEMENT)->create(['division_id' => null]);

        $this->actingAs($management)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ])->assertForbidden();

        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, PerformanceMetric::count());
        $this->assertSame(0, OrgUnit::count());
        $this->assertSame(0, Indicator::count());
        $this->assertSame(0, PerformanceObservation::count());
    }

    public function test_import_periode_sama_mengarsipkan_dataset_lama(): void
    {
        // B2: periode sama = revisi. Dataset READY lama di-ARCHIVE (bukan
        // dihapus) supaya agregasi chart tidak menjumlah dua dataset periode
        // sama. Dataset baru tetap READY.
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ])->assertRedirect(route('datasets.create'));

        $first = Dataset::sole();
        $this->assertSame(Dataset::STATUS_READY, $first->status->value);

        // Konfirmasi wajib: periode sama = revisi, jadi archive dipaksa
        // eksplisit agar salah ketik periode tidak mengarsipkan data diam-diam.
        $response = $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
            'confirm_archive' => 1,
        ]);

        $response->assertRedirect(route('datasets.create'))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'diarsipkan otomatis'));

        $this->assertSame(2, Dataset::count());
        $this->assertSame(1, Dataset::where('status', Dataset::STATUS_READY)->count());
        $this->assertSame(1, Dataset::where('status', Dataset::STATUS_ARCHIVED)->count());
        $this->assertSame(
            Dataset::STATUS_ARCHIVED,
            $first->fresh()->status->value,
            'Dataset pertama harus diarsipkan, bukan dibiarkan READY.',
        );
    }

    public function test_import_periode_beda_tidak_mengarsipkan_dataset_lama(): void
    {
        // Guard B2 harus spesifik periode: periode lain tidak boleh tersentuh.
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ])->assertRedirect(route('datasets.create'));

        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-09-30',
        ])->assertRedirect(route('datasets.create'));

        $this->assertSame(2, Dataset::count());
        $this->assertSame(2, Dataset::where('status', Dataset::STATUS_READY)->count());
        $this->assertSame(0, Dataset::where('status', Dataset::STATUS_ARCHIVED)->count());
    }

    public function test_import_periode_sama_tanpa_konfirmasi_tidak_mengarsipkan(): void
    {
        // Arsip satu arah (tidak ada route un-archive), jadi revisi periode
        // yang sama harus minta persetujuan eksplisit. Tanpa konfirmasi,
        // dataset lama TETAP READY dan tidak ada yang berubah sama sekali.
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ])->assertRedirect(route('datasets.create'));

        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('period');

        $this->assertSame(1, Dataset::count());
        $this->assertSame(1, Dataset::where('status', Dataset::STATUS_READY)->count());
        $this->assertSame(0, Dataset::where('status', Dataset::STATUS_ARCHIVED)->count());
    }

    public function test_periode_kosong_tidak_muncul_di_form_untuk_divisi_bukan_pemilik(): void
    {
        // Staf divisi lain tidak boleh impor KPI, jadi tidak perlu melihat
        // kartu Import Laporan KPI maupun daftar periode milik divisi pemilik data.
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => Division::factory()->create()->id,
        ]);

        $this->actingAs($staff)->get(route('datasets.create'))
            ->assertOk()
            ->assertDontSee('Import Laporan KPI dari Mentor (Otomatis)')
            ->assertDontSee(route('datasets.kpi-import'), false)
            ->assertDontSee('Periode yang sudah pernah diimpor');
    }

    public function test_periode_kpi_tidak_muncul_di_form_untuk_staf_tanpa_divisi(): void
    {
        $owner = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        // Import dataset pertama agar ada riwayat periode
        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ])->assertRedirect(route('datasets.create'));

        // Admin dan staf pemilik melihat kartu Import Laporan KPI dan riwayat periode
        $this->actingAs($admin)->get(route('datasets.create'))
            ->assertOk()
            ->assertSee('Import Laporan KPI dari Mentor (Otomatis)')
            ->assertSee(route('datasets.kpi-import'), false)
            ->assertSee('Periode yang sudah pernah diimpor:');

        $staffOwner = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $owner->id]);
        $this->actingAs($staffOwner)->get(route('datasets.create'))
            ->assertOk()
            ->assertSee('Import Laporan KPI dari Mentor (Otomatis)')
            ->assertSee(route('datasets.kpi-import'), false)
            ->assertSee('Periode yang sudah pernah diimpor:');

        // Staf tanpa divisi tidak boleh melihat kartu Import Laporan KPI maupun riwayat periode KPI
        $staffNoDiv = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => null]);
        $this->actingAs($staffNoDiv)->get(route('datasets.create'))
            ->assertOk()
            ->assertDontSee('Import Laporan KPI dari Mentor (Otomatis)')
            ->assertDontSee(route('datasets.kpi-import'), false)
            ->assertDontSee('Periode yang sudah pernah diimpor');
    }

    public function test_kegagalan_structured_import_tidak_mengarsipkan_dataset_lama_dan_dataset_baru_failed(): void
    {
        $owner = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $period = '2026-08-31';

        // 1. Import dataset pertama (sukses penuh)
        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => $period,
        ])->assertRedirect(route('datasets.create'));

        $firstDataset = Dataset::where('division_id', $owner->id)->sole();
        $this->assertSame(Dataset::STATUS_READY, $firstDataset->status->value);
        $this->assertGreaterThan(0, PerformanceObservation::where('dataset_id', $firstDataset->id)->count());

        // 2. Mock StructuredKpiImporter agar melempar exception pada import revisi
        $this->mock(StructuredKpiImporter::class, function ($mock): void {
            $mock->shouldReceive('import')->andThrow(new \RuntimeException('Koneksi terputus saat tulis terstruktur'));
        });

        // 3. Import revisi dengan confirm_archive = 1
        $response = $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => $period,
            'confirm_archive' => 1,
        ]);

        $response->assertRedirect(route('datasets.create'));

        // 4. Verifikasi dataset pertama TETAP READY (tidak diarsipkan)
        $this->assertSame(
            Dataset::STATUS_READY,
            $firstDataset->fresh()->status->value,
            'Dataset lama harus tetap READY jika import revisi gagal pada layer terstruktur.',
        );

        // 5. Verifikasi dataset kedua berstatus FAILED dan metrik legacynya dibersihkan
        $newDataset = Dataset::where('id', '!=', $firstDataset->id)->sole();
        $this->assertSame(
            Dataset::STATUS_FAILED,
            $newDataset->status->value,
            'Dataset baru harus berstatus FAILED jika penulisan terstruktur gagal.',
        );
        $this->assertStringContainsString('tabel terstruktur gagal', (string) $newDataset->error_message);
        $this->assertSame(
            0,
            $newDataset->metrics()->count(),
            'Metrik legacy dari dataset yang gagal terstruktur harus dibersihkan agar tidak meninggalkan baris yatim.',
        );
    }

    public function test_import_menghasilkan_nol_observasi_ditolak_dan_tidak_mengarsipkan_dataset_lama(): void
    {
        $owner = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $period = '2026-08-31';

        // 1. Dataset awal yang aktif
        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => $period,
        ])->assertRedirect(route('datasets.create'));

        $firstDataset = Dataset::where('division_id', $owner->id)->sole();
        $this->assertSame(Dataset::STATUS_READY, $firstDataset->status->value);

        // 2. Mock importer agar mengembalikan 0 observasi (misal semua baris kosong/terfilter)
        $this->mock(StructuredKpiImporter::class, function ($mock): void {
            $mock->shouldReceive('import')->andReturn([
                'org_units' => 0,
                'indicators' => 0,
                'observations' => 0,
                'excluded' => 10,
            ]);
        });

        // 3. Eksekusi import revisi
        $response = $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => $period,
            'confirm_archive' => 1,
        ]);

        $response->assertRedirect(route('datasets.create'));

        // 4. Dataset lama tetap aktif
        $this->assertSame(Dataset::STATUS_READY, $firstDataset->fresh()->status->value);

        // 5. Dataset baru ditandai FAILED
        $newDataset = Dataset::where('id', '!=', $firstDataset->id)->sole();
        $this->assertSame(Dataset::STATUS_FAILED, $newDataset->status->value);
        $this->assertSame(0, $newDataset->metrics()->count());
    }

    public function test_flash_message_melaporkan_error_dan_bukan_success_ketika_structured_gagal(): void
    {
        $owner = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $period = '2026-08-31';

        $this->mock(StructuredKpiImporter::class, function ($mock): void {
            $mock->shouldReceive('import')->andThrow(new \RuntimeException('Gagal memproses sheet'));
        });

        $response = $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => $period,
        ]);

        $response->assertRedirect(route('datasets.create'));
        // Harus ada flash error, BUKAN flash success
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');
        $response->assertSessionHas('error', fn ($msg) => str_contains($msg, 'Gagal mengimpor') || str_contains($msg, 'tidak dapat diproses'));
        $response->assertSessionHas('kpi_failed', fn ($items) => count($items) > 0);
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
