<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DashboardLayout;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test Division CRUD — index / create / store / edit / update / destroy + RBAC.
 *
 * Division management di-gate via permission user.view / user.create /
 * user.update / user.delete (semua dimiliki admin). Role lain tidak bisa
 * mengakses endpoint ini.
 */
class DivisionCrudTest extends TestCase
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

        $this->division = Division::factory()->create(['name' => 'Divisi Awal', 'slug' => 'divisi-awal']);
        $this->admin = User::factory()->withRole(Role::ADMIN)->create();
        $this->staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->division->id]);
        $this->management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $this->editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
    }

    // --- Index ---

    public function test_index_dapat_diakses_admin(): void
    {
        $this->actingAs($this->admin)
            ->get(route('divisions.index'))
            ->assertOk()
            ->assertSee('Divisi Awal');
    }

    public function test_index_ditolak_403_non_admin(): void
    {
        foreach ([$this->staff, $this->management, $this->editor] as $actor) {
            $this->actingAs($actor)
                ->get(route('divisions.index'))
                ->assertForbidden();
        }
    }

    // --- Create form ---

    public function test_create_form_hanya_admin(): void
    {
        $this->actingAs($this->admin)->get(route('divisions.create'))->assertOk();
        $this->actingAs($this->staff)->get(route('divisions.create'))->assertForbidden();
        $this->actingAs($this->management)->get(route('divisions.create'))->assertForbidden();
    }

    // --- Store ---

    public function test_store_berhasil_membuat_divisi(): void
    {
        $this->actingAs($this->admin)
            ->post(route('divisions.store'), [
                'name' => 'Divisi Baru',
                'description' => 'Deskripsi singkat.',
            ])
            ->assertRedirect(route('divisions.index'));

        $this->assertDatabaseHas('divisions', [
            'name' => 'Divisi Baru',
            'slug' => 'divisi-baru',
            'description' => 'Deskripsi singkat.',
        ]);
    }

    public function test_store_gagal_nama_duplikat(): void
    {
        $count = Division::count();

        $this->actingAs($this->admin)
            ->post(route('divisions.store'), [
                'name' => 'Divisi Awal', // sudah ada
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame($count, Division::count());
    }

    public function test_store_gagal_nama_kosong(): void
    {
        $this->actingAs($this->admin)
            ->post(route('divisions.store'), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_store_ditolak_403_non_admin(): void
    {
        foreach ([$this->staff, $this->management, $this->editor] as $actor) {
            $this->actingAs($actor)
                ->post(route('divisions.store'), ['name' => 'Coba'])
                ->assertForbidden();
        }
    }

    // --- Edit form ---

    public function test_edit_form_hanya_admin(): void
    {
        $this->actingAs($this->admin)
            ->get(route('divisions.edit', $this->division))
            ->assertOk()
            ->assertSee('Divisi Awal');

        $this->actingAs($this->staff)
            ->get(route('divisions.edit', $this->division))
            ->assertForbidden();
    }

    // --- Update ---

    public function test_updating_division_name_preserves_existing_canonical_slug_and_kpi_import_access(): void
    {
        $division = Division::factory()->create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);

        DashboardLayout::create([
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => $division->id,
            'widgets' => ['kpi-summary', 'dataset-status'],
            'updated_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('divisions.update', $division), [
                'name' => 'Subbidang Perencanaan Pengusahaan',
                'description' => 'Nama baru tetap menjaga slug kanonis.',
            ])
            ->assertRedirect(route('divisions.index'));

        $division->refresh();
        $this->assertSame('Subbidang Perencanaan Pengusahaan', $division->name);
        $this->assertSame('perencanaan-pengusahaan', $division->slug, 'Slug divisi wajib immutable saat rename.');

        $this->assertDatabaseHas('dashboard_layouts', [
            'layout_key' => 'staff_divisi:perencanaan-pengusahaan',
            'division_id' => $division->id,
        ]);
    }

    public function test_updating_division_ignores_attempted_slug_mutation_in_payload(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('divisions.update', $this->division), [
                'name' => 'Divisi Nama Baru',
                'slug' => 'slug-baru-yang-dilarang',
            ])
            ->assertRedirect(route('divisions.index'));

        $this->assertSame('divisi-awal', $this->division->fresh()->slug);
    }

    public function test_store_gagal_jika_slug_bertabrakan_dengan_divisi_lain(): void
    {
        Division::factory()->create(['name' => 'Divisi Khusus', 'slug' => 'divisi-khusus']);

        $this->actingAs($this->admin)
            ->post(route('divisions.store'), [
                'name' => 'Divisi Khusus!',
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_update_berhasil(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('divisions.update', $this->division), [
                'name' => 'Divisi Diperbarui',
                'description' => 'Deskripsi baru.',
            ])
            ->assertRedirect(route('divisions.index'));

        $this->division->refresh();
        $this->assertSame('Divisi Diperbarui', $this->division->name);
        $this->assertSame('divisi-awal', $this->division->slug, 'Slug divisi harus tetap divisi-awal.');
        $this->assertSame('Deskripsi baru.', $this->division->description);
    }

    public function test_update_nama_sama_dengan_dirinya_lolos(): void
    {
        // Unique kecuali dirinya sendiri
        $this->actingAs($this->admin)
            ->patch(route('divisions.update', $this->division), [
                'name' => 'Divisi Awal',
                'description' => 'Update deskripsi saja.',
            ])
            ->assertRedirect(route('divisions.index'));

        $this->assertSame('Divisi Awal', $this->division->fresh()->name);
        $this->assertSame('Update deskripsi saja.', $this->division->fresh()->description);
    }

    public function test_update_gagal_nama_duplikat_dengan_divisi_lain(): void
    {
        Division::factory()->create(['name' => 'Divisi Lain', 'slug' => 'divisi-lain']);

        $this->actingAs($this->admin)
            ->patch(route('divisions.update', $this->division), [
                'name' => 'Divisi Lain',
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_update_ditolak_403_non_admin(): void
    {
        foreach ([$this->staff, $this->management, $this->editor] as $actor) {
            $this->actingAs($actor)
                ->patch(route('divisions.update', $this->division), ['name' => 'Coba'])
                ->assertForbidden();
        }
    }

    // --- Destroy ---

    public function test_destroy_berhasil_divisi_kosong(): void
    {
        $empty = Division::factory()->create(['name' => 'Kosong', 'slug' => 'kosong']);

        $this->actingAs($this->admin)
            ->delete(route('divisions.destroy', $empty))
            ->assertRedirect(route('divisions.index'));

        $this->assertDatabaseMissing('divisions', ['id' => $empty->id]);
    }

    public function test_destroy_ditolak_jika_masih_ada_user(): void
    {
        // $this->division sudah punya $this->staff sebagai user
        $this->actingAs($this->admin)
            ->delete(route('divisions.destroy', $this->division))
            ->assertRedirect(route('divisions.index'));

        // Divisi tidak terhapus
        $this->assertDatabaseHas('divisions', ['id' => $this->division->id]);
    }

    public function test_destroy_ditolak_jika_masih_ada_dataset(): void
    {
        $empty = Division::factory()->create(['name' => 'Punya Dataset', 'slug' => 'punya-dataset']);
        Dataset::factory()->create(['division_id' => $empty->id]);

        $this->actingAs($this->admin)
            ->delete(route('divisions.destroy', $empty))
            ->assertRedirect(route('divisions.index'));

        $this->assertDatabaseHas('divisions', ['id' => $empty->id]);
    }

    public function test_destroy_ditolak_403_non_admin(): void
    {
        $empty = Division::factory()->create(['name' => 'Target', 'slug' => 'target']);

        foreach ([$this->staff, $this->management, $this->editor] as $actor) {
            $this->actingAs($actor)
                ->delete(route('divisions.destroy', $empty))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('divisions', ['id' => $empty->id]);
    }

    // --- Audit log ---

    public function test_store_mencatat_audit_log_create_divisi(): void
    {
        $this->actingAs($this->admin)
            ->post(route('divisions.store'), [
                'name' => 'Divisi Dicatat',
                'description' => 'Audit test.',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_CREATE,
            'auditable_type' => Division::class,
            'auditable_id' => Division::where('slug', 'divisi-dicatat')->value('id'),
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_update_mencatat_audit_log_update_divisi(): void
    {
        $before = Division::where('slug', 'divisi-awal')->value('id');

        $this->actingAs($this->admin)
            ->patch(route('divisions.update', $this->division), [
                'name' => 'Nama Baru',
                'description' => 'Desc baru.',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_UPDATE,
            'auditable_type' => Division::class,
            'auditable_id' => $before,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_destroy_mencatat_audit_log_delete_divisi(): void
    {
        $empty = Division::factory()->create(['name' => 'Untuk Hapus', 'slug' => 'untuk-hapus']);
        $deleteId = $empty->id;

        $this->actingAs($this->admin)
            ->delete(route('divisions.destroy', $empty));

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_DELETE,
            'auditable_type' => Division::class,
            'auditable_id' => $deleteId,
            'user_id' => $this->admin->id,
        ]);
    }
}
