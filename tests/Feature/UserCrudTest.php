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
 * Test User CRUD — create / update / delete + RBAC guards.
 *
 * Semua aksi manajemen user (create/update/delete) hanya untuk admin.
 * user.view juga hanya admin berdasarkan RolePermissionSeeder.
 */
class UserCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private User $management;

    private User $editor;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->division = Division::factory()->create();
        $this->admin = User::factory()->withRole(Role::ADMIN)->create();
        $this->staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->division->id]);
        $this->management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $this->editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
    }

    // Index

    public function test_index_ditolak_403_tanpa_permission_user_view(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('users.index'))->assertForbidden();
    }

    // Create form

    public function test_create_form_hanya_bisa_diakses_admin(): void
    {
        $this->actingAs($this->admin)->get(route('users.create'))->assertOk();
        $this->actingAs($this->management)->get(route('users.create'))->assertForbidden();
        $this->actingAs($this->editor)->get(route('users.create'))->assertForbidden();
        $this->actingAs($this->staff)->get(route('users.create'))->assertForbidden();
    }

    // Store

    public function test_store_berhasil_dan_user_terbuat(): void
    {
        $divisionB = Division::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('users.store'), [
                'name' => 'User Baru',
                'email' => 'userbaru@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role_id' => Role::where('slug', Role::STAFF_DIVISI)->value('id'),
                'division_id' => $divisionB->id,
            ])
            ->assertRedirect(route('users.index'));

        $created = User::where('email', 'userbaru@example.com')->firstOrFail();
        $this->assertSame('User Baru', $created->name);
        $this->assertSame(Role::STAFF_DIVISI, $created->role->slug);
        $this->assertSame($divisionB->id, $created->division_id);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class,
            'auditable_id' => $created->id,
            'action' => AuditLog::ACTION_CREATE,
        ]);
    }

    public function test_store_gagal_email_duplikat(): void
    {
        $count = User::count();

        $this->actingAs($this->admin)
            ->post(route('users.store'), [
                'name' => 'Duplikat',
                'email' => $this->admin->email,
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role_id' => Role::where('slug', Role::ADMIN)->value('id'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertSame($count, User::count());
    }

    public function test_store_gagal_password_kurang_dari_8_karakter(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), [
                'name' => 'Pendek',
                'email' => 'pendek@example.com',
                'password' => '1234567',
                'password_confirmation' => '1234567',
                'role_id' => Role::where('slug', Role::ADMIN)->value('id'),
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_store_gagal_role_id_tidak_ada(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), [
                'name' => 'Tanpa Role',
                'email' => 'tanparole@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role_id' => 9999,
            ])
            ->assertSessionHasErrors('role_id');
    }

    // Edit form

    public function test_edit_form_hanya_bisa_diakses_admin(): void
    {
        $this->actingAs($this->admin)->get(route('users.edit', $this->staff))->assertOk();
        $this->actingAs($this->staff)->get(route('users.edit', $this->staff))->assertForbidden();
        $this->actingAs($this->management)->get(route('users.edit', $this->staff))->assertForbidden();
    }

    // Update

    public function test_update_berhasil(): void
    {
        $newRole = Role::where('slug', Role::MANAGEMENT)->firstOrFail();

        $this->actingAs($this->admin)
            ->patch(route('users.update', $this->staff), [
                'name' => 'Nama Baru',
                'email' => $this->staff->email,
                'role_id' => $newRole->id,
                'division_id' => $this->division->id,
            ])
            ->assertRedirect(route('users.index'));

        $this->staff->refresh();
        $this->assertSame('Nama Baru', $this->staff->name);
        $this->assertSame(Role::MANAGEMENT, $this->staff->role->slug);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class,
            'auditable_id' => $this->staff->id,
            'action' => AuditLog::ACTION_UPDATE,
        ]);
    }

    public function test_update_password_opsional(): void
    {
        $originalHash = $this->staff->password;

        // PATCH tanpa password — hash tidak berubah
        $this->actingAs($this->admin)
            ->patch(route('users.update', $this->staff), [
                'name' => $this->staff->name,
                'email' => $this->staff->email,
                'role_id' => $this->staff->role_id,
                'division_id' => $this->staff->division_id,
            ])
            ->assertRedirect();

        $this->assertSame($originalHash, $this->staff->fresh()->password);

        // PATCH dengan password baru — hash berubah
        $this->actingAs($this->admin)
            ->patch(route('users.update', $this->staff), [
                'name' => $this->staff->name,
                'email' => $this->staff->email,
                'role_id' => $this->staff->role_id,
                'division_id' => $this->staff->division_id,
                'password' => 'newpassword99',
                'password_confirmation' => 'newpassword99',
            ])
            ->assertRedirect();

        $this->assertNotSame($originalHash, $this->staff->fresh()->password);
    }

    public function test_update_email_unique_kecuali_dirinya(): void
    {
        $otherUser = User::factory()->create(['email' => 'other@example.com']);

        // Email milik user lain → ditolak
        $this->actingAs($this->admin)
            ->patch(route('users.update', $this->staff), [
                'name' => $this->staff->name,
                'email' => 'other@example.com',
                'role_id' => $this->staff->role_id,
            ])
            ->assertSessionHasErrors('email');

        // Email dirinya sendiri → lolos
        $this->actingAs($this->admin)
            ->patch(route('users.update', $this->staff), [
                'name' => $this->staff->name,
                'email' => $this->staff->email,
                'role_id' => $this->staff->role_id,
            ])
            ->assertRedirect(route('users.index'));
    }

    // Delete

    public function test_delete_oleh_admin_berhasil(): void
    {
        $target = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->division->id]);
        $targetId = $target->id;

        // Buat dataset + metrics agar cascade bisa diverifikasi
        $dataset = Dataset::factory()->create([
            'user_id' => $targetId,
            'division_id' => $this->division->id,
        ]);
        PerformanceMetric::factory()->count(2)->create([
            'dataset_id' => $dataset->id,
            'division_id' => $this->division->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('users.destroy', $target))
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseMissing('users', ['id' => $targetId]);

        // Audit log DELETE tercatat
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class,
            'auditable_id' => $targetId,
            'action' => AuditLog::ACTION_DELETE,
        ]);

        // Dataset tetap ada, user_id di-set NULL (nullOnDelete)
        $this->assertDatabaseHas('datasets', ['id' => $dataset->id, 'user_id' => null]);
    }

    public function test_delete_ditolak_403_untuk_non_admin(): void
    {
        $target = User::factory()->withRole(Role::STAFF_DIVISI)->create();

        foreach ([$this->management, $this->editor, $this->staff] as $actor) {
            $this->actingAs($actor)
                ->delete(route('users.destroy', $target))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    // Division optional

    public function test_delete_diri_sendiri_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('users.destroy', $this->admin))
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
        $this->assertNotNull(User::find($this->admin->id));
    }

    public function test_delete_user_menull_kan_user_id_di_audit_logs(): void
    {
        // Buat audit log yang tercatat atas nama target
        $target = User::factory()->withRole(Role::STAFF_DIVISI)->create();
        $log = AuditLog::create([
            'user_id' => $target->id,
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'action' => AuditLog::ACTION_CREATE,
            'new_values' => [],
        ]);

        $this->actingAs($this->admin)
            ->delete(route('users.destroy', $target))
            ->assertRedirect(route('users.index'));

        $this->assertNull($log->fresh()->user_id);
    }

    public function test_delete_user_tanpa_dataset_berhasil(): void
    {
        $target = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($this->admin)
            ->delete(route('users.destroy', $target))
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_store_ditolak_403_untuk_non_admin(): void
    {
        $roleId = Role::where('slug', Role::STAFF_DIVISI)->value('id');

        foreach ([$this->management, $this->editor, $this->staff] as $actor) {
            $this->actingAs($actor)
                ->post(route('users.store'), [
                    'name' => 'Coba',
                    'email' => 'coba'.$actor->id.'@example.com',
                    'password' => 'password123',
                    'password_confirmation' => 'password123',
                    'role_id' => $roleId,
                ])
                ->assertForbidden();
        }
    }

    // Division optional

    public function test_division_opsional_bagi_admin(): void
    {
        // Tanpa division
        $this->actingAs($this->admin)
            ->post(route('users.store'), [
                'name' => 'Tanpa Divisi',
                'email' => 'nodivision@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role_id' => Role::where('slug', Role::ADMIN)->value('id'),
            ])
            ->assertRedirect(route('users.index'));

        $this->assertNull(User::where('email', 'nodivision@example.com')->value('division_id'));

        // Dengan division
        $this->actingAs($this->admin)
            ->post(route('users.store'), [
                'name' => 'Dengan Divisi',
                'email' => 'withdivision@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role_id' => Role::where('slug', Role::STAFF_DIVISI)->value('id'),
                'division_id' => $this->division->id,
            ])
            ->assertRedirect(route('users.index'));

        $this->assertSame($this->division->id, User::where('email', 'withdivision@example.com')->value('division_id'));
    }
}
