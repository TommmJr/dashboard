<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->division = Division::factory()->create();
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

        $this->actingAs($this->staff)->post(route('datasets.store'), ['file' => $file]);

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

        $this->assertSame(Dataset::STATUS_READY, $dataset->status);
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

        $this->assertSame(Dataset::STATUS_READY, $dataset->status);
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

        $this->assertSame(Dataset::STATUS_READY, $dataset->status);
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

        $this->assertSame(Dataset::STATUS_READY, $dataset->status);
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
            ->assertSee('FATAL: 1')
            ->assertSee('OK: 1');
    }
}
