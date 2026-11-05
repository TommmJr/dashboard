<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use App\Services\StructuredKpiImporter;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StructuredKpiChronologicalGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Division $owner;

    private OrgUnit $orgUnit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        $this->owner = Division::where('slug', 'perencanaan-pengusahaan')->firstOrFail();
        $this->admin = User::factory()->withRole(Role::ADMIN)->create([
            'division_id' => null,
        ]);

        $this->orgUnit = OrgUnit::firstOrCreate(['name' => 'TJBT']);
    }

    private function createDataset(array $attributes = []): Dataset
    {
        return Dataset::factory()->create(array_merge([
            'division_id' => $this->owner->id,
            'user_id' => $this->admin->id,
            'status' => Dataset::STATUS_READY,
        ], $attributes));
    }

    public function test_max_ready_periods_hanya_mengambil_dataset_ready_dan_mengabaikan_failed_archived_serta_current(): void
    {
        $importer = app(StructuredKpiImporter::class);

        $indicatorA = Indicator::create([
            'org_unit_id' => $this->orgUnit->id,
            'name' => 'Indikator A',
            'is_group' => false,
            'satuan' => '%',
        ]);

        $indicatorB = Indicator::create([
            'org_unit_id' => $this->orgUnit->id,
            'name' => 'Indikator B',
            'is_group' => false,
            'satuan' => 'Kali',
        ]);

        // Dataset 1: READY periode 2026-08-31
        $readyDataset = $this->createDataset([
            'filename' => 'ready.xlsx',
            'original_name' => 'ready.xlsx',
            'status' => Dataset::STATUS_READY,
        ]);

        PerformanceObservation::create([
            'dataset_id' => $readyDataset->id,
            'indicator_id' => $indicatorA->id,
            'org_unit_id' => $this->orgUnit->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_EMPTY,
            'realisasi_type' => PerformanceObservation::TYPE_EMPTY,
        ]);

        // Dataset 2: FAILED periode 2026-10-31 (harus diabaikan)
        $failedDataset = $this->createDataset([
            'filename' => 'failed.xlsx',
            'original_name' => 'failed.xlsx',
            'status' => DatasetStatus::FAILED,
        ]);

        PerformanceObservation::create([
            'dataset_id' => $failedDataset->id,
            'indicator_id' => $indicatorA->id,
            'org_unit_id' => $this->orgUnit->id,
            'period' => '2026-10-31',
            'target_type' => PerformanceObservation::TYPE_EMPTY,
            'realisasi_type' => PerformanceObservation::TYPE_EMPTY,
        ]);

        // Dataset 3: ARCHIVED periode 2026-09-30 (harus diabaikan)
        $archivedDataset = $this->createDataset([
            'filename' => 'archived.xlsx',
            'original_name' => 'archived.xlsx',
            'status' => Dataset::STATUS_ARCHIVED,
        ]);

        PerformanceObservation::create([
            'dataset_id' => $archivedDataset->id,
            'indicator_id' => $indicatorB->id,
            'org_unit_id' => $this->orgUnit->id,
            'period' => '2026-09-30',
            'target_type' => PerformanceObservation::TYPE_EMPTY,
            'realisasi_type' => PerformanceObservation::TYPE_EMPTY,
        ]);

        // Dataset 4: Current dataset yang sedang diproses (harus diabaikan)
        $currentDataset = $this->createDataset([
            'filename' => 'current.xlsx',
            'original_name' => 'current.xlsx',
            'status' => Dataset::STATUS_READY,
        ]);

        $reflection = new \ReflectionClass($importer);
        $method = $reflection->getMethod('maxReadyPeriods');
        $method->setAccessible(true);

        /** @var array<int, string> $result */
        $result = $method->invoke($importer, $currentDataset);

        $this->assertArrayHasKey($indicatorA->id, $result);
        $this->assertSame('2026-08-31', $result[$indicatorA->id]);
        $this->assertArrayNotHasKey($indicatorB->id, $result, 'Dataset archived tidak boleh masuk acuan maxReadyPeriods');
    }
}
