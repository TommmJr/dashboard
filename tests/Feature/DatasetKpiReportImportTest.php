<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Models\AuditLog;
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
use Illuminate\Support\Facades\Storage;
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

    public function test_satu_sheet_korup_membatalkan_seluruh_import_dan_dataset_failed(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $response = $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(withBrokenSheet: true),
            'period' => '2026-08-31',
        ]);

        $response->assertRedirect(route('datasets.create'));
        $this->assertSame(1, Dataset::count());
        $dataset = Dataset::sole();

        // Kontrak integritas baru: sisi terstruktur ALL-OR-NOTHING. Jika ada sheet
        // dengan layout tak dikenali, structured import gagal total, dataset baru
        // dikompensasi menjadi FAILED, dan metrik legacy dibersihkan agar tidak
        // menjadi ghost dataset dengan 0 observasi terstruktur.
        $this->assertSame(Dataset::STATUS_FAILED, $dataset->status->value);
        $this->assertSame(0, $dataset->metrics()->count());
        $this->assertStringContainsString('RUSAK', session('kpi_failed')[0]);

        $this->assertSame(0, Indicator::count());
        $this->assertSame(0, PerformanceObservation::count());

        $this->assertCount(2, session('kpi_failed'));
        $this->assertStringContainsString(
            'penulisan tabel terstruktur gagal',
            implode(' | ', session('kpi_failed')),
        );
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');
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

    public function test_kegagalan_saat_archive_menggagalkan_seluruh_arsip_secara_atomis(): void
    {
        $owner = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $period = '2026-08-31';

        // Buat 2 dataset terdahulu berstatus READY
        $prev1 = Dataset::factory()->create([
            'division_id' => $owner->id,
            'status' => Dataset::STATUS_READY,
        ]);
        PerformanceMetric::factory()->create(['dataset_id' => $prev1->id, 'period' => $period]);

        $prev2 = Dataset::factory()->create([
            'division_id' => $owner->id,
            'status' => Dataset::STATUS_READY,
        ]);
        PerformanceMetric::factory()->create(['dataset_id' => $prev2->id, 'period' => $period]);

        // Simulasikan error saat update record kedua di dalam transaction
        try {
            Dataset::updating(function ($dataset) use ($prev2) {
                if ($dataset->id === $prev2->id && ($dataset->status === DatasetStatus::ARCHIVED || $dataset->status?->value === Dataset::STATUS_ARCHIVED)) {
                    throw new \RuntimeException('Simulasi database error saat archive');
                }
            });

            $response = $this->actingAs($admin)->post(route('datasets.kpi-import'), [
                'file' => $this->makeKpiReport(),
                'period' => $period,
                'confirm_archive' => 1,
            ]);

            $response->assertRedirect(route('datasets.create'));
            $response->assertSessionHas('error');
            $response->assertSessionMissing('success');

            // 1. Pastikan prev1 dan prev2 tetap READY karena rollback atomis
            $this->assertSame(Dataset::STATUS_READY, $prev1->fresh()->status->value);
            $this->assertSame(Dataset::STATUS_READY, $prev2->fresh()->status->value);

            // 2. Pastikan dataset baru dikompensasi menjadi FAILED dan metrik/observasinya bersih
            $newDataset = Dataset::whereNotIn('id', [$prev1->id, $prev2->id])->sole();
            $this->assertSame(Dataset::STATUS_FAILED, $newDataset->status->value);
            $this->assertSame(0, $newDataset->metrics()->count());
            $this->assertSame(0, $newDataset->observations()->count());
            $this->assertStringContainsString('Gagal mengarsipkan dataset sebelumnya', (string) $newDataset->error_message);

            // Pastikan dataset baru yang gagal tidak meninggalkan jejak ACTION_IMPORT
            $this->assertSame(
                0,
                AuditLog::where('action', AuditLog::ACTION_IMPORT)
                    ->where('auditable_type', Dataset::class)
                    ->where('auditable_id', $newDataset->id)
                    ->count()
            );

            // 3. Pastikan jumlah dataset READY pada divisi dan periode tersebut tidak bertambah
            $readyCount = Dataset::where('division_id', $owner->id)
                ->where('status', Dataset::STATUS_READY)
                ->whereHas('metrics', fn ($q) => $q->where('period', $period))
                ->count();
            $this->assertSame(2, $readyCount);
        } finally {
            Dataset::getEventDispatcher()->forget('eloquent.updating: '.Dataset::class);
        }
    }

    public function test_dashboard_tetap_menampilkan_data_lama_ketika_revisi_gagal_structured(): void
    {
        config(['kpi.read_model' => 'structured']);
        config(['kpi.visible_units' => ['TJBT']]);

        $owner = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $period = '2026-08-31';

        // 1. Import awal yang sukses
        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => $period,
        ]);

        // Verifikasi endpoint performance data mengembalikan NKO awal (105.5) dan dashboard render OK
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $responseBefore = $this->actingAs($admin)->getJson(route('performance.data'));
        $responseBefore->assertOk();
        $this->assertEquals([105.5], $responseBefore->json('series.0.data'));

        // 2. Import revisi yang gagal pada structured importer
        $this->mock(StructuredKpiImporter::class, function ($mock): void {
            $mock->shouldReceive('import')->andThrow(new \RuntimeException('Layout sheet korup'));
        });

        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => $period,
            'confirm_archive' => 1,
        ]);

        // 3. Verifikasi dashboard TETAP mengembalikan NKO awal (105.5) dan TIDAK kosong
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $responseAfter = $this->actingAs($admin)->getJson(route('performance.data'));
        $responseAfter->assertOk();
        $this->assertEquals(
            [105.5],
            $responseAfter->json('series.0.data'),
            'Dashboard harus tetap menyajikan data valid dataset terdahulu setelah revisi gagal.',
        );
    }

    public function test_import_kpi_sukses_mencatat_satu_action_import_terkonsolidasi_dengan_nol_noise_granular(): void
    {
        $owner = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ])->assertRedirect(route('datasets.create'));

        $dataset = Dataset::where('status', Dataset::STATUS_READY)->latest('id')->firstOrFail();

        // 1. Tepat 1 entri ACTION_IMPORT untuk dataset ini
        $importLogs = AuditLog::where('action', AuditLog::ACTION_IMPORT)
            ->where('auditable_type', Dataset::class)
            ->where('auditable_id', $dataset->id)
            ->get();
        $this->assertCount(1, $importLogs);

        $payload = $importLogs->first()->new_values;
        $this->assertArrayHasKey('rows_imported', $payload);
        $this->assertArrayHasKey('org_units_count', $payload);
        $this->assertArrayHasKey('indicators_count', $payload);
        $this->assertArrayHasKey('observations_count', $payload);
        $this->assertSame(7, $payload['rows_imported']);
        $this->assertGreaterThan(0, $payload['observations_count']);

        // 2. Nol log untuk model terstruktur individual
        $this->assertSame(0, AuditLog::where('auditable_type', 'App\\Models\\PerformanceObservation')->count());
        $this->assertSame(0, AuditLog::where('auditable_type', 'App\\Models\\Indicator')->count());
        $this->assertSame(0, AuditLog::where('auditable_type', 'App\\Models\\OrgUnit')->count());
    }

    public function test_impor_kpi_gagal_pada_structured_tidak_meninggalkan_action_import_pada_dataset_failed(): void
    {
        $owner = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        // Mock StructuredKpiImporter agar melempar exception di tengah proses
        $mockImporter = $this->mock(StructuredKpiImporter::class);
        $mockImporter->shouldReceive('import')
            ->andThrow(new \RuntimeException('Simulasi gagal tulis terstruktur'));

        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ])->assertRedirect(route('datasets.create'));

        $failedDataset = Dataset::where('status', Dataset::STATUS_FAILED)->latest('id')->firstOrFail();

        // Tidak boleh ada ACTION_IMPORT sama sekali untuk dataset yang gagal
        $this->assertSame(
            0,
            AuditLog::where('action', AuditLog::ACTION_IMPORT)
                ->where('auditable_type', Dataset::class)
                ->where('auditable_id', $failedDataset->id)
                ->count()
        );
    }

    public function test_failed_structured_import_cleans_up_stored_file_and_never_leaves_dataset_ready(): void
    {
        Storage::fake('local');

        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $mockImporter = $this->mock(StructuredKpiImporter::class);
        $mockImporter->shouldReceive('import')
            ->andThrow(new \RuntimeException('Simulasi gagal tulis terstruktur'));

        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ])->assertRedirect(route('datasets.create'));

        $dataset = Dataset::latest('id')->firstOrFail();
        $this->assertSame(DatasetStatus::FAILED, $dataset->status);
        $this->assertSame(0, $dataset->metrics()->count());
        $this->assertSame(0, $dataset->observations()->count());

        // File fisik di storage/app/private/datasets harus sudah dibersihkan
        Storage::disk('local')->assertMissing('datasets/'.$dataset->filename);
    }

    public function test_unexpected_failure_during_kpi_import_triggers_fail_safe_cleanup(): void
    {
        Storage::fake('local');

        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        // Simulasikan kegagalan tak terduga saat dataset bertransisi ke status READY (mis. deadlock / constraint DB)
        Dataset::updating(function (Dataset $d) {
            if ($d->status === Dataset::STATUS_READY || $d->status === DatasetStatus::READY) {
                throw new \RuntimeException('Simulasi kegagalan tak terduga aktivasi READY');
            }
        });

        $response = $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ]);

        $response->assertRedirect(route('datasets.create'));

        $dataset = Dataset::latest('id')->firstOrFail();
        $this->assertSame(DatasetStatus::FAILED, $dataset->status);
        $this->assertSame(0, $dataset->metrics()->count());
        $this->assertSame(0, $dataset->observations()->count());
        Storage::disk('local')->assertMissing('datasets/'.$dataset->filename);
    }

    public function test_imported_kpi_periods_detects_ready_dataset_having_only_structured_observations(): void
    {
        $owner = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        // Buat dataset READY yang hanya memiliki performance_observations (0 performance_metrics)
        $dataset = Dataset::factory()->create([
            'division_id' => $owner->id,
            'status' => DatasetStatus::READY,
        ]);
        $unit = OrgUnit::firstOrCreate(['name' => 'TJBT']);
        $indicator = Indicator::factory()->create(['org_unit_id' => $unit->id]);

        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $unit->id,
            'division_id' => $owner->id,
            'period' => '2026-08-31',
        ]);

        $this->assertSame(0, $dataset->metrics()->count());
        $this->assertSame(1, $dataset->observations()->count());

        // Periode harus terdeteksi di importedKpiPeriods (halaman create)
        $response = $this->actingAs($admin)->get(route('datasets.create'));
        $response->assertOk();
        $response->assertSee('2026-08-31');

        // Dan jika mengimpor periode yang sama tanpa confirm_archive, harus tertolak dengan error periode
        $postResponse = $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $this->makeKpiReport(),
            'period' => '2026-08-31',
        ]);
        $postResponse->assertSessionHasErrors('period');
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
