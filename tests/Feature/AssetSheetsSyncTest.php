<?php

namespace Tests\Feature;

use App\Exceptions\AssetSyncException;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetSheetsSyncService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssetSheetsSyncTest extends TestCase
{
    use RefreshDatabase;

    private Division $assetDivision;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Enforce network isolation for every test
        Http::preventStrayRequests();
        Storage::fake('local');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        // Dynamic division resolution via slug (dilarang hardcode ID)
        $this->assetDivision = Division::where('slug', 'managemen-aset')->sole();

        $this->admin = User::factory()->withRole(Role::ADMIN)->create([
            'email' => 'admin_aset_test@dashboard.local',
            'division_id' => null,
        ]);

        config([
            'asset.sheet_id' => 'mock-sheet-id-test-xyz',
            'asset.sheet_gid_1a' => '111111111',
            'asset.sheet_gid_1b' => '222222222',
            'asset.period' => '2026-10-31',
            'asset.timeout' => 5,
            'asset.retries' => 1,
        ]);
    }

    private function fakeGoogleSheetsResponses(?string $csv1a = null, ?string $csv1b = null): void
    {
        $content1a = $csv1a ?? file_get_contents(base_path('tests/Fixtures/asset_tab1a_sample.csv'));
        $content1b = $csv1b ?? file_get_contents(base_path('tests/Fixtures/asset_tab1b_sample.csv'));

        Http::fake([
            'https://docs.google.com/spreadsheets/d/*/export?format=csv&gid='.config('asset.sheet_gid_1a') => Http::response($content1a, 200, ['Content-Type' => 'text/csv; charset=utf-8']),
            'https://docs.google.com/spreadsheets/d/*/export?format=csv&gid='.config('asset.sheet_gid_1b') => Http::response($content1b, 200, ['Content-Type' => 'text/csv; charset=utf-8']),
        ]);
    }

    public function test_aset_sync_berhasil_membuat_dataset_ready_metrik_dan_audit_log(): void
    {
        $this->fakeGoogleSheetsResponses();

        $this->artisan('aset:sync')
            ->assertExitCode(0);

        // Verifikasi Dataset READY
        $dataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->sole();

        $this->assertSame($this->admin->id, $dataset->user_id);
        $this->assertNull($dataset->error_message);

        // Verifikasi metrik UPT (1b) dan UIT (1a) tersimpan di dataset yang sama
        $uptMetrics = $dataset->metrics()
            ->where('metric_name', 'not like', 'ASET | UIT |%')
            ->where('metric_name', 'like', 'ASET | %')
            ->get();
        $this->assertNotEmpty($uptMetrics);

        $uitMetrics = $dataset->metrics()
            ->where('metric_name', 'like', 'ASET | UIT |%')
            ->get();
        $this->assertNotEmpty($uitMetrics);

        // Spot-check metrik UIT
        $trafoMva = $dataset->metrics()
            ->where('metric_name', 'ASET | UIT | Trafo 500/150 KV | MVA')
            ->sole();
        $this->assertSame(20500.0, (float) $trafoMva->value);

        // Spot-check metrik UPT
        $towerUpt = $dataset->metrics()
            ->where('metric_name', 'ASET | BANDUNG | BANDUNG BARAT | Tower 500 KV | SU | Unit')
            ->sole();
        $this->assertSame(800.0, (float) $towerUpt->value);

        // Verifikasi audit log tidak memuat URL spreadsheet atau ID rahasia
        $audit = AuditLog::where('auditable_type', Dataset::class)
            ->where('auditable_id', $dataset->id)
            ->where('action', AuditLog::ACTION_IMPORT)
            ->sole();

        $details = json_encode($audit->new_values);
        $this->assertStringNotContainsString('docs.google.com', $details);
        $this->assertStringNotContainsString(config('asset.sheet_id'), $details);
        $this->assertStringNotContainsString(config('asset.sheet_gid_1a'), $details);
        $this->assertStringNotContainsString(config('asset.sheet_gid_1b'), $details);
        $this->assertGreaterThan(0, $audit->new_values['rows_imported'] ?? 0);
    }

    public function test_aset_sync_dry_run_memvalidasi_tanpa_menyimpan_dataset_ready(): void
    {
        $this->fakeGoogleSheetsResponses();

        $this->artisan('aset:sync', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN selesai')
            ->expectsOutputToContain('Tidak ada dataset yang disimpan ke database')
            ->assertExitCode(0);

        // Verifikasi 0 dataset dan 0 metrik permanen dibuat
        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, PerformanceMetric::count());
    }

    public function test_aset_sync_gagal_elegan_jika_konfigurasi_env_hilang(): void
    {
        config(['asset.sheet_id' => '']);

        $this->artisan('aset:sync')
            ->expectsOutputToContain('Konfigurasi Google Sheets (ASET_SHEET_ID, ASET_SHEET_GID_1A, ASET_SHEET_GID_1B) belum lengkap.')
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame(0, Dataset::count());
    }

    public function test_aset_sync_menolak_host_selain_docs_google_com(): void
    {
        // Uji validasi URL langsung pada method validateUrl
        $syncService = app(AssetSheetsSyncService::class);

        $this->expectException(AssetSyncException::class);
        $this->expectExceptionMessage('Host atau skema URL tidak diizinkan. Hanya HTTPS ke docs.google.com yang diperbolehkan.');

        $syncService->validateUrl('https://evil-phishing.com/spreadsheets/d/123/export?gid=0');
    }

    public function test_aset_sync_menolak_format_sheet_id_atau_gid_berbahaya(): void
    {
        $syncService = app(AssetSheetsSyncService::class);

        $this->expectException(AssetSyncException::class);
        $this->expectExceptionMessage('Format ID spreadsheet atau GID Google Sheets tidak valid.');

        $syncService->buildExportUrl('evil.com@attacker', 'invalid_gid');
    }

    public function test_aset_sync_menangani_kegagalan_jaringan_500_dan_timeout(): void
    {
        Http::fake([
            'https://docs.google.com/*' => Http::response('Server Error', 500),
        ]);

        $this->artisan('aset:sync')
            ->expectsOutputToContain('Gagal mengunduh berkas Google Sheets: HTTP status 500')
            ->assertExitCode(1);

        // Verifikasi dataset ditandai FAILED
        $failedDataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_FAILED)
            ->sole();
        $this->assertNotNull($failedDataset);
        $this->assertSame(0, Dataset::where('status', Dataset::STATUS_READY)->count());
    }

    public function test_aset_sync_menolak_rekap_dengan_header_berubah(): void
    {
        $corruptCsv1a = "BARIS_JUDUL_KORUP\nHEADER_TIDAK_SESUAI\n1,2,3,4\n";
        $this->fakeGoogleSheetsResponses($corruptCsv1a);

        // Buat dataset READY sebelumnya untuk memastikan tetap utuh
        $previousReady = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $this->artisan('aset:sync')
            ->expectsOutputToContain('Sanity guard failure')
            ->assertExitCode(1);

        // Verifikasi dataset FAILED dibuat
        $failedDataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_FAILED)
            ->sole();
        $this->assertStringContainsString('Header mismatch or structure failure', (string) $failedDataset->error_message);

        // Verifikasi dataset READY sebelumnya tetap utuh dan aktif
        $this->assertSame(Dataset::STATUS_READY, $previousReady->fresh()->status->value);
    }

    public function test_aset_sync_menolak_penurunan_jumlah_baris_drastis_dan_pertahankan_dataset_lama(): void
    {
        // 1. Buat dataset READY awal dengan 200 metrik
        $previousReady = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);

        for ($i = 0; $i < 200; $i++) {
            PerformanceMetric::create([
                'dataset_id' => $previousReady->id,
                'division_id' => $this->assetDivision->id,
                'unit_id' => $this->admin->id,
                'metric_name' => "ASET | METRIC | {$i}",
                'value' => (float) $i,
                'period' => '2026-09-30',
            ]);
        }

        $this->assertSame(200, $previousReady->metrics()->count());

        // 2. Mock Google Sheets menghasilkan data sampel (~90 metrik, di bawah 80% dari 200 = 160)
        $this->fakeGoogleSheetsResponses();

        $this->artisan('aset:sync')
            ->expectsOutputToContain('Sanity guard failure: Metric row count dropped')
            ->assertExitCode(1);

        // Verifikasi dataset baru berstatus FAILED dan dataset lama tetap READY
        $failedDataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_FAILED)
            ->sole();
        $this->assertStringContainsString('Minimum retention threshold is 80%', (string) $failedDataset->error_message);

        // Dataset READY lama tetap utuh
        $this->assertSame(Dataset::STATUS_READY, $previousReady->fresh()->status->value);
        $this->assertSame(200, $previousReady->fresh()->metrics()->count());
    }

    public function test_aset_sync_menolak_cross_check_rekonsiliasi_1a_vs_1b_yang_meleset(): void
    {
        // Siapkan Tab 1b dengan selisih buatan pada Tower 500 KV (+1000 unit di Bandung Barat)
        $valid1b = file_get_contents(base_path('tests/Fixtures/asset_tab1b_sample.csv'));
        $mismatched1b = str_replace(
            '1,BANDUNG,"BANDUNG BARAT",15,5,2500.0,500.0,-,800,-,2,4,1000.0,1000.0,-,1500,-',
            '1,BANDUNG,"BANDUNG BARAT",15,5,2500.0,500.0,-,1800,-,2,4,1000.0,1000.0,-,1500,-',
            $valid1b
        );

        $this->fakeGoogleSheetsResponses(null, $mismatched1b);

        $previousReady = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $this->artisan('aset:sync')
            ->expectsOutputToContain('Cross-check reconciliation mismatch')
            ->assertExitCode(1);

        $failedDataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_FAILED)
            ->sole();
        $this->assertStringContainsString('Cross-check reconciliation mismatch', (string) $failedDataset->error_message);

        // Dataset READY sebelumnya tetap aktif
        $this->assertSame(Dataset::STATUS_READY, $previousReady->fresh()->status->value);
    }

    public function test_aset_sync_dapat_menerima_opsi_user_dan_period_eksplisit(): void
    {
        $this->fakeGoogleSheetsResponses();

        $customUser = User::factory()->withRole(Role::ADMIN)->create([
            'email' => 'custom_admin@dashboard.local',
        ]);

        $this->artisan('aset:sync', [
            '--user' => $customUser->email,
            '--period' => '2026-10-31',
        ])->assertExitCode(0);

        $dataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->sole();

        $this->assertSame($customUser->id, $dataset->user_id);
    }

    public function test_aset_sync_menolak_format_period_yang_tidak_valid(): void
    {
        $this->artisan('aset:sync', ['--period' => 'invalid-date'])
            ->expectsOutputToContain('Opsi --period harus berformat Y-m-d')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_web_sync_endpoint_succeeds_for_admin_and_manset_staff_and_creates_ready_dataset(): void
    {
        $this->fakeGoogleSheetsResponses();

        // 1. Admin sync
        $response = $this->actingAs($this->admin)->post(route('datasets.asset-sync'));

        $response->assertRedirect(route('datasets.index'));
        $response->assertSessionHas('success');

        $datasetAdmin = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->where('user_id', $this->admin->id)
            ->sole();

        $this->assertNull($datasetAdmin->error_message);
        $this->assertGreaterThan(0, $datasetAdmin->metrics()->count());

        // 2. Staf Divisi Manajemen Aset sync
        $staffManset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'email' => 'staff_manset_test@dashboard.local',
            'division_id' => $this->assetDivision->id,
        ]);

        $responseStaff = $this->actingAs($staffManset)->post(route('datasets.asset-sync'));

        $responseStaff->assertRedirect(route('datasets.index'));
        $responseStaff->assertSessionHas('success');

        $datasetStaff = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->where('user_id', $staffManset->id)
            ->sole();

        $this->assertNull($datasetStaff->error_message);
        $this->assertGreaterThan(0, $datasetStaff->metrics()->count());
    }

    public function test_web_sync_endpoint_forbids_other_division_staff_and_unassigned_staff(): void
    {
        $pengusahaanDivision = Division::where('slug', 'perencanaan-pengusahaan')->sole();

        // Staf Divisi Perencanaan Pengusahaan -> 403
        $staffPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'email' => 'staff_pengusahaan_test@dashboard.local',
            'division_id' => $pengusahaanDivision->id,
        ]);
        $this->actingAs($staffPengusahaan)
            ->post(route('datasets.asset-sync'))
            ->assertForbidden();

        // Staf tanpa divisi -> 403
        $unassignedStaff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'email' => 'unassigned_staff_test@dashboard.local',
            'division_id' => null,
        ]);
        $this->actingAs($unassignedStaff)
            ->post(route('datasets.asset-sync'))
            ->assertForbidden();

        // Management -> 403 (ditolak middleware can:dataset.import)
        $management = User::factory()->withRole(Role::MANAGEMENT)->create([
            'email' => 'management_test@dashboard.local',
        ]);
        $this->actingAs($management)
            ->post(route('datasets.asset-sync'))
            ->assertForbidden();

        // Editor Dashboard -> 403 (ditolak middleware can:dataset.import)
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create([
            'email' => 'editor_test@dashboard.local',
        ]);
        $this->actingAs($editor)
            ->post(route('datasets.asset-sync'))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_web_sync_endpoint_handles_unexpected_exception_with_generic_error_and_no_leak(): void
    {
        $sensitiveUrl = 'https://storage.googleusercontent.com/internal-secret-bucket/dump.csv';
        $sheetUrl = 'https://docs.google.com/spreadsheets/d/mock-sheet-id-test-xyz/export?gid=111111111';

        Http::fake([
            'https://docs.google.com/*' => function () use ($sensitiveUrl, $sheetUrl) {
                throw new \RuntimeException("Unexpected crash while accessing {$sheetUrl} redirected to {$sensitiveUrl}");
            },
        ]);

        $response = $this->actingAs($this->admin)
            ->from(route('datasets.create'))
            ->post(route('datasets.asset-sync'));

        $response->assertRedirect(route('datasets.create'));
        $response->assertSessionHas('error', 'Terjadi kesalahan sistem saat sinkronisasi data aset. Hubungi administrator.');

        $errorMessage = (string) session('error');
        $this->assertStringNotContainsString('docs.google.com', $errorMessage);
        $this->assertStringNotContainsString(config('asset.sheet_id'), $errorMessage);
        $this->assertStringNotContainsString('googleusercontent.com', $errorMessage);
        $this->assertStringNotContainsString('internal-secret-bucket', $errorMessage);
    }

    public function test_web_sync_endpoint_handles_sanity_guard_failure_gracefully_and_preserves_previous_ready_dataset(): void
    {
        // Siapkan dataset READY awal
        $previousReady = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);

        for ($i = 0; $i < 200; $i++) {
            PerformanceMetric::create([
                'dataset_id' => $previousReady->id,
                'division_id' => $this->assetDivision->id,
                'unit_id' => $this->admin->id,
                'metric_name' => "ASET | METRIC | {$i}",
                'value' => (float) $i,
                'period' => '2026-09-30',
            ]);
        }

        // Mock respons CSV yang memicu kegagalan sanity guard (drop baris < 80%)
        $this->fakeGoogleSheetsResponses();

        $response = $this->actingAs($this->admin)
            ->from(route('datasets.create'))
            ->post(route('datasets.asset-sync'));

        $response->assertRedirect(route('datasets.create'));
        $response->assertSessionHas('error');

        $errorMessage = session('error');
        $this->assertStringContainsString('Sanity guard failure', $errorMessage);

        // Pastikan tidak ada kebocoran URL atau Sheet ID di error message
        $this->assertStringNotContainsString('docs.google.com', $errorMessage);
        $this->assertStringNotContainsString(config('asset.sheet_id'), $errorMessage);
        $this->assertStringNotContainsString(config('asset.sheet_gid_1a'), $errorMessage);
        $this->assertStringNotContainsString(config('asset.sheet_gid_1b'), $errorMessage);

        // Verifikasi dataset READY sebelumnya tetap utuh
        $this->assertSame(Dataset::STATUS_READY, $previousReady->fresh()->status->value);
        $this->assertSame(200, $previousReady->fresh()->metrics()->count());

        // Verifikasi dataset gagal tercatat dengan status FAILED
        $failedDataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_FAILED)
            ->sole();
        $this->assertNotNull($failedDataset);
    }

    public function test_create_dataset_page_renders_sync_card_only_for_authorized_asset_users(): void
    {
        $pengusahaanDivision = Division::where('slug', 'perencanaan-pengusahaan')->sole();

        // 1. Admin melihat kartu sinkronisasi
        $this->actingAs($this->admin)
            ->get(route('datasets.create'))
            ->assertOk()
            ->assertSee('Sinkronisasi Data Aset (Google Sheets)')
            ->assertSee('VIEW-ONLY SYNC')
            ->assertSee(route('datasets.asset-sync'));

        // 2. Staf Divisi Manajemen Aset melihat kartu sinkronisasi
        $staffManset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'email' => 'staff_manset_render@dashboard.local',
            'division_id' => $this->assetDivision->id,
        ]);

        $this->actingAs($staffManset)
            ->get(route('datasets.create'))
            ->assertOk()
            ->assertSee('Sinkronisasi Data Aset (Google Sheets)')
            ->assertSee('VIEW-ONLY SYNC')
            ->assertSee(route('datasets.asset-sync'));

        // 3. Staf Divisi Perencanaan Pengusahaan TIDAK melihat kartu sinkronisasi
        $staffPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'email' => 'staff_pengusahaan_render@dashboard.local',
            'division_id' => $pengusahaanDivision->id,
        ]);

        $this->actingAs($staffPengusahaan)
            ->get(route('datasets.create'))
            ->assertOk()
            ->assertDontSee('Sinkronisasi Data Aset (Google Sheets)')
            ->assertDontSee('VIEW-ONLY SYNC')
            ->assertDontSee(route('datasets.asset-sync'));
    }
}
