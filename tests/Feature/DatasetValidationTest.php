<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Validation severity rules (FATAL/WARNING).
 *
 * FATAL   = row skipped (empty required column, non-numeric value,
 *           invalid date, duplicate metric_name+period key).
 * WARNING = row imported but flagged (statistical outlier:
 *           |value - mean| > 3 * std-dev within the file).
 * The summary is persisted to datasets.validation_report (JSON) and
 * shown on the dataset detail page.
 */
class DatasetValidationTest extends TestCase
{
    use RefreshDatabase;

    private Division $division;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        $this->division = Division::factory()->create([
            'name' => 'Divisi Generik',
            'slug' => 'divisi-generik',
        ]);
        $this->admin = User::factory()->withRole(Role::ADMIN)->create([
            'division_id' => null,
        ]);
        $this->staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $this->division->id,
        ]);
    }

    private function upload(array $rows): Dataset
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 1], $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'val_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $file = new UploadedFile($path, 'laporan.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->admin)->post(route('datasets.store'), [
            'file' => $file,
            'division_id' => $this->division->id,
        ]);

        return Dataset::sole();
    }

    public function test_duplicate_key_is_fatal_and_reported(): void
    {
        $dataset = $this->upload([
            ['metric_name', 'value', 'period'],
            ['Penjualan', '100', '2026-01-31'],
            ['Penjualan', '200', '2026-01-31'], // duplicate key
            ['Biaya', '50', '2026-01-31'],
        ]);

        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertSame(2, $dataset->metrics()->count()); // second Penjualan skipped

        $report = $dataset->validation_report;
        $this->assertSame(1, $report['fatal']);
        $duplicate = collect($report['issues'])->firstWhere('severity', 'FATAL');
        $this->assertStringContainsString('duplikat', $duplicate['message']);
    }

    public function test_each_fatal_rule_skips_the_row(): void
    {
        $dataset = $this->upload([
            ['metric_name', 'value', 'period'],
            ['Penjualan', '100', '2026-01-31'],  // valid
            ['Penjualan', 'abc', '2026-02-28'],  // non-numeric value
            ['Penjualan', '200', '2026-13-40'],  // invalid date
            ['', '10', '2026-03-31'],            // empty metric_name
        ]);

        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertSame(1, $dataset->metrics()->count());

        $report = $dataset->validation_report;
        $this->assertSame(3, $report['fatal']);
        $this->assertSame(1, $report['ok']);
    }

    public function test_outlier_is_warning_but_row_is_imported(): void
    {
        // 10 rows around 100 + one extreme outlier (10000).
        $rows = [['metric_name', 'value', 'period']];
        foreach (range(1, 10) as $i) {
            $rows[] = ['Penjualan', (string) (100 + $i), sprintf('2026-01-%02d', $i + 10)];
        }
        $rows[] = ['Penjualan', '10000', '2026-02-28'];

        $dataset = $this->upload($rows);

        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        // Outlier row is imported, not skipped.
        $this->assertSame(11, $dataset->metrics()->count());

        $report = $dataset->validation_report;
        $this->assertSame(0, $report['fatal']);
        $this->assertSame(1, $report['warning']);

        $warning = collect($report['issues'])->firstWhere('severity', 'WARNING');
        $this->assertStringContainsString('outlier', $warning['message']);
    }

    public function test_warnings_do_not_block_ready_status(): void
    {
        // 20 tight rows + one extreme value: guaranteed >3 std-dev outlier.
        $rows = [['metric_name', 'value', 'period']];
        foreach (range(1, 20) as $i) {
            $rows[] = ['Biaya', (string) (200 + ($i % 3)), sprintf('2026-03-%02d', $i)];
        }
        $rows[] = ['Biaya', '99999', '2026-03-31'];

        $dataset = $this->upload($rows);

        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertSame(21, $dataset->metrics()->count()); // outlier kept
        $this->assertGreaterThanOrEqual(1, $dataset->validation_report['warning']);
    }

    public function test_validation_report_shown_on_detail_page(): void
    {
        $dataset = $this->upload([
            ['metric_name', 'value', 'period'],
            ['Penjualan', '100', '2026-01-31'],
            ['Penjualan', 'abc', '2026-02-28'],
        ]);

        $this->actingAs($this->staff)
            ->get(route('datasets.show', $dataset))
            ->assertOk()
            ->assertSee('Ringkasan validasi')
            ->assertSee('Kesalahan: 1')
            ->assertSee('Sesuai: 1')
            ->assertSee('id="validation-summary-panel"', false)
            ->assertDontSee('/export"');

        $this->assertFalse(Route::has('datasets.export'));

        $this->actingAs($this->staff)
            ->get('/datasets/'.$dataset->id.'/export')
            ->assertRedirect(route('dashboard'));
    }

    public function test_period_datetime_dengan_komponen_jam_diterima_tanggalnya_saja(): void
    {
        // CSV/export sistem lain bisa mengirim datetime, bukan tanggal murni.
        // Kolom period = tanggal, jadi jamnya dibuang (konsisten dengan sel
        // Excel bertipe date-time yang sudah dinormalisasi di parseRows).
        $dataset = $this->upload([
            ['metric_name', 'value', 'period'],
            ['A', '100', '2026-01-31 10:30:00'],
            ['B', '200', '28/02/2026 23:59'],
        ]);

        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertSame(2, $dataset->metrics()->count());
        $this->assertSame(0, $dataset->validation_report['fatal']);
        $this->assertTrue($dataset->metrics()->where('period', '2026-01-31')->exists());
        $this->assertTrue($dataset->metrics()->where('period', '2026-02-28')->exists());
    }

    public function test_period_datetime_dengan_tanggal_invalid_tetap_ditolak(): void
    {
        // Jam tidak boleh meloloskan tanggal yang digulung Carbon.
        $dataset = $this->upload([
            ['metric_name', 'value', 'period'],
            ['A', '100', '31/02/2026 10:00:00'],
        ]);

        $this->assertSame(Dataset::STATUS_FAILED, $dataset->status->value);
        $this->assertSame(0, $dataset->metrics()->count());
    }

    public function test_value_pemisah_ribuan_gaya_indonesia_ditolak_dengan_pesan_jelas(): void
    {
        // is_numeric() hanya menerima gaya PHP/Inggris (1234.56). Gaya
        // Indonesia (1.234,56) ditolak dengan pesan yang menjelaskan format
        // yang diterima — tidak dinormalisasi otomatis (ambiguous + berisiko
        // menggeser nilai 1000x).
        $dataset = $this->upload([
            ['metric_name', 'value', 'period'],
            ['A', '1.234,56', '2026-01-31'],
            ['B', '1234.56', '2026-02-28'],
        ]);

        $this->assertSame(Dataset::STATUS_READY, $dataset->status->value);
        $this->assertSame(1, $dataset->metrics()->count());
        $this->assertSame(1, $dataset->validation_report['fatal']);

        $issue = collect($dataset->validation_report['issues'])->firstWhere('severity', 'FATAL');
        $this->assertStringContainsString('numeric', $issue['message']);
        $this->assertStringContainsString('1234.56', $issue['message']);
    }

    public function test_detail_masalah_dibatasi_50_dengan_flag_truncated_dan_count_penuh(): void
    {
        // 60 baris invalid: semua FATAL, tidak ada yang lolos.
        $rows = [['metric_name', 'value', 'period']];
        foreach (range(1, 60) as $i) {
            $rows[] = ['', 'abc', 'bukan-tanggal-'.$i];
        }

        $dataset = $this->upload($rows);

        $report = $dataset->validation_report;
        $this->assertSame(60, $report['fatal']);
        $this->assertCount(50, $report['issues']);
        $this->assertTrue($report['issues_truncated']);
    }

    public function test_period_conflict_ditampilkan_jelas_dan_terpisah_dari_error_validasi_di_halaman_detail(): void
    {
        $dataset = $this->upload([
            ['metric_name', 'value', 'period'],
            ['Penjualan', '100', '2026-08-31'],
            ['Biaya', '200', '2026-08-31'],
        ]);

        $unitConflict = OrgUnit::create(['name' => 'TRANS']);
        $unitMatch = OrgUnit::create(['name' => 'TJBT']);
        $indicatorConflict1 = Indicator::create(['org_unit_id' => $unitConflict->id, 'name' => 'Indikator 1']);
        $indicatorConflict2 = Indicator::create(['org_unit_id' => $unitConflict->id, 'name' => 'Indikator 2']);
        $indicatorMatch = Indicator::create(['org_unit_id' => $unitMatch->id, 'name' => 'Indikator 3']);

        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'division_id' => $this->division->id,
            'org_unit_id' => $unitConflict->id,
            'indicator_id' => $indicatorConflict1->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'sheet_period' => 'S.D. FEBRUARI 2025',
            'period_conflict' => true,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'division_id' => $this->division->id,
            'org_unit_id' => $unitConflict->id,
            'indicator_id' => $indicatorConflict2->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'sheet_period' => 'S.D. FEBRUARI 2025',
            'period_conflict' => true,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'division_id' => $this->division->id,
            'org_unit_id' => $unitMatch->id,
            'indicator_id' => $indicatorMatch->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'sheet_period' => 'S.D. AGUSTUS 2026',
            'period_conflict' => false,
        ]);

        $this->actingAs($this->staff)
            ->get(route('datasets.show', $dataset))
            ->assertOk()
            ->assertSee('Ringkasan validasi')
            ->assertSee('Sesuai: 2')
            ->assertSee('Peringatan: 0')
            ->assertSee('Kesalahan: 0')
            ->assertSee('Konflik Periode: 2')
            ->assertSee('Konflik periode banner sheet (2 observasi)')
            ->assertSee('S.D. FEBRUARI 2025')
            ->assertDontSee('S.D. AGUSTUS 2026')
            ->assertDontSee('[Peringatan]')
            ->assertDontSee('[Kesalahan]');
    }

    public function test_halaman_detail_tidak_menampilkan_peringatan_konflik_periode_palsu_saat_tidak_ada_konflik(): void
    {
        $dataset = $this->upload([
            ['metric_name', 'value', 'period'],
            ['Penjualan', '100', '2026-08-31'],
        ]);

        $unit = OrgUnit::create(['name' => 'TJBT']);
        $indicator = Indicator::create(['org_unit_id' => $unit->id, 'name' => 'Indikator 1']);

        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'division_id' => $this->division->id,
            'org_unit_id' => $unit->id,
            'indicator_id' => $indicator->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'sheet_period' => 'S.D. AGUSTUS 2026',
            'period_conflict' => false,
        ]);

        $this->actingAs($this->staff)
            ->get(route('datasets.show', $dataset))
            ->assertOk()
            ->assertSee('Ringkasan validasi')
            ->assertSee('Sesuai: 1')
            ->assertDontSee('Konflik Periode:')
            ->assertDontSee('Konflik periode banner sheet');
    }
}
