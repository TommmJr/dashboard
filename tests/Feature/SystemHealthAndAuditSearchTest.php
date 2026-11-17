<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetSheetsSyncService;
use App\Services\SystemHealthService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SystemHealthAndAuditSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_audit_log_keyword_search_matches_actor_ip_and_payload_for_admin_and_management(): void
    {
        $division = Division::factory()->create();
        $budi = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'name' => 'Budi Observabilitas',
            'division_id' => $division->id,
        ]);
        $siti = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'name' => 'Siti Nusantara',
            'division_id' => $division->id,
        ]);

        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'user_id' => $budi->id,
            'original_name' => 'Laporan_Triwulan_Khusus.xlsx',
        ]);

        AuditLog::create([
            'user_id' => $budi->id,
            'action' => AuditLog::ACTION_IMPORT,
            'auditable_type' => Dataset::class,
            'auditable_id' => $dataset->id,
            'new_values' => ['original_name' => 'Laporan_Triwulan_Khusus.xlsx'],
            'ip' => '10.99.88.77',
            'user_agent' => 'agent-khusus-satu',
        ]);

        AuditLog::create([
            'user_id' => $siti->id,
            'action' => AuditLog::ACTION_LOGIN,
            'auditable_type' => null,
            'auditable_id' => null,
            'new_values' => ['note' => 'Masuk rutin harian'],
            'ip' => '10.11.22.33',
            'user_agent' => 'agent-lain-dua',
        ]);

        foreach ([Role::ADMIN, Role::MANAGEMENT] as $roleSlug) {
            $viewer = User::factory()->withRole($roleSlug)->create();

            // Pencarian berdasarkan nama aktor
            $this->actingAs($viewer)
                ->get(route('audit-logs.index', ['q' => 'Budi Observabilitas']))
                ->assertOk()
                ->assertSee('10.99.88.77')
                ->assertDontSee('10.11.22.33');

            // Pencarian berdasarkan IP
            $this->actingAs($viewer)
                ->get(route('audit-logs.index', ['q' => '10.11.22.33']))
                ->assertOk()
                ->assertSee('Siti Nusantara')
                ->assertDontSee('10.99.88.77');

            // Pencarian berdasarkan payload new_values
            $this->actingAs($viewer)
                ->get(route('audit-logs.index', ['q' => 'Laporan_Triwulan_Khusus']))
                ->assertOk()
                ->assertSee('Budi Observabilitas')
                ->assertDontSee('Siti Nusantara');
        }
    }

    public function test_audit_log_keyword_search_escapes_sql_like_wildcards_percent_and_underscore(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $division = Division::factory()->create();
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'user_id' => $admin->id,
        ]);

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => AuditLog::ACTION_UPDATE,
            'auditable_type' => Dataset::class,
            'auditable_id' => $dataset->id,
            'new_values' => ['note' => 'Capaian 100% tercapai'],
            'ip' => '192.168.1.10',
            'user_agent' => 'probe_khusus_v1',
        ]);

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => AuditLog::ACTION_UPDATE,
            'auditable_type' => Dataset::class,
            'auditable_id' => $dataset->id,
            'new_values' => ['note' => 'Capaian 1000 tercapai'],
            'ip' => '192.168.1.11',
            'user_agent' => 'probeXkhususXv1',
        ]);

        // Wildcard '%' tidak boleh cocok dengan '1000'
        $this->actingAs($admin)
            ->get(route('audit-logs.index', ['q' => '100%']))
            ->assertOk()
            ->assertSee('192.168.1.10')
            ->assertDontSee('192.168.1.11');

        // Wildcard '_' tidak boleh cocok dengan karakter sembarang 'X'
        $this->actingAs($admin)
            ->get(route('audit-logs.index', ['q' => 'probe_khusus']))
            ->assertOk()
            ->assertSee('192.168.1.10')
            ->assertDontSee('192.168.1.11');

        // Input hanya '%' tidak boleh mengembalikan seluruh log
        $this->actingAs($admin)
            ->get(route('audit-logs.index', ['q' => '%']))
            ->assertOk()
            ->assertSee('192.168.1.10')
            ->assertDontSee('192.168.1.11');
    }

    public function test_audit_log_entity_filter_works_for_admin_and_management_and_scopes_editor_fail_closed(): void
    {
        $division = Division::factory()->create(['name' => 'Divisi Audit Uji']);
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);
        $dataset = Dataset::factory()->create(['division_id' => $division->id, 'user_id' => $staff->id]);
        $metric = PerformanceMetric::create([
            'metric_name' => 'Pendapatan Uji',
            'value' => 123,
            'period' => '2026-10-31',
            'division_id' => $division->id,
            'dataset_id' => $dataset->id,
        ]);

        AuditLog::create([
            'user_id' => $staff->id,
            'action' => AuditLog::ACTION_UPDATE,
            'auditable_type' => Dataset::class,
            'auditable_id' => $dataset->id,
            'old_values' => ['status' => Dataset::STATUS_READY],
            'new_values' => ['status' => Dataset::STATUS_ARCHIVED],
            'ip' => '10.10.1.1',
        ]);

        AuditLog::create([
            'user_id' => $staff->id,
            'action' => AuditLog::ACTION_UPDATE,
            'auditable_type' => PerformanceMetric::class,
            'auditable_id' => $metric->id,
            'old_values' => ['value' => 100],
            'new_values' => ['value' => 123],
            'ip' => '10.10.1.2',
        ]);

        AuditLog::create([
            'user_id' => $staff->id,
            'action' => AuditLog::ACTION_UPDATE,
            'auditable_type' => User::class,
            'auditable_id' => $staff->id,
            'old_values' => ['name' => 'Lama'],
            'new_values' => ['name' => 'Baru'],
            'ip' => '10.10.1.3',
        ]);

        AuditLog::create([
            'user_id' => $staff->id,
            'action' => AuditLog::ACTION_LOGIN,
            'auditable_type' => null,
            'auditable_id' => null,
            'ip' => '10.10.1.4',
        ]);

        foreach ([Role::ADMIN, Role::MANAGEMENT] as $roleSlug) {
            $viewer = User::factory()->withRole($roleSlug)->create();

            $this->actingAs($viewer)
                ->get(route('audit-logs.index', ['entity' => 'dataset']))
                ->assertOk()
                ->assertSee('10.10.1.1')
                ->assertDontSee('10.10.1.2')
                ->assertDontSee('10.10.1.3')
                ->assertDontSee('10.10.1.4');

            $this->actingAs($viewer)
                ->get(route('audit-logs.index', ['entity' => 'user']))
                ->assertOk()
                ->assertSee('10.10.1.3')
                ->assertDontSee('10.10.1.1')
                ->assertDontSee('10.10.1.4');

            $this->actingAs($viewer)
                ->get(route('audit-logs.index', ['entity' => 'account']))
                ->assertOk()
                ->assertSee('10.10.1.4')
                ->assertDontSee('10.10.1.1')
                ->assertDontSee('10.10.1.3');
        }

        // Editor Dashboard hanya boleh memilih entitas data (dataset / performance_metric)
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        $this->actingAs($editor)
            ->get(route('audit-logs.index', ['entity' => 'performance_metric']))
            ->assertOk()
            ->assertSee('10.10.1.2')
            ->assertDontSee('10.10.1.1')
            ->assertDontSee('10.10.1.3')
            ->assertDontSee('10.10.1.4');

        // Probing entitas non-data oleh Editor ditolak oleh validasi whitelist
        $this->actingAs($editor)
            ->get(route('audit-logs.index', ['entity' => 'user']))
            ->assertSessionHasErrors('entity');

        $this->actingAs($editor)
            ->get(route('audit-logs.index', ['entity' => 'account']))
            ->assertSessionHasErrors('entity');

        // Staf divisi tetap 403
        $this->actingAs($staff)
            ->get(route('audit-logs.index', ['q' => '10.10.1.1']))
            ->assertForbidden();
    }

    public function test_system_health_panel_displays_real_metrics_for_admin_and_is_hidden_from_non_admin(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('datasets/berkas-1.xlsx', str_repeat('A', 1024));
        Storage::disk('local')->put('datasets/berkas-2.xlsx', str_repeat('B', 2048));

        $asetDivision = Division::factory()->create([
            'name' => 'Managemen Aset',
            'slug' => 'managemen-aset',
        ]);
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        Dataset::factory()->create([
            'division_id' => $asetDivision->id,
            'user_id' => $admin->id,
            'original_name' => AssetSheetsSyncService::DISPLAY_NAME,
            'status' => DatasetStatus::READY,
        ]);

        DB::table('jobs')->insert([
            [
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => time(),
                'created_at' => time(),
            ],
            [
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => time(),
                'created_at' => time(),
            ],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Simulated failure',
            'failed_at' => now(),
        ]);

        $auditHtml = $this->actingAs($admin)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee('id="system-health-panel"', false)
            ->assertSee('Kesehatan Sistem')
            ->assertSee('Terhubung')
            ->assertSee('2 antrean tertunda')
            ->assertSee('1 tugas gagal')
            ->assertSee('Berhasil')
            ->assertSee('2 berkas')
            ->assertSee('3,0 KB')
            ->getContent();

        $this->assertStringNotContainsString('font-mono', $auditHtml);
        $this->assertStringNotContainsString('failed_jobs', $auditHtml);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="system-health-panel"', false)
            ->assertSee('Kesehatan Sistem')
            ->assertSee('2 berkas');

        // Non-admin TIDAK boleh melihat atau menerima data System Health
        foreach ([Role::MANAGEMENT, Role::EDITOR_DASHBOARD] as $roleSlug) {
            $nonAdmin = User::factory()->withRole($roleSlug)->create();

            $auditResponse = $this->actingAs($nonAdmin)
                ->get(route('audit-logs.index'))
                ->assertOk()
                ->assertDontSee('id="system-health-panel"', false)
                ->assertDontSee('Kesehatan Sistem');

            $this->assertNull($auditResponse->viewData('systemHealth'));

            $this->actingAs($nonAdmin)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee('id="system-health-panel"', false)
                ->assertDontSee('Kesehatan Sistem');
        }

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $asetDivision->id]);
        $this->actingAs($staff)->get(route('audit-logs.index'))->assertForbidden();
        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="system-health-panel"', false);
    }

    public function test_system_health_service_sanitizes_database_failures_without_leaking_raw_exception(): void
    {
        Storage::fake('local');
        $brokenConnection = $this->createStub(Connection::class);
        $brokenConnection->method('getPdo')->willThrowException(
            new RuntimeException('SQLSTATE[HY000] [2002] Connection refused at /var/www/html/secret-dsn')
        );

        DB::shouldReceive('connection')->andReturn($brokenConnection);
        DB::shouldReceive('table')->andThrow(new RuntimeException('Internal SQL error'));

        $snapshot = app(SystemHealthService::class)->snapshot();

        $this->assertFalse($snapshot['database']['connected']);
        $this->assertSame('Terganggu', $snapshot['database']['status_label']);

        $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SQLSTATE', $serialized);
        $this->assertStringNotContainsString('/var/www/html', $serialized);
        $this->assertStringNotContainsString('secret-dsn', $serialized);
    }
}
