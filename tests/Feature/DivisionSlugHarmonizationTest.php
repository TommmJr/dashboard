<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DivisionSlugHarmonizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_unit_division_seeder_creates_canonical_asset_division_slug(): void
    {
        $this->seed(UnitDivisionSeeder::class);

        $division = Division::where('slug', 'managemen-aset')->first();
        $this->assertNotNull($division, 'Divisi dengan slug managemen-aset wajib terbuat.');
        $this->assertSame('Managemen Aset', $division->name);
    }

    public function test_database_seeder_creates_demo_users_with_canonical_asset_slug(): void
    {
        $previousEnv = $this->app['env'];
        $this->app['env'] = 'local';

        try {
            $this->seed(DatabaseSeeder::class);

            $stafAset = User::where('email', 'staf.aset@dashboard.local')->first();
            $this->assertNotNull($stafAset, 'Akun staf demo aset wajib terdaftar.');
            $this->assertNotNull($stafAset->division, 'Divisi staf demo aset tidak boleh null.');
            $this->assertSame('managemen-aset', $stafAset->division->slug);
        } finally {
            $this->app['env'] = $previousEnv;
        }
    }

    public function test_dashboard_controller_layout_targets_resolves_canonical_asset_division(): void
    {
        $this->seed(UnitDivisionSeeder::class);

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->get(route('dashboard.builder'));

        $response->assertOk();
        $payload = $response->viewData('builderData');
        $this->assertIsArray($payload);

        $assetTarget = collect($payload['targets'] ?? [])->firstWhere('layout_key', 'staff_divisi:managemen-aset');
        $this->assertNotNull($assetTarget, 'Target layout staff_divisi:managemen-aset wajib terdaftar pada editor layout.');
        $this->assertSame('Staf Managemen Aset', $assetTarget['label']);
    }
}
