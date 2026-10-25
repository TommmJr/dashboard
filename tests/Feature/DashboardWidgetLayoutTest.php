<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertDontSee('Ringkasan KPI')
            ->assertDontSee('Grafik Tren Kinerja')
            ->assertDontSee('Peringkat NKO Unit');
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

    public function test_management_tidak_melihat_widget_admin(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Grafik Tren Kinerja')
            ->assertDontSee('Ringkasan Operasional')
            ->assertDontSee('Aksi Cepat')
            ->assertDontSee('Audit Terbaru')
            ->assertDontSee('Dataset Gagal')
            ->assertDontSee('Editor Layout');
    }

    public function test_editor_dashboard_melihat_placeholder_layout_editor(): void
    {
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        $this->actingAs($editor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Editor Layout')
            ->assertSee('Mode edit layout menyusul (Fase 2)')
            ->assertDontSee('Audit Terbaru');
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
            ->assertSee('Dataset READY (Divisi)')
            ->assertSee('Baris Data Aktif (TJBT)')
            ->assertDontSee('Baris Aktif')
            ->assertDontSee('Rata-rata NKO')
            ->assertDontSee('Grafik Tren Kinerja')
            ->assertDontSee('Peringkat NKO Unit')
            ->assertDontSee('Target vs Realisasi')
            ->assertDontSee('Distribusi NKO');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Grafik Tren Kinerja')
            ->assertDontSee('Audit Terbaru');
    }

    public function test_staff_pengusahaan_melihat_layout_kpi(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Rata-rata NKO')
            ->assertSee('Dataset READY (Divisi)')
            ->assertSee('Baris Data Aktif (TJBT)')
            ->assertSee('Grafik Tren Kinerja')
            // Widget LAMA dicabut dari layout 2026-10-19 (keputusan user): R8
            // masih menjumlah lintas satuan. Judulnya dikunci dengan literal
            // persis ('Target vs Realisasi') karena label widget PENGGANTI
            // ("Target dan Realisasi per Indikator") tidak memuat substring
            // itu; substring 'Realisasi' saja akan cocok ke keduanya.
            ->assertDontSee('Target vs Realisasi')
            // Widget BARU (keputusan Pak Gama 2026-10-19 11:17) ter-mount.
            ->assertSee('Target dan Realisasi per Indikator</h3>', false)
            ->assertSee('Distribusi NKO')
            ->assertDontSee('Audit Terbaru')
            ->assertDontSee('Dataset Gagal')
            ->assertDontSee('Editor Layout');
    }

    /**
     * Widget lama dan baru punya NAMA KATALOG yang berbeda; kunci keduanya di
     * level layout supaya pemasangan/penghapusan tidak tertukar.
     */
    public function test_layout_staff_pengusahaan_memakai_widget_per_indikator_bukan_widget_lama(): void
    {
        $layout = config('dashboard_layouts.layouts')['staff_divisi:perencanaan-pengusahaan'];
        $widgets = config('dashboard_layouts.widgets');

        $this->assertContains('target-realisasi-per-indikator', $layout);
        $this->assertArrayHasKey('target-realisasi-per-indikator', $widgets);
        $this->assertNotContains('target-vs-realisasi', $layout);
        $this->assertArrayNotHasKey('target-vs-realisasi', $widgets);
    }
}
