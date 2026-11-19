<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Exceptions\AssetSyncException;
use App\Jobs\SyncAssetSheetsJob;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetSheetsSyncService;
use App\Services\PublicDnsResolver;
use App\Support\DatasetFreshness;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->bindDnsMap([
            'docs.google.com' => ['142.250.190.46'],
            'doc-0k-12-sheets.googleusercontent.com' => ['142.250.190.50'],
            'doc-01-sheets.googleusercontent.com' => ['142.250.190.51'],
        ]);

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

    /**
     * @param  array<string, list<string>>  $hostToIps
     */
    private function bindDnsMap(array $hostToIps): void
    {
        $this->app->instance(PublicDnsResolver::class, new class($hostToIps) extends PublicDnsResolver
        {
            /**
             * @param  array<string, list<string>>  $hostToIps
             */
            public function __construct(private readonly array $hostToIps) {}

            public function resolve(string $host): array
            {
                return $this->hostToIps[strtolower($host)] ?? [];
            }
        });
    }

    private function fakeGoogleSheetsResponses(
        ?string $csv1a = null,
        ?string $csv1b = null,
        ?string $disposition1a = null,
        ?string $disposition1b = null,
    ): void {
        $content1a = $csv1a ?? file_get_contents(base_path('tests/Fixtures/asset_tab1a_sample.csv'));
        $content1b = $csv1b ?? file_get_contents(base_path('tests/Fixtures/asset_tab1b_sample.csv'));

        $headers1a = ['Content-Type' => 'text/csv; charset=utf-8'];
        if ($disposition1a !== null) {
            $headers1a['Content-Disposition'] = $disposition1a;
        }

        $headers1b = ['Content-Type' => 'text/csv; charset=utf-8'];
        if ($disposition1b !== null) {
            $headers1b['Content-Disposition'] = $disposition1b;
        }

        Http::fake([
            'https://docs.google.com/spreadsheets/d/*/export?format=csv&gid='.config('asset.sheet_gid_1a') => Http::response($content1a, 200, $headers1a),
            'https://docs.google.com/spreadsheets/d/*/export?format=csv&gid='.config('asset.sheet_gid_1b') => Http::response($content1b, 200, $headers1b),
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

    public function test_aset_sync_menolak_url_dengan_userinfo_atau_port_non_standar(): void
    {
        $syncService = app(AssetSheetsSyncService::class);

        foreach ([
            'https://docs.google.com@evil.com/spreadsheets/d/123/export?format=csv&gid=0',
            'https://user:pass@docs.google.com/spreadsheets/d/123/export?format=csv&gid=0',
            'https://docs.google.com:8443/spreadsheets/d/123/export?format=csv&gid=0',
            'http://docs.google.com/spreadsheets/d/123/export?format=csv&gid=0',
        ] as $url) {
            try {
                $syncService->validateUrl($url);
                $this->fail("Seharusnya menolak URL: {$url}");
            } catch (AssetSyncException $e) {
                $this->assertSame(
                    'Host atau skema URL tidak diizinkan. Hanya HTTPS ke docs.google.com yang diperbolehkan.',
                    $e->getMessage(),
                );
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function disallowedRedirectTargetProvider(): array
    {
        return [
            'external evil domain' => ['https://evil.com/steal.csv'],
            'google accounts login' => ['https://accounts.google.com/ServiceLogin'],
            'bare googleusercontent' => ['https://googleusercontent.com/evil.csv'],
            'http metadata endpoint' => ['http://169.254.169.254/latest/meta-data/'],
            'https metadata ip' => ['https://169.254.169.254/latest/meta-data/'],
            'userinfo host confusion' => ['https://docs.google.com@evil.com/steal.csv'],
            'backslash parser confusion' => ['https://evil.com\\@docs.google.com/steal.csv'],
            'protocol relative url' => ['//evil.com/protocol-relative.csv'],
            'non-standard port' => ['https://docs.google.com:8443/spreadsheets/d/123/export?format=csv'],
        ];
    }

    #[DataProvider('disallowedRedirectTargetProvider')]
    public function test_aset_sync_menolak_http_redirect_ke_host_atau_skema_tidak_diizinkan(string $disallowedTarget): void
    {
        Http::fake([
            'https://docs.google.com/*' => Http::response('', 302, ['Location' => $disallowedTarget]),
            '*' => Http::response('should never be reached', 200, ['Content-Type' => 'text/csv']),
        ]);

        $this->artisan('aset:sync')
            ->assertExitCode(1);

        $failedDataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_FAILED)
            ->sole();

        $this->assertStringContainsString('Redirect', (string) $failedDataset->error_message);
        $this->assertStringNotContainsString('evil.com', (string) $failedDataset->error_message);
        $this->assertStringNotContainsString('169.254.169.254', (string) $failedDataset->error_message);
        $this->assertStringNotContainsString('accounts.google.com', (string) $failedDataset->error_message);
        $this->assertSame(0, Dataset::where('status', Dataset::STATUS_READY)->count());
    }

    public function test_aset_sync_menolak_redirect_tanpa_location(): void
    {
        Http::fake([
            'https://docs.google.com/*' => Http::response('', 302),
        ]);

        $this->artisan('aset:sync')->assertExitCode(1);

        $failedMissing = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_FAILED)
            ->sole();
        $this->assertStringContainsString('Location', (string) $failedMissing->error_message);
        $this->assertSame(0, Dataset::where('status', Dataset::STATUS_READY)->count());
    }

    public function test_aset_sync_menolak_redirect_melebihi_batas_hop(): void
    {
        Http::fake([
            'https://docs.google.com/*' => Http::response('', 302, [
                'Location' => 'https://doc-01-sheets.googleusercontent.com/export-hop',
            ]),
            'https://doc-01-sheets.googleusercontent.com/*' => Http::response('', 302, [
                'Location' => 'https://doc-01-sheets.googleusercontent.com/export-hop-2',
            ]),
        ]);

        $this->artisan('aset:sync')->assertExitCode(1);

        $failedLoop = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_FAILED)
            ->sole();
        $this->assertStringContainsString('batas maksimum', (string) $failedLoop->error_message);
        $this->assertSame(0, Dataset::where('status', Dataset::STATUS_READY)->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonPublicIpProvider(): array
    {
        return [
            'ipv4 loopback' => ['127.0.0.1'],
            'ipv4 loopback upper' => ['127.255.255.254'],
            'ipv4 rfc1918 10/8' => ['10.0.0.1'],
            'ipv4 rfc1918 172.16/12' => ['172.16.0.1'],
            'ipv4 rfc1918 172.31/12' => ['172.31.255.255'],
            'ipv4 rfc1918 192.168/16' => ['192.168.1.1'],
            'ipv4 link-local metadata' => ['169.254.169.254'],
            'ipv4 cgnat lower' => ['100.64.0.1'],
            'ipv4 cgnat upper' => ['100.127.255.255'],
            'ipv4 unspecified' => ['0.0.0.0'],
            'ipv4 multicast' => ['224.0.0.1'],
            'ipv4 reserved' => ['240.0.0.1'],
            'ipv6 loopback' => ['::1'],
            'ipv6 unspecified' => ['::'],
            'ipv6 link-local' => ['fe80::1'],
            'ipv6 ula fc00' => ['fc00::1'],
            'ipv6 ula fd00' => ['fd12:3456::1'],
            'ipv6 multicast' => ['ff02::1'],
            'ipv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'ipv4-mapped metadata' => ['::ffff:169.254.169.254'],
            'ipv4-mapped rfc1918' => ['::ffff:10.0.0.1'],
            'ipv4-mapped cgnat' => ['::ffff:100.64.0.1'],
            'invalid ip string' => ['not-an-ip'],
        ];
    }

    #[DataProvider('nonPublicIpProvider')]
    public function test_public_dns_resolver_memblokir_ip_non_publik(string $ip): void
    {
        $resolver = new PublicDnsResolver;

        $this->assertFalse($resolver->isPublicIp($ip), "IP {$ip} seharusnya diblokir.");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function publicIpProvider(): array
    {
        return [
            'google public ipv4' => ['142.250.190.46'],
            'below 172.16 boundary' => ['172.15.255.255'],
            'above 172.31 boundary' => ['172.32.0.1'],
            'below cgnat boundary' => ['100.63.255.255'],
            'above cgnat boundary' => ['100.128.0.1'],
            'google public ipv6' => ['2607:f8b0:4005:80b::200e'],
        ];
    }

    #[DataProvider('publicIpProvider')]
    public function test_public_dns_resolver_mengizinkan_ip_publik(string $ip): void
    {
        $resolver = new PublicDnsResolver;

        $this->assertTrue($resolver->isPublicIp($ip), "IP publik {$ip} seharusnya diizinkan.");
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function nonPublicDnsResolutionProvider(): array
    {
        return [
            'empty dns' => [[]],
            'loopback ipv4' => [['127.0.0.1']],
            'metadata ipv4' => [['169.254.169.254']],
            'cgnat ipv4' => [['100.64.0.5']],
            'ipv6 loopback' => [['::1']],
            'ipv4-mapped metadata' => [['::ffff:169.254.169.254']],
            'mixed public and private' => [['142.250.190.46', '10.0.0.1']],
        ];
    }

    /**
     * @param  list<string>  $resolvedIps
     */
    #[DataProvider('nonPublicDnsResolutionProvider')]
    public function test_aset_sync_menolak_dns_awal_yang_mengarah_ke_ip_non_publik_atau_kosong(array $resolvedIps): void
    {
        $this->bindDnsMap(['docs.google.com' => $resolvedIps]);
        Http::fake();

        $this->artisan('aset:sync')->assertExitCode(1);

        Http::assertNothingSent();

        $failedDataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_FAILED)
            ->sole();

        $this->assertStringContainsString('DNS', (string) $failedDataset->error_message);
        $this->assertStringNotContainsString('169.254.169.254', (string) $failedDataset->error_message);
        $this->assertStringNotContainsString('127.0.0.1', (string) $failedDataset->error_message);
        $this->assertStringNotContainsString('10.0.0.1', (string) $failedDataset->error_message);
        $this->assertStringNotContainsString('100.64.0.5', (string) $failedDataset->error_message);
    }

    public function test_aset_sync_menolak_redirect_yang_dns_tujuannya_mengarah_ke_ip_privat(): void
    {
        $this->bindDnsMap([
            'docs.google.com' => ['142.250.190.46'],
            'doc-01-sheets.googleusercontent.com' => ['192.168.1.10'],
        ]);

        Http::fake([
            'https://docs.google.com/*' => Http::response('', 302, [
                'Location' => 'https://doc-01-sheets.googleusercontent.com/pub/internal.csv',
            ]),
            'https://doc-01-sheets.googleusercontent.com/*' => Http::response('should not execute', 200, [
                'Content-Type' => 'text/csv',
            ]),
        ]);

        $this->artisan('aset:sync')->assertExitCode(1);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'doc-01-sheets.googleusercontent.com'));

        $failedDataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_FAILED)
            ->sole();

        $this->assertStringContainsString('DNS', (string) $failedDataset->error_message);
        $this->assertStringNotContainsString('192.168.1.10', (string) $failedDataset->error_message);
    }

    public function test_aset_sync_menyematkan_ip_resolusi_ke_curl_resolve_dan_mematikan_auto_redirect(): void
    {
        $content1a = file_get_contents(base_path('tests/Fixtures/asset_tab1a_sample.csv'));
        $content1b = file_get_contents(base_path('tests/Fixtures/asset_tab1b_sample.csv'));
        $capturedOptions = [];

        Http::fake(function ($request, $options) use (&$capturedOptions, $content1a, $content1b) {
            $capturedOptions[$request->url()] = $options;

            if (str_contains($request->url(), 'docs.google.com')) {
                return Http::response('', 302, [
                    'Location' => 'https://doc-01-sheets.googleusercontent.com/pub/sheet.csv',
                ]);
            }

            $body = count($capturedOptions) <= 2 ? $content1a : $content1b;

            return Http::response($body, 200, ['Content-Type' => 'text/csv; charset=utf-8']);
        });

        $this->artisan('aset:sync')->assertExitCode(0);

        $this->assertNotEmpty($capturedOptions);
        foreach ($capturedOptions as $url => $options) {
            $this->assertFalse($options['allow_redirects'] ?? true, "Auto redirect harus mati untuk {$url}");
            $resolveList = $options['curl'][CURLOPT_RESOLVE] ?? [];
            $this->assertNotEmpty($resolveList, "CURLOPT_RESOLVE harus terisi untuk {$url}");

            if (str_contains($url, 'docs.google.com')) {
                $this->assertContains('docs.google.com:443:142.250.190.46', $resolveList);
            } else {
                $this->assertContains('doc-01-sheets.googleusercontent.com:443:142.250.190.51', $resolveList);
            }
        }
    }

    public function test_web_sync_endpoint_rejects_ssrf_redirect_safely_and_preserves_previous_ready_dataset(): void
    {
        $previousReady = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);

        Http::fake([
            'https://docs.google.com/*' => Http::response('', 302, [
                'Location' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
            ]),
            '*' => Http::response('metadata', 200),
        ]);

        $response = $this->actingAs($this->admin)
            ->from(route('datasets.create'))
            ->post(route('datasets.asset-sync'));

        $response->assertRedirect(route('datasets.create'));
        $response->assertSessionHas('error');

        $errorMessage = (string) session('error');
        $this->assertStringContainsString('Redirect', $errorMessage);
        $this->assertStringNotContainsString('169.254.169.254', $errorMessage);
        $this->assertStringNotContainsString(config('asset.sheet_id'), $errorMessage);
        $this->assertSame(Dataset::STATUS_READY, $previousReady->fresh()->status->value);
    }

    public function test_aset_sync_mengizinkan_redirect_aman_ke_googleusercontent_dan_relative_path(): void
    {
        $content1a = file_get_contents(base_path('tests/Fixtures/asset_tab1a_sample.csv'));
        $content1b = file_get_contents(base_path('tests/Fixtures/asset_tab1b_sample.csv'));

        Http::fake([
            'https://docs.google.com/spreadsheets/d/*/export?format=csv&gid='.config('asset.sheet_gid_1a') => Http::response('', 302, [
                'Location' => '/spreadsheets/d/resolved-rel/export?format=csv&gid=1a',
            ]),
            'https://docs.google.com/spreadsheets/d/resolved-rel/export?format=csv&gid=1a' => Http::response('', 307, [
                'Location' => 'https://doc-0k-12-sheets.googleusercontent.com/pub/tab1a.csv',
            ]),
            'https://doc-0k-12-sheets.googleusercontent.com/pub/tab1a.csv' => Http::response($content1a, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
            ]),
            'https://docs.google.com/spreadsheets/d/*/export?format=csv&gid='.config('asset.sheet_gid_1b') => Http::response('', 302, [
                'Location' => 'https://doc-0k-12-sheets.googleusercontent.com/pub/tab1b.csv',
            ]),
            'https://doc-0k-12-sheets.googleusercontent.com/pub/tab1b.csv' => Http::response($content1b, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
            ]),
        ]);

        $this->artisan('aset:sync')->assertExitCode(0);

        $this->assertSame(1, Dataset::where('division_id', $this->assetDivision->id)->where('status', Dataset::STATUS_READY)->count());
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
            ->assertSee('Data Rincian 8 UPT')
            ->assertSee('Data Induk UIT JBT')
            ->assertSee(route('datasets.asset-sync'))
            ->assertDontSee('VIEW-ONLY SYNC')
            ->assertDontSee('Tab 1a')
            ->assertDontSee('Tab 1b');

        // 2. Staf Divisi Manajemen Aset melihat kartu sinkronisasi
        $staffManset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'email' => 'staff_manset_render@dashboard.local',
            'division_id' => $this->assetDivision->id,
        ]);

        $this->actingAs($staffManset)
            ->get(route('datasets.create'))
            ->assertOk()
            ->assertSee('Sinkronisasi Data Aset (Google Sheets)')
            ->assertSee('Data Rincian 8 UPT')
            ->assertSee('Data Induk UIT JBT')
            ->assertSee(route('datasets.asset-sync'))
            ->assertDontSee('VIEW-ONLY SYNC')
            ->assertDontSee('Tab 1a')
            ->assertDontSee('Tab 1b');

        // 3. Staf Divisi Perencanaan Pengusahaan TIDAK melihat kartu sinkronisasi
        $staffPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'email' => 'staff_pengusahaan_render@dashboard.local',
            'division_id' => $pengusahaanDivision->id,
        ]);

        $this->actingAs($staffPengusahaan)
            ->get(route('datasets.create'))
            ->assertOk()
            ->assertDontSee('Sinkronisasi Data Aset (Google Sheets)')
            ->assertDontSee('Data Rincian 8 UPT')
            ->assertDontSee('Data Induk UIT JBT')
            ->assertDontSee('VIEW-ONLY SYNC')
            ->assertDontSee(route('datasets.asset-sync'));
    }

    public function test_dataset_show_renders_asset_sync_reconciliation_and_8_upt_matrix_with_zero_delta(): void
    {
        $this->fakeGoogleSheetsResponses();

        $this->artisan('aset:sync')->assertExitCode(0);

        $dataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->sole();

        $response = $this->actingAs($this->admin)
            ->get(route('datasets.show', $dataset));

        $response->assertOk()
            ->assertSee('Rekonsiliasi Data Aset UIT JBT vs 8 UPT')
            ->assertSee('Total Baris Data')
            ->assertSee('Status Rekonsiliasi')
            ->assertSee('Total Tower UIT vs 8 UPT')
            ->assertSee('Total Transmisi kms UIT vs 8 UPT')
            ->assertSee('Total Trafo MVA UIT vs 8 UPT')
            ->assertSee('Rekonsiliasi UIT vs 8 UPT')
            ->assertSee('Distribusi 8 UPT')
            ->assertSee('Rekap Induk UIT')
            ->assertSee('Induk UIT JBT')
            ->assertSee('Akumulasi 8 UPT')
            ->assertSee('Sinkronisasi Google Sheets')
            ->assertSee('Rekonsiliasi Sinkron')
            ->assertSee('BANDUNG')
            ->assertSee('BEKASI')
            ->assertSee('BOGOR')
            ->assertSee('CIREBON')
            ->assertSee('KARAWANG')
            ->assertSee('PURWOKERTO')
            ->assertSee('SALATIGA')
            ->assertSee('SEMARANG')
            ->assertSee('Total 8 UPT')
            ->assertSee('Total Tower SU')
            ->assertSee('Total Transmisi SU kms')
            ->assertSee('Total Kapasitas Trafo MVA')
            ->assertSee('20.500,0')
            ->assertDontSee('20.500,00')
            ->assertSee('Rincian Metrik Data')
            ->assertSee('Detektor, Bukan Generator')
            ->assertSee('Read-Only')
            ->assertSee('<details', false)
            ->assertSee('id="dataset-raw-metrics-details"', false)
            ->assertDontSee('application/octet-stream')
            ->assertDontSee('Tab 1a')
            ->assertDontSee('Tab 1b')
            ->assertDontSee('TAB 1A')
            ->assertDontSee('TAB 1B')
            ->assertDontSee('Total Baris Sync')
            ->assertDontSee('Data Sync Aset')
            ->assertDontSee('>Edit<', false);

        $this->assertSame('application/octet-stream', $dataset->mime_type);
        $this->assertDoesNotMatchRegularExpression(
            '/<details\b[^>]*id="dataset-raw-metrics-details"[^>]*\bopen\b/i',
            (string) $response->getContent(),
        );

        $pageResponse = $this->actingAs($this->admin)
            ->get(route('datasets.show', ['dataset' => $dataset, 'page' => 2]))
            ->assertOk();
        $this->assertMatchesRegularExpression(
            '/<details\b[^>]*id="dataset-raw-metrics-details"[^>]*\bopen\b/i',
            (string) $pageResponse->getContent(),
        );

        $this->assertTrue($response->viewData('isAssetDataset'));

        $analysis = $response->viewData('assetAnalysis');
        $this->assertIsArray($analysis);
        $this->assertGreaterThan(0, $analysis['summary']['reconciled_count']);
        $this->assertSame(0, $analysis['summary']['mismatched_count']);
        $this->assertSame(
            $analysis['summary']['reconciled_count'],
            $analysis['summary']['matched_count'],
        );

        foreach ($analysis['reconciliation'] as $row) {
            $this->assertTrue($row['is_matched'], "Expected zero delta for {$row['label']}, got {$row['delta']}");
            $this->assertSame(0.0, $row['delta']);
        }

        // Index page marks synced asset dataset with inspection indicator
        $this->actingAs($this->admin)
            ->get(route('datasets.index'))
            ->assertOk()
            ->assertSee('Inspeksi Aset');
    }

    public function test_dataset_show_detects_nonzero_delta_in_asset_reconciliation_and_enforces_403_division_isolation(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'user_id' => $this->admin->id,
            'status' => Dataset::STATUS_READY,
            'original_name' => 'asset_mismatch_check.csv',
        ]);

        PerformanceMetric::create([
            'dataset_id' => $dataset->id,
            'division_id' => $this->assetDivision->id,
            'unit_id' => $this->admin->id,
            'metric_name' => 'ASET | UIT | Trafo 500/150 KV | MVA',
            'value' => 20500.00,
            'period' => '2026-10-31',
        ]);
        PerformanceMetric::create([
            'dataset_id' => $dataset->id,
            'division_id' => $this->assetDivision->id,
            'unit_id' => $this->admin->id,
            'metric_name' => 'ASET | BANDUNG | BANDUNG BARAT | Trafo 500/150 KV | MVA',
            'value' => 19000.00,
            'period' => '2026-10-31',
        ]);
        PerformanceMetric::create([
            'dataset_id' => $dataset->id,
            'division_id' => $this->assetDivision->id,
            'unit_id' => $this->admin->id,
            'metric_name' => 'ASET | UIT | Trafo 150/70 KV | BANK',
            'value' => 10.00,
            'period' => '2026-10-31',
        ]);
        PerformanceMetric::create([
            'dataset_id' => $dataset->id,
            'division_id' => $this->assetDivision->id,
            'unit_id' => $this->admin->id,
            'metric_name' => 'ASET | BEKASI | BEKASI | Trafo Tenaga 150 kV | Trafo 150/70 KV | Bank',
            'value' => 8.00,
            'period' => '2026-10-31',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('datasets.show', $dataset));

        $response->assertOk()
            ->assertSee('Rekonsiliasi Data Aset UIT JBT vs 8 UPT')
            ->assertSee('Selisih')
            ->assertSee('1.500,0')
            ->assertSee('2,0')
            ->assertDontSee('1.500,00');

        $analysis = $response->viewData('assetAnalysis');
        $this->assertSame(3, $analysis['summary']['mismatched_count']);
        $this->assertSame(0, $analysis['summary']['matched_count']);

        $byLabel = collect($analysis['reconciliation'])->keyBy('label');
        $this->assertSame(1500.0, $byLabel['Total Kapasitas Trafo MVA']['delta']);
        $this->assertFalse($byLabel['Total Kapasitas Trafo MVA']['is_matched']);
        $this->assertSame(1500.0, $byLabel['Trafo 500/150 KV | MVA']['delta']);
        $this->assertSame(2.0, $byLabel['Trafo 150/70 KV | BANK']['delta']);

        // Staf Manajemen Aset diizinkan melihat
        $staffManset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $this->assetDivision->id,
        ]);
        $this->actingAs($staffManset)
            ->get(route('datasets.show', $dataset))
            ->assertOk();

        // Staf divisi lain dan staf tanpa divisi ditolak 403
        $otherDivision = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $staffOther = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $otherDivision->id,
        ]);
        $this->actingAs($staffOther)
            ->get(route('datasets.show', $dataset))
            ->assertForbidden();

        $staffNoDiv = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => null,
        ]);
        $this->actingAs($staffNoDiv)
            ->get(route('datasets.show', $dataset))
            ->assertForbidden();
    }

    public function test_sync_periode_sama_mengarsipkan_dataset_ready_lama_tanpa_menyentuh_periode_atau_divisi_lain(): void
    {
        $this->fakeGoogleSheetsResponses();

        $otherDivision = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $otherDivisionDataset = Dataset::factory()->create([
            'division_id' => $otherDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);
        PerformanceMetric::create([
            'dataset_id' => $otherDivisionDataset->id,
            'division_id' => $otherDivision->id,
            'unit_id' => $this->admin->id,
            'metric_name' => 'TJBT | NKO',
            'value' => 105.5,
            'period' => '2026-10-31',
        ]);

        // 1. Dataset aset READY periode berbeda (2026-09-30) harus tetap READY
        $septDataset = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);
        for ($i = 0; $i < 50; $i++) {
            PerformanceMetric::create([
                'dataset_id' => $septDataset->id,
                'division_id' => $this->assetDivision->id,
                'unit_id' => $this->admin->id,
                'metric_name' => "ASET | BANDUNG | BANDUNG BARAT | Metric {$i}",
                'value' => (float) ($i + 1),
                'period' => '2026-09-30',
            ]);
        }

        // 2. Sync pertama periode 2026-10-31
        $this->actingAs($this->admin)->post(route('datasets.asset-sync'))
            ->assertRedirect(route('datasets.index'))
            ->assertSessionHas('success');

        $firstOctDataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->whereHas('metrics', fn ($q) => $q->where('period', '2026-10-31'))
            ->sole();

        // 3. Sync kedua periode 2026-10-31 harus mengarsipkan dataset 2026-10-31 pertama
        $this->actingAs($this->admin)->post(route('datasets.asset-sync'))
            ->assertRedirect(route('datasets.index'))
            ->assertSessionHas('success');

        $secondOctDataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->whereHas('metrics', fn ($q) => $q->where('period', '2026-10-31'))
            ->sole();

        $this->assertNotSame($firstOctDataset->id, $secondOctDataset->id);
        $this->assertSame(Dataset::STATUS_ARCHIVED, $firstOctDataset->fresh()->status->value);
        $this->assertSame(Dataset::STATUS_READY, $secondOctDataset->fresh()->status->value);
        $this->assertSame(Dataset::STATUS_READY, $septDataset->fresh()->status->value);
        $this->assertSame(Dataset::STATUS_READY, $otherDivisionDataset->fresh()->status->value);
    }

    public function test_sync_gagal_sanity_guard_pada_periode_sama_membuat_failed_dan_pertahankan_ready_lama(): void
    {
        $content1a = file_get_contents(base_path('tests/Fixtures/asset_tab1a_sample.csv'));
        $valid1b = file_get_contents(base_path('tests/Fixtures/asset_tab1b_sample.csv'));
        $mismatched1b = str_replace(
            '1,BANDUNG,"BANDUNG BARAT",15,5,2500.0,500.0,-,800,-,2,4,1000.0,1000.0,-,1500,-',
            '1,BANDUNG,"BANDUNG BARAT",15,5,2500.0,500.0,-,1800,-,2,4,1000.0,1000.0,-,1500,-',
            $valid1b
        );
        $useMismatched1b = false;

        Http::fake([
            'https://docs.google.com/spreadsheets/d/*/export?format=csv&gid='.config('asset.sheet_gid_1a') => fn () => Http::response($content1a, 200, ['Content-Type' => 'text/csv; charset=utf-8']),
            'https://docs.google.com/spreadsheets/d/*/export?format=csv&gid='.config('asset.sheet_gid_1b') => function () use (&$useMismatched1b, $mismatched1b, $valid1b) {
                return Http::response($useMismatched1b ? $mismatched1b : $valid1b, 200, ['Content-Type' => 'text/csv; charset=utf-8']);
            },
        ]);

        $this->actingAs($this->admin)->post(route('datasets.asset-sync'))
            ->assertRedirect(route('datasets.index'))
            ->assertSessionHas('success');

        $activeDataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->sole();

        // Sync kedua pada periode yang sama gagal cross-check rekonsiliasi
        $useMismatched1b = true;

        $response = $this->actingAs($this->admin)
            ->from(route('datasets.create'))
            ->post(route('datasets.asset-sync'));

        $response->assertRedirect(route('datasets.create'))
            ->assertSessionHas('error');

        $failedDataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_FAILED)
            ->sole();
        $this->assertStringContainsString('Cross-check reconciliation mismatch', (string) $failedDataset->error_message);
        $this->assertSame(Dataset::STATUS_READY, $activeDataset->fresh()->status->value);
    }

    public function test_web_sync_executes_synchronously_without_pushing_to_queue_and_returns_ready_response(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $this->fakeGoogleSheetsResponses();

        $response = $this->actingAs($this->admin)->post(route('datasets.asset-sync'));

        $response->assertRedirect(route('datasets.index'));
        $response->assertSessionHas('success');
        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('jobs')->count());

        $ready = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->sole();

        $this->assertSame($this->admin->id, $ready->user_id);
        $this->assertNull($ready->error_message);
        $this->assertGreaterThan(0, $ready->size);
        $this->assertGreaterThan(0, $ready->metrics()->count());
        $this->assertTrue(
            AuditLog::where('auditable_type', Dataset::class)
                ->where('auditable_id', $ready->id)
                ->where('action', AuditLog::ACTION_IMPORT)
                ->exists()
        );

        // Endpoint JSON langsung mengembalikan HTTP 200 dengan status READY tanpa masuk antrean
        $jsonResponse = $this->actingAs($this->admin)->postJson(route('datasets.asset-sync'));
        $jsonResponse->assertOk()
            ->assertJsonPath('dataset.status', Dataset::STATUS_READY);
        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_web_sync_with_database_queue_marks_dataset_failed_synchronously_with_sanitized_error_on_failure(): void
    {
        config(['queue.default' => 'database']);
        $content1a = (string) file_get_contents(base_path('tests/Fixtures/asset_tab1a_sample.csv'));
        $content1b = (string) file_get_contents(base_path('tests/Fixtures/asset_tab1b_sample.csv'));
        $shouldFail = true;

        Http::fake([
            'https://docs.google.com/spreadsheets/d/*/export?format=csv&gid='.config('asset.sheet_gid_1a') => function () use (&$shouldFail, $content1a) {
                return $shouldFail
                    ? Http::response('Server Error', 500)
                    : Http::response($content1a, 200, ['Content-Type' => 'text/csv; charset=utf-8']);
            },
            'https://docs.google.com/spreadsheets/d/*/export?format=csv&gid='.config('asset.sheet_gid_1b') => function () use (&$shouldFail, $content1b) {
                return $shouldFail
                    ? Http::response('Server Error', 500)
                    : Http::response($content1b, 200, ['Content-Type' => 'text/csv; charset=utf-8']);
            },
        ]);

        $jsonResponse = $this->actingAs($this->admin)->postJson(route('datasets.asset-sync'));

        $jsonResponse->assertStatus(422);
        $this->assertSame(0, DB::table('jobs')->count());

        $failed = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_FAILED)
            ->sole();

        $this->assertNotNull($failed->error_message);
        $this->assertStringNotContainsString('docs.google.com', (string) $failed->error_message);
        $this->assertStringNotContainsString((string) config('asset.sheet_id'), (string) $failed->error_message);

        // Lock harus dilepas setelah kegagalan sehingga percobaan ulang berikutnya dapat langsung berjalan
        $shouldFail = false;
        $retryResponse = $this->actingAs($this->admin)->postJson(route('datasets.asset-sync'));
        $retryResponse->assertOk()
            ->assertJsonPath('dataset.status', Dataset::STATUS_READY);
    }

    public function test_web_sync_prevents_duplicate_dispatch_while_active_sync_is_in_progress(): void
    {
        Queue::fake();

        $active = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'user_id' => $this->admin->id,
            'status' => Dataset::STATUS_UPLOADING,
            'original_name' => AssetSheetsSyncService::DISPLAY_NAME,
        ]);

        $response = $this->actingAs($this->admin)
            ->from(route('datasets.create'))
            ->post(route('datasets.asset-sync'));

        $response->assertRedirect(route('datasets.index'));
        $response->assertSessionHas('warning');
        Queue::assertNothingPushed();

        $this->actingAs($this->admin)
            ->postJson(route('datasets.asset-sync'))
            ->assertStatus(409);

        $this->assertSame(Dataset::STATUS_UPLOADING, $active->fresh()->status->value);

        // Proteksi anti-double-submit berbasis atomic Cache::lock menolak request paralel
        $active->update(['status' => DatasetStatus::ARCHIVED]);
        $lock = Cache::lock("asset-sync:division:{$this->assetDivision->id}", 60);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($this->admin)
                ->postJson(route('datasets.asset-sync'))
                ->assertStatus(409);

            $this->actingAs($this->admin)
                ->post(route('datasets.asset-sync'))
                ->assertRedirect(route('datasets.index'))
                ->assertSessionHas('warning');
        } finally {
            $lock->release();
        }
    }

    public function test_web_sync_marks_stale_uploading_placeholder_failed_and_executes_new_sync(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $this->fakeGoogleSheetsResponses();

        $stale = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'user_id' => $this->admin->id,
            'status' => Dataset::STATUS_UPLOADING,
            'original_name' => AssetSheetsSyncService::DISPLAY_NAME,
        ]);

        DB::table('datasets')
            ->where('id', $stale->id)
            ->update([
                'created_at' => now()->subMinutes(15),
                'updated_at' => now()->subMinutes(15),
            ]);

        $response = $this->actingAs($this->admin)->post(route('datasets.asset-sync'));

        $response->assertRedirect(route('datasets.index'));
        $response->assertSessionHas('success');

        $this->assertSame(Dataset::STATUS_FAILED, $stale->fresh()->status->value);
        $this->assertNotNull($stale->fresh()->error_message);
        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('jobs')->count());

        $ready = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->sole();
        $this->assertGreaterThan(0, $ready->metrics()->count());
    }

    public function test_sync_asset_sheets_job_failed_hook_marks_dataset_failed_with_sanitized_message(): void
    {
        $placeholder = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'user_id' => $this->admin->id,
            'status' => Dataset::STATUS_VALIDATING,
            'original_name' => AssetSheetsSyncService::DISPLAY_NAME,
        ]);

        $job = new SyncAssetSheetsJob($placeholder->id, $this->admin->id);
        $job->failed(new \RuntimeException('Crash accessing https://docs.google.com/spreadsheets/d/'.config('asset.sheet_id').'/export'));

        $fresh = $placeholder->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(Dataset::STATUS_FAILED, $fresh->status->value);
        $this->assertNotNull($fresh->error_message);
        $this->assertStringNotContainsString('docs.google.com', (string) $fresh->error_message);
        $this->assertStringNotContainsString((string) config('asset.sheet_id'), (string) $fresh->error_message);
    }

    public function test_datasets_index_displays_processing_banner_and_client_friendly_labels_without_technical_jargon(): void
    {
        Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'user_id' => $this->admin->id,
            'status' => Dataset::STATUS_UPLOADING,
            'original_name' => AssetSheetsSyncService::DISPLAY_NAME,
        ]);

        Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'user_id' => $this->admin->id,
            'status' => Dataset::STATUS_VALIDATING,
            'original_name' => 'Sinkronisasi Validasi Berjalan',
        ]);

        $response = $this->actingAs($this->admin)->get(route('datasets.index'));

        $response->assertOk()
            ->assertSee('id="dataset-processing-banner"', false)
            ->assertSee(DatasetStatus::UPLOADING->label())
            ->assertSee(DatasetStatus::VALIDATING->label())
            ->assertDontSee('queue:work')
            ->assertDontSee('Laravel Queue')
            ->assertDontSee('SyncAssetSheetsJob');

        $staffManset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'email' => 'staff_manset_banner@dashboard.local',
            'division_id' => $this->assetDivision->id,
        ]);

        $this->actingAs($staffManset)
            ->get(route('datasets.create'))
            ->assertOk()
            ->assertSee('id="asset-sync-active-notice"', false);
    }

    public function test_cli_aset_sync_executes_synchronously_without_dispatching_queue_job(): void
    {
        Queue::fake();
        $this->fakeGoogleSheetsResponses();

        $this->artisan('aset:sync')->assertExitCode(0);

        Queue::assertNothingPushed();

        $dataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->sole();

        $this->assertGreaterThan(0, $dataset->metrics()->count());
    }

    public function test_scheduler_registers_aset_sync_with_default_cron_override_and_without_overlapping(): void
    {
        $this->assertFalse(config('asset.schedule_enabled'));
        $this->assertSame('0 6 * * *', config('asset.schedule_cron'));

        $this->artisan('schedule:list')
            ->expectsOutputToContain('aset:sync')
            ->assertExitCode(0);

        $this->assertSame(0, Artisan::call('schedule:list', ['--json' => true]));
        $scheduledItems = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $listedEvent = collect($scheduledItems)->first(
            fn (array $item): bool => str_contains((string) ($item['command'] ?? ''), 'aset:sync')
        );
        $this->assertIsArray($listedEvent);
        $this->assertSame('0 6 * * *', $listedEvent['expression']);

        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);
        $event = collect($schedule->events())->first(
            fn (ScheduledEvent $item): bool => str_contains((string) $item->command, 'aset:sync')
        );

        $this->assertInstanceOf(ScheduledEvent::class, $event);
        $this->assertSame('0 6 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(15, $event->expiresAt);

        config(['asset.schedule_cron' => '15 7 * * *']);
        ScheduleFacade::clearResolvedInstance(Schedule::class);
        $this->app->forgetInstance(Schedule::class);
        require base_path('routes/console.php');

        /** @var Schedule $overriddenSchedule */
        $overriddenSchedule = app(Schedule::class);
        $overriddenEvent = collect($overriddenSchedule->events())->first(
            fn (ScheduledEvent $item): bool => str_contains((string) $item->command, 'aset:sync')
        );

        $this->assertInstanceOf(ScheduledEvent::class, $overriddenEvent);
        $this->assertSame('15 7 * * *', $overriddenEvent->expression);
    }

    public function test_scheduler_skips_aset_sync_and_sends_no_http_when_schedule_disabled(): void
    {
        config(['asset.schedule_enabled' => false]);
        $this->fakeGoogleSheetsResponses();

        try {
            Carbon::setTestNow('2026-11-16 06:00:00');

            $this->artisan('schedule:run')
                ->assertExitCode(0);

            /** @var Schedule $schedule */
            $schedule = app(Schedule::class);
            $event = collect($schedule->events())->first(
                fn (ScheduledEvent $item): bool => str_contains((string) $item->command, 'aset:sync')
            );

            $this->assertInstanceOf(ScheduledEvent::class, $event);
            $this->assertTrue($event->isDue($this->app));
            $this->assertFalse($event->filtersPass($this->app));

            Http::assertNothingSent();
            $this->assertSame(0, Dataset::count());
            $this->assertSame(0, AuditLog::where('action', AuditLog::ACTION_IMPORT)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_scheduler_passes_due_and_overlap_checks_when_enabled_and_aset_sync_produces_ready_dataset(): void
    {
        config(['asset.schedule_enabled' => true]);
        $this->fakeGoogleSheetsResponses();

        try {
            $this->artisan('schedule:list')->assertExitCode(0);

            /** @var Schedule $schedule */
            $schedule = app(Schedule::class);
            $event = collect($schedule->events())->first(
                fn (ScheduledEvent $item): bool => str_contains((string) $item->command, 'aset:sync')
            );

            $this->assertInstanceOf(ScheduledEvent::class, $event);

            Carbon::setTestNow('2026-11-16 05:59:00');
            $this->assertFalse($event->isDue($this->app));

            Carbon::setTestNow('2026-11-16 06:00:00');
            $this->assertTrue($event->isDue($this->app));
            $this->assertTrue($event->filtersPass($this->app));

            $this->assertTrue($event->mutex->create($event));
            try {
                $this->assertFalse($event->filtersPass($this->app));
            } finally {
                $event->mutex->forget($event);
            }

            $this->assertTrue($event->filtersPass($this->app));

            $this->artisan('aset:sync')->assertExitCode(0);

            $dataset = Dataset::where('division_id', $this->assetDivision->id)
                ->where('status', Dataset::STATUS_READY)
                ->sole();

            $this->assertNull($dataset->error_message);
            $this->assertGreaterThan(0, $dataset->metrics()->count());
            $this->assertTrue(
                AuditLog::where('auditable_type', Dataset::class)
                    ->where('auditable_id', $dataset->id)
                    ->where('action', AuditLog::ACTION_IMPORT)
                    ->exists()
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function contentDispositionFilenameProvider(): array
    {
        $longName = str_repeat('REKAP ASET UIT JBT ', 10);

        return [
            'rfc5987 utf-8 diprioritaskan atas filename biasa' => [
                'attachment; filename="fallback.csv"; filename*=UTF-8\'\'REKAP%20ASET%20UIT%20JBT%20-%201b.%20REKAP%20PER%20UPT.csv',
                'REKAP ASET UIT JBT - 1b. REKAP PER UPT',
            ],
            'filename berkutip dengan ekstensi csv' => [
                'attachment; filename="Rekap Aset Oktober 2026.CSV"',
                'Rekap Aset Oktober 2026',
            ],
            'filename tanpa kutip' => [
                'attachment; filename=Rekap_Aset_UIT.csv',
                'Rekap_Aset_UIT',
            ],
            'header null atau kosong menghasilkan null' => [
                null,
                null,
            ],
            'header whitespace menghasilkan null' => [
                '   ',
                null,
            ],
            'path traversal dibersihkan ke basename' => [
                'attachment; filename="../../etc/passwd.csv"',
                'passwd',
            ],
            'crlf dan karakter kontrol dihapus' => [
                "attachment; filename=\"Rekap\r\n\tAset  UIT.csv\"",
                'Rekap Aset UIT',
            ],
            'nama lebih dari 120 karakter dipotong ke 120 karakter' => [
                'attachment; filename="'.$longName.'.csv"',
                mb_substr(trim($longName), 0, 120),
            ],
        ];
    }

    #[DataProvider('contentDispositionFilenameProvider')]
    public function test_extract_filename_mengurai_variasi_content_disposition(?string $header, ?string $expected): void
    {
        $this->assertSame($expected, AssetSheetsSyncService::extractFilename($header));
    }

    public function test_extract_filename_menolak_nama_yang_memuat_sheet_id_gid_atau_url(): void
    {
        $sheetId = (string) config('asset.sheet_id');
        $gid1a = (string) config('asset.sheet_gid_1a');
        $gid1b = (string) config('asset.sheet_gid_1b');

        $this->assertNull(AssetSheetsSyncService::extractFilename("attachment; filename=\"Export {$sheetId}.csv\""));
        $this->assertNull(AssetSheetsSyncService::extractFilename("attachment; filename=\"Sheet {$gid1a}.csv\""));
        $this->assertNull(AssetSheetsSyncService::extractFilename("attachment; filename=\"Sheet {$gid1b}.csv\""));
        $this->assertNull(AssetSheetsSyncService::extractFilename('attachment; filename="https://docs.google.com/spreadsheets/d/abc.csv"'));
        $this->assertNull(AssetSheetsSyncService::extractFilename('attachment; filename="1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms.csv"'));
    }

    public function test_sync_menamai_dataset_dari_judul_spreadsheet_bila_prefix_sama(): void
    {
        $this->fakeGoogleSheetsResponses(
            null,
            null,
            "attachment; filename=\"fallback.csv\"; filename*=UTF-8''REKAP%20ASET%20UIT%20JBT%20-%201a.%20REKAP%20UIT.csv",
            "attachment; filename=\"fallback.csv\"; filename*=UTF-8''REKAP%20ASET%20UIT%20JBT%20-%201b.%20REKAP%20PER%20UPT.csv",
        );

        $this->artisan('aset:sync')->assertExitCode(0);

        $dataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->sole();

        $this->assertSame('REKAP ASET UIT JBT', $dataset->original_name);
    }

    public function test_sync_memakai_nama_tab_1b_bila_judul_berbeda(): void
    {
        $this->fakeGoogleSheetsResponses(
            null,
            null,
            'attachment; filename="Spreadsheet Induk A - 1a. REKAP UIT.csv"',
            'attachment; filename="Rekap B - 1b. REKAP PER UPT.csv"',
        );

        $this->artisan('aset:sync')->assertExitCode(0);

        $dataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->sole();

        $this->assertSame('Rekap B - 1b. REKAP PER UPT', $dataset->original_name);
    }

    public function test_sync_fallback_ke_config_bila_header_tidak_ada(): void
    {
        config(['asset.dataset_name' => 'Rekap Aset UIT JBT']);
        $this->fakeGoogleSheetsResponses();

        $this->artisan('aset:sync')->assertExitCode(0);

        $dataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->sole();

        $this->assertSame('Rekap Aset UIT JBT', $dataset->original_name);

        config(['asset.dataset_name' => 'Master Aset Transmisi JBT']);
        $this->fakeGoogleSheetsResponses();

        $this->artisan('aset:sync')->assertExitCode(0);

        $latest = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('Master Aset Transmisi JBT', $latest->original_name);
    }

    public function test_nama_dataset_tidak_pernah_memuat_sheet_id_gid_maupun_url(): void
    {
        $sheetId = (string) config('asset.sheet_id');
        $gid1a = (string) config('asset.sheet_gid_1a');
        $gid1b = (string) config('asset.sheet_gid_1b');

        $this->fakeGoogleSheetsResponses(
            null,
            null,
            "attachment; filename=\"https://docs.google.com/spreadsheets/d/{$sheetId}/export?gid={$gid1a}.csv\"",
            "attachment; filename=\"{$sheetId}_{$gid1b}.csv\"",
        );

        $this->artisan('aset:sync')->assertExitCode(0);

        $dataset = Dataset::where('division_id', $this->assetDivision->id)
            ->where('status', Dataset::STATUS_READY)
            ->sole();

        $this->assertSame('Rekap Aset UIT JBT', $dataset->original_name);
        $this->assertStringNotContainsString($sheetId, $dataset->original_name);
        $this->assertStringNotContainsString($gid1a, $dataset->original_name);
        $this->assertStringNotContainsString($gid1b, $dataset->original_name);
        $this->assertStringNotContainsString('docs.google.com', $dataset->original_name);

        $auditPayload = json_encode(
            AuditLog::where('auditable_type', Dataset::class)->where('auditable_id', $dataset->id)->get()->toArray()
        );
        $this->assertStringNotContainsString($sheetId, (string) $auditPayload);
        $this->assertStringNotContainsString('docs.google.com', (string) $auditPayload);
    }

    public function test_dataset_freshness_for_asset_menyertakan_source_name_dan_memetakan_label_lama(): void
    {
        config(['asset.dataset_name' => 'Rekap Aset UIT JBT']);

        $dataset = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'user_id' => $this->admin->id,
            'status' => Dataset::STATUS_READY,
            'original_name' => 'Sinkronisasi Google Sheets (Tab 1b + Tab 1a)',
        ]);

        PerformanceMetric::create([
            'dataset_id' => $dataset->id,
            'division_id' => $this->assetDivision->id,
            'metric_name' => 'ASET | BANDUNG | BANDUNG SELATAN | Tower 150 KV | SU',
            'period' => '2026-10-31',
            'value' => 100,
        ]);

        $freshnessLegacy = DatasetFreshness::forAsset($this->assetDivision->id, '2026-10-31');
        $this->assertSame('Rekap Aset UIT JBT', $freshnessLegacy['source_name'] ?? null);

        $dataset->update(['original_name' => 'REKAP ASET UIT JBT']);
        $freshnessReal = DatasetFreshness::forAsset($this->assetDivision->id, '2026-10-31');
        $this->assertSame('REKAP ASET UIT JBT', $freshnessReal['source_name'] ?? null);
    }

    public function test_dataset_show_accepts_one_decimal_rounding_tolerance_for_transmisi_kms_without_mutating_raw_values(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'user_id' => $this->admin->id,
            'status' => Dataset::STATUS_READY,
            'original_name' => 'DATA ASET REGISTER UIT JBT',
        ]);

        $rows = [
            // Transmisi 30 KV SU: 41.2 (UIT) vs 7.0 + 25.2 + 8.9 = 41.1 (8 UPT), delta +0.1 (1-decimal CSV rounding drift)
            ['ASET | UIT | Transmisi 30 KV | SU (kms)', 41.20],
            ['ASET | BANDUNG | BANDUNG SELATAN | Transmisi 30 KV | SU | kms', 7.00],
            ['ASET | PURWOKERTO | PURWOKERTO | Transmisi 30 KV | SU | kms', 25.20],
            ['ASET | SALATIGA | SALATIGA | Transmisi 30 KV | SU | kms', 8.90],
            // Transmisi 70 KV SU: 1143.5 (UIT) vs 533.7 + 609.9 = 1143.6 (8 UPT), delta -0.1 (1-decimal CSV rounding drift)
            ['ASET | UIT | Transmisi 70 KV | SU (kms)', 1143.50],
            ['ASET | BANDUNG | BANDUNG BARAT | Transmisi 70 KV | SU | kms', 533.70],
            ['ASET | CIREBON | CIREBON | Transmisi 70 KV | SU | kms', 609.90],
        ];

        foreach ($rows as [$metricName, $value]) {
            PerformanceMetric::create([
                'dataset_id' => $dataset->id,
                'division_id' => $this->assetDivision->id,
                'unit_id' => $this->admin->id,
                'metric_name' => $metricName,
                'value' => $value,
                'period' => '2026-10-31',
            ]);
        }

        $response = $this->actingAs($this->admin)
            ->get(route('datasets.show', $dataset));

        $response->assertOk()
            ->assertSee('Rekonsiliasi Sinkron')
            ->assertSee('Sinkron (Pembulatan)')
            ->assertSee('toleransi pembulatan desimal')
            ->assertSee('41,2')
            ->assertSee('41,1')
            ->assertSee('1.143,5')
            ->assertSee('1.143,6');

        $analysis = $response->viewData('assetAnalysis');
        $this->assertSame(3, $analysis['summary']['reconciled_count']);
        $this->assertSame(3, $analysis['summary']['matched_count']);
        $this->assertSame(0, $analysis['summary']['mismatched_count']);
        $this->assertSame(2, $analysis['summary']['rounding_tolerance_count']);
        $this->assertTrue($analysis['summary']['transmisi_matched']);

        $byLabel = collect($analysis['reconciliation'])->keyBy('label');
        $this->assertSame(41.2, $byLabel['Transmisi 30 KV | SU (kms)']['uit_value']);
        $this->assertSame(41.1, $byLabel['Transmisi 30 KV | SU (kms)']['upt_sum']);
        $this->assertSame(0.1, $byLabel['Transmisi 30 KV | SU (kms)']['delta']);
        $this->assertTrue($byLabel['Transmisi 30 KV | SU (kms)']['is_matched']);
        $this->assertTrue($byLabel['Transmisi 30 KV | SU (kms)']['is_rounding_tolerance']);

        $this->assertSame(1143.5, $byLabel['Transmisi 70 KV | SU (kms)']['uit_value']);
        $this->assertSame(1143.6, $byLabel['Transmisi 70 KV | SU (kms)']['upt_sum']);
        $this->assertSame(-0.1, $byLabel['Transmisi 70 KV | SU (kms)']['delta']);
        $this->assertTrue($byLabel['Transmisi 70 KV | SU (kms)']['is_matched']);
        $this->assertTrue($byLabel['Transmisi 70 KV | SU (kms)']['is_rounding_tolerance']);
    }

    public function test_dataset_show_still_flags_transmisi_kms_mismatch_above_rounding_tolerance_and_strict_non_transmisi_delta(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'user_id' => $this->admin->id,
            'status' => Dataset::STATUS_READY,
            'original_name' => 'asset_real_mismatch_check.csv',
        ]);

        $rows = [
            // Transmisi 30 KV SU dengan selisih 0.2 kms (di atas toleransi pembulatan 0.1 kms) -> wajib Selisih
            ['ASET | UIT | Transmisi 30 KV | SU (kms)', 41.20],
            ['ASET | BANDUNG | BANDUNG SELATAN | Transmisi 30 KV | SU | kms', 41.00],
            // Trafo MVA dengan selisih 0.1 (bukan kms) -> wajib tetap Selisih
            ['ASET | UIT | Trafo 500/150 KV | MVA', 20500.00],
            ['ASET | BANDUNG | BANDUNG BARAT | Trafo 500/150 KV | MVA', 20499.90],
        ];

        foreach ($rows as [$metricName, $value]) {
            PerformanceMetric::create([
                'dataset_id' => $dataset->id,
                'division_id' => $this->assetDivision->id,
                'unit_id' => $this->admin->id,
                'metric_name' => $metricName,
                'value' => $value,
                'period' => '2026-10-31',
            ]);
        }

        $response = $this->actingAs($this->admin)
            ->get(route('datasets.show', $dataset));

        $response->assertOk()
            ->assertSee('selisih data sumber');

        $analysis = $response->viewData('assetAnalysis');
        $this->assertSame(4, $analysis['summary']['mismatched_count']);
        $this->assertSame(0, $analysis['summary']['matched_count']);
        $this->assertFalse($analysis['summary']['transmisi_matched']);
        $this->assertFalse($analysis['summary']['trafo_mva_matched']);

        $byLabel = collect($analysis['reconciliation'])->keyBy('label');
        $this->assertFalse($byLabel['Transmisi 30 KV | SU (kms)']['is_matched']);
        $this->assertFalse($byLabel['Trafo 500/150 KV | MVA']['is_matched']);
    }

    public function test_reconcile_cross_check_accepts_transmisi_one_decimal_rounding_drift_and_rejects_larger_mismatch(): void
    {
        $content1a = (string) file_get_contents(base_path('tests/Fixtures/asset_tab1a_sample.csv'));
        $valid1b = (string) file_get_contents(base_path('tests/Fixtures/asset_tab1b_sample.csv'));

        // Selisih 0.1 kms pada Transmisi 500 KV SU (500.0 -> 499.9) harus diterima sebagai toleransi pembulatan CSV 1 desimal
        $rounded1b = str_replace(
            '1,BANDUNG,"BANDUNG BARAT",15,5,2500.0,500.0,-,800,-,2,4,1000.0,1000.0,-,1500,-',
            '1,BANDUNG,"BANDUNG BARAT",15,5,2500.0,499.9,-,800,-,2,4,1000.0,1000.0,-,1500,-',
            $valid1b
        );

        // Selisih 0.5 kms pada Transmisi 500 KV SU (500.0 -> 499.5) harus ditolak sanity guard
        $mismatched1b = str_replace(
            '1,BANDUNG,"BANDUNG BARAT",15,5,2500.0,500.0,-,800,-,2,4,1000.0,1000.0,-,1500,-',
            '1,BANDUNG,"BANDUNG BARAT",15,5,2500.0,499.5,-,800,-,2,4,1000.0,1000.0,-,1500,-',
            $valid1b
        );

        $useMismatched = false;
        Http::fake([
            'https://docs.google.com/spreadsheets/d/*/export?format=csv&gid='.config('asset.sheet_gid_1a') => fn () => Http::response($content1a, 200, ['Content-Type' => 'text/csv; charset=utf-8']),
            'https://docs.google.com/spreadsheets/d/*/export?format=csv&gid='.config('asset.sheet_gid_1b') => function () use (&$useMismatched, $rounded1b, $mismatched1b) {
                return Http::response($useMismatched ? $mismatched1b : $rounded1b, 200, ['Content-Type' => 'text/csv; charset=utf-8']);
            },
        ]);

        $this->artisan('aset:sync')->assertExitCode(0);

        $useMismatched = true;

        $this->artisan('aset:sync')
            ->expectsOutputToContain('Cross-check reconciliation mismatch')
            ->assertExitCode(1);
    }

    public function test_dataset_show_and_index_map_file_type_and_hide_technical_jargon_without_mutating_database(): void
    {
        config(['asset.dataset_name' => 'Rekap Aset UIT JBT']);

        $staffManset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $this->assetDivision->id,
        ]);

        $syncedDataset = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'user_id' => $staffManset->id,
            'status' => Dataset::STATUS_READY,
            'original_name' => 'Sinkronisasi Google Sheets (Tab 1b + Tab 1a)',
            'mime_type' => 'application/octet-stream',
            'filename' => 'synced_asset_check.csv',
        ]);
        PerformanceMetric::create([
            'dataset_id' => $syncedDataset->id,
            'division_id' => $this->assetDivision->id,
            'unit_id' => $staffManset->id,
            'metric_name' => 'ASET | UIT | Tower 500 KV | SU',
            'value' => 100.0,
            'period' => '2026-10-31',
        ]);

        $xlsxDataset = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'user_id' => $staffManset->id,
            'status' => Dataset::STATUS_READY,
            'original_name' => 'aset_upt.xlsx + aset_uit.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'filename' => 'manual_asset_check.xlsx',
        ]);
        PerformanceMetric::create([
            'dataset_id' => $xlsxDataset->id,
            'division_id' => $this->assetDivision->id,
            'unit_id' => $staffManset->id,
            'metric_name' => 'ASET | BANDUNG | BANDUNG BARAT | Tower 500 KV | SU | Unit',
            'value' => 100.0,
            'period' => '2026-10-31',
        ]);

        $csvDataset = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'user_id' => $staffManset->id,
            'status' => Dataset::STATUS_READY,
            'original_name' => 'aset_upt.csv + aset_uit.csv',
            'mime_type' => 'text/csv',
            'filename' => 'manual_asset_check.csv',
        ]);
        PerformanceMetric::create([
            'dataset_id' => $csvDataset->id,
            'division_id' => $this->assetDivision->id,
            'unit_id' => $staffManset->id,
            'metric_name' => 'ASET | BEKASI | BEKASI | Tower 500 KV | SU | Unit',
            'value' => 50.0,
            'period' => '2026-10-31',
        ]);

        $this->actingAs($staffManset)
            ->get(route('datasets.index'))
            ->assertOk()
            ->assertSee('Rekap Aset UIT JBT')
            ->assertDontSee('Tab 1a')
            ->assertDontSee('Tab 1b')
            ->assertDontSee('application/octet-stream');

        $this->actingAs($staffManset)
            ->get(route('datasets.show', $syncedDataset))
            ->assertOk()
            ->assertSee('Rekap Aset UIT JBT')
            ->assertSee('Sinkronisasi Google Sheets')
            ->assertDontSee('application/octet-stream')
            ->assertDontSee('Tab 1a')
            ->assertDontSee('Tab 1b');

        $this->actingAs($staffManset)
            ->get(route('datasets.show', $xlsxDataset))
            ->assertOk()
            ->assertSee('Excel (.xlsx)')
            ->assertDontSee('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAs($staffManset)
            ->get(route('datasets.show', $csvDataset))
            ->assertOk()
            ->assertSee('CSV (.csv)')
            ->assertDontSee('text/csv');

        // Kontrak database mentah tetap terjaga apa adanya
        $this->assertSame('application/octet-stream', $syncedDataset->fresh()->mime_type);
        $this->assertSame('Sinkronisasi Google Sheets (Tab 1b + Tab 1a)', $syncedDataset->fresh()->original_name);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsxDataset->fresh()->mime_type);
        $this->assertSame('text/csv', $csvDataset->fresh()->mime_type);
    }
}
