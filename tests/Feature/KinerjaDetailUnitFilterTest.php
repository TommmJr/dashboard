<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceMetric;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Pemilih unit untuk widget `kinerja-detail` (R7) - display-only.
 *
 * Kontrak yang dikunci di sini:
 * - TANPA parameter unit: payload IDENTIK dengan perilaku lama (filter + cap
 *   global, tanpa key baru di payload reader).
 * - `?unit=TJBT` dan sejenisnya: baris difilter ke unit itu SAJA, dan cap 50 +
 *   total_rows + truncated dihitung PER unit terpilih.
 * - Unit tidak dikenal: perilaku default persis (bukan empty state).
 * - Dropdown memuat SEMUA unit di data termasuk yang terpotong cap 50, TJBT
 *   lebih dulu, sisanya alfabetis, plus link "TJBT" yang terlihat.
 *
 * Test ini TIDAK menyentuh karakterisasi legacy (rumus, polaritas, ambang, cap
 * default) dan tidak mengubah test lama.
 */
class KinerjaDetailUnitFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Test ini mengunci pemilih unit (R7) memakai nama unit stand-in (MES,
        // OSL, ZZZ). Batas unit TAMPIL (keputusan 2026-10-12, `kpi.visible_units`)
        // dimatikan supaya stand-in non-TJBT tetap valid; batas itu diuji di
        // `KpiUnitScopeTest`.
        config(['kpi.visible_units' => null]);
    }

    // ------------------------------------------------------------------
    // 1. Default: tanpa parameter unit = perilaku lama persis
    // ------------------------------------------------------------------

    public function test_tanpa_param_unit_payload_sama_seperti_sebelum_perubahan(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | Indikator A | target', 200.0);
        $this->metric($dataset, $division, 'TJBT | Indikator A | realisasi', 100.0);
        $this->metric($dataset, $division, 'TJBT | NKO', 105.5);
        $this->metric($dataset, $division, 'MES | Indikator B | target', 10.0);
        $this->metric($dataset, $division, 'MES | Indikator B | realisasi', 5.0);

        $result = $this->reader('kinerjaDetail', [$this->management()]);

        // Key payload TIDAK bertambah: karakterisasi mengunci 4 key ini.
        $this->assertSame(['period', 'rows', 'truncated', 'total_rows'], array_keys($result));

        $this->assertSame('2026-08-31', $result['period']);
        $this->assertFalse($result['truncated']);
        $this->assertSame(2, $result['total_rows']);
        $this->assertSame(['MES', 'TJBT'], array_column($result['rows'], 'unit'));

        // Nilai tidak berubah: pencapaian legacy = realisasi/target*100.
        $tjbt = $result['rows'][1];
        $this->assertSame(200.0, $tjbt['target']);
        $this->assertSame(100.0, $tjbt['realisasi']);
        $this->assertSame(50.0, $tjbt['pencapaian']);
        $this->assertSame(105.5, $tjbt['nko']);
    }

    public function test_unit_kosong_eksplisit_juga_perilaku_default(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->metric($dataset, $division, 'TJBT | I | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | I | realisasi', 100.0);

        $this->assertSame(
            $this->reader('kinerjaDetail', [$this->management()]),
            $this->reader('kinerjaDetail', [$this->management(), null, null, []]),
        );
    }

    // ------------------------------------------------------------------
    // 2. Filter unit eksplisit
    // ------------------------------------------------------------------

    public function test_param_unit_tjbt_hanya_menampilkan_baris_tjbt(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | Indikator A | target', 200.0);
        $this->metric($dataset, $division, 'TJBT | Indikator A | realisasi', 100.0);
        $this->metric($dataset, $division, 'TJBT | NKO', 105.5);
        $this->metric($dataset, $division, 'MES | Indikator B | target', 10.0);
        $this->metric($dataset, $division, 'MES | Indikator B | realisasi', 5.0);
        $this->metric($dataset, $division, 'OSL | Indikator C | target', 10.0);
        $this->metric($dataset, $division, 'OSL | Indikator C | realisasi', 5.0);

        $result = $this->reader('kinerjaDetail', [$this->management(), null, null, ['TJBT']]);

        $this->assertSame(['TJBT'], array_column($result['rows'], 'unit'));
        $this->assertSame(1, $result['total_rows']);
        $this->assertFalse($result['truncated']);
        $this->assertSame(105.5, $result['rows'][0]['nko']);
    }

    public function test_unit_tidak_dikenal_kembali_ke_perilaku_default(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->metric($dataset, $division, 'TJBT | I | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | I | realisasi', 100.0);
        $this->metric($dataset, $division, 'MES | I | target', 100.0);
        $this->metric($dataset, $division, 'MES | I | realisasi', 100.0);

        $default = $this->reader('kinerjaDetail', [$this->management()]);
        $unknown = $this->reader('kinerjaDetail', [$this->management(), null, null, ['TIDAK-ADA']]);

        $this->assertSame(['MES', 'TJBT'], array_column($unknown['rows'], 'unit'));
        $this->assertSame($default, $unknown);
    }

    public function test_sebagian_unit_tidak_dikenal_tetap_memfilter_yang_valid(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->metric($dataset, $division, 'TJBT | I | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | I | realisasi', 100.0);
        $this->metric($dataset, $division, 'MES | I | target', 100.0);
        $this->metric($dataset, $division, 'MES | I | realisasi', 100.0);

        $result = $this->reader('kinerjaDetail', [$this->management(), null, null, ['TJBT', 'GHOST']]);

        $this->assertSame(['TJBT'], array_column($result['rows'], 'unit'));
        $this->assertSame(1, $result['total_rows']);
    }

    // ------------------------------------------------------------------
    // 3. Cap 50 berlaku PER unit terpilih
    // ------------------------------------------------------------------

    public function test_cap_50_dan_truncated_dihitung_per_unit_terpilih(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        // TJBT: 60 indikator -> di ATAS cap 50, dan urutan alfabetis menaruh
        // TJBT setelah MES sehingga tanpa filter ia tidak pernah tampil.
        for ($i = 1; $i <= 60; $i++) {
            $name = sprintf('TJBT | Indikator %02d | target', $i);
            $this->metric($dataset, $division, $name, 100.0);
            $this->metric($dataset, $division, sprintf('TJBT | Indikator %02d | realisasi', $i), 100.0);
        }
        for ($i = 1; $i <= 5; $i++) {
            $this->metric($dataset, $division, sprintf('MES | Indikator %02d | target', $i), 100.0);
            $this->metric($dataset, $division, sprintf('MES | Indikator %02d | realisasi', $i), 100.0);
        }

        // Default: cap global -> seluruh 50 baris terpakai MES + awal TJBT.
        $default = $this->reader('kinerjaDetail', [$this->management()]);
        $this->assertTrue($default['truncated']);
        $this->assertSame(65, $default['total_rows']);
        $this->assertCount(50, $default['rows']);
        $this->assertContains('MES', array_column($default['rows'], 'unit'));

        // Per unit: hanya TJBT, cap dihitung atas 60 baris TJBT saja.
        $tjbt = $this->reader('kinerjaDetail', [$this->management(), null, null, ['TJBT']]);
        $this->assertTrue($tjbt['truncated']);
        $this->assertSame(60, $tjbt['total_rows']);
        $this->assertCount(50, $tjbt['rows']);
        $this->assertSame(['TJBT'], array_unique(array_column($tjbt['rows'], 'unit')));
    }

    // ------------------------------------------------------------------
    // 4. Daftar unit untuk dropdown: semua unit, TJBT pertama
    // ------------------------------------------------------------------

    public function test_daftar_unit_memuat_semua_unit_termasuk_yang_terpotong_cap(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | I | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | I | realisasi', 100.0);
        // MES punya 60 baris; unit lain (ZZZ) hanya punya 1 baris dan jatuh
        // di luar cap 50 -> tetap WAJIB muncul di daftar unit.
        for ($i = 1; $i <= 60; $i++) {
            $this->metric($dataset, $division, sprintf('MES | Indikator %02d | target', $i), 100.0);
            $this->metric($dataset, $division, sprintf('MES | Indikator %02d | realisasi', $i), 100.0);
        }
        $this->metric($dataset, $division, 'ZZZ | I | target', 100.0);
        $this->metric($dataset, $division, 'ZZZ | I | realisasi', 100.0);

        $result = $this->reader('kinerjaDetail', [$this->management()]);

        $this->assertCount(50, $result['rows']);
        $this->assertNotContains('ZZZ', array_column($result['rows'], 'unit'));

        $units = $this->units('kinerjaDetail', [$this->management()]);

        // TJBT lebih dulu, sisanya alfabetis, dan ZZZ yang terpotong tetap ada.
        $this->assertSame(['TJBT', 'MES', 'ZZZ'], $units);
    }

    public function test_tjbt_pertama_di_dropdown_lewat_html(): void
    {
        $division = $this->pengusahaanDivision();
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | I | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | I | realisasi', 100.0);
        $this->metric($dataset, $division, 'MES | I | target', 100.0);
        $this->metric($dataset, $division, 'MES | I | realisasi', 100.0);

        $response = $this->actingAs($this->staffOf($division))->get(route('dashboard'))->assertOk();

        $response->assertSee('id="kinerja-detail-unit"', false);
        $response->assertSeeInOrder(['>TJBT<', '>MES<'], false);

        // Link jalan pintas untuk TJBT, plus opsi default "Semua unit".
        $response->assertSee('unit=TJBT', false);
        $response->assertSee('Semua unit');
    }

    public function test_tanpa_data_unit_dropdown_tidak_dirender(): void
    {
        $division = $this->pengusahaanDivision();

        $response = $this->actingAs($this->staffOf($division))->get(route('dashboard'))->assertOk();

        $response->assertDontSee('id="kinerja-detail-unit"', false);
        $response->assertSee('Belum ada data metrik pada periode ini.');
    }

    public function test_unit_terpilih_lewat_http_ditandai_dan_baris_terfilter(): void
    {
        $division = $this->pengusahaanDivision();
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | Indikator A | target', 200.0);
        $this->metric($dataset, $division, 'TJBT | Indikator A | realisasi', 100.0);
        $this->metric($dataset, $division, 'MES | Indikator B | target', 10.0);
        $this->metric($dataset, $division, 'MES | Indikator B | realisasi', 5.0);

        $response = $this->actingAs($this->staffOf($division))
            ->get(route('dashboard', ['unit' => 'TJBT']))
            ->assertOk();

        $response->assertSee('value="TJBT" selected', false);
        $response->assertSee('Indikator A');
        $response->assertDontSee('Indikator B');
    }

    public function test_tanggal_metadata_terstruktur_hanya_mengubah_display_di_kedua_read_model(): void
    {
        $division = $this->pengusahaanDivision();
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->metric($dataset, $division, 'TJBT | Tanggal | target', 46173);
        $this->metric($dataset, $division, 'TJBT | Tanggal | realisasi', 46163);
        $indicator = Indicator::create([
            'org_unit_id' => OrgUnit::firstOrCreate(['name' => 'TJBT'])->id,
            'name' => 'Tanggal',
            'category' => 'kinerja',
            'is_group' => false,
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $indicator->org_unit_id,
            'division_id' => $division->id,
            'period' => '2026-08-31',
            'target_value' => null,
            'target_date' => '2026-05-31',
            'target_type' => PerformanceObservation::TYPE_DATE,
            'realisasi_value' => null,
            'realisasi_date' => '2026-05-21',
            'realisasi_type' => PerformanceObservation::TYPE_DATE,
        ]);

        foreach (['legacy', 'structured'] as $readModel) {
            config(['kpi.read_model' => $readModel]);
            $result = $this->reader('kinerjaDetail', [$this->management()]);
            $row = $result['rows'][0];

            if ($readModel === 'legacy') {
                $this->assertSame(46173.0, $row['target']);
                $this->assertSame(46163.0, $row['realisasi']);
            } else {
                $this->assertNull($row['target']);
                $this->assertNull($row['realisasi']);
            }
            $this->assertSame('31/05/2026', $row['target_display']);
            $this->assertSame('21/05/2026', $row['realisasi_display']);
        }

        config(['kpi.read_model' => 'legacy']);
        $response = $this->actingAs($this->staffOf($division))->get(route('dashboard'))->assertOk();
        $response->assertSee('31/05/2026');
        $response->assertSee('21/05/2026');
        $response->assertDontSee('46.173,00');
        $response->assertDontSee('46.163,00');
    }

    public function test_tidak_ada_pasangan_tanggal_maka_angka_tetap_diformat_sebagai_angka(): void
    {
        $division = $this->pengusahaanDivision();
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->metric($dataset, $division, 'TJBT | Angka | target', 46173);
        $this->metric($dataset, $division, 'TJBT | Angka | realisasi', 10);
        $unrelated = Indicator::create([
            'org_unit_id' => OrgUnit::firstOrCreate(['name' => 'TJBT'])->id,
            'name' => 'Indikator lain',
            'category' => 'kinerja',
            'is_group' => false,
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $unrelated->id,
            'org_unit_id' => $unrelated->org_unit_id,
            'division_id' => $division->id,
            'period' => '2026-08-31',
            'target_value' => null,
            'target_date' => '2026-05-31',
            'target_type' => PerformanceObservation::TYPE_DATE,
        ]);

        $result = $this->reader('kinerjaDetail', [$this->management()]);
        $row = $result['rows'][0];

        $this->assertSame(46173.0, $row['target']);
        $this->assertNull($row['target_display']);

        $response = $this->actingAs($this->staffOf($division))->get(route('dashboard'))->assertOk();
        $response->assertSee('46.173');
    }

    // ------------------------------------------------------------------
    // 5. Pencapaian baris bertipe tanggal = skor workbook (R7 legacy)
    // ------------------------------------------------------------------

    public function test_baris_tanggal_memakai_skor_pencapaian_workbook(): void
    {
        $division = $this->pengusahaanDivision();
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->metric($dataset, $division, 'TJBT | Material Cadang | target', 46173);
        $this->metric($dataset, $division, 'TJBT | Material Cadang | realisasi', 46163);
        $this->dateObservation($dataset, $division, 'Material Cadang', 1.0142857);

        foreach (['legacy', 'structured'] as $readModel) {
            config(['kpi.read_model' => $readModel]);
            $row = $this->reader('kinerjaDetail', [$this->management()])['rows'][0];

            $this->assertSame('31/05/2026', $row['target_display']);
            // Rasio serial tanggal (46163/46173) TIDAK dipakai; skor workbook 1,0142857.
            $this->assertSame(101.4, $row['pencapaian']);
        }

        config(['kpi.read_model' => 'legacy']);
    }

    public function test_baris_tanggal_tanpa_skor_tetap_null_bukan_rasio_serial(): void
    {
        $division = $this->pengusahaanDivision();
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->metric($dataset, $division, 'TJBT | Tanpa Skor | target', 46173);
        $this->metric($dataset, $division, 'TJBT | Tanpa Skor | realisasi', 46163);
        $this->dateObservation($dataset, $division, 'Tanpa Skor', null);

        foreach (['legacy', 'structured'] as $readModel) {
            config(['kpi.read_model' => $readModel]);
            $row = $this->reader('kinerjaDetail', [$this->management()])['rows'][0];

            $this->assertSame('31/05/2026', $row['target_display']);
            $this->assertNull($row['pencapaian']);
        }

        config(['kpi.read_model' => 'legacy']);
    }

    public function test_baris_numerik_tetap_memakai_rasio_realisasi_per_target(): void
    {
        $division = $this->pengusahaanDivision();
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->metric($dataset, $division, 'TJBT | Numerik | target', 200);
        $this->metric($dataset, $division, 'TJBT | Numerik | realisasi', 100);
        $this->numericObservation($dataset, $division, 'TJBT', 'Numerik', 200.0, 100.0, 1.1);

        foreach (['legacy', 'structured'] as $readModel) {
            config(['kpi.read_model' => $readModel]);
            $row = $this->reader('kinerjaDetail', [$this->management()])['rows'][0];

            $this->assertSame(50.0, $row['pencapaian']);
            $this->assertNull($row['target_display']);
        }

        config(['kpi.read_model' => 'legacy']);
    }

    public function test_r7_structured_filter_unit_dropdown_dan_cap_50_tanpa_performance_metrics(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->pengusahaanDivision();
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        for ($i = 1; $i <= 60; $i++) {
            $this->numericObservation($dataset, $division, 'TJBT', sprintf('Indikator %02d', $i), 200.0, 100.0);
        }
        for ($i = 1; $i <= 5; $i++) {
            $this->numericObservation($dataset, $division, 'MES', sprintf('Indikator %02d', $i), 10.0, 5.0);
        }
        $this->numericObservation($dataset, $division, 'ZZZ', 'Indikator 01', 10.0, 10.0);

        $this->assertSame(0, PerformanceMetric::count());

        $default = $this->reader('kinerjaDetail', [$this->management()]);
        $this->assertSame('2026-08-31', $default['period']);
        $this->assertTrue($default['truncated']);
        $this->assertSame(66, $default['total_rows']);
        $this->assertCount(50, $default['rows']);
        $this->assertNotContains('ZZZ', array_column($default['rows'], 'unit'));

        $units = $this->units('kinerjaDetail', [$this->management()]);
        $this->assertSame(['TJBT', 'MES', 'ZZZ'], $units);

        $tjbtOnly = $this->reader('kinerjaDetail', [$this->management(), null, null, ['TJBT']]);
        $this->assertTrue($tjbtOnly['truncated']);
        $this->assertSame(60, $tjbtOnly['total_rows']);
        $this->assertCount(50, $tjbtOnly['rows']);
        $this->assertSame(['TJBT'], array_values(array_unique(array_column($tjbtOnly['rows'], 'unit'))));

        $unknown = $this->reader('kinerjaDetail', [$this->management(), null, null, ['TIDAK-ADA']]);
        $this->assertSame($default, $unknown);

        config(['kpi.read_model' => 'legacy']);
    }

    public function test_r7_structured_baris_daun_tanpa_pasangan_numerik_memakai_skor_excel_dan_mengabaikan_grup(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->pengusahaanDivision();
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $unit = OrgUnit::firstOrCreate(['name' => 'TJBT']);

        $group = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Grup Induk',
            'category' => 'kinerja',
            'is_group' => true,
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $group->id,
            'org_unit_id' => $unit->id,
            'division_id' => $division->id,
            'period' => '2026-08-31',
            'target_value' => null,
            'target_type' => PerformanceObservation::TYPE_EMPTY,
            'realisasi_value' => null,
            'realisasi_type' => PerformanceObservation::TYPE_EMPTY,
            'pencapaian' => 0.95,
        ]);

        $textTarget = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Target Teks Rasio',
            'category' => 'kinerja',
            'is_group' => false,
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $textTarget->id,
            'org_unit_id' => $unit->id,
            'division_id' => $division->id,
            'period' => '2026-08-31',
            'target_value' => null,
            'target_text' => 'Sesuai RKAP',
            'target_type' => PerformanceObservation::TYPE_TEXT,
            'realisasi_value' => 99.85,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'pencapaian' => 1.0485,
        ]);

        $textRealisasi = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Realisasi Teks Persen',
            'category' => 'kinerja',
            'is_group' => false,
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $textRealisasi->id,
            'org_unit_id' => $unit->id,
            'division_id' => $division->id,
            'period' => '2026-08-31',
            'target_value' => 261.0,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_value' => null,
            'realisasi_text' => 'Dokumen Lengkap',
            'realisasi_type' => PerformanceObservation::TYPE_TEXT,
            'pencapaian' => 110.0,
        ]);

        $result = $this->reader('kinerjaDetail', [$this->management()]);
        $byName = collect($result['rows'])->keyBy('indikator');

        $this->assertSame(2, $result['total_rows']);
        $this->assertFalse($byName->has('Grup Induk'));
        $this->assertSame(104.9, $byName['Target Teks Rasio']['pencapaian']);
        $this->assertSame(110.0, $byName['Realisasi Teks Persen']['pencapaian']);

        config(['kpi.read_model' => 'legacy']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Observasi terstruktur bertipe tanggal untuk unit TJBT + indikator tsb.
     * `$pencapaian` = rasio skor workbook (mis. 1,0142857), NULL bila sel kosong.
     */
    private function dateObservation(Dataset $dataset, Division $division, string $name, ?float $pencapaian): PerformanceObservation
    {
        $indicator = Indicator::create([
            'org_unit_id' => OrgUnit::firstOrCreate(['name' => 'TJBT'])->id,
            'name' => $name,
            'category' => 'kinerja',
            'is_group' => false,
        ]);

        return PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $indicator->org_unit_id,
            'division_id' => $division->id,
            'period' => '2026-08-31',
            'target_value' => null,
            'target_date' => '2026-05-31',
            'target_type' => PerformanceObservation::TYPE_DATE,
            'realisasi_value' => null,
            'realisasi_date' => '2026-05-21',
            'realisasi_type' => PerformanceObservation::TYPE_DATE,
            'pencapaian' => $pencapaian,
        ]);
    }

    private function numericObservation(
        Dataset $dataset,
        Division $division,
        string $unit,
        string $name,
        float $target,
        float $realisasi,
        ?float $pencapaian = null,
        string $period = '2026-08-31',
    ): PerformanceObservation {
        $orgUnit = OrgUnit::firstOrCreate(['name' => $unit]);
        $indicator = Indicator::firstOrCreate(
            ['org_unit_id' => $orgUnit->id, 'name' => $name],
            ['category' => 'kinerja', 'is_group' => false],
        );

        return PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $orgUnit->id,
            'division_id' => $division->id,
            'period' => $period,
            'target_value' => $target,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_value' => $realisasi,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'pencapaian' => $pencapaian,
        ]);
    }

    private function reader(string $method, array $args = []): mixed
    {
        $controller = app(DashboardController::class);
        $reflection = new ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$args);
    }

    /**
     * Daftar unit untuk dropdown: properti controller yang diisi reader dan
     * ditempel controller ke wrapper widget (bukan ke payload). Dipakai
     * instance controller YANG SAMA, karena properti ini per-instance.
     *
     * @return list<string>
     */
    private function units(string $method, array $args = []): array
    {
        $controller = app(DashboardController::class);
        $reflection = new ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);
        $reflection->invoke($controller, ...$args);

        $property = new \ReflectionProperty($controller, 'kinerjaDetailUnits');
        $property->setAccessible(true);

        return $property->getValue($controller) ?? [];
    }

    private function division(string $name): Division
    {
        return Division::factory()->create(['name' => $name]);
    }

    /**
     * Divisi pemilik layout KPI lengkap (kinerja-detail hanya dipasang di sini).
     */
    private function pengusahaanDivision(): Division
    {
        return Division::factory()->create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);
    }

    private function staffOf(Division $division): User
    {
        return User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);
    }

    private function management(): User
    {
        return User::factory()->withRole(Role::MANAGEMENT)->create();
    }

    private function metric(Dataset $dataset, Division $division, string $name, float $value, string $period = '2026-08-31'): PerformanceMetric
    {
        return PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'metric_name' => $name,
            'value' => $value,
            'period' => $period,
        ]);
    }
}
