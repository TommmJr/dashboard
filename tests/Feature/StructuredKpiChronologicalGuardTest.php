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
use App\Services\KpiReportConverter;
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

    public function test_impor_periode_lama_tidak_menimpa_metadata_indikator_periode_terbaru(): void
    {
        // 1. Kondisi awal: Indikator sudah ada dari periode Agustus 2026 (READY)
        $indicator = Indicator::create([
            'org_unit_id' => $this->orgUnit->id,
            'name' => 'Keandalan Trafo',
            'is_group' => false,
            'satuan' => '%',
            'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
            'polarity' => 'POS',
            'bobot' => '10',
            'bobot_value' => 10.0,
            'category' => null,
        ]);

        $augustDataset = $this->createDataset([
            'filename' => 'august.xlsx',
            'original_name' => 'august.xlsx',
            'status' => Dataset::STATUS_READY,
        ]);

        PerformanceObservation::create([
            'dataset_id' => $augustDataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $this->orgUnit->id,
            'period' => '2026-08-31',
            'pencapaian' => 1.05,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'target_value' => 100,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_value' => 105,
        ]);

        // 2. Impor berkas revisi/lampau untuk Juli 2026 (2026-07-31 < 2026-08-31)
        // Di mana di berkas Juli indikator memiliki metadata berbeda (misal is_group=true, satuan='Kali')
        $julyDataset = $this->createDataset([
            'filename' => 'july.xlsx',
            'original_name' => 'july.xlsx',
            'status' => Dataset::STATUS_READY,
        ]);

        // Mock converter output untuk baris Juli
        $fakeJulyRows = [
            [
                'org_unit' => 'TJBT',
                'row' => 10,
                'parent_row' => null,
                'label' => 'Keandalan Trafo',
                'aggregation_code' => 'AVG',
                'polarity' => 'NEG',
                'formula' => '=AVERAGE(C10:C11)',
                'satuan' => 'Kali',
                'bobot' => '5',
                'bobot_value' => 5.0,
                'bobot_min' => null,
                'bobot_max' => null,
                'is_group' => true, // mencoba mengubah menjadi grup
                'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_AVERAGE,
                'target' => ['type' => PerformanceObservation::TYPE_NUMERIC, 'value' => 10.0],
                'realisasi' => ['type' => PerformanceObservation::TYPE_NUMERIC, 'value' => 8.0],
                'pencapaian' => ['type' => PerformanceObservation::TYPE_NUMERIC, 'value' => 0.8],
                'nilai' => ['type' => PerformanceObservation::TYPE_EMPTY, 'value' => null],
                'ket' => ['type' => PerformanceObservation::TYPE_EMPTY, 'text' => null],
                'sheet_period' => 'JULI 2026',
            ],
        ];

        $mockConverter = $this->createMock(KpiReportConverter::class);
        $mockConverter->method('extractStructured')->willReturn($fakeJulyRows);

        $customImporter = new StructuredKpiImporter($mockConverter);
        $counts = $customImporter->import('dummy/path/july.xlsx', $julyDataset, '2026-07-31');

        $this->assertSame(0, $counts['indicators'], 'Indikator tidak boleh bertambah karena nama sudah ada');
        $this->assertSame(1, $counts['observations'], 'Observasi baru Juli harus tetap tercatat');

        // 3. Verifikasi proteksi metadata pada master indicator
        $refreshed = $indicator->fresh();
        $this->assertFalse((bool) $refreshed->is_group, 'is_group tidak boleh tertimpa menjadi true oleh file lampau');
        $this->assertSame('%', $refreshed->satuan, 'satuan tidak boleh tertimpa menjadi Kali oleh file lampau');
        $this->assertSame(
            KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
            $refreshed->pencapaian_formula_kind,
            'pencapaian_formula_kind tidak boleh tertimpa mundur',
        );
        $this->assertSame('POS', $refreshed->polarity);
        $this->assertSame('10', $refreshed->bobot);

        // 4. Verifikasi bahwa observasi Juli tetap tersimpan akurat dengan target/realisasi Juli
        $julyObservation = PerformanceObservation::where('dataset_id', $julyDataset->id)->firstOrFail();
        $this->assertSame($indicator->id, $julyObservation->indicator_id);
        $this->assertSame('2026-07-31', $julyObservation->period->format('Y-m-d'));
        $this->assertEquals(10.0, (float) $julyObservation->target_value);
        $this->assertEquals(8.0, (float) $julyObservation->realisasi_value);
    }

    public function test_impor_periode_sama_atau_lebih_baru_tetap_memperbarui_metadata_indikator(): void
    {
        // Kondisi awal: Indikator periode Agustus 2026
        $indicator = Indicator::create([
            'org_unit_id' => $this->orgUnit->id,
            'name' => 'Gangguan SUTT',
            'is_group' => false,
            'satuan' => 'Kali',
            'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
        ]);

        $augustDataset = $this->createDataset([
            'filename' => 'august.xlsx',
            'original_name' => 'august.xlsx',
            'status' => Dataset::STATUS_READY,
        ]);

        PerformanceObservation::create([
            'dataset_id' => $augustDataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $this->orgUnit->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_EMPTY,
            'realisasi_type' => PerformanceObservation::TYPE_EMPTY,
        ]);

        // Kasus A: Impor revisi periode yang sama (2026-08-31 >= 2026-08-31) -> metadata boleh diperbarui
        $revisionDataset = $this->createDataset([
            'filename' => 'august_rev.xlsx',
            'original_name' => 'august_rev.xlsx',
            'status' => Dataset::STATUS_READY,
        ]);

        $fakeRevRows = [
            [
                'org_unit' => 'TJBT',
                'row' => 15,
                'parent_row' => null,
                'label' => 'Gangguan SUTT',
                'aggregation_code' => 'SUM',
                'polarity' => 'POS',
                'formula' => null,
                'satuan' => 'Gangguan',
                'bobot' => '15',
                'bobot_value' => 15.0,
                'bobot_min' => null,
                'bobot_max' => null,
                'is_group' => false,
                'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
                'target' => ['type' => PerformanceObservation::TYPE_NUMERIC, 'value' => 5.0],
                'realisasi' => ['type' => PerformanceObservation::TYPE_NUMERIC, 'value' => 2.0],
                'pencapaian' => ['type' => PerformanceObservation::TYPE_NUMERIC, 'value' => 1.0],
                'nilai' => ['type' => PerformanceObservation::TYPE_EMPTY, 'value' => null],
                'ket' => ['type' => PerformanceObservation::TYPE_EMPTY, 'text' => null],
                'sheet_period' => 'AGUSTUS 2026',
            ],
        ];

        $mockConverter = $this->createMock(KpiReportConverter::class);
        $mockConverter->method('extractStructured')->willReturn($fakeRevRows);

        $importer = new StructuredKpiImporter($mockConverter);
        $importer->import('dummy/path.xlsx', $revisionDataset, '2026-08-31');

        $this->assertSame('Gangguan', $indicator->fresh()->satuan, 'Satuan pada revisi periode sama harus diperbarui');

        // Kasus B: Impor periode lebih baru (Oktober 2026 > Agustus 2026) -> metadata boleh diperbarui
        $octoberDataset = $this->createDataset([
            'filename' => 'october.xlsx',
            'original_name' => 'october.xlsx',
            'status' => Dataset::STATUS_READY,
        ]);

        $fakeOctRows = [
            [
                'org_unit' => 'TJBT',
                'row' => 15,
                'parent_row' => null,
                'label' => 'Gangguan SUTT',
                'aggregation_code' => 'SUM',
                'polarity' => 'POS',
                'formula' => null,
                'satuan' => 'Titik Gangguan',
                'bobot' => '20',
                'bobot_value' => 20.0,
                'bobot_min' => null,
                'bobot_max' => null,
                'is_group' => false,
                'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
                'target' => ['type' => PerformanceObservation::TYPE_NUMERIC, 'value' => 5.0],
                'realisasi' => ['type' => PerformanceObservation::TYPE_NUMERIC, 'value' => 2.0],
                'pencapaian' => ['type' => PerformanceObservation::TYPE_NUMERIC, 'value' => 1.0],
                'nilai' => ['type' => PerformanceObservation::TYPE_EMPTY, 'value' => null],
                'ket' => ['type' => PerformanceObservation::TYPE_EMPTY, 'text' => null],
                'sheet_period' => 'OKTOBER 2026',
            ],
        ];

        $mockConverter2 = $this->createMock(KpiReportConverter::class);
        $mockConverter2->method('extractStructured')->willReturn($fakeOctRows);

        $importer2 = new StructuredKpiImporter($mockConverter2);
        $importer2->import('dummy/path_oct.xlsx', $octoberDataset, '2026-10-31');

        $this->assertSame('Titik Gangguan', $indicator->fresh()->satuan, 'Satuan pada periode lebih baru harus diperbarui');
    }

    public function test_observasi_dari_dataset_failed_tidak_memblokir_pembaruan_metadata_indikator(): void
    {
        $indicator = Indicator::create([
            'org_unit_id' => $this->orgUnit->id,
            'name' => 'Taraf Mutu',
            'is_group' => false,
            'satuan' => '%',
        ]);

        // Ada observasi masa depan tetapi status dataset-nya FAILED
        $failedDataset = $this->createDataset([
            'filename' => 'failed_future.xlsx',
            'original_name' => 'failed_future.xlsx',
            'status' => DatasetStatus::FAILED,
        ]);

        PerformanceObservation::create([
            'dataset_id' => $failedDataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $this->orgUnit->id,
            'period' => '2026-12-31',
            'target_type' => PerformanceObservation::TYPE_EMPTY,
            'realisasi_type' => PerformanceObservation::TYPE_EMPTY,
        ]);

        // Impor dataset sah untuk periode Agustus 2026 (2026-08-31 < 2026-12-31)
        // Karena observasi 2026-12-31 berasal dari FAILED, maka tidak boleh memblokir update Agustus
        $augustDataset = $this->createDataset([
            'filename' => 'august.xlsx',
            'original_name' => 'august.xlsx',
            'status' => Dataset::STATUS_READY,
        ]);

        $fakeRows = [
            [
                'org_unit' => 'TJBT',
                'row' => 20,
                'parent_row' => null,
                'label' => 'Taraf Mutu',
                'aggregation_code' => 'AVG',
                'polarity' => 'POS',
                'formula' => null,
                'satuan' => 'Indeks',
                'bobot' => '10',
                'bobot_value' => 10.0,
                'bobot_min' => null,
                'bobot_max' => null,
                'is_group' => false,
                'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
                'target' => ['type' => PerformanceObservation::TYPE_NUMERIC, 'value' => 100.0],
                'realisasi' => ['type' => PerformanceObservation::TYPE_NUMERIC, 'value' => 98.0],
                'pencapaian' => ['type' => PerformanceObservation::TYPE_NUMERIC, 'value' => 0.98],
                'nilai' => ['type' => PerformanceObservation::TYPE_EMPTY, 'value' => null],
                'ket' => ['type' => PerformanceObservation::TYPE_EMPTY, 'text' => null],
                'sheet_period' => 'AGUSTUS 2026',
            ],
        ];

        $mockConverter = $this->createMock(KpiReportConverter::class);
        $mockConverter->method('extractStructured')->willReturn($fakeRows);

        $importer = new StructuredKpiImporter($mockConverter);
        $importer->import('dummy/path.xlsx', $augustDataset, '2026-08-31');

        $this->assertSame('Indeks', $indicator->fresh()->satuan, 'Dataset failed tidak boleh memblokir pembaruan satuan');
    }

    public function test_indikator_baru_pada_periode_lama_tetap_dibuat_tanpa_mengubah_indikator_lain(): void
    {
        $augustDataset = $this->createDataset([
            'filename' => 'august.xlsx',
            'original_name' => 'august.xlsx',
            'status' => Dataset::STATUS_READY,
        ]);

        $existingIndicator = Indicator::create([
            'org_unit_id' => $this->orgUnit->id,
            'name' => 'Indikator Modern',
            'is_group' => false,
            'satuan' => '%',
        ]);

        PerformanceObservation::create([
            'dataset_id' => $augustDataset->id,
            'indicator_id' => $existingIndicator->id,
            'org_unit_id' => $this->orgUnit->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_EMPTY,
            'realisasi_type' => PerformanceObservation::TYPE_EMPTY,
        ]);

        // Berkas Juli memuat indikator usang yang belum ada di database
        $julyDataset = $this->createDataset([
            'filename' => 'july.xlsx',
            'original_name' => 'july.xlsx',
            'status' => Dataset::STATUS_READY,
        ]);

        $fakeRows = [
            [
                'org_unit' => 'TJBT',
                'row' => 30,
                'parent_row' => null,
                'label' => 'Indikator Kuno Sudah Dihapus di Agustus',
                'aggregation_code' => 'SUM',
                'polarity' => 'POS',
                'formula' => null,
                'satuan' => 'Dokumen',
                'bobot' => '5',
                'bobot_value' => 5.0,
                'bobot_min' => null,
                'bobot_max' => null,
                'is_group' => false,
                'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
                'target' => ['type' => PerformanceObservation::TYPE_NUMERIC, 'value' => 1.0],
                'realisasi' => ['type' => PerformanceObservation::TYPE_NUMERIC, 'value' => 1.0],
                'pencapaian' => ['type' => PerformanceObservation::TYPE_NUMERIC, 'value' => 1.0],
                'nilai' => ['type' => PerformanceObservation::TYPE_EMPTY, 'value' => null],
                'ket' => ['type' => PerformanceObservation::TYPE_EMPTY, 'text' => null],
                'sheet_period' => 'JULI 2026',
            ],
        ];

        $mockConverter = $this->createMock(KpiReportConverter::class);
        $mockConverter->method('extractStructured')->willReturn($fakeRows);

        $importer = new StructuredKpiImporter($mockConverter);
        $counts = $importer->import('dummy/path.xlsx', $julyDataset, '2026-07-31');

        $this->assertSame(1, $counts['indicators'], 'Indikator kuno harus berhasil dibuat sebagai master baru');
        $this->assertSame(1, $counts['observations']);

        $created = Indicator::where('name', 'Indikator Kuno Sudah Dihapus di Agustus')->first();
        $this->assertNotNull($created);
        $this->assertSame('Dokumen', $created->satuan);
    }
}
