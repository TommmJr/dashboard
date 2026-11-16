<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Test Feature untuk Milestone 2: Fitur A (Dual-File Web Upload).
 *
 * Menguji ingestion atomis Tab 1b (UPT) dan Tab 1a (UIT) ke dalam SATU
 * dataset divisi Manajemen Aset dengan aturan:
 * 1. Single dataset berisi metrik UPT dan UIT (prefix ASET | UIT | ...).
 * 2. Penolakan 422 jika hanya 1 berkas yang diunggah untuk Manajemen Aset.
 * 3. Atomic rollback saat berkas korup: file fisik terhapus, 0 baris parsial,
 *    dataset READY sebelumnya tetap utuh.
 * 4. Otorisasi fail-closed (Admin & Staf Manajemen Aset boleh; staf divisi lain
 *    dan staf tanpa divisi ditolak 403).
 * 5. Single-file upload divisi lain tetap berfungsi normal tanpa regresi.
 */
class AssetDualUploadTest extends TestCase
{
    use RefreshDatabase;

    private Division $assetDivision;

    private Division $otherDivision;

    private User $admin;

    private User $assetStaff;

    private User $otherStaff;

    private User $staffWithoutDivision;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        // Dynamic division resolution via slug (dilarang hardcode ID)
        $this->assetDivision = Division::where('slug', 'managemen-aset')->sole();
        $this->otherDivision = Division::where('slug', 'perencanaan-pengusahaan')->sole();

        $this->admin = User::factory()->withRole(Role::ADMIN)->create([
            'division_id' => null,
        ]);

