<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $management;

    private User $editor;

    private User $staffA;

    private User $staffB;

    private Division $divisionA;

    private Division $divisionB;

    protected function setUp(): void
    {
        parent::setUp();
        // Fitur Reports dinonaktifkan sementara 2026-09-15 - route /reports/* di-comment,
        // lihat routes/web.php dan progress.md. Aktifkan lagi bersama route-nya.
        $this->markTestSkipped('Reports feature dinonaktifkan sementara 2026-09-15 - lihat progress.md.');

        $this->seed(RolePermissionSeeder::class);

        $this->divisionA = Division::factory()->create(['name' => 'Divisi A']);
        $this->divisionB = Division::factory()->create(['name' => 'Divisi B']);

        $this->admin = User::factory()->withRole(Role::ADMIN)->create();
        $this->management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $this->editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => $this->divisionA->id]);
        $this->staffA = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divisionA->id]);
        $this->staffB = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divisionB->id]);
    }

    /** Guest diredirect ke login. */
    public function test_guest_diredirect_ke_login(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));
    }

    /** Admin dapat mengakses halaman reports. */
    public function test_admin_dapat_mengakses_halaman_reports(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertViewIs('reports.index');
    }

    /** Management dapat mengakses halaman reports. */
    public function test_management_dapat_mengakses_halaman_reports(): void
    {
        $this->actingAs($this->management)
            ->get(route('reports.index'))
            ->assertOk();
    }

    /** Editor dapat mengakses halaman reports. */
    public function test_editor_dapat_mengakses_halaman_reports(): void
    {
        $this->actingAs($this->editor)
            ->get(route('reports.index'))
            ->assertOk();
    }

    /** Staff divisi dapat mengakses halaman reports. */
    public function test_staff_dapat_mengakses_halaman_reports(): void
    {
        $this->actingAs($this->staffA)
            ->get(route('reports.index'))
            ->assertOk();
    }

    /** Halaman menampilkan link dataset summary dan validation export. */
    public function test_halaman_menampilkan_link_report_yang_tersedia(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee(route('reports.dataset-summary'))
            ->assertSee(route('reports.audit-export'));
    }

    /** Staff hanya melihat dataset divisinya sendiri di daftar validasi. */
    public function test_staff_hanya_melihat_dataset_divisinya_di_daftar_validasi(): void
    {
        $dsA = Dataset::factory()->create(['division_id' => $this->divisionA->id, 'user_id' => $this->staffA->id, 'original_name' => 'file-a.csv']);
        $dsB = Dataset::factory()->create(['division_id' => $this->divisionB->id, 'user_id' => $this->staffB->id, 'original_name' => 'file-b.csv']);

        $response = $this->actingAs($this->staffA)
            ->get(route('reports.index'));

        $response->assertOk();
        $response->assertSee('file-a.csv');
        $response->assertDontSee('file-b.csv');
    }

    /** Admin melihat semua dataset lintas divisi di daftar validasi. */
    public function test_admin_melihat_semua_dataset_di_daftar_validasi(): void
    {
        $dsA = Dataset::factory()->create(['division_id' => $this->divisionA->id, 'user_id' => $this->staffA->id, 'original_name' => 'file-a.csv']);
        $dsB = Dataset::factory()->create(['division_id' => $this->divisionB->id, 'user_id' => $this->staffB->id, 'original_name' => 'file-b.csv']);

        $response = $this->actingAs($this->admin)
            ->get(route('reports.index'));

        $response->assertOk();
        $response->assertSee('file-a.csv');
        $response->assertSee('file-b.csv');
    }

    /** Audit log export link hanya tampil untuk user dengan audit.view. */
    public function test_audit_export_link_hanya_tampil_untuk_user_dengan_audit_view(): void
    {
        // staff tidak punya audit.view — link audit tidak tampil
        $this->actingAs($this->staffA)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertDontSee(route('reports.audit-export'));
    }
}
