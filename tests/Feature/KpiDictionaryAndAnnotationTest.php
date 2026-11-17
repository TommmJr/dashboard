<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogFormatter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class KpiDictionaryAndAnnotationTest extends TestCase
{
    use RefreshDatabase;

    private Division $pengusahaan;

    private Division $aset;

    private string $annotationsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->pengusahaan = Division::firstOrCreate(
            ['slug' => 'perencanaan-pengusahaan'],
            ['name' => 'Perencanaan Pengusahaan'],
        );

        $this->aset = Division::firstOrCreate(
            ['slug' => 'managemen-aset'],
            ['name' => 'Managemen Aset'],
        );

        $this->annotationsPath = storage_path('framework/testing/kpi-annotations-'.uniqid('', true).'.json');
        config(['kpi.annotations_path' => $this->annotationsPath]);
    }

    protected function tearDown(): void
    {
        if (isset($this->annotationsPath) && is_file($this->annotationsPath)) {
            @unlink($this->annotationsPath);
        }

        parent::tearDown();
    }

    private function seedKpiPeriod(string $period = '2026-10-31', float $nko = 104.2): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->pengusahaan->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $unit = OrgUnit::firstOrCreate(['name' => 'TJBT']);
        $indicator = Indicator::firstOrCreate(
            ['org_unit_id' => $unit->id, 'name' => 'NILAI KINERJA ORGANISASI', 'category' => 'nko'],
            ['satuan' => '%', 'is_group' => false],
        );

        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $unit->id,
            'division_id' => $this->pengusahaan->id,
            'period' => $period,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'target_value' => 100.0,
            'realisasi_value' => $nko,
            'nilai' => $nko,
            'pencapaian' => $nko / 100.0,
        ]);
    }

    public function test_dashboard_kpi_dan_kpi_detail_merender_drawer_kamus_kpi_dinamis_dalam_bahasa_klien(): void
    {
        $this->seedKpiPeriod();

        config([
            'kpi.rag_thresholds' => [
                'tercapai_min' => 100.0,
                'hampir_min' => 95.0,
            ],
        ]);

        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $staffPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $this->pengusahaan->id,
        ]);

        foreach ([$management, $staffPengusahaan] as $user) {
            foreach ([route('dashboard'), route('dashboard.kpi-detail')] as $url) {
                $response = $this->actingAs($user)->get($url);
                $response->assertOk()
                    ->assertSee('id="kpi-dictionary-trigger"', false)
                    ->assertSee('id="kpi-dictionary-drawer"', false)
                    ->assertSee('Kamus KPI')
                    ->assertSee('Polaritas Positif')
                    ->assertSee('Polaritas Negatif')
                    ->assertSee('110%')
                    ->assertSee('Tercapai')
                    ->assertSee('Hampir Tercapai')
                    ->assertSee('Perlu Peningkatan')
                    ->assertSee('100%')
                    ->assertSee('95%')
                    ->assertDontSee('is_group')
                    ->assertDontSee('STATUS_READY')
                    ->assertDontSee('ARCHIVED')
                    ->assertDontSee('font-mono');
            }
        }
    }

    public function test_drawer_kamus_kpi_mengikuti_konfigurasi_ambang_rag_secara_dinamis(): void
    {
        config([
            'kpi.rag_thresholds' => [
                'tercapai_min' => 102.5,
                'hampir_min' => 96.5,
            ],
        ]);

        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="kpi-dictionary-drawer"', false)
            ->assertSee('102,5%')
            ->assertSee('96,5%')
            ->assertSee('110%');
    }

    public function test_dashboard_non_kpi_tidak_merender_drawer_kamus_kpi(): void
    {
        $staffAset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $this->aset->id,
        ]);
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        foreach ([$staffAset, $admin] as $user) {
            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee('id="kpi-dictionary-trigger"', false)
                ->assertDontSee('id="kpi-dictionary-drawer"', false);
        }
    }

    public function test_filter_rentang_tanggal_menyaring_anotasi_dan_urutan_terbaru_di_atas(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($management)
            ->postJson(route('dashboard.kpi-annotations.store'), [
                'period' => '2026-08-31',
                'note' => 'Catatan Agustus',
            ])
            ->assertOk();

        $this->actingAs($management)
            ->postJson(route('dashboard.kpi-annotations.store'), [
                'period' => '2026-10-31',
                'note' => 'Catatan Oktober',
            ])
            ->assertOk();

        $this->actingAs($management)
            ->getJson(route('performance.data'))
            ->assertOk()
            ->assertJsonCount(2, 'annotations')
            ->assertJsonPath('annotations.0.period', '2026-10')
            ->assertJsonPath('annotations.1.period', '2026-08');

        $this->actingAs($management)
            ->getJson(route('performance.data', ['start_date' => '2026-09-01', 'end_date' => '2026-10-31']))
            ->assertOk()
            ->assertJsonCount(1, 'annotations')
            ->assertJsonPath('annotations.0.period', '2026-10');
    }

    public function test_role_berwenang_dapat_simpan_ubah_hapus_anotasi_terkanonisasi_dan_tercatat_di_audit_logs(): void
    {
        $this->seedKpiPeriod('2026-10-31', 104.2);

        $management = User::factory()->withRole(Role::MANAGEMENT)->create(['name' => 'Kepala Bidang']);
        $staffPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'name' => 'Staf Rendalev',
            'division_id' => $this->pengusahaan->id,
        ]);
        $admin = User::factory()->withRole(Role::ADMIN)->create(['name' => 'Admin Sistem']);
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        // 1. Management simpan anotasi dengan format YYYY-MM-31 -> terkanonisasi ke 2026-10.
        $createRes = $this->actingAs($management)
            ->postJson(route('dashboard.kpi-annotations.store'), [
                'period' => '2026-10-31',
                'note' => 'Pemeliharaan preventif trafo menjaga keandalan sistem.',
            ]);

        $createRes->assertOk()
            ->assertJsonPath('annotation.period', '2026-10')
            ->assertJsonPath('annotation.period_date', '2026-10-31')
            ->assertJsonPath('annotation.note', 'Pemeliharaan preventif trafo menjaga keandalan sistem.');

        // 2. Staf Perencanaan Pengusahaan memperbarui periode yang sama memakai format YYYY-MM.
        $updateRes = $this->actingAs($staffPengusahaan)
            ->postJson(route('dashboard.kpi-annotations.store'), [
                'period' => '2026-10',
                'note' => 'Revisi catatan: pemulihan gangguan GI Ungaran selesai lebih cepat.',
            ]);

        $updateRes->assertOk()
            ->assertJsonCount(1, 'annotations')
            ->assertJsonPath('annotations.0.period', '2026-10')
            ->assertJsonPath('annotations.0.note', 'Revisi catatan: pemulihan gangguan GI Ungaran selesai lebih cepat.');

        // 3. Seluruh role yang berhak melihat trend-chart (termasuk Editor Dashboard) menerima anotasi di /api/performance-data.
        foreach ([$management, $staffPengusahaan, $admin, $editor] as $viewer) {
            $this->actingAs($viewer)
                ->getJson(route('performance.data'))
                ->assertOk()
                ->assertJsonCount(1, 'annotations')
                ->assertJsonPath('annotations.0.period', '2026-10')
                ->assertJsonPath('annotations.0.note', 'Revisi catatan: pemulihan gangguan GI Ungaran selesai lebih cepat.');
        }

        // 4. Staf Aset tidak mendapat bocoran anotasi KPI pada /api/performance-data.
        $staffAset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $this->aset->id,
        ]);
        $this->actingAs($staffAset)
            ->getJson(route('performance.data'))
            ->assertOk()
            ->assertJsonCount(0, 'annotations');

        // 5. Admin dapat menghapus anotasi dengan format YYYY-MM atau YYYY-MM-31.
        $this->actingAs($admin)
            ->deleteJson(route('dashboard.kpi-annotations.destroy', ['period' => '2026-10-31']))
            ->assertOk()
            ->assertJsonCount(0, 'annotations');

        // 6. AuditLog mencatat CREATE, UPDATE, dan DELETE dengan format terbaca.
        $logs = AuditLog::query()
            ->whereNull('auditable_type')
            ->whereIn('action', [AuditLog::ACTION_CREATE, AuditLog::ACTION_UPDATE, AuditLog::ACTION_DELETE])
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $logs);
        $this->assertSame(AuditLog::ACTION_CREATE, $logs[0]->action);
        $this->assertSame(AuditLog::ACTION_UPDATE, $logs[1]->action);
        $this->assertSame(AuditLog::ACTION_DELETE, $logs[2]->action);
        $this->assertSame('Anotasi periode KPI', AuditLogFormatter::objectLabel($logs[0]));

        $updateRows = AuditLogFormatter::rows($logs[1]);
        $this->assertNotEmpty($updateRows);
        $this->assertSame('Catatan', $updateRows[0]['label']);
    }

    public function test_role_tidak_berwenang_dan_guest_ditolak_fail_closed_pada_mutasi_anotasi(): void
    {
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $staffAset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $this->aset->id,
        ]);
        $staffTanpaDivisi = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => null,
        ]);

        // Guest dialihkan ke login pada form web & 401 pada JSON.
        $this->post(route('dashboard.kpi-annotations.store'), [
            'period' => '2026-10',
            'note' => 'Catatan tamu',
        ])->assertRedirect(route('login'));

        $this->delete(route('dashboard.kpi-annotations.destroy', ['period' => '2026-10']))
            ->assertRedirect(route('login'));

        // Role tidak berwenang mendapat 403 fail-closed untuk POST & DELETE.
        foreach ([$editor, $staffAset, $staffTanpaDivisi] as $unauthorized) {
            $this->actingAs($unauthorized)
                ->postJson(route('dashboard.kpi-annotations.store'), [
                    'period' => '2026-10',
                    'note' => 'Percobaan mutasi tanpa izin',
                ])
                ->assertForbidden();

            $this->actingAs($unauthorized)
                ->deleteJson(route('dashboard.kpi-annotations.destroy', ['period' => '2026-10']))
                ->assertForbidden();
        }

        $this->assertFileDoesNotExist($this->annotationsPath);
        $this->assertSame(
            0,
            AuditLog::query()
                ->whereNull('auditable_type')
                ->whereIn('action', [AuditLog::ACTION_CREATE, AuditLog::ACTION_UPDATE, AuditLog::ACTION_DELETE])
                ->count(),
        );
    }

    public function test_validasi_anotasi_menolak_kosong_format_periode_salah_melebihi_300_karakter_dan_sanitasi_html(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        // Kosong / spasi saja
        $this->actingAs($management)
            ->postJson(route('dashboard.kpi-annotations.store'), [
                'period' => '2026-10',
                'note' => '   ',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['note']);

        // Murni tag script -> setelah sanitasi kosong, ditolak 422
        $this->actingAs($management)
            ->postJson(route('dashboard.kpi-annotations.store'), [
                'period' => '2026-10',
                'note' => '<script>alert("xss")</script>',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['note']);

        // Format periode tidak valid (termasuk tanggal kalender mustahil & path traversal)
        foreach (['2026', '2026-13', '2026-02-31', 'invalid-period'] as $badPeriod) {
            $this->actingAs($management)
                ->postJson(route('dashboard.kpi-annotations.store'), [
                    'period' => $badPeriod,
                    'note' => 'Catatan valid',
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['period']);
        }

        // Melebihi 300 karakter
        $this->actingAs($management)
            ->postJson(route('dashboard.kpi-annotations.store'), [
                'period' => '2026-10',
                'note' => str_repeat('a', 301),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['note']);

        // Campuran tag HTML disanitasi menjadi teks polos tanpa tag
        $this->actingAs($management)
            ->postJson(route('dashboard.kpi-annotations.store'), [
                'period' => '2026-10',
                'note' => '<b>Perhatian</b>   deviasi  <img src=x onerror=alert(1)>beban puncak.',
            ])
            ->assertOk()
            ->assertJsonPath('annotation.note', 'Perhatian deviasi beban puncak.');
    }

    public function test_mutasi_anotasi_dilindungi_rate_limiter_layout_mutation(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        RateLimiter::clear((string) $management->id);

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($management)
                ->postJson(route('dashboard.kpi-annotations.store'), [
                    'period' => '2026-10',
                    'note' => 'Catatan ke-'.$i,
                ])
                ->assertOk();
        }

        $this->actingAs($management)
            ->postJson(route('dashboard.kpi-annotations.store'), [
                'period' => '2026-10',
                'note' => 'Melebihi batas',
            ])
            ->assertStatus(429);

        RateLimiter::clear((string) $management->id);
    }

    public function test_halaman_dashboard_merender_panel_anotasi_pada_trend_chart_sesuai_otorisasi(): void
    {
        $this->seedKpiPeriod('2026-10-31', 104.2);

        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="trend-annotations-panel"', false)
            ->assertSee('id="trend-annotation-add-btn"', false)
            ->assertSee(route('dashboard.kpi-annotations.store'), false);

        // Editor Dashboard melihat panel anotasi (read-only), tanpa tombol/form tambah mutasi.
        $this->actingAs($editor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="trend-annotations-panel"', false)
            ->assertDontSee('id="trend-annotation-add-btn"', false);
    }

    public function test_periode_februari_tidak_overflow_pada_akhir_bulan(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 31, 12, 0, 0, 'Asia/Jakarta'));

        try {
            $management = User::factory()->withRole(Role::MANAGEMENT)->create();

            $this->actingAs($management)
                ->postJson(route('dashboard.kpi-annotations.store'), [
                    'period' => '2026-02',
                    'note' => 'Evaluasi kinerja bulan Februari.',
                ])
                ->assertOk()
                ->assertJsonPath('annotation.period', '2026-02')
                ->assertJsonPath('annotation.period_date', '2026-02-28')
                ->assertJsonPath('annotation.period_label', 'Februari 2026');
        } finally {
            Carbon::setTestNow();
        }
    }
}
