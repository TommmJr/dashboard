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
 * Widget peringkat NKO per unit (server-side, periode terbaru).
 *
 * NKO bukan besaran aditif, jadi widget ini TIDAK memakai SUM(value) seperti
 * chart tren. Hanya baris berakhiran '| NKO' yang dihitung.
 */
class NkoRankingWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_melihat_peringkat_nko_urut_menurun(): void
    {
        $this->nko($this->division('TJBT'), 105.5);
        $this->nko($this->division('TRANS'), 100.74);
        $this->nko($this->division('RST'), 99.5);

        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Peringkat NKO Unit')
            ->assertSee('105.50')
            ->assertSeeInOrder(['TJBT', 'TRANS', 'RST']);
    }

    public function test_staff_divisi_tidak_melihat_widget_peringkat(): void
    {
        // Peringkat lintas-unit tidak bermakna untuk staf yang datanya
        // di-scope satu divisi; widget sengaja tidak ada di layoutnya.
        $this->nko($this->division('TJBT'), 105.5);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create();

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Peringkat NKO Unit');
    }

    public function test_management_melihat_widget_peringkat(): void
    {
        $this->nko($this->division('TJBT'), 105.5);

        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Peringkat NKO Unit');
    }

    public function test_hanya_periode_terbaru_yang_ditampilkan(): void
    {
        $division = $this->division('TJBT');
        $this->nko($division, 90.0, '2026-07-31');
        $this->nko($division, 105.5, '2026-08-31');

        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('105.50')
            ->assertDontSee('90.00');
    }

    public function test_dataset_archived_tidak_dihitung(): void
    {
        $division = $this->division('TJBT');
        $dataset = Dataset::factory()->archived()->create(['division_id' => $division->id]);

        PerformanceMetric::factory()->create([
            'metric_name' => 'TJBT | NKO',
            'value' => 105.5,
            'period' => '2026-08-31',
            'division_id' => $division->id,
            'dataset_id' => $dataset->id,
        ]);

        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Belum ada data NKO')
            ->assertDontSee('105.50');
    }

    public function test_metrik_biasa_tidak_ikut_terhitung_sebagai_nko(): void
    {
        $division = $this->division('TJBT');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        PerformanceMetric::factory()->create([
            'metric_name' => 'TJBT | Faktor Ketersediaan Trafo',
            'value' => 20.01,
            'period' => '2026-08-31',
            'division_id' => $division->id,
            'dataset_id' => $dataset->id,
        ]);

        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Belum ada data NKO');
    }

    private function division(string $unit): Division
    {
        return Division::factory()->create(['name' => $unit]);
    }

    private function nko(Division $division, float $value, string $period = '2026-08-31'): void
    {
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        PerformanceMetric::factory()->create([
            'metric_name' => $division->name.' | NKO',
            'value' => $value,
            'period' => $period,
            'division_id' => $division->id,
            'dataset_id' => $dataset->id,
        ]);
    }
}
