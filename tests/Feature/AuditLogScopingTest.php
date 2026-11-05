<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scoping halaman audit log per permission (matriks User Roles baris 70):
 * - Admin & Management (audit.view_all): melihat SELURUH entri, termasuk
 *   aktivitas non-data (login, perubahan user).
 * - Editor Dashboard / IT (audit.view saja): HANYA entri perubahan data
 *   (Dataset & PerformanceMetric) — "cuma liat perubahan datasetnya aja
 *   biar tim IT tau apa yang harus dibuat".
 * - Staf divisi: tetap 403 (tanpa audit.view).
 */
class AuditLogScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * Buat satu entri perubahan data (UPDATE dataset READY -> ARCHIVED)
     * dan satu entri non-data (LOGIN) sebagai pembanding.
     *
     * @return array{0: Dataset, 1: AuditLog, 2: AuditLog} [dataset, entriData, entriLogin]
     */
    private function seedAuditEntries(): array
    {
        $dataset = Dataset::factory()->create([
            'status' => Dataset::STATUS_READY,
            'division_id' => Division::factory()->create()->id,
        ]);

        $dataEntry = AuditLog::record(
            AuditLog::ACTION_UPDATE,
            $dataset,
            ['status' => Dataset::STATUS_READY],
            ['status' => Dataset::STATUS_ARCHIVED],
        );

        $loginEntry = AuditLog::record(AuditLog::ACTION_LOGIN, null, null, null, $dataset->user_id);

        return [$dataset, $dataEntry, $loginEntry];
    }

    public function test_editor_dashboard_hanya_melihat_entri_perubahan_data(): void
    {
        [$dataset, $dataEntry] = $this->seedAuditEntries();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        $response = $this->actingAs($editor)
            ->get(route('audit-logs.index'))
            ->assertOk();

        // Entri perubahan dataset tampil, dengan diff readable lama -> baru.
        $response->assertSee('Dataset #'.$dataset->id);
        $response->assertSee(Dataset::STATUS_READY);
        $response->assertSee(Dataset::STATUS_ARCHIVED);

        // Entri LOGIN TIDAK boleh terlihat oleh editor (label aksi "Masuk"
        // maupun objek "Aktivitas akun" absen total dari halaman).
        $response->assertDontSee('Aktivitas akun');
        $response->assertDontSee('Masuk');
        $response->assertSee('perubahan data saja');
    }

    public function test_admin_dan_management_melihat_seluruh_entri(): void
    {
        [$dataset] = $this->seedAuditEntries();

        foreach ([Role::ADMIN, Role::MANAGEMENT] as $slug) {
            $user = User::factory()->withRole($slug)->create();

            $response = $this->actingAs($user)
                ->get(route('audit-logs.index'))
                ->assertOk();

            $response->assertSee('Dataset #'.$dataset->id);
            $response->assertSee('Aktivitas akun');
            $response->assertSee('Masuk');
        }
    }

    public function test_entri_metric_juga_terlihat_oleh_editor(): void
    {
        $division = Division::factory()->create();
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $metric = PerformanceMetric::create([
            'metric_name' => 'Pendapatan',
            'value' => 100,
            'period' => '2026-01-15',
            'division_id' => $division->id,
            'dataset_id' => $dataset->id,
        ]);

        AuditLog::record(
            AuditLog::ACTION_UPDATE,
            $metric,
            ['value' => 100],
            ['value' => 250],
        );

        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        $response = $this->actingAs($editor)
            ->get(route('audit-logs.index'))
            ->assertOk();

        $response->assertSee('Baris data kinerja #'.$metric->id);
        $response->assertSee('250');
    }

    public function test_staf_divisi_tetap_ditolak_403(): void
    {
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create();

        $this->actingAs($staff)->get(route('audit-logs.index'))->assertForbidden();
    }

    /** Pagination aktif: lebih dari 50 entri menghasilkan halaman pertama berisi tepat 50 record. */
    public function test_pagination_membatasi_50_record_per_halaman(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $before = AuditLog::count();
        AuditLog::factory()->count(51)->create();

        $response = $this->actingAs($admin)
            ->get(route('audit-logs.index'))
            ->assertOk();

        $logs = $response->viewData('logs');
        $this->assertCount(50, $logs->items());
        $this->assertSame($before + 51, $logs->total());
        $this->assertTrue($logs->hasMorePages());
    }

    /** Filter action tetap bekerja saat pagination aktif. */
    public function test_filter_action_tetap_bekerja_dengan_pagination(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        // Catat jumlah LOGIN yang sudah ada (dari setUp user creation) sebelum menambah.
        $existingLoginCount = AuditLog::where('action', AuditLog::ACTION_LOGIN)->count();

        AuditLog::factory()->count(3)->create(['action' => AuditLog::ACTION_LOGIN]);
        AuditLog::factory()->count(2)->create(['action' => AuditLog::ACTION_CREATE]);

        $response = $this->actingAs($admin)
            ->get(route('audit-logs.index', ['action' => AuditLog::ACTION_LOGIN]))
            ->assertOk();

        $logs = $response->viewData('logs');
        $this->assertSame($existingLoginCount + 3, $logs->total());
    }

    /** Halaman kedua pagination dapat diakses dan berisi sisa record. */
    public function test_halaman_kedua_pagination_berisi_sisa_record(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $before = AuditLog::count();
        // Buat tepat cukup entri agar halaman kedua berisi 1 record.
        $needed = 50 - $before + 1;
        AuditLog::factory()->count($needed)->create();

        $response = $this->actingAs($admin)
            ->get(route('audit-logs.index', ['page' => 2]))
            ->assertOk();

        $logs = $response->viewData('logs');
        $this->assertCount(1, $logs->items());
    }

    public function test_reader_mengecualikan_entri_audit_noise_terstruktur_tetapi_mempertahankan_login(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        // Entri login (auditable_type = null)
        $login = AuditLog::record(AuditLog::ACTION_LOGIN, null, null, null, $admin->id);

        // Entri noise terstruktur historis
        AuditLog::create([
            'user_id' => $admin->id,
            'action' => AuditLog::ACTION_CREATE,
            'auditable_type' => 'App\\Models\\PerformanceObservation',
            'auditable_id' => 999,
            'new_values' => ['value' => 100],
        ]);
        AuditLog::create([
            'user_id' => $admin->id,
            'action' => AuditLog::ACTION_CREATE,
            'auditable_type' => 'App\\Models\\Indicator',
            'auditable_id' => 888,
            'new_values' => ['name' => 'Test Indicator'],
        ]);
        AuditLog::create([
            'user_id' => $admin->id,
            'action' => AuditLog::ACTION_CREATE,
            'auditable_type' => 'App\\Models\\OrgUnit',
            'auditable_id' => 777,
            'new_values' => ['name' => 'TJBT'],
        ]);

        $response = $this->actingAs($admin)
            ->get(route('audit-logs.index'))
            ->assertOk();

        // Login log tetap terlihat
        $response->assertSee('Aktivitas akun');
        $response->assertSee('Masuk');

        // Noise internal terstruktur tidak tampil
        $response->assertDontSee('Observasi kinerja terstruktur #999');
        $response->assertDontSee('Indikator kinerja #888');
        $response->assertDontSee('Unit organisasi #777');
    }
}
