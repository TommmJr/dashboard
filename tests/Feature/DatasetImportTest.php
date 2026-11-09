<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use App\Services\DatasetImportService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Test Tahap 4 - Import Excel end-to-end (template generik).
 *
 * Mapping kolom mengikuti config/dataset_import.php (metric_name, value,
 * period). Penyesuaian kolom final mentor nanti = ubah config + test ini.
 */
class DatasetImportTest extends TestCase
{
    use RefreshDatabase;

    private Division $division;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        $this->division = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $this->staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $this->division->id,
        ]);
    }

    /**
     * Buat file .xlsx sementara dari array baris (baris pertama = header).
     */
    private function makeXlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 1], $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'imp_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'laporan.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null, true);
    }

    private function validRows(array $extra = []): array
    {
        return array_merge([
            ['metric_name', 'value', 'period'],
            ['Penjualan', '100.5', '2026-01-31'],
            ['Penjualan', '250', '2026-02-28'],
        ], $extra);
    }

    public function test_upload_sukses_dataset_ready_dan_baris_masuk(): void
    {
        $file = $this->makeXlsx($this->validRows());
        $fileSize = $file->getSize();

        $response = $this->actingAs($this->staff)->post(route('datasets.store'), ['file' => $file]);

        $dataset = Dataset::sole();

        $response->assertRedirect(route('datasets.show', $dataset));
        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertSame($this->division->id, $dataset->division_id);
        $this->assertSame($this->staff->id, $dataset->user_id);
        $this->assertSame('laporan.xlsx', $dataset->original_name);

        // Metadata penyimpanan: nama file tersimpan (dipakai delete/export),
        // ukuran asli, dan mime yang terdeteksi harus terisi.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}\.xlsx$/', $dataset->filename);
        $this->assertSame($fileSize, $dataset->size);
        $this->assertNotEmpty($dataset->mime_type);

        $this->assertSame(2, $dataset->metrics()->count());
        $metric = $dataset->metrics()->where('period', '2026-01-31')->sole();
        $this->assertSame('Penjualan', $metric->metric_name);
        $this->assertEquals(100.5, (float) $metric->value);
        $this->assertSame($this->division->id, $metric->division_id);
        $this->assertSame($this->staff->id, $metric->unit_id);
    }

    public function test_file_non_excel_ditolak_validasi(): void
    {
        $file = UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream');

        $this->actingAs($this->staff)
            ->post(route('datasets.store'), ['file' => $file])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Dataset::count());
    }

    public function test_baris_invalid_di_skip_dan_dihitung(): void
    {
        $file = $this->makeXlsx($this->validRows([
            ['Penjualan', 'bukan-angka', '2026-03-31'], // value non-numeric
            ['Penjualan', '75', '2026-13-40'],          // tanggal silent-rolling
            ['', '10', '2026-04-30'],                   // metric_name kosong
        ]));

        $response = $this->actingAs($this->staff)->post(route('datasets.store'), ['file' => $file]);

        $dataset = Dataset::sole();
        $response->assertRedirect(route('datasets.show', $dataset));
        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertSame(2, $dataset->metrics()->count()); // hanya 2 baris valid
        // Flash message melaporkan jumlah baris yang dilewati.
        $response->assertSessionHas('success', fn ($msg) => str_contains($msg, '3 baris dilewati'));
    }

    public function test_gagal_total_dataset_failed_dengan_error_message(): void
    {
        $file = $this->makeXlsx([
            ['metric_name', 'value', 'period'],
            ['Penjualan', 'abc', '2026-13-40'],
            ['', '', ''],
        ]);

        $response = $this->actingAs($this->staff)->post(route('datasets.store'), ['file' => $file]);

        $dataset = Dataset::sole();
        $response->assertRedirect(route('datasets.show', $dataset));
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');
        $this->assertSame(Dataset::STATUS_FAILED, $dataset->status->value);
        $this->assertNotEmpty($dataset->error_message);
        $this->assertSame(0, $dataset->metrics()->count());
    }

    public function test_file_korup_ditandai_failed_bukan_menggantung(): void
    {
        // ZIP valid tapi bukan struktur XLSX: lolos deteksi MIME (zip),
        // namun gagal di-parse reader -> exception -> FAILED, bukan
        // menggantung di UPLOADING/VALIDATING.
        $path = tempnam(sys_get_temp_dir(), 'imp_').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'bukan spreadsheet');
        $zip->close();
        $file = new UploadedFile($path, 'korup.xlsx', 'application/zip', null, true);

        $this->actingAs($this->staff)->post(route('datasets.store'), ['file' => $file]);

        $dataset = Dataset::sole();
        $this->assertSame(Dataset::STATUS_FAILED, $dataset->status->value);
        $this->assertNotEmpty($dataset->error_message);
    }

    public function test_upload_ditolak_403_tanpa_permission_dataset_import(): void
    {
        // Editor Dashboard: read-only mutlak, tidak punya dataset.import.
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $file = $this->makeXlsx($this->validRows());

        $this->actingAs($editor)->post(route('datasets.store'), ['file' => $file])
            ->assertForbidden();

        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, PerformanceMetric::count());
    }

    public function test_upload_ditolak_403_untuk_management(): void
    {
        // Management: penonton utama dashboard, tidak punya dataset.import.
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $file = $this->makeXlsx($this->validRows());

        $this->actingAs($management)->post(route('datasets.store'), ['file' => $file])
            ->assertForbidden();

        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, PerformanceMetric::count());
    }

    public function test_form_upload_dapat_diakses_pemegang_dataset_import(): void
    {
        $this->actingAs($this->staff)->get(route('datasets.create'))->assertOk();

        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $this->actingAs($admin)->get(route('datasets.create'))
            ->assertOk()
            ->assertSee('Divisi Pemilik Data');

        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $this->actingAs($editor)->get(route('datasets.create'))->assertForbidden();

        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $this->actingAs($management)->get(route('datasets.create'))->assertForbidden();
    }

    public function test_form_upload_hanya_menampilkan_dropdown_divisi_untuk_admin(): void
    {
        // Admin: wajib memilih divisi, form menampilkan dropdown semua divisi
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $responseAdmin = $this->actingAs($admin)->get(route('datasets.create'));
        $responseAdmin->assertOk()
            ->assertSee('Divisi Pemilik Data')
            ->assertViewHas('divisions', fn ($divisions) => $divisions->isNotEmpty());

        // Staf berdivisi: terikat ke divisinya sendiri, form tidak menampilkan dropdown
        $responseStaff = $this->actingAs($this->staff)->get(route('datasets.create'));
        $responseStaff->assertOk()
            ->assertDontSee('Divisi Pemilik Data')
            ->assertSee('Dataset akan tertaut ke divisi Anda')
            ->assertViewHas('divisions', fn ($divisions) => $divisions->isEmpty());

        // Staf tanpa divisi: non-privileged, form tidak boleh menampilkan dropdown divisi
        $staffNoDiv = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => null]);
        $responseStaffNoDiv = $this->actingAs($staffNoDiv)->get(route('datasets.create'));
        $responseStaffNoDiv->assertOk()
            ->assertDontSee('Divisi Pemilik Data')
            ->assertViewHas('divisions', fn ($divisions) => $divisions->isEmpty());
    }

    public function test_admin_wajib_memilih_divisi_tujuan(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $file = $this->makeXlsx($this->validRows());

        $this->actingAs($admin)->post(route('datasets.store'), ['file' => $file])
            ->assertSessionHasErrors('division_id');

        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, PerformanceMetric::count());
    }

    public function test_admin_dapat_memilih_divisi_tujuan(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $targetDivision = Division::where('slug', 'managemen-aset')->sole();

        $file = $this->makeXlsx($this->validRows());

        $response = $this->actingAs($admin)->post(route('datasets.store'), [
            'file' => $file,
            'division_id' => $targetDivision->id,
        ]);

        // Filter by user_id to isolate this test's dataset regardless of
        // other records that may exist in the same DB transaction scope.
        $dataset = Dataset::where('user_id', $admin->id)->firstOrFail();
        $response->assertRedirect(route('datasets.show', $dataset));
        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertSame($targetDivision->id, $dataset->division_id);
        $this->assertSame($admin->id, $dataset->user_id);
        // Semua baris metric mengikuti divisi dataset, bukan division_id uploader (NULL).
        $this->assertSame($targetDivision->id, $dataset->metrics()->value('division_id'));
    }

    public function test_staf_tidak_bisa_menyelipkan_division_id_lain(): void
    {
        // Input division_id dari staf DIABAIKAN (rule prohibited): data
        // selalu tertaut ke divisi staf sendiri — anti scoping bypass.
        $otherDivision = Division::factory()->create();
        $file = $this->makeXlsx($this->validRows());

        $this->actingAs($this->staff)->post(route('datasets.store'), [
            'file' => $file,
            'division_id' => $otherDivision->id,
        ])->assertSessionHasErrors('division_id');

        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, PerformanceMetric::count());
    }

    public function test_staf_tanpa_divisi_ditolak_403_dan_tidak_meninggalkan_data(): void
    {
        $staffNoDiv = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => null]);
        $targetDivision = Division::factory()->create();
        $file = $this->makeXlsx($this->validRows());

        // Mencoba upload dengan division_id
        $this->actingAs($staffNoDiv)->post(route('datasets.store'), [
            'file' => $file,
            'division_id' => $targetDivision->id,
        ])->assertForbidden();

        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, PerformanceMetric::count());

        // Mencoba upload tanpa division_id
        $this->actingAs($staffNoDiv)->post(route('datasets.store'), [
            'file' => $file,
        ])->assertForbidden();

        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, PerformanceMetric::count());
    }

    public function test_import_dengan_record_audit_false_tidak_mencatat_action_import(): void
    {
        $user = User::factory()->withRole(Role::ADMIN)->create();
        $division = Division::factory()->create();
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'user_id' => $user->id,
            'status' => Dataset::STATUS_UPLOADING,
        ]);

        $file = $this->makeXlsx($this->validRows());

        $initialAuditCount = AuditLog::where('action', AuditLog::ACTION_IMPORT)->count();

        $service = app(DatasetImportService::class);
        $result = $service->import($file, $user, $dataset, recordAudit: false);

        $this->assertGreaterThan(0, $result['imported']);
        $this->assertSame(
            $initialAuditCount,
            AuditLog::where('action', AuditLog::ACTION_IMPORT)->count(),
            'ACTION_IMPORT tidak boleh dicatat saat recordAudit false'
        );
    }
}
