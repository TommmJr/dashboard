<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    // Seeding roles & permissions
    public function test_seeder_membuat_empat_role(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(
            [Role::ADMIN, Role::MANAGEMENT, Role::EDITOR_DASHBOARD, Role::STAFF_DIVISI],
            Role::query()->orderBy('id')->pluck('slug')->all(),
        );
    }

    public function test_seeder_membuat_semua_permission_katalog(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(
            Permission::names(),
            Permission::query()->orderBy('id')->pluck('name')->all(),
        );
    }

    public function test_katalog_dan_matriks_permission_bebas_dari_permission_yatim(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $orphanedPermissions = [
            'report.export',
            'dashboard.create',
            'dashboard.delete',
            'dashboard.publish',
        ];

        $expectedCatalog = [
            'user.view',
            'user.create',
            'user.update',
            'user.delete',
            'dataset.view',
            'dataset.import',
            'dataset.update',
            'dataset.delete',
            'dashboard.view',
            'dashboard.update',
            'audit.view',
            'audit.view_all',
        ];

        $this->assertSame($expectedCatalog, Permission::names());

        foreach ($orphanedPermissions as $orphaned) {
            $this->assertFalse(Permission::exists($orphaned), "Permission yatim {$orphaned} tidak boleh ada di katalog.");
            $this->assertDatabaseMissing('permissions', ['name' => $orphaned]);
        }

        $matrix = RolePermissionSeeder::matrix();

        $this->assertSame($expectedCatalog, $matrix[Role::ADMIN]);
        $this->assertSame([
            'dashboard.view',
            'dataset.view',
            'audit.view',
            'audit.view_all',
        ], $matrix[Role::MANAGEMENT]);
        $this->assertSame([
            'dashboard.view',
            'dashboard.update',
            'dataset.view',
            'audit.view',
        ], $matrix[Role::EDITOR_DASHBOARD]);
        $this->assertSame([
            'dashboard.view',
            'dataset.view',
            'dataset.import',
            'dataset.update',
        ], $matrix[Role::STAFF_DIVISI]);
    }

    public function test_admin_memiliki_semua_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::where('slug', Role::ADMIN)->firstOrFail();

        $this->assertSame(
            collect(Permission::names())->sort()->values()->all(),
            $adminRole->permissions->pluck('name')->sort()->values()->all(),
        );
    }

    public function test_editor_dashboard_tidak_punya_hak_tulis_data(): void
    {
        // Aturan bisnis terpenting (confirmed mentor 2026-08-28): akses data
        // Editor Dashboard (IT) READ-ONLY mutlak. Boleh atur visual, dilarang
        // utak-atik data dalam bentuk apa pun.
        $this->seed(RolePermissionSeeder::class);

        $editor = Role::where('slug', Role::EDITOR_DASHBOARD)->firstOrFail();
        $permissions = $editor->permissions->pluck('name');

        $this->assertTrue($permissions->contains('dashboard.view'));
        $this->assertTrue($permissions->contains('dashboard.update'));
        $this->assertTrue($permissions->contains('dataset.view'));
        $this->assertTrue($permissions->contains('audit.view'));
        $this->assertFalse($permissions->contains('dataset.import'));
        $this->assertFalse($permissions->contains('dataset.update'));
        $this->assertFalse($permissions->contains('dataset.delete'));
        $this->assertFalse($permissions->contains('dashboard.create'));
        $this->assertFalse($permissions->contains('dashboard.delete'));
        $this->assertFalse($permissions->contains('dashboard.publish'));
        $this->assertFalse($permissions->contains('report.export'));
    }

    public function test_management_hanya_penonton_dan_penikmat_laporan(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $management = Role::where('slug', Role::MANAGEMENT)->firstOrFail();
        $permissions = $management->permissions->pluck('name');

        $this->assertTrue($permissions->contains('dashboard.view'));
        $this->assertTrue($permissions->contains('dataset.view'));
        $this->assertTrue($permissions->contains('audit.view'));
        $this->assertTrue($permissions->contains('audit.view_all'));
        $this->assertFalse($permissions->contains('report.export'));
        $this->assertFalse($permissions->contains('dataset.import'));
        $this->assertFalse($permissions->contains('dashboard.update'));
        $this->assertFalse($permissions->contains('user.create'));
    }

    public function test_staf_divisi_punya_hak_data_tapi_bukan_hak_visual(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $staff = Role::where('slug', Role::STAFF_DIVISI)->firstOrFail();
        $permissions = $staff->permissions->pluck('name');

        $this->assertTrue($permissions->contains('dataset.view'));
        $this->assertTrue($permissions->contains('dataset.import'));
        $this->assertTrue($permissions->contains('dataset.update'));
        $this->assertTrue($permissions->contains('dashboard.view'));
        $this->assertFalse($permissions->contains('dataset.delete'));
        $this->assertFalse($permissions->contains('dashboard.update'));
        $this->assertFalse($permissions->contains('audit.view'));
        $this->assertFalse($permissions->contains('user.view'));
        $this->assertFalse($permissions->contains('report.export'));
    }

    // Gate permission (otorisasi di backend)

    public function test_user_dengan_role_mendapat_permission_sesuai_matriks(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        $this->assertTrue(Gate::forUser($admin)->allows('dataset.import'));
        $this->assertTrue(Gate::forUser($admin)->allows('user.delete'));

        // Editor Dashboard: boleh atur visual, tidak boleh sentuh data.
        $this->assertTrue(Gate::forUser($editor)->allows('dashboard.update'));
        $this->assertTrue(Gate::forUser($editor)->denies('dataset.import'));
    }

    public function test_user_tanpa_role_ditolak_semua_permission(): void
    {
        $user = User::factory()->create(); // tanpa role

        $this->assertTrue(Gate::forUser($user)->denies('dashboard.view'));
        $this->assertTrue(Gate::forUser($user)->denies('dataset.import'));
    }

    // Proteksi route via middleware 'can:'

    public function test_halaman_users_wajib_login(): void
    {
        $this->get(route('users.index'))->assertRedirect(route('login'));
    }

    public function test_halaman_users_ditolak_403_tanpa_permission_user_view(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // Staf Divisi tidak punya user.view -> harus 403 meskipun sudah login.
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create();

        $this->actingAs($staff)->get(route('users.index'))->assertForbidden();
    }

    public function test_halaman_users_bisa_diakses_admin(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->withRole(Role::ADMIN)->create([
            'name' => 'Admin Utama',
        ]);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Admin Utama');
    }

    public function test_dashboard_tetap_terbuka_untuk_semua_role(): void
    {
        // Lihat dashboard transparan dalam Bidang Perencanaan:
        $this->seed(RolePermissionSeeder::class);

        foreach ([Role::ADMIN, Role::MANAGEMENT, Role::EDITOR_DASHBOARD, Role::STAFF_DIVISI] as $slug) {
            $user = User::factory()->withRole($slug)->create();

            $this->actingAs($user)->get(route('dashboard'))->assertOk();
        }
    }

    //  Proteksi permission pada route performance
    // NOTE: route performance.upload (PerformanceUploadController, Slice #1) sudah
    // dinonaktifkan — digantikan sepenuhnya oleh DatasetController::store() (POST /datasets).
    // Test otorisasi upload sekarang ada di DatasetImportTest.

    public function test_performance_data_api_ditolak_403_tanpa_permission_dashboard_view(): void
    {
        // User tanpa role sama sekali tidak punya dashboard.view.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('performance.data'))
            ->assertForbidden();
    }

    public function test_performance_data_api_bisa_diakses_semua_role(): void
    {
        // Semua role yang punya dashboard.view boleh akses API data.
        $this->seed(RolePermissionSeeder::class);

        foreach ([Role::ADMIN, Role::MANAGEMENT, Role::EDITOR_DASHBOARD, Role::STAFF_DIVISI] as $slug) {
            $user = User::factory()->withRole($slug)->create();

            $this->actingAs($user)
                ->getJson(route('performance.data'))
                ->assertOk();
        }
    }
}
