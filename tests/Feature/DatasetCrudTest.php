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
 * Test Tahap 3 - Dataset CRUD (index, show, archive, delete).
 *
 * Aturan bisnis yang dikunci (keputusan archive-vs-delete):
 * - Staf Divisi: hanya melihat & mengarsipkan dataset divisinya sendiri.
 * - Admin: melihat semua + delete permanen.
 * - Editor Dashboard (IT) & Management: read-only mutlak (403 pada aksi tulis).
 * - Dataset ARCHIVED: hilang dari index default & agregasi chart.
 */
class DatasetCrudTest extends TestCase
{
    use RefreshDatabase;

    private Division $divisionA;

    private Division $divisionB;

    private User $staffA;

    private User $staffB;

    private User $admin;

    private User $editor;

    private User $management;

    private Dataset $datasetA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->divisionA = Division::factory()->create();
        $this->divisionB = Division::factory()->create();

        $this->staffA = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divisionA->id]);
        $this->staffB = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divisionB->id]);
        $this->admin = User::factory()->withRole(Role::ADMIN)->create();
        $this->editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => $this->divisionA->id]);
        $this->management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->datasetA = Dataset::factory()->create([
            'division_id' => $this->divisionA->id,
            'user_id' => $this->staffA->id,
            'original_name' => 'laporan-divisi-a.csv',
        ]);
    }

    public function test_index_ditolak_403_tanpa_permission_dataset_view(): void
    {
        $user = User::factory()->create(); // tanpa role

        $this->actingAs($user)->get(route('datasets.index'))->assertForbidden();
    }

    public function test_index_staf_hanya_melihat_dataset_divisinya(): void
    {
        Dataset::factory()->create([
            'division_id' => $this->divisionB->id,
            'original_name' => 'laporan-divisi-b.csv',
        ]);

        $response = $this->actingAs($this->staffA)->get(route('datasets.index'));

        $response->assertOk()
            ->assertSee('laporan-divisi-a.csv')
            ->assertDontSee('laporan-divisi-b.csv');
    }

    public function test_index_admin_melihat_semua_divisi(): void
    {
        Dataset::factory()->create([
            'division_id' => $this->divisionB->id,
            'original_name' => 'laporan-divisi-b.csv',
        ]);

        $this->actingAs($this->admin)->get(route('datasets.index'))
            ->assertOk()
            ->assertSee('laporan-divisi-a.csv')
            ->assertSee('laporan-divisi-b.csv');
    }

    public function test_index_default_menyembunyikan_dataset_archived(): void
    {
        Dataset::factory()->archived()->create([
            'division_id' => $this->divisionA->id,
            'original_name' => 'laporan-lama.csv',
        ]);

        $this->actingAs($this->admin)->get(route('datasets.index'))
            ->assertOk()
            ->assertSee('laporan-divisi-a.csv')
            ->assertDontSee('laporan-lama.csv');

        $this->actingAs($this->admin)->get(route('datasets.index', ['status' => 'ARCHIVED']))
            ->assertOk()
            ->assertSee('laporan-lama.csv')
            ->assertDontSee('laporan-divisi-a.csv');
    }

    public function test_show_200_untuk_pemilik_dan_role_privileged(): void
    {
        $this->actingAs($this->staffA)->get(route('datasets.show', $this->datasetA))->assertOk();
        $this->actingAs($this->admin)->get(route('datasets.show', $this->datasetA))->assertOk();
        $this->actingAs($this->management)->get(route('datasets.show', $this->datasetA))->assertOk();
    }

    public function test_show_403_untuk_staf_divisi_lain(): void
    {
        $this->actingAs($this->staffB)->get(route('datasets.show', $this->datasetA))->assertForbidden();
    }

    public function test_archive_oleh_staf_pemilik_berhasil(): void
    {
        $this->actingAs($this->staffA)
            ->patch(route('datasets.archive', $this->datasetA))
            ->assertRedirect(route('datasets.index'));

        $this->assertSame(Dataset::STATUS_ARCHIVED, $this->datasetA->fresh()->status->value);
    }

    public function test_archive_oleh_staf_divisi_lain_ditolak_403(): void
    {
        $this->actingAs($this->staffB)
            ->patch(route('datasets.archive', $this->datasetA))
            ->assertForbidden();

        $this->assertSame(Dataset::STATUS_READY, $this->datasetA->fresh()->status->value);
    }

    public function test_archive_oleh_editor_ditolak_403(): void
    {
        // Editor Dashboard read-only mutlak: tidak punya dataset.update.
        $this->actingAs($this->editor)
            ->patch(route('datasets.archive', $this->datasetA))
            ->assertForbidden();
    }

    public function test_archive_oleh_management_ditolak_403(): void
    {
        $this->actingAs($this->management)
            ->patch(route('datasets.archive', $this->datasetA))
            ->assertForbidden();
    }

    public function test_delete_oleh_admin_berhasil_dan_baris_ikut_terhapus(): void
    {
        PerformanceMetric::factory()->count(3)->create([
            'dataset_id' => $this->datasetA->id,
            'division_id' => $this->divisionA->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('datasets.destroy', $this->datasetA))
            ->assertRedirect(route('datasets.index'));

        $this->assertDatabaseMissing('datasets', ['id' => $this->datasetA->id]);
        $this->assertSame(0, PerformanceMetric::where('dataset_id', $this->datasetA->id)->count());
    }

    public function test_delete_oleh_staf_ditolak_403(): void
    {
        // Staf tidak punya dataset.delete; archive adalah jalur koreksinya.
        $this->actingAs($this->staffA)
            ->delete(route('datasets.destroy', $this->datasetA))
            ->assertForbidden();

        $this->assertDatabaseHas('datasets', ['id' => $this->datasetA->id]);
    }

    public function test_dataset_archived_tidak_masuk_agregasi_chart(): void
    {
        PerformanceMetric::factory()->create([
            'dataset_id' => $this->datasetA->id,
            'division_id' => $this->divisionA->id,
            'period' => '2026-01-15',
            'value' => 100,
        ]);

        $payload = fn () => $this->actingAs($this->staffA)->getJson(route('performance.data'))->json();

        $hasPeriod = fn (array $labels) => collect($labels)->contains('2026-01-15');

        $this->assertTrue($hasPeriod($payload()['labels']));

        $this->datasetA->update(['status' => Dataset::STATUS_ARCHIVED]);

        $this->assertFalse($hasPeriod($payload()['labels']));
    }
}
