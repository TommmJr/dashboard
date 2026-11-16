<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\DashboardLayout;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceMetric;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Layout widget dashboard per role (Dashboard Builder Fase 1).
 *
 * Label widget di-render server-side (bukan Alpine/JS) sehingga
 * assertSee/assertDontSee valid untuk memverifikasi komposisi layout
 * per role dari config/dashboard_layouts.php.
 */
class DashboardWidgetLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Default produksi kini 'structured' (Fase 4.3 Tahap B). Test ini
        // mengunci jalur legacy, jadi mode legacy ditetapkan eksplisit.
        config(['kpi.read_model' => 'legacy']);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_hanya_melihat_widget_operasional(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Total Dataset')
            ->assertSee('Total Pengguna')
            ->assertSee('Audit Terbaru')
            ->assertSee('Dataset Gagal')
            ->assertSee('Aksi Cepat')
            ->assertDontSee('id="dashboard-layout-builder"', false)
            ->assertDontSee('Ringkasan KPI')
            ->assertDontSee('Tren NKO')
            ->assertDontSee('Peringkat NKO');

        $this->actingAs($admin)
            ->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('id="dashboard-layout-builder"', false);
    }

    public function test_dashboard_merender_kontrol_filter_performance(): void
    {
        // Isi satu unit NKO supaya dropdown unit punya isi (tanpa data,
        // dropdown memang kosong dan checkbox-nya tidak dirender).
        $dataset = Dataset::factory()->create([
            'status' => Dataset::STATUS_READY,
            'division_id' => Division::factory()->create()->id,
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $dataset->division_id,
            'metric_name' => 'TJBT | NKO',
            'value' => 105.5,
            'period' => '2026-08-31',
        ]);

        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="start_date"', false)
            ->assertSee('id="end_date"', false)
            ->assertSee('id="unit"', false)
            // Form GET (bukan fetch): halaman reload supaya widget server-side
            // (peringkat NKO) ikut terfilter bersama chart.
            ->assertSee('name="start_date"', false)
            ->assertSee('name="end_date"', false)
            ->assertSee('name="unit[]"', false)
            ->assertSee('method="GET"', false)
            ->assertSee('TJBT')
            ->assertSee('Semua unit')
            ->assertSee('Terapkan')
            ->assertSee('Reset');
    }

    public function test_management_filter_is_compact_inside_dashboard_header(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $html = $this->actingAs($management)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="dashboard-header".*id="dashboard-filter".*name="start_date"/s', $html);
        $this->assertStringNotContainsString('Beranda / Dashboard', $html);
    }

    public function test_management_tidak_melihat_widget_admin(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tren NKO')
            ->assertDontSee('Ringkasan Operasional')
            ->assertDontSee('Aksi Cepat')
            ->assertDontSee('Audit Terbaru')
            ->assertDontSee('Dataset Gagal')
            ->assertDontSee('Editor Layout');
    }

    public function test_editor_dashboard_melihat_placeholder_layout_editor(): void
    {
        $division = Division::factory()->create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        $response = $this->actingAs($editor)
            ->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('id="dashboard-layout-builder"', false)
            ->assertSee('id="layout-editor-target"', false)
            ->assertSee('id="preview-canvas-viewport"', false)
            ->assertSee('Komponen')
            ->assertSee('Simpan Layout')
            ->assertSee('Reset ke Bawaan')
            ->assertSee('staff_divisi:perencanaan-pengusahaan', false)
            ->assertDontSee('Audit Terbaru');

        // Pastikan editor tata letak tidak digabungkan pada halaman dashboard utama
        $this->actingAs($editor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="dashboard-layout-builder"', false);

        preg_match("/dashboardLayoutBuilder\('([^']+)'\)/", $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? '');

        $payload = json_decode((string) base64_decode($matches[1], true), true);
        $this->assertIsArray($payload);

        $catalogKeys = array_column($payload['catalog'] ?? [], 'key');
        $this->assertContains('kpi-summary', $catalogKeys);
        $this->assertContains('target-realisasi-per-indikator', $catalogKeys);
        $this->assertContains('aset-upt-chart', $catalogKeys); // demo prototipe MANSET: tidak lagi terlarang
        $this->assertNotContains('target-vs-realisasi', $catalogKeys);

        $this->assertSame(
            ['admin', 'staff_divisi:perencanaan-pengusahaan'],
            array_column($payload['targets'] ?? [], 'layout_key'),
        ); // that test only creates the pengusahaan division -> manset target skipped
        $this->assertSame($division->id, $payload['targets'][1]['division_id']);
        $this->assertFalse($payload['targets'][1]['is_customized']);
    }

    public function test_staff_divisi_non_pengusahaan_melihat_status_dataset_saja(): void
    {
        $division = Division::factory()->create();
        $dataset = Dataset::factory()->create([
            'status' => Dataset::STATUS_READY,
            'division_id' => $division->id,
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'metric_name' => 'TJBT | Indikator A | target',
            'value' => 100,
            'period' => '2026-08-31',
        ]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);
        $user = User::factory()->create();

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Status Dataset')
            ->assertSee('Dataset Berhasil (Divisi)')
            ->assertSee('Baris Data Aktif')
            ->assertDontSee('Baris Data Aktif (TJBT)')
            ->assertDontSee('Baris Aktif')
            ->assertDontSee('NKO (Nilai Kinerja Organisasi)')
            ->assertDontSee('Tren NKO')
            ->assertDontSee('Peringkat NKO')
            ->assertDontSee('Target vs Realisasi')
            ->assertDontSee('Distribusi NKO');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tren NKO')
            ->assertDontSee('Audit Terbaru');
    }

    public function test_staff_pengusahaan_melihat_layout_kpi(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Kinerja Pengusahaan')
            ->assertSee('NKO (Nilai Kinerja Organisasi)')
            ->assertSee('Rata-rata Capaian')
            ->assertSee('Memenuhi Target')
            ->assertSee('Perlu Perhatian')
            ->assertDontSee('id="executive-headline"', false)
            ->assertDontSee('id="kpi-methodology-note"', false)
            ->assertSee('Rincian KPI')
            ->assertDontSee('Analitik KPI')
            ->assertSee('Unggah Data')
            ->assertSee('Tren NKO')
            ->assertSee('Target 100')
            ->assertSee('Status Indikator')
            ->assertDontSee('Dataset Berhasil (Divisi)')
            ->assertDontSee('Baris Data Aktif')
            ->assertDontSee('Target vs Realisasi')
            ->assertDontSee('Target dan Realisasi per Indikator')
            ->assertDontSee('Distribusi NKO')
            ->assertDontSee('Peringkat NKO')
            ->assertDontSee('Audit Terbaru')
            ->assertDontSee('Dataset Gagal')
            ->assertDontSee('Editor Layout')
            ->assertDontSee('Stok Aset per UPT')
            ->assertDontSee('Prototipe Demo (Rekapitulasi UPT)')
            ->assertDontSee('data-category-filter', false)
            ->assertDontSee('Belum ada data aset.');
    }

    /**
     * Layout default L1 Pengusahaan dirampingkan untuk satu layar eksekutif,
     * sementara katalog target-realisasi-per-indikator tetap tersedia untuk
     * Builder dan widget lama target-vs-realisasi tetap dihapus.
     */
    public function test_layout_staff_pengusahaan_memakai_widget_per_indikator_bukan_widget_lama(): void
    {
        $layout = config('dashboard_layouts.layouts')['staff_divisi:perencanaan-pengusahaan'];
        $widgets = config('dashboard_layouts.widgets');

        $this->assertSame(['kpi-summary', 'trend-chart', 'kpi-rag-status'], $layout);
        $this->assertNotContains('target-realisasi-per-indikator', $layout);
        $this->assertArrayHasKey('target-realisasi-per-indikator', $widgets);
        $this->assertNotContains('target-vs-realisasi', $layout);
        $this->assertArrayNotHasKey('target-vs-realisasi', $widgets);
    }

    public function test_model_dashboard_layout_menurunkan_layout_key_deterministik_dan_menolak_duplikat(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        $managementLayout = DashboardLayout::create([
            'role_slug' => Role::MANAGEMENT,
            'widgets' => ['nko-ranking', 'kpi-summary'],
            'updated_by' => $editor->id,
        ]);

        $this->assertSame('management', $managementLayout->layout_key);
        $this->assertNull($managementLayout->division_id);
        $this->assertSame($editor->id, $managementLayout->updated_by);

        $staffDivisionLayout = DashboardLayout::create([
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => $division->id,
            'widgets' => ['kpi-summary', 'dataset-status'],
            'updated_by' => $editor->id,
        ]);

        $this->assertSame('staff_divisi:perencanaan-pengusahaan', $staffDivisionLayout->layout_key);
        $this->assertSame($division->id, $staffDivisionLayout->division_id);

        $this->expectException(QueryException::class);
        DB::table('dashboard_layouts')->insert([
            'layout_key' => 'management',
            'role_slug' => Role::MANAGEMENT,
            'division_id' => null,
            'widgets' => json_encode(['kpi-summary']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_model_dashboard_layout_menolak_division_id_untuk_role_non_staff_dan_widget_terlarang(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);

        try {
            DashboardLayout::create([
                'role_slug' => Role::MANAGEMENT,
                'division_id' => $division->id,
                'widgets' => ['kpi-summary'],
            ]);
            $this->fail('Seharusnya gagal ketika role non-staff memiliki division_id.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('division_id', $e->getMessage());
        }

        try {
            DashboardLayout::create([
                'role_slug' => Role::MANAGEMENT,
                'widgets' => ['kpi-summary', 'widget-palsu'],
            ]);
            $this->fail('Seharusnya gagal ketika menyimpan widget yang tidak ada di katalog.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('widget-palsu', $e->getMessage());
        }

        // aset-upt-chart sah disimpan sejak pembukaan untuk demo prototipe MANSET.
        $saved = DashboardLayout::create([
            'role_slug' => Role::MANAGEMENT,
            'widgets' => ['kpi-summary', 'aset-upt-chart'],
        ]);
        $this->assertSame(['kpi-summary', 'aset-upt-chart'], DashboardLayout::widgetKeys($saved->widgets));
    }

    public function test_resolusi_layout_mengutamakan_database_dan_mematuhi_urutan_fallback_staff_divisi(): void
    {
        $pengusahaan = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $divisiLain = Division::factory()->create(['slug' => 'perencanaan-aset']);

        $staffPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $pengusahaan->id,
        ]);
        $staffLain = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $divisiLain->id,
        ]);

        // 1. DB staff_divisi:<slug> menang atas Config staff_divisi:<slug>.
        $overridePengusahaan = DashboardLayout::create([
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => $pengusahaan->id,
            'widgets' => ['dataset-status'],
        ]);

        $this->actingAs($staffPengusahaan)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Status Dataset')
            ->assertDontSee('Tren NKO')
            ->assertDontSee('Distribusi NKO');

        // 2. Config staff_divisi:<slug> menang atas DB staff_divisi generik.
        $overridePengusahaan->delete();
        DashboardLayout::create([
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => null,
            'widgets' => ['nko-donut'],
        ]);

        // Staff Pengusahaan punya Config staff_divisi:perencanaan-pengusahaan (urutan #2),
        // sehingga tetap melihat Tren NKO dan Status Indikator (bukan hanya nko-donut dari DB staff_divisi #3).
        $this->actingAs($staffPengusahaan)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tren NKO')
            ->assertSee('Status Indikator')
            ->assertDontSee('Distribusi NKO');

        // 3. Staff divisi lain yang tidak punya override divisi di DB (#1) maupun Config (#2)
        // memakai DB staff_divisi (#3) di atas Config staff_divisi (#4).
        $this->actingAs($staffLain)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Distribusi NKO')
            ->assertDontSee('Status Dataset');
    }

    public function test_resolusi_layout_menyaring_widget_asing_dan_fallback_ke_config_jika_db_kosong(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        // Bypass model validation via DB::table untuk menguji defense-in-depth saat baca:
        // record hanya berisi widget yang sudah tidak ada di katalog dan widget tak dikenal.
        DB::table('dashboard_layouts')->insert([
            'layout_key' => Role::MANAGEMENT,
            'role_slug' => Role::MANAGEMENT,
            'division_id' => null,
            'widgets' => json_encode(['target-vs-realisasi', 'widget-palsu']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Karena daftar widget valid dari DB kosong setelah disaring, resolver wajib
        // fallback ke config('dashboard_layouts.layouts.management').
        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tren NKO')
            ->assertSee('Peringkat NKO')
            ->assertDontSee('Stok Aset per UPT');

        // Jika DB berisi campuran widget valid + widget asing + widget ber-permission
        // yang tidak dimiliki management (layout-editor-placeholder butuh dashboard.update),
        // widget asing dibuang sanitizer dan layout-editor-placeholder disaring Gate.
        DB::table('dashboard_layouts')
            ->where('layout_key', Role::MANAGEMENT)
            ->update([
                'widgets' => json_encode(['nko-donut', 'widget-palsu', 'layout-editor-placeholder']),
            ]);

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Distribusi NKO')
            ->assertDontSee('Stok Aset per UPT')
            ->assertDontSee('Editor Layout')
            ->assertDontSee('Tren NKO');
    }

    public function test_endpoint_update_dan_reset_layout_hanya_diizinkan_untuk_pemegang_dashboard_update(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        // Guest diarahkan ke login.
        $this->put(route('dashboard.layout.update'), [
            'role_slug' => Role::ADMIN,
            'widgets' => ['admin-summary'],
        ])->assertRedirect(route('login'));

        // Management dan Staff Divisi ditolak 403 Forbidden.
        foreach ([$management, $staff] as $unauthorizedUser) {
            $this->actingAs($unauthorizedUser)
                ->put(route('dashboard.layout.update'), [
                    'role_slug' => Role::ADMIN,
                    'widgets' => ['admin-summary'],
                ])
                ->assertForbidden();

            $this->actingAs($unauthorizedUser)
                ->delete(route('dashboard.layout.reset'), [
                    'role_slug' => Role::ADMIN,
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('dashboard_layouts', 0);

        // Editor Dashboard diizinkan menyimpan layout staf kanonis.
        $this->actingAs($editor)
            ->put(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $division->id,
                'widgets' => ['nko-donut', 'kpi-summary'],
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('dashboard_layouts', [
            'layout_key' => 'staff_divisi:perencanaan-pengusahaan',
            'role_slug' => 'staff_divisi',
            'division_id' => $division->id,
            'updated_by' => $editor->id,
        ]);

        // Admin diizinkan memperbarui dan mereset layout per divisi untuk staff_divisi.
        $this->actingAs($admin)
            ->put(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $division->id,
                'widgets' => ['dataset-status', 'kpi-summary'],
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('dashboard_layouts', [
            'layout_key' => 'staff_divisi:perencanaan-pengusahaan',
            'role_slug' => 'staff_divisi',
            'division_id' => $division->id,
            'updated_by' => $admin->id,
        ]);

        // Reset oleh Editor menghapus override DB dan mengembalikan fallback config.
        $this->actingAs($editor)
            ->delete(route('dashboard.layout.reset'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $division->id,
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('dashboard_layouts', [
            'layout_key' => 'staff_divisi:perencanaan-pengusahaan',
        ]);
    }

    public function test_endpoint_update_layout_memvalidasi_payload_scoping_dan_menerima_aset_upt_chart_untuk_demo(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        // 1. Tolak division_id pada role selain staff_divisi.
        $this->actingAs($editor)
            ->put(route('dashboard.layout.update'), [
                'role_slug' => Role::ADMIN,
                'division_id' => $division->id,
                'widgets' => ['admin-summary'],
            ])
            ->assertSessionHasErrors('division_id');

        // 2. Tolak widget yang tidak terdaftar di katalog (aset-upt-chart sah sejak demo prototipe MANSET).
        $this->actingAs($editor)
            ->put(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $division->id,
                'widgets' => ['kpi-summary', 'widget-palsu'],
            ])
            ->assertSessionHasErrors('widgets.1');

        $this->assertDatabaseCount('dashboard_layouts', 0);

        $this->actingAs($editor)
            ->put(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $division->id,
                'widgets' => ['kpi-summary', 'aset-upt-chart'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('dashboard_layouts', 1);
        DashboardLayout::query()->delete();

        // 3. Tolak widget yang tidak ada di katalog (mis. R8 target-vs-realisasi).
        $this->actingAs($editor)
            ->put(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $division->id,
                'widgets' => ['target-vs-realisasi'],
            ])
            ->assertSessionHasErrors('widgets.0');

        // 4. Tolak daftar widget kosong.
        $this->actingAs($editor)
            ->put(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $division->id,
                'widgets' => [],
            ])
            ->assertSessionHasErrors('widgets');

        // 5. Tolak layout_key yang tidak konsisten dengan role_slug + division.
        $this->actingAs($editor)
            ->put(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $division->id,
                'layout_key' => 'staff_divisi:divisi-salah',
                'widgets' => ['dataset-status'],
            ])
            ->assertSessionHasErrors('layout_key');

        $this->assertDatabaseCount('dashboard_layouts', 0);
    }

    public function test_harden_audit_log_sql_mencakup_grant_untuk_seluruh_tabel_migrasi_termasuk_dashboard_layouts(): void
    {
        $sql = (string) file_get_contents(base_path('docker/mysql/harden-audit-log.sql'));

        $this->assertStringContainsString(
            "GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`dashboard_layouts` TO 'dashboard_app'@'%';",
            $sql,
        );

        $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
            ->map(fn (object $row): string => (string) $row->name)
            ->values();

        $this->assertContains('dashboard_layouts', $tables);

        foreach ($tables as $table) {
            $this->assertMatchesRegularExpression(
                '/GRANT\s+[^;]+ON\s+`dashboard`\.`'.preg_quote($table, '/').'`\s+TO\s+\'dashboard_app\'@\'%\';/',
                $sql,
                "Tabel '{$table}' belum memiliki GRANT eksplisit di docker/mysql/harden-audit-log.sql.",
            );
        }
    }

    public function test_fk_cascade_division_null_on_delete_updated_by_dan_urutan_render_widget(): void
    {
        $division = Division::factory()->create(['slug' => 'divisi-uji']);
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $divLayout = DashboardLayout::create([
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => $division->id,
            'widgets' => ['dataset-status', 'kpi-summary'],
            'updated_by' => $editor->id,
        ]);

        $mgmtLayout = DashboardLayout::create([
            'role_slug' => Role::MANAGEMENT,
            'widgets' => ['nko-donut', 'kpi-summary'],
            'updated_by' => $editor->id,
        ]);

        $this->assertSame($division->id, $divLayout->division?->id);
        $this->assertSame($editor->id, $mgmtLayout->updatedBy?->id);

        // Hapus user editor -> updated_by menjadi null (nullOnDelete), layout tetap ada.
        $editor->delete();
        $this->assertNull($mgmtLayout->fresh()?->updated_by);

        // Hapus divisi -> layout divisi ikut terhapus (cascadeOnDelete).
        $division->delete();
        $this->assertDatabaseMissing('dashboard_layouts', ['id' => $divLayout->id]);

        // Urutan render mengikuti susunan di DB ('nko-donut' sebelum 'kpi-summary').
        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Distribusi NKO', 'NKO (Nilai Kinerja Organisasi)']);
    }

    public function test_resolusi_layout_tetap_fallback_ke_config_saat_tabel_belum_dimigrasi(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        Schema::dropIfExists('dashboard_layouts');

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tren NKO')
            ->assertSee('Peringkat NKO');
    }

    public function test_ui_builder_tidak_ditampilkan_untuk_pengguna_tanpa_permission_dashboard_update(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        foreach ([$management, $staff] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee('id="dashboard-layout-builder"', false)
                ->assertDontSee('Simpan Layout')
                ->assertDontSee('Reset ke Bawaan');

            $this->actingAs($viewer)
                ->get(route('dashboard.builder'))
                ->assertForbidden();
        }
    }

    public function test_integrasi_ui_builder_json_tambah_hapus_ubah_urutan_simpan_dan_reset_layout(): void
    {
        $divPengusahaan = Division::factory()->create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);
        $divAset = Division::factory()->create([
            'name' => 'Managemen Aset',
            'slug' => 'managemen-aset',
        ]);

        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $staffPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $divPengusahaan->id,
        ]);
        $staffAset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $divAset->id,
        ]);

        // 1. Editor menyimpan susunan baru untuk staff_divisi:perencanaan-pengusahaan
        // (mengubah urutan: nko-donut di depan, menghapus widget lain, menambah dataset-status).
        $this->actingAs($editor)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $divPengusahaan->id,
                'layout_key' => 'staff_divisi:perencanaan-pengusahaan',
                'widgets' => ['nko-donut', 'dataset-status', 'kpi-summary'],
            ])
            ->assertOk()
            ->assertJson([
                'message' => 'Layout dashboard berhasil disimpan.',
                'layout_key' => 'staff_divisi:perencanaan-pengusahaan',
                'widgets' => ['nko-donut', 'dataset-status', 'kpi-summary'],
            ]);

        // 2. Staff Pengusahaan melihat urutan baru (Distribusi NKO -> Status Dataset -> kpi-summary)
        // dan tidak lagi melihat Tren NKO yang dihapus dari layout.
        $this->actingAs($staffPengusahaan)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Distribusi NKO', 'Status Dataset', 'NKO (Nilai Kinerja Organisasi)'])
            ->assertDontSee('Tren NKO');

        // 3. Isolasi divisi: Staff Managemen Aset tidak terdampak perubahan divisi Pengusahaan.
        $this->actingAs($staffAset)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Status Dataset')
            ->assertDontSee('Distribusi NKO');

        // 4. Data payload builder pada halaman Editor mencerminkan status kustom (is_customized = true)
        // dan susunan widget terbaru ['nko-donut', 'dataset-status', 'kpi-summary'].
        $editorResponse = $this->actingAs($editor)
            ->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('staff_divisi:perencanaan-pengusahaan', false);

        preg_match("/dashboardLayoutBuilder\('([^']+)'\)/", $editorResponse->getContent(), $matches);
        $payload = json_decode((string) base64_decode($matches[1] ?? '', true), true);
        $targetPengusahaan = collect($payload['targets'] ?? [])->firstWhere('layout_key', 'staff_divisi:perencanaan-pengusahaan');
        $targetAset = collect($payload['targets'] ?? [])->firstWhere('layout_key', 'staff_divisi:managemen-aset');

        $this->assertNotNull($targetPengusahaan);
        $this->assertTrue($targetPengusahaan['is_customized']);
        $this->assertSame(['nko-donut', 'dataset-status', 'kpi-summary'], $targetPengusahaan['active_widgets']);

        $this->assertNotNull($targetAset);
        $this->assertFalse($targetAset['is_customized']);
        $this->assertSame(['dataset-status', 'aset-upt-chart'], $targetAset['active_widgets']);

        // 5. Editor mereset layout staff_divisi:perencanaan-pengusahaan via JSON.
        $this->actingAs($editor)
            ->deleteJson(route('dashboard.layout.reset'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $divPengusahaan->id,
                'layout_key' => 'staff_divisi:perencanaan-pengusahaan',
            ])
            ->assertOk()
            ->assertJson([
                'message' => 'Layout dashboard dikembalikan ke bawaan.',
                'layout_key' => 'staff_divisi:perencanaan-pengusahaan',
            ]);

        // 6. Setelah reset, Staff Pengusahaan kembali melihat layout fallback config (termasuk Tren NKO).
        $this->actingAs($staffPengusahaan)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['NKO (Nilai Kinerja Organisasi)', 'Tren NKO', 'Status Indikator']);
    }

    public function test_endpoint_json_mengembalikan_422_untuk_widget_asing_dan_konflik_scoping(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        $this->actingAs($editor)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $division->id,
                'widgets' => ['kpi-summary', 'widget-palsu'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['widgets.1']);

        $this->actingAs($editor)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $division->id,
                'widgets' => ['widget-tidak-dikenal'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['widgets.0']);

        $this->actingAs($editor)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::ADMIN,
                'division_id' => $division->id,
                'widgets' => ['kpi-summary'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['division_id']);

        $this->actingAs($editor)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $division->id,
                'layout_key' => 'management',
                'widgets' => ['dataset-status'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['layout_key']);
    }

    public function test_penolakan_slug_divisi_tidak_valid_manipulasi_lintas_divisi_dan_spoofing_mass_assignment(): void
    {
        $divA = Division::factory()->create(['name' => 'Perencanaan Pengusahaan', 'slug' => 'perencanaan-pengusahaan']);
        $divB = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $divInvalidSlug = Division::factory()->create(['name' => 'Divisi Slug Rusak', 'slug' => 'Slug Tidak Valid!']);

        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $otherAdmin = User::factory()->withRole(Role::ADMIN)->create();
        $staffA = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $divA->id]);

        // Layout awal Divisi B disimpan oleh Admin.
        DashboardLayout::create([
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => $divB->id,
            'widgets' => ['nko-donut'],
            'updated_by' => $otherAdmin->id,
        ]);

        // 1. Model keyFor menolak slug divisi tidak valid.
        try {
            DashboardLayout::keyFor(Role::STAFF_DIVISI, 'Slug Tidak Valid!');
            $this->fail('Seharusnya gagal untuk slug divisi yang tidak valid.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('slug valid', $e->getMessage());
        }

        // 2. Endpoint menolak penyimpanan untuk divisi dengan slug DB yang tidak valid (HTTP 422).
        $this->actingAs($editor)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $divInvalidSlug->id,
                'widgets' => ['dataset-status'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['division_id']);

        // 3. Endpoint menolak input division_slug yang tidak valid atau tidak cocok dengan division_id.
        $this->actingAs($editor)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $divA->id,
                'division_slug' => 'managemen-aset',
                'widgets' => ['dataset-status'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['division_slug']);

        // 4. Manipulasi division_id vs layout_key (division_id = A tetapi layout_key = staff_divisi:managemen-aset) ditolak 422
        // dan tidak mengubah layout Divisi B.
        $this->actingAs($editor)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $divA->id,
                'layout_key' => 'staff_divisi:managemen-aset',
                'widgets' => ['kpi-summary'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['layout_key']);

        $this->assertSame(
            ['nko-donut'],
            DashboardLayout::query()->where('layout_key', 'staff_divisi:managemen-aset')->value('widgets'),
        );

        // 5. Payload widgets berupa associative array (bukan list) ditolak 422.
        $this->actingAs($editor)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::ADMIN,
                'widgets' => ['custom_key' => 'kpi-summary'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['widgets']);

        // 6. Staff Divisi A tidak dapat memanipulasi endpoint JSON untuk mengubah Divisi B (403),
        // dan tidak dapat memanipulasi query string GET /dashboard untuk mengintip layout Divisi B.
        $this->actingAs($staffA)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $divB->id,
                'widgets' => ['kpi-summary'],
            ])
            ->assertForbidden();

        $this->actingAs($staffA)
            ->get(route('dashboard', [
                'division_id' => $divB->id,
                'role_slug' => Role::STAFF_DIVISI,
                'layout_key' => 'staff_divisi:managemen-aset',
            ]))
            ->assertOk()
            ->assertSee('Status Indikator')
            ->assertDontSee('Distribusi NKO');

        // 7. Percobaan spoofing id dan updated_by pada request body diabaikan; updated_by selalu user yang login.
        $this->actingAs($editor)
            ->putJson(route('dashboard.layout.update'), [
                'id' => 99999,
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $divA->id,
                'updated_by' => $otherAdmin->id,
                'widgets' => ['kpi-summary', 'dataset-status'],
            ])
            ->assertOk();

        $savedA = DashboardLayout::query()->where('layout_key', 'staff_divisi:perencanaan-pengusahaan')->firstOrFail();
        $this->assertNotSame(99999, $savedA->id);
        $this->assertSame($editor->id, $savedA->updated_by);
    }

    public function test_reset_hanya_menghapus_target_yang_diminta_dan_fallback_tahan_terhadap_data_db_inkonsisten(): void
    {
        $divA = Division::factory()->create(['name' => 'Perencanaan Pengusahaan', 'slug' => 'perencanaan-pengusahaan']);
        $divB = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $divBad = Division::factory()->create(['name' => 'Divisi Rusak', 'slug' => 'Slug Rusak!']);

        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $staffBad = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $divBad->id]);

        DashboardLayout::create(['role_slug' => Role::MANAGEMENT, 'widgets' => ['nko-donut']]);
        DashboardLayout::create(['role_slug' => Role::STAFF_DIVISI, 'division_id' => null, 'widgets' => ['kpi-summary']]);
        DashboardLayout::create(['role_slug' => Role::STAFF_DIVISI, 'division_id' => $divA->id, 'widgets' => ['nko-donut']]);
        DashboardLayout::create(['role_slug' => Role::STAFF_DIVISI, 'division_id' => $divB->id, 'widgets' => ['trend-chart']]);

        // 1. Reset pada staff_divisi:perencanaan-pengusahaan hanya menghapus record tersebut, tanpa menyentuh
        // staff_divisi umum, staff_divisi:managemen-aset, maupun management.
        $this->actingAs($editor)
            ->deleteJson(route('dashboard.layout.reset'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $divA->id,
                'layout_key' => 'staff_divisi:perencanaan-pengusahaan',
            ])
            ->assertOk();

        $this->assertDatabaseMissing('dashboard_layouts', ['layout_key' => 'staff_divisi:perencanaan-pengusahaan']);
        $this->assertDatabaseHas('dashboard_layouts', ['layout_key' => 'staff_divisi']);
        $this->assertDatabaseHas('dashboard_layouts', ['layout_key' => 'staff_divisi:managemen-aset']);
        $this->assertDatabaseHas('dashboard_layouts', ['layout_key' => 'management']);

        // 2. Jika baris DB rusak/inkonsisten (mis. layout_key = 'management' tetapi division_id terisi),
        // resolver menolak baris tersebut dan jatuh ke fallback config management.
        DB::table('dashboard_layouts')
            ->where('layout_key', Role::MANAGEMENT)
            ->update(['division_id' => $divB->id]);

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tren NKO')
            ->assertDontSee('Distribusi NKO');

        // 3. Staf dengan divisi yang memiliki slug rusak di DB tidak memicu 500 dan jatuh ke fallback staff_divisi.
        $this->actingAs($staffBad)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('NKO (Nilai Kinerja Organisasi)');
    }

    public function test_layout_update_rejects_non_canonical_targets_such_as_management_or_other_divisions(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $otherDivision = Division::factory()->create(['name' => 'Engineer', 'slug' => 'engineer']);

        // 1. Role non-kanonis (mis. management atau editor_dashboard) ditolak 422
        $this->actingAs($admin)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::MANAGEMENT,
                'layout_key' => Role::MANAGEMENT,
                'widgets' => ['kpi-summary', 'trend-chart'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role_slug']);

        $this->actingAs($admin)
            ->deleteJson(route('dashboard.layout.reset'), [
                'role_slug' => Role::MANAGEMENT,
                'layout_key' => Role::MANAGEMENT,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role_slug']);

        // 2. Divisi di luar 2 divisi kanonis ditolak 422
        $this->actingAs($admin)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $otherDivision->id,
                'widgets' => ['kpi-summary'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['division_id']);

        $this->actingAs($admin)
            ->deleteJson(route('dashboard.layout.reset'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $otherDivision->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['division_id']);
    }

    public function test_editor_dashboard_cannot_modify_or_reset_admin_layout(): void
    {
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        // 1. PUT layout admin oleh editor ditolak 403 Forbidden
        $this->actingAs($editor)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::ADMIN,
                'layout_key' => Role::ADMIN,
                'widgets' => ['admin-summary'],
            ])
            ->assertForbidden();

        // 2. DELETE layout admin oleh editor ditolak 403 Forbidden
        $this->actingAs($editor)
            ->deleteJson(route('dashboard.layout.reset'), [
                'role_slug' => Role::ADMIN,
                'layout_key' => Role::ADMIN,
            ])
            ->assertForbidden();

        // 3. PUT target admin via parameter tunggal juga ditolak 403 Forbidden
        $this->actingAs($editor)
            ->putJson(route('dashboard.layout.update'), [
                'target' => Role::ADMIN,
                'widgets' => ['admin-summary'],
            ])
            ->assertForbidden();

        // 4. DELETE target admin via parameter tunggal juga ditolak 403 Forbidden
        $this->actingAs($editor)
            ->deleteJson(route('dashboard.layout.reset'), [
                'target' => Role::ADMIN,
            ])
            ->assertForbidden();
    }

    public function test_admin_layout_can_be_saved_without_layout_editor_placeholder_widget(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::ADMIN,
                'layout_key' => Role::ADMIN,
                'widgets' => ['admin-summary', 'recent-audit'],
            ]);

        $response->assertOk()
            ->assertJsonPath('layout_key', Role::ADMIN)
            ->assertJsonPath('widgets', ['admin-summary', 'recent-audit']);

        $this->assertDatabaseHas('dashboard_layouts', [
            'layout_key' => Role::ADMIN,
            'role_slug' => Role::ADMIN,
        ]);

        $saved = DashboardLayout::where('layout_key', Role::ADMIN)->firstOrFail();
        $this->assertSame(['admin-summary', 'recent-audit'], $saved->widgets);
    }

    public function test_halaman_admin_merender_state_performance_dashboard_saat_layout_admin_dikustomisasi_widget_kinerja(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        // 1. Secara default (fallback config), Admin Dashboard tidak memuat widget kinerja dan tidak merender performanceDashboard.
        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('x-data="performanceDashboard(', false);

        // 2. Saat layout admin dikustomisasi di DB dengan menyertakan 'kpi-summary', wrapper merender performanceDashboard.
        DashboardLayout::create([
            'role_slug' => Role::ADMIN,
            'widgets' => ['admin-summary', 'kpi-summary'],
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('x-data="performanceDashboard(', false)
            ->assertSee('NKO (Nilai Kinerja Organisasi)');

        // 3. Saat layout admin diubah ke 'trend-chart', wrapper juga tetap merender performanceDashboard.
        DashboardLayout::where('role_slug', Role::ADMIN)->update([
            'widgets' => ['admin-summary', 'trend-chart'],
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('x-data="performanceDashboard(', false)
            ->assertSee('Tren NKO');
    }

    public function test_penyimpanan_layout_admin_dapat_menyimpan_dengan_atau_tanpa_placeholder_dan_builder_tetap_aktif(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        // 1. Penyimpanan tanpa 'layout-editor-placeholder' untuk role Admin berhasil HTTP 200.
        $this->actingAs($admin)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::ADMIN,
                'layout_key' => Role::ADMIN,
                'widgets' => ['admin-summary', 'recent-audit'],
            ])
            ->assertOk()
            ->assertJsonPath('widgets', ['admin-summary', 'recent-audit']);

        $this->assertDatabaseHas('dashboard_layouts', [
            'layout_key' => Role::ADMIN,
            'role_slug' => Role::ADMIN,
        ]);

        // 2. Form request tanpa 'layout-editor-placeholder' berhasil redirect 302.
        $this->actingAs($admin)
            ->from(route('dashboard'))
            ->put(route('dashboard.layout.update'), [
                'role_slug' => Role::ADMIN,
                'layout_key' => Role::ADMIN,
                'widgets' => ['admin-summary'],
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        // 3. Penyimpanan yang menyertakan 'layout-editor-placeholder' juga tetap berhasil.
        $this->actingAs($admin)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::ADMIN,
                'layout_key' => Role::ADMIN,
                'widgets' => ['admin-summary', 'layout-editor-placeholder'],
            ])
            ->assertOk()
            ->assertJsonPath('widgets', ['admin-summary', 'layout-editor-placeholder']);

        // 4. Jalur baca builder: halaman builder menyediakan UI layout builder,
        // sedangkan halaman dashboard utama tidak menampilkan builder placeholder.
        $this->actingAs($admin)
            ->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('id="dashboard-layout-builder"', false);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="dashboard-layout-builder"', false);
    }

    public function test_render_chart_memiliki_null_guard_saat_elemen_canvas_tidak_ada(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee("const el = document.getElementById('performanceChart');", false)
            ->assertSee('if (!el) return;', false);
    }

    public function test_staff_divisi_tidak_melihat_filter_dashboard(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tren NKO')
            ->assertDontSee('name="start_date"', false)
            ->assertDontSee('name="unit[]"', false)
            ->assertDontSee('Terapkan');
    }

    public function test_builder_hanya_tiga_target_dan_simpan_sampai_ke_staf_divisi(): void
    {
        $pengusahaan = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $manset = Division::factory()->create(['slug' => 'managemen-aset']);
        Division::factory()->create(['slug' => 'perencanaan-aset']);
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $stafManset = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $manset->id]);

        // Existing generic row must not hijack the manset dashboard after a manset save.
        DashboardLayout::create(['role_slug' => Role::STAFF_DIVISI, 'division_id' => null, 'widgets' => ['nko-donut']]);

        $payload = $this->builderPayload($admin);
        $this->assertSame(
            ['admin', 'staff_divisi:perencanaan-pengusahaan', 'staff_divisi:managemen-aset'],
            array_column($payload['targets'], 'layout_key'),
        );
        $this->assertContains('trend-chart', $payload['targets'][1]['active_widgets']); // config, not DB generic
        $this->assertContains($this->builderPayload($editor)['initial_target_key'], array_column($payload['targets'], 'layout_key'));

        $this->actingAs($admin)->putJson(route('dashboard.layout.update'), [
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => $manset->id,
            'layout_key' => 'staff_divisi:managemen-aset',
            'widgets' => ['dataset-status'],
        ])->assertOk();

        $this->actingAs($stafManset)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Status Dataset')
            ->assertDontSee('Distribusi NKO');
    }

    /**
     * @return array<string, mixed>
     */
    private function builderPayload(User $user): array
    {
        $response = $this->actingAs($user)->get(route('dashboard.builder'))->assertOk();
        preg_match("/dashboardLayoutBuilder\('([^']+)'\)/", (string) $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? '');

        $payload = json_decode((string) base64_decode($matches[1], true), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    public function test_echarts_mark_line_component_is_registered_in_bundle(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('MarkLineComponent', $js);
        $this->assertStringContainsString('GaugeChart', $js);
    }

    public function test_widget_tren_nko_menampilkan_header_ringkas_tanpa_subjudul_kalimat(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tren NKO')
            ->assertSee('Target 100')
            ->assertDontSee('Tren muncul setelah 2+ bulan');
    }

    public function test_staff_dashboard_menampilkan_header_ringkas_dan_aksi_cepat(): void
    {
        $division = Division::factory()->create(['name' => 'Perencanaan Pengusahaan', 'slug' => 'perencanaan-pengusahaan']);
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Kinerja Pengusahaan')
            ->assertSee('TJBT')
            ->assertSee('Unggah Data')
            ->assertDontSee('Dashboard Divisi')
            ->assertDontSee('Dataset Siap:');
    }

    public function test_layout_staff_pengusahaan_memiliki_urutan_seimbang_tanpa_celah_grid(): void
    {
        $layout = config('dashboard_layouts.layouts')['staff_divisi:perencanaan-pengusahaan'];

        $this->assertCount(3, $layout);
        // Baris 1: kpi-summary (3)
        $this->assertSame('kpi-summary', $layout[0]);
        // Baris 2: trend-chart (2) + kpi-rag-status (1) = 3
        $this->assertSame('trend-chart', $layout[1]);
        $this->assertSame('kpi-rag-status', $layout[2]);
    }

    public function test_kpi_summary_menampilkan_badge_dan_ikon_konteks_informatif(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('NKO (Nilai Kinerja Organisasi)')
            ->assertSee('Target &ge; 100', false)
            ->assertSee('Rata-rata Capaian')
            ->assertSee('Maks 110%')
            ->assertSee('Memenuhi Target')
            ->assertSee('Perlu Perhatian')
            ->assertDontSee('Capaian per indikator')
            ->assertDontSee('Dataset Berhasil (Divisi)')
            ->assertDontSee('Baris Data Aktif');
    }

    public function test_executive_header_dan_navigasi_l3_mengikuti_rbac_masing_masing_role(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $asetDivision = Division::factory()->create(['slug' => 'managemen-aset']);
        $asetStaff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $asetDivision->id]);

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="executive-headline"', false)
            ->assertDontSee('id="kpi-methodology-note"', false)
            ->assertSee('Kinerja Pengusahaan')
            ->assertSee('id="dashboard-filter"', false)
            ->assertSee('Rincian KPI')
            ->assertDontSee('Analitik KPI')
            ->assertDontSee('Unggah Data');

        $this->actingAs($editor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="executive-headline"', false)
            ->assertDontSee('id="kpi-methodology-note"', false)
            ->assertSee('Kinerja Pengusahaan')
            ->assertSee('id="dashboard-filter"', false)
            ->assertSee('Rincian KPI')
            ->assertDontSee('Analitik KPI')
            ->assertDontSee('Unggah Data');

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="executive-headline"', false)
            ->assertDontSee('Kinerja Pengusahaan')
            ->assertDontSee('Rincian KPI')
            ->assertDontSee('Analitik KPI');

        $this->actingAs($asetStaff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="executive-headline"', false)
            ->assertDontSee('Kinerja Pengusahaan')
            ->assertDontSee('Rincian KPI')
            ->assertDontSee('Analitik KPI');
    }

    public function test_widget_pencapaian_nko_dan_rag_status_serta_nko_ranking_merender_kontrak_visual_baru(): void
    {
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $this->actingAs($editor);

        $pencapaianHtml = (string) $this->blade('<x-widgets.pencapaian-nko :data="$data" />', [
            'data' => [
                'period' => '2026-09-30',
                'labels' => ['TJBT'],
                'data' => [102.4],
            ],
        ]);
        $this->assertStringContainsString('type="application/json"', $pencapaianHtml);
        $this->assertStringNotContainsString('JSON.parse(\'', $pencapaianHtml);
        $this->assertStringContainsString('"labels":["TJBT"]', $pencapaianHtml);

        $ragHtml = (string) $this->blade('<x-widgets.kpi-rag-status :data="$data" />', [
            'data' => [
                'period' => '2026-09-30',
                'has_data' => true,
                'counts' => ['tercapai' => 10, 'hampir' => 4, 'perlu' => 2, 'total' => 16],
            ],
        ]);
        $this->assertStringContainsString('data-kpi-rag-chart="true"', $ragHtml);
        $this->assertStringContainsString('Status Indikator', $ragHtml);
        $this->assertStringContainsString('Tercapai', $ragHtml);
        $this->assertStringContainsString('Hampir Tercapai', $ragHtml);
        $this->assertStringContainsString('Perlu Peningkatan', $ragHtml);
        $this->assertStringNotContainsString('Total Indikator Kinerja', $ragHtml);
        $this->assertStringNotContainsString('Pencapaian &ge;', $ragHtml);
        $this->assertStringNotContainsString('Rincian KPI', $ragHtml);
        $this->assertStringNotContainsString('Analitik KPI', $ragHtml);

        $rankingHtml = (string) $this->blade('<x-widgets.nko-ranking :data="$rows" />', [
            'rows' => [(object) ['unit' => 'TJBT', 'nko' => 101.5]],
        ]);
        $this->assertStringContainsString('Peringkat NKO', $rankingHtml);
        $this->assertStringNotContainsString('NKO (Nilai Kinerja Organisasi)', $rankingHtml);
        $this->assertStringContainsString('Tercapai', $rankingHtml);
    }

    public function test_tercapai_di_kartu_5_sama_dengan_tercapai_di_rag(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);
        $orgUnit = OrgUnit::firstOrCreate(['name' => 'TJBT']);

        // Indikator 1: >= 100% (Tercapai)
        $ind1 = Indicator::create([
            'org_unit_id' => $orgUnit->id,
            'name' => 'Indikator A',
            'is_group' => false,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $ind1->id,
            'org_unit_id' => $orgUnit->id,
            'division_id' => $division->id,
            'period' => '2026-08-31',
            'target_value' => 100,
            'realisasi_value' => 105,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'pencapaian' => 1.05,
        ]);

        // Indikator 2: 95% <= Skor < 100% (Hampir Tercapai)
        $ind2 = Indicator::create([
            'org_unit_id' => $orgUnit->id,
            'name' => 'Indikator B',
            'is_group' => false,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $ind2->id,
            'org_unit_id' => $orgUnit->id,
            'division_id' => $division->id,
            'period' => '2026-08-31',
            'target_value' => 100,
            'realisasi_value' => 97,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'pencapaian' => 0.97,
        ]);

        // Indikator 3: < 95% (Perlu Peningkatan)
        $ind3 = Indicator::create([
            'org_unit_id' => $orgUnit->id,
            'name' => 'Indikator C',
            'is_group' => false,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $ind3->id,
            'org_unit_id' => $orgUnit->id,
            'division_id' => $division->id,
            'period' => '2026-08-31',
            'target_value' => 100,
            'realisasi_value' => 90,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'pencapaian' => 0.90,
        ]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $response = $this->actingAs($staff)->get(route('dashboard'))->assertOk();

        // Kartu 3 & 4 serta Widget RAG menampilkan status ringkas tanpa sub-teks berulang
        $response->assertSee('1 / 3');
        $response->assertSee('2 / 3');
        $response->assertSee('2 Indikator');
        $response->assertSee('Status Indikator');
        $response->assertSee('Tercapai');
        $response->assertSee('Hampir Tercapai');
        $response->assertSee('Perlu Peningkatan');
        $response->assertDontSee('Pencapaian &ge; 100%', false);
        $response->assertDontSee('95% &le; Skor &lt; 100%', false);
        $response->assertDontSee('Skor &lt; 95%', false);
        $response->assertDontSee('Total Indikator Kinerja');

        // Verifikasi kesetaraan data controller: tercapai kartu 5 == tercapai RAG
        $controller = app(DashboardController::class);
        $ref = new \ReflectionClass($controller);

        $kpiSummaryMethod = $ref->getMethod('kpiSummary');
        $kpiSummaryMethod->setAccessible(true);
        $kpiSummary = $kpiSummaryMethod->invoke($controller, $staff, null, null);

        $kpiRagMethod = $ref->getMethod('kpiRagStatus');
        $kpiRagMethod->setAccessible(true);
        $kpiRag = $kpiRagMethod->invoke($controller, $staff, null, null);

        $this->assertSame(1, $kpiSummary['achieved_count']);
        $this->assertSame(3, $kpiSummary['total_count']);
        $this->assertSame(1, $kpiRag['counts']['tercapai']);
        $this->assertSame(1, $kpiRag['counts']['hampir']);
        $this->assertSame(1, $kpiRag['counts']['perlu']);
        $this->assertSame(3, $kpiRag['counts']['total']);
        $this->assertSame($kpiSummary['achieved_count'], $kpiRag['counts']['tercapai']);
    }

    public function test_dataset_status_menampilkan_tautan_dan_kartu_kesehatan_operasional(): void
    {
        $division = Division::factory()->create();
        $dataset = Dataset::factory()->create(['status' => Dataset::STATUS_READY, 'division_id' => $division->id]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'metric_name' => 'TJBT | Indikator A | target',
            'value' => 100,
            'period' => '2026-08-31',
        ]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Status Dataset')
            ->assertSee('Dataset Berhasil (Divisi)')
            ->assertSee('Baris Data Aktif')
            ->assertDontSee('Baris Data Aktif (TJBT)')
            ->assertSee('Kelola Dataset');
    }

    public function test_staff_divisi_non_pengusahaan_melihat_kartu_panduan_alur_kerja(): void
    {
        $division = Division::factory()->create(['name' => 'Perencanaan Aset', 'slug' => 'perencanaan-aset']);
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ruang Kerja Divisi')
            ->assertSee('Perencanaan Aset')
            ->assertSee('Status Dataset');
    }

    public function test_staf_managemen_aset_tidak_melihat_kartu_panduan_karena_layout_demo_memuat_dua_widget(): void
    {
        $division = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Ruang Kerja Divisi')
            ->assertSee('Status Dataset')
            ->assertSee('Stok Aset per UPT');
    }

    public function test_staf_pengusahaan_tidak_melihat_widget_aset_dan_staf_managemen_aset_melihat_lengkap(): void
    {
        $pengusahaan = Division::factory()->create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);
        $manset = Division::factory()->create([
            'name' => 'Managemen Aset',
            'slug' => 'managemen-aset',
        ]);

        $staffPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'email' => 'staf.pengusahaan@dashboard.local',
            'division_id' => $pengusahaan->id,
        ]);
        $staffAset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'email' => 'staf.aset@dashboard.local',
            'division_id' => $manset->id,
        ]);

        $dataset = Dataset::factory()->create([
            'division_id' => $manset->id,
            'status' => Dataset::STATUS_READY,
        ]);

        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | BEKASI | BEKASI | Tower 150 KV | SU',
            'dataset_id' => $dataset->id,
            'division_id' => $manset->id,
            'period' => '2026-08-31',
            'value' => 10,
        ]);
        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | BEKASI | BEKASI | Transmisi 150 KV | SU | kms',
            'dataset_id' => $dataset->id,
            'division_id' => $manset->id,
            'period' => '2026-08-31',
            'value' => 25.5,
        ]);
        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | BEKASI | BEKASI | Trafo 500/150 KV | MVA',
            'dataset_id' => $dataset->id,
            'division_id' => $manset->id,
            'period' => '2026-08-31',
            'value' => 1000,
        ]);

        // 1. Staf Perencanaan Pengusahaan WAJIB bersih murni berisi widget KPI tanpa kotak aset
        $this->actingAs($staffPengusahaan)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Stok Aset per UPT')
            ->assertDontSee('Prototipe Demo (Rekapitulasi UPT)')
            ->assertDontSee('data-category-filter="tower"', false)
            ->assertDontSee('data-category-filter="transmisi"', false)
            ->assertDontSee('data-category-filter="trafo"', false)
            ->assertDontSee('Belum ada data aset.');

        // 2. Staf Manajemen Aset melihat widget aset secara lengkap (3 scorecard, tabel, dan grafik)
        $this->actingAs($staffAset)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Stok Aset per UPT')
            ->assertSee('Prototipe Demo (Rekapitulasi UPT)')
            ->assertSee('Total Tower')
            ->assertSee('Total Jalur Transmisi')
            ->assertSee('Total Kapasitas Trafo')
            ->assertSee('Rekapitulasi Matriks Fisik per UPT')
            ->assertSee('TOTAL UIT JBT')
            ->assertSee('asetUptChart', false)
            ->assertSee('data-category-filter="tower"', false)
            ->assertSee('data-category-filter="transmisi"', false)
            ->assertSee('data-category-filter="trafo"', false);
    }

    public function test_header_eksekutif_kpi_menampilkan_indikator_kesegaran_tanpa_peringatan_untuk_periode_bulan_berjalan(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 16, 10, 0, 0, 'Asia/Jakarta'));

        try {
            config(['kpi.read_model' => 'structured']);

            $pengusahaan = Division::factory()->create([
                'name' => 'Perencanaan Pengusahaan',
                'slug' => 'perencanaan-pengusahaan',
            ]);
            $manset = Division::factory()->create([
                'name' => 'Managemen Aset',
                'slug' => 'managemen-aset',
            ]);

            $dataset = Dataset::factory()->create([
                'division_id' => $pengusahaan->id,
                'status' => Dataset::STATUS_READY,
                'created_at' => Carbon::create(2026, 11, 16, 10, 0, 0, 'Asia/Jakarta'),
                'updated_at' => Carbon::create(2026, 11, 16, 10, 0, 0, 'Asia/Jakarta'),
            ]);

            PerformanceMetric::factory()->create([
                'dataset_id' => $dataset->id,
                'division_id' => $pengusahaan->id,
                'metric_name' => 'TJBT | NKO',
                'value' => 105.5,
                'period' => '2026-11-30',
            ]);

            $unit = OrgUnit::create(['name' => 'TJBT']);
            $indicator = Indicator::create([
                'org_unit_id' => $unit->id,
                'name' => 'Indikator Kesegaran Bulan Berjalan',
                'satuan' => '%',
                'category' => 'nko',
                'is_group' => false,
            ]);
            PerformanceObservation::create([
                'dataset_id' => $dataset->id,
                'indicator_id' => $indicator->id,
                'org_unit_id' => $unit->id,
                'division_id' => $pengusahaan->id,
                'period' => '2026-11-30',
                'target_value' => 100,
                'target_type' => 'numeric',
                'realisasi_value' => 100,
                'realisasi_type' => 'numeric',
                'pencapaian' => 1.0,
                'nilai' => 105.5,
                'source_sheet' => 'TJBT',
                'source_row' => 5,
            ]);

            $management = User::factory()->withRole(Role::MANAGEMENT)->create();
            $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
            $admin = User::factory()->withRole(Role::ADMIN)->create();
            $staffPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $pengusahaan->id]);
            $staffAset = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $manset->id]);

            foreach ([$management, $editor, $admin, $staffPengusahaan] as $actor) {
                $this->actingAs($actor)
                    ->get(route('dashboard'))
                    ->assertOk()
                    ->assertSee('id="kpi-freshness-indicator"', false)
                    ->assertSee('Diperbarui 16 Nov 2026, 10.00 WIB')
                    ->assertDontSee('Data periode berjalan belum tersedia')
                    ->assertDontSee('Belum ada data aktif');
            }

            $this->actingAs($staffAset)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee('id="kpi-freshness-indicator"', false)
                ->assertDontSee('Diperbarui 16 Nov 2026, 10.00 WIB');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_header_eksekutif_kpi_menampilkan_peringatan_stale_dan_terisolasi_dari_divisi_aset(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 16, 20, 15, 0, 'Asia/Jakarta'));

        try {
            config(['kpi.read_model' => 'structured']);

            $pengusahaan = Division::factory()->create([
                'name' => 'Perencanaan Pengusahaan',
                'slug' => 'perencanaan-pengusahaan',
            ]);
            $manset = Division::factory()->create([
                'name' => 'Managemen Aset',
                'slug' => 'managemen-aset',
            ]);

            $kpiDataset = Dataset::factory()->create([
                'division_id' => $pengusahaan->id,
                'status' => Dataset::STATUS_READY,
                'created_at' => Carbon::create(2026, 11, 10, 9, 30, 0, 'Asia/Jakarta'),
                'updated_at' => Carbon::create(2026, 11, 10, 9, 30, 0, 'Asia/Jakarta'),
            ]);

            PerformanceMetric::factory()->create([
                'dataset_id' => $kpiDataset->id,
                'division_id' => $pengusahaan->id,
                'metric_name' => 'TJBT | NKO',
                'value' => 104.2,
                'period' => '2026-10-31',
            ]);

            $unit = OrgUnit::create(['name' => 'TJBT']);
            $indicator = Indicator::create([
                'org_unit_id' => $unit->id,
                'name' => 'Indikator Bulan Lalu',
                'satuan' => '%',
                'category' => 'nko',
                'is_group' => false,
            ]);
            PerformanceObservation::create([
                'dataset_id' => $kpiDataset->id,
                'indicator_id' => $indicator->id,
                'org_unit_id' => $unit->id,
                'division_id' => $pengusahaan->id,
                'period' => '2026-10-31',
                'target_value' => 100,
                'target_type' => 'numeric',
                'realisasi_value' => 100,
                'realisasi_type' => 'numeric',
                'pencapaian' => 1.0,
                'nilai' => 104.2,
                'source_sheet' => 'TJBT',
                'source_row' => 5,
            ]);

            // Dataset Aset READY pada bulan berjalan TIDAK boleh memalsukan kesegaran KPI
            $asetDataset = Dataset::factory()->create([
                'division_id' => $manset->id,
                'status' => Dataset::STATUS_READY,
                'created_at' => Carbon::create(2026, 11, 16, 20, 15, 0, 'Asia/Jakarta'),
                'updated_at' => Carbon::create(2026, 11, 16, 20, 15, 0, 'Asia/Jakarta'),
            ]);
            PerformanceMetric::factory()->create([
                'dataset_id' => $asetDataset->id,
                'division_id' => $manset->id,
                'metric_name' => 'ASET | BEKASI | BEKASI | Tower 150 KV | SU',
                'value' => 25,
                'period' => '2026-11-30',
            ]);

            $management = User::factory()->withRole(Role::MANAGEMENT)->create();
            $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
            $admin = User::factory()->withRole(Role::ADMIN)->create();
            $staffPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $pengusahaan->id]);

            foreach ([$management, $editor, $admin, $staffPengusahaan] as $actor) {
                $this->actingAs($actor)
                    ->get(route('dashboard'))
                    ->assertOk()
                    ->assertSee('id="kpi-freshness-indicator"', false)
                    ->assertSee('Diperbarui 10 Nov 2026, 09.30 WIB')
                    ->assertSee('Data periode berjalan belum tersedia')
                    ->assertDontSee('Diperbarui 16 Nov 2026, 20.15 WIB')
                    ->assertDontSee('READY')
                    ->assertDontSee('ARCHIVED');
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_header_eksekutif_kpi_menangani_kondisi_kosong_tanpa_dataset_ready_secara_bersih(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 16, 10, 0, 0, 'Asia/Jakarta'));

        try {
            $pengusahaan = Division::factory()->create([
                'name' => 'Perencanaan Pengusahaan',
                'slug' => 'perencanaan-pengusahaan',
            ]);

            Dataset::factory()->create([
                'division_id' => $pengusahaan->id,
                'status' => Dataset::STATUS_ARCHIVED,
            ]);

            $management = User::factory()->withRole(Role::MANAGEMENT)->create();
            $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
            $admin = User::factory()->withRole(Role::ADMIN)->create();
            $staffPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $pengusahaan->id]);

            foreach ([$management, $editor, $admin, $staffPengusahaan] as $actor) {
                $html = $this->actingAs($actor)
                    ->get(route('dashboard'))
                    ->assertOk()
                    ->assertSee('id="kpi-freshness-indicator"', false)
                    ->assertSee('Belum ada data aktif')
                    ->assertSee('Belum ada dataset aktif untuk periode berjalan')
                    ->getContent();

                $visibleHtml = (string) preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
                $this->assertStringNotContainsString('—', $visibleHtml);
                $this->assertDoesNotMatchRegularExpression('/>\s*(?:-|–|—)+\s*</', $visibleHtml);
            }
        } finally {
            Carbon::setTestNow();
        }
    }
}