        $this->assetStaff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $this->assetDivision->id,
        ]);

        $this->otherStaff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $this->otherDivision->id,
        ]);

        $this->staffWithoutDivision = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => null,
        ]);

        Storage::fake('local');
    }

    private function getFixture1b(): UploadedFile
    {
        $path = base_path('tests/Fixtures/asset_tab1b_sample.csv');
        $temp = tempnam(sys_get_temp_dir(), 'dual_1b_');
        copy($path, $temp);

        return new UploadedFile($temp, 'asset_tab1b_sample.csv', 'text/csv', null, true);
    }

    private function getFixture1a(): UploadedFile
    {
        $path = base_path('tests/Fixtures/asset_tab1a_sample.csv');
        $temp = tempnam(sys_get_temp_dir(), 'dual_1a_');
        copy($path, $temp);

        return new UploadedFile($temp, 'asset_tab1a_sample.csv', 'text/csv', null, true);
    }

    private function getCorruptFile(): UploadedFile
    {
        $temp = tempnam(sys_get_temp_dir(), 'dual_bad_');
        file_put_contents($temp, "KORUP\nBaris data salah format tanpa header\n");

        return new UploadedFile($temp, 'corrupt.csv', 'text/csv', null, true);
    }

    private function makeGenericXlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 1], $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'gen_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'generic_template.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null, true);
    }

    public function test_admin_dapat_mengunggah_dual_file_aset_dan_menghasilkan_satu_dataset_ready(): void
    {
        $file1b = $this->getFixture1b();
        $file1a = $this->getFixture1a();

        $response = $this->actingAs($this->admin)->post(route('datasets.asset-upload'), [
            'file_1b' => $file1b,
            'file_1a' => $file1a,
        ]);

        $dataset = Dataset::where('division_id', $this->assetDivision->id)->sole();
        $response->assertRedirect(route('datasets.show', $dataset));

        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertSame($this->assetDivision->id, $dataset->division_id);
        $this->assertSame($this->admin->id, $dataset->user_id);

        // Verifikasi metrik UPT (dari Tab 1b) dan metrik UIT (dari Tab 1a) masuk ke dataset yang sama
        $uptMetrics = $dataset->metrics()
            ->where('metric_name', 'not like', 'ASET | UIT |%')
            ->where('metric_name', 'like', 'ASET | %')
            ->get();
        $this->assertNotEmpty($uptMetrics);

        $uitMetrics = $dataset->metrics()
            ->where('metric_name', 'like', 'ASET | UIT |%')
            ->get();
        $this->assertNotEmpty($uitMetrics);

        // Spot-check nilai metrik UIT
        $trafoMva = $dataset->metrics()
            ->where('metric_name', 'ASET | UIT | Trafo 500/150 KV | MVA')
            ->sole();
        $this->assertSame(20500.0, (float) $trafoMva->value);
        $this->assertSame('2026-10-31', $trafoMva->period->format('Y-m-d'));

        // Spot-check nilai metrik UPT
        $bandungTower = $dataset->metrics()
            ->where('metric_name', 'ASET | BANDUNG | BANDUNG BARAT | Tower 500 KV | SU | Unit')
            ->sole();
        $this->assertSame(800.0, (float) $bandungTower->value);

        $bandungBank = $dataset->metrics()
            ->where('metric_name', 'ASET | BANDUNG | BANDUNG BARAT | Trafo 500/150 KV | Trafo 500/150 KV | Bank')
            ->sole();
        $this->assertSame(15.0, (float) $bandungBank->value);

        // Verifikasi berkas fisik tersimpan di storage
        Storage::disk('local')->assertExists('datasets/'.$dataset->filename);

        // Verifikasi audit log tercatat
        $audit = AuditLog::where('auditable_type', Dataset::class)
            ->where('auditable_id', $dataset->id)
            ->where('action', AuditLog::ACTION_IMPORT)
            ->first();
        $this->assertNotNull($audit);
    }

    public function test_staf_managemen_aset_dapat_mengunggah_dual_file_aset(): void
    {
        $file1b = $this->getFixture1b();
        $file1a = $this->getFixture1a();

        $response = $this->actingAs($this->assetStaff)->post(route('datasets.asset-upload'), [
            'file_1b' => $file1b,
            'file_1a' => $file1a,
        ]);

        $dataset = Dataset::where('division_id', $this->assetDivision->id)->sole();
        $response->assertRedirect(route('datasets.show', $dataset));

        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertSame($this->assetDivision->id, $dataset->division_id);
        $this->assertSame($this->assetStaff->id, $dataset->user_id);
        $this->assertGreaterThan(0, $dataset->metrics()->count());
    }

    public function test_upload_dual_file_via_datasets_store_berhasil_untuk_managemen_aset(): void
    {
        $file1b = $this->getFixture1b();
        $file1a = $this->getFixture1a();

        $response = $this->actingAs($this->admin)->post(route('datasets.store'), [
            'file_1b' => $file1b,
            'file_1a' => $file1a,
            'division_id' => $this->assetDivision->id,
        ]);

        $dataset = Dataset::where('division_id', $this->assetDivision->id)->sole();
        $response->assertRedirect(route('datasets.show', $dataset));
        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertGreaterThan(0, $dataset->metrics()->count());
    }

    public function test_upload_ditolak_422_jika_hanya_satu_berkas_diunggah_untuk_managemen_aset(): void
    {
        // Hanya unggah 1b tanpa 1a via asset-upload endpoint (JSON request)
        $responseJson = $this->actingAs($this->admin)->postJson(route('datasets.asset-upload'), [
            'file_1b' => $this->getFixture1b(),
        ]);
        $responseJson->assertStatus(422)
            ->assertJsonValidationErrors(['file_1a']);

        // Hanya unggah 1a tanpa 1b via asset-upload endpoint (JSON request)
        $responseJson2 = $this->actingAs($this->admin)->postJson(route('datasets.asset-upload'), [
            'file_1a' => $this->getFixture1a(),
        ]);
        $responseJson2->assertStatus(422)
            ->assertJsonValidationErrors(['file_1b']);

        // Web request: redirect dengan session error
        $responseWeb = $this->actingAs($this->assetStaff)->post(route('datasets.asset-upload'), [
            'file_1b' => $this->getFixture1b(),
        ]);
        $responseWeb->assertSessionHasErrors(['file_1a']);

        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, PerformanceMetric::count());
    }

    public function test_upload_via_datasets_store_ditolak_422_jika_hanya_satu_berkas_aset(): void
    {
        // Unggah hanya file_1b ke route datasets.store
        $response = $this->actingAs($this->admin)->postJson(route('datasets.store'), [
            'file_1b' => $this->getFixture1b(),
            'division_id' => $this->assetDivision->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file_1a']);

        // Unggah file non-generic tunggal ke datasets.store dengan target managemen-aset
        $response2 = $this->actingAs($this->admin)->postJson(route('datasets.store'), [
            'file' => $this->getFixture1b(),
            'division_id' => $this->assetDivision->id,
        ]);

        $response2->assertStatus(422)
            ->assertJsonValidationErrors(['file_1a']);

        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, PerformanceMetric::count());
    }

    public function test_atomic_rollback_pada_berkas_korup_membersihkan_file_fisik_dan_mempertahankan_dataset_lama(): void
    {
        // 1. Buat dataset READY sebelumnya yang sudah ada di database
        $previousDataset = Dataset::factory()->create([
            'division_id' => $this->assetDivision->id,
            'status' => Dataset::STATUS_READY,
            'original_name' => 'previous_dataset.xlsx',
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $previousDataset->id,
            'division_id' => $this->assetDivision->id,
            'metric_name' => 'ASET | BEKASI | BEKASI | Trafo 500/150 KV | Bank',
            'value' => 10.0,
            'period' => '2026-09-30',
        ]);

        $initialDatasetCount = Dataset::count();
        $initialMetricCount = PerformanceMetric::count();

        // 2. Unggah file 1b valid bersama file 1a yang korup
        $valid1b = $this->getFixture1b();
        $corrupt1a = $this->getCorruptFile();

        $response = $this->actingAs($this->admin)->post(route('datasets.asset-upload'), [
            'file_1b' => $valid1b,
            'file_1a' => $corrupt1a,
        ]);

        $response->assertRedirect(route('datasets.create'))
            ->assertSessionHas('error');

        // 3. Verifikasi atomic rollback:
        // - Tidak ada dataset baru yang tersimpan (jumlah dataset sama)
        $this->assertSame($initialDatasetCount, Dataset::count());

        // - Tidak ada baris parsial di performance_metrics (jumlah baris sama)
        $this->assertSame($initialMetricCount, PerformanceMetric::count());

        // - Dataset READY sebelumnya tetap aktif dan tidak berubah
        $this->assertSame(Dataset::STATUS_READY, $previousDataset->fresh()->status->value);

        // - Tidak ada berkas fisik yatim (orphan) yang tertinggal di storage disk
        $filesOnDisk = Storage::disk('local')->allFiles('datasets');
        $this->assertEmpty($filesOnDisk);
    }

    public function test_staf_divisi_lain_ditolak_403_saat_mengunggah_aset(): void
    {
        $file1b = $this->getFixture1b();
        $file1a = $this->getFixture1a();

        // Akses via endpoint khusus aset-upload
        $response = $this->actingAs($this->otherStaff)->post(route('datasets.asset-upload'), [
            'file_1b' => $file1b,
            'file_1a' => $file1a,
        ]);
        $response->assertForbidden();

        // Akses via datasets.store menargetkan divisi Manajemen Aset
        $responseStore = $this->actingAs($this->otherStaff)->post(route('datasets.store'), [
            'file_1b' => $file1b,
            'file_1a' => $file1a,
            'division_id' => $this->assetDivision->id,
        ]);
        $responseStore->assertForbidden();

        $this->assertSame(0, Dataset::count());
    }

    public function test_staf_tanpa_divisi_ditolak_403_saat_mengunggah_aset(): void
    {
        $file1b = $this->getFixture1b();
        $file1a = $this->getFixture1a();

        $response = $this->actingAs($this->staffWithoutDivision)->post(route('datasets.asset-upload'), [
            'file_1b' => $file1b,
            'file_1a' => $file1a,
        ]);
        $response->assertForbidden();

        $this->assertSame(0, Dataset::count());
    }

    public function test_role_management_dan_editor_ditolak_403(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        $this->actingAs($management)->post(route('datasets.asset-upload'), [
            'file_1b' => $this->getFixture1b(),
            'file_1a' => $this->getFixture1a(),
        ])->assertForbidden();

        $this->actingAs($editor)->post(route('datasets.asset-upload'), [
            'file_1b' => $this->getFixture1b(),
            'file_1a' => $this->getFixture1a(),
        ])->assertForbidden();

        $this->assertSame(0, Dataset::count());
    }

    public function test_admin_upload_single_file_divisi_generik_tetap_berfungsi_normal(): void
    {
        $genericDivision = Division::factory()->create([
            'name' => 'Divisi Generik',
            'slug' => 'divisi-generik',
        ]);

        $rows = [
            ['metric_name', 'value', 'period'],
            ['Penjualan Tenaga Listrik', '1500.5', '2026-10-31'],
            ['Susut Jaringan', '5.2', '2026-10-31'],
        ];

        $file = $this->makeGenericXlsx($rows);

        $response = $this->actingAs($this->admin)->post(route('datasets.store'), [
            'file' => $file,
            'division_id' => $genericDivision->id,
        ]);

        $dataset = Dataset::where('division_id', $genericDivision->id)->sole();
        $response->assertRedirect(route('datasets.show', $dataset));

        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertSame($genericDivision->id, $dataset->division_id);
        $this->assertSame(2, $dataset->metrics()->count());
    }
}
