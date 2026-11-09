<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetStockConverter;
use App\Services\KpiReportConverter;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Isolasi domain Aset dari agregasi KPI/NKO.
 *
 * Baris `ASET | ...` (fisik: Bank, MVA, kms) tidak sebanding dengan skor
 * kinerja, jadi upload dataset Aset tidak boleh mengubah average_nko,
 * series chart, peringkat NKO, maupun hitungan metric_count/ready_datasets
 * di scope KPI. Series dan ranking aman by construction (hanya baris
 * `% | NKO`); test ini membuktikannya, bukan mengasumsikannya.
 */
class AssetIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Default produksi kini 'structured' (Fase 4.3 Tahap B). Test ini
        // mengunci jalur legacy, jadi mode legacy ditetapkan eksplisit.
        config(['kpi.read_model' => 'legacy']);

        // Isolasi domain Aset diuji dengan nama unit stand-in (RST). Batas unit
        // TAMPIL (keputusan 2026-10-12, `kpi.visible_units`) dimatikan supaya
        // stand-in non-TJBT tetap valid; batas itu diuji di `KpiUnitScopeTest`.
        config(['kpi.visible_units' => null]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_upload_aset_tidak_mengubah_agregasi_kpi(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        $kpiDivision = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $asetDivision = Division::where('slug', 'managemen-aset')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $dataset = Dataset::factory()->create([
            'division_id' => $kpiDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);
        PerformanceMetric::factory()->create([
            'metric_name' => 'RST'.KpiReportConverter::NKO_SUFFIX,
            'dataset_id' => $dataset->id,
            'division_id' => $kpiDivision->id,
            'period' => '2026-01-31',
            'value' => 100,
        ]);

        $baseline = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk()->json();

        $converter = app(AssetStockConverter::class);
        $path = $this->tempFile($converter->write([
            ['ASET | BANDUNG | BANDUNG BARAT | Trafo 500/150 KV | Bank', 2.0, '2026-01-31'],
            ['ASET | BANDUNG | BANDUNG BARAT | Trafo 500/150 KV | MVA', 100.5, '2026-01-31'],
        ]));

        $this->actingAs($admin)->post(route('datasets.store'), [
            'file' => new UploadedFile($path, 'rekap-aset.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'division_id' => $asetDivision->id,
        ])->assertRedirect();

        $this->assertSame(Dataset::STATUS_READY, Dataset::latest('id')->first()->status->value);

        $after = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk()->json();

        $this->assertSame($baseline['labels'], $after['labels']);
        $this->assertSame($baseline['series'], $after['series']);
        $this->assertSame($baseline['kpi']['average_nko'], $after['kpi']['average_nko']);
        $this->assertSame($baseline['kpi']['metric_count'], $after['kpi']['metric_count']);
        $this->assertSame($baseline['kpi']['ready_datasets'], $after['kpi']['ready_datasets']);
        $this->assertSame(1, $after['kpi']['metric_count']);
        $this->assertSame(1, $after['kpi']['ready_datasets']);
    }

    public function test_peringkat_nko_tidak_tercemar_baris_aset(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        $kpiDivision = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $asetDivision = Division::where('slug', 'managemen-aset')->sole();
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $dataset = Dataset::factory()->create([
            'division_id' => $kpiDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);
        PerformanceMetric::factory()->create([
            'metric_name' => 'RST'.KpiReportConverter::NKO_SUFFIX,
            'dataset_id' => $dataset->id,
            'division_id' => $kpiDivision->id,
            'period' => '2026-01-31',
            'value' => 100,
        ]);

        $converter = app(AssetStockConverter::class);
        $path = $this->tempFile($converter->write([
            ['ASET | BANDUNG | BANDUNG BARAT | Trafo 500/150 KV | Bank', 2.0, '2026-01-31'],
        ]));

        $this->actingAs($admin)->post(route('datasets.store'), [
            'file' => new UploadedFile($path, 'rekap-aset.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'division_id' => $asetDivision->id,
        ])->assertRedirect();

        // Layout admin operasional saja (§5.5): peringkat dibaca via
        // management (privileged lintas divisi, scoping tetap valid).
        $management = User::factory()->withRole(Role::MANAGEMENT)->create(['division_id' => null]);

        $this->actingAs($management)->get(route('dashboard'))->assertOk()
            ->assertSee('Peringkat NKO Unit')
            ->assertSee('RST')
            ->assertDontSee('BANDUNG');
    }

    /**
     * Daftarkan file sementara supaya dibersihkan setelah test.
     */
    private function tempFile(string $path): string
    {
        $this->tempFiles[] = $path;

        return $path;
    }
}
