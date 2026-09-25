<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetStockReport;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AssetRealDataIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_mengimpor_data_konversi_rekap_1b_riil_dan_menghasilkan_totals_per_upt(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        $asetDivision = Division::where('slug', 'data-aset')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $convertedFilePath = base_path('storage/app/test-asset-converted.xlsx');

        if (! file_exists($convertedFilePath)) {
            $this->markTestSkipped('Berkas storage/app/test-asset-converted.xlsx tidak ditemukan.');
        }

        $this->actingAs($admin)->post(route('datasets.store'), [
            'file' => new UploadedFile(
                $convertedFilePath,
                'test-asset-converted.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            ),
            'division_id' => $asetDivision->id,
        ])->assertRedirect();

        $dataset = Dataset::sole();
        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertSame(437, $dataset->metrics()->count());

        $report = app(AssetStockReport::class)->totalsByUpt();

        $this->assertSame('2026-08-31', $report['period']);
        $this->assertNotEmpty($report['totals']);

        // Pastikan UPT riil dari berkas 1b muncul di hasil agregasi
        $upts = $report['totals']->pluck('upt')->unique()->values()->all();
        $this->assertContains('BEKASI', $upts);
        $this->assertContains('BANDUNG', $upts);
    }
}
