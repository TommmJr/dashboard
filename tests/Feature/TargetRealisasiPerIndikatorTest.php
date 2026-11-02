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
use App\Services\KpiReportConverter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Panel Target vs Realisasi PER INDIKATOR (keputusan Pak Gama 2026-10-19 11:17).
 *
 * Kontrak yang dikunci:
 * 1. Serial tanggal mentah TIDAK pernah masuk nilai bar; tanggal tampil sebagai
 *    tanggal + selisih hari.
 * 2. Tidak ada agregasi lintas indikator/satuan: satu panel = satu observasi.
 * 3. Skor berskala: `pencapaian` dipakai apa adanya; 110 (persen) tampil 110%,
 *    skor yang skalanya tidak terbukti tampil "tidak terverifikasi" TANPA persen.
 * 4. Baris grup hanya judul seksi; NKO tidak pernah jadi panel.
 * 5. Identitas panel = indicator_id (bukan nama).
 * 6. Widget tidak bergantung pada `kpi.read_model` dan tidak mengubahnya.
 */
class TargetRealisasiPerIndikatorTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08-31';

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
    }

    // ------------------------------------------------------------- helpers --

    private function reader(array $args): array
    {
        $controller = app(DashboardController::class);
        $method = new ReflectionMethod($controller, 'targetRealisasiPerIndikator');
        $method->setAccessible(true);

        return $method->invoke($controller, ...$args);
    }

    private function dataset(): Dataset
    {
        return Dataset::factory()->create([
            'division_id' => $this->division->id,
            'status' => Dataset::STATUS_READY,
        ]);
    }

    private function unit(string $name): OrgUnit
    {
        return OrgUnit::firstOrCreate(['name' => $name]);
    }

    private function indicator(string $unit, string $name, array $attributes = []): Indicator
    {
        return Indicator::create(array_merge([
            'org_unit_id' => $this->unit($unit)->id,
            'name' => $name,
            'category' => 'kinerja',
            'is_group' => false,
        ], $attributes));
    }

    private function observe(Dataset $dataset, Indicator $indicator, array $attributes = []): PerformanceObservation
    {
        return PerformanceObservation::create(array_merge([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $indicator->org_unit_id,
            'division_id' => $this->division->id,
            'period' => self::PERIOD,
            'target_value' => 100,
            'realisasi_value' => 100,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
        ], $attributes));
    }

    private function panels(): array
    {
        return $this->reader([$this->management(), null, null]);
    }

    private function staffPanels(): array
    {
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->division->id]);

        return $this->reader([$staff, null, null]);
    }

    private function management(): User
    {
        return User::factory()->withRole(Role::MANAGEMENT)->create();
    }

    private function panelById(array $payload, int $indicatorId): ?array
    {
        foreach ($payload['sections'] as $section) {
            foreach ($section['panels'] as $panel) {
                if ($panel['indicator_id'] === $indicatorId) {
                    return $panel;
                }
            }
        }

        return null;
    }

    // -------------------------------------------------- 1. tanggal mentah ----

    public function test_serial_tanggal_mentah_tidak_masuk_nilai_bar(): void
    {
        $dataset = $this->dataset();
        $indicator = $this->indicator('TJBT', 'Kualitas Data Material Cadang', [
            'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_DATE_DELTA,
        ]);

        // Bentuk paling berbahaya: kolom numeric berisi serial Excel, tetapi
        // tipenya sudah ditandai date. Nilai bar harus kosong, bukan 46173.
        $this->observe($dataset, $indicator, [
            'target_type' => PerformanceObservation::TYPE_DATE,
            'realisasi_type' => PerformanceObservation::TYPE_DATE,
            'target_value' => 46173,
            'realisasi_value' => 46163,
            'target_date' => '2026-05-31',
            'realisasi_date' => '2026-05-21',
            'pencapaian' => 1.0143,
        ]);

        $panel = $this->panelById($this->panels(), $indicator->id);

        $this->assertNotNull($panel);
        $this->assertSame('date', $panel['mode']);
        $this->assertNull($panel['numeric'], 'serial tanggal tidak boleh jadi nilai bar');
        $this->assertSame('2026-05-31', $panel['date']['target']);
        $this->assertSame('2026-05-21', $panel['date']['realisasi']);
        $this->assertSame(-10, $panel['date']['delta_days']);

        // Skor rasio date_delta -> 101,4% (dipakai apa adanya, bukan dihitung ulang).
        $this->assertSame('ratio', $panel['score']['scale']);
        $this->assertEqualsWithDelta(101.43, $panel['score']['percent'], 0.01);
    }

    // -------------------------------------- 2. tanpa agregasi lintas satuan ---

    public function test_indikator_bersatuan_berbeda_tetap_panel_terpisah(): void
    {
        $dataset = $this->dataset();
        $juta = $this->indicator('TJBT', 'Usulan Penghapusan ATTB', ['satuan' => 'Rp. Juta']);
        $milyar = $this->indicator('TJBT', 'Pengendalian NAC', ['satuan' => 'Rp. Milyar']);

        $this->observe($dataset, $juta, ['target_value' => 28795.3, 'realisasi_value' => 181979.53, 'pencapaian' => 1.1]);
        $this->observe($dataset, $milyar, ['target_value' => 205.16, 'realisasi_value' => 158.53, 'pencapaian' => 1.1]);

        $payload = $this->panels();
        $panels = array_merge(...array_map(fn (array $s): array => $s['panels'], $payload['sections']));

        $this->assertCount(2, $panels);
        $this->assertSame([$juta->id, $milyar->id], array_column($panels, 'indicator_id'));
        $this->assertSame(28795.3, $panels[0]['numeric']['target']);
        $this->assertSame(205.16, $panels[1]['numeric']['target']);
        // Tidak ada nilai gabungan (28795,3 + 205,16) di mana pun.
        foreach ($panels as $panel) {
            $this->assertNotEqualsWithDelta(29000.46, $panel['numeric']['target'], 0.001);
        }
    }

    // --------------------------------------------------- 3. skala skor ------

    public function test_id_410_tampil_110_persen_bukan_11000(): void
    {
        $dataset = $this->dataset();
        $indicator = $this->indicator('TJBT', 'Penyelesaian Dokumen Legal Aset Tanah PLN', ['satuan' => 'Dokumen Legal']);

        // Bentuk data riil id 410: target numeric, realisasi teks, skor 110
        // pada skala PERSEN workbook, formula tidak terklasifikasi.
        $this->observe($dataset, $indicator, [
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_TEXT,
            'target_value' => 261,
            'realisasi_value' => null,
            'realisasi_text' => "Sesuai Target : 283\nDiluar Target : 52",
            'pencapaian' => 110,
            'pencapaian_formula_kind' => null,
        ]);

        $panel = $this->panelById($this->panels(), $indicator->id);

        $this->assertSame('score_only', $panel['mode']);
        $this->assertSame('percent', $panel['score']['scale']);
        $this->assertEqualsWithDelta(110.0, $panel['score']['percent'], 0.0001);
        $this->assertNull($panel['numeric'], 'skor tidak boleh disamarkan sebagai nilai numerik');
    }

    public function test_skor_tanpa_bukti_skala_tidak_dikonversi(): void
    {
        $dataset = $this->dataset();
        $indicator = $this->indicator('TJBT', 'Pengendalian Anggaran Investasi sesuai RKAP');

        // Bentuk id 403: target numeric, realisasi teks, skor 1,0485 dan
        // formula tidak terklasifikasi -> rasio vs persen tidak terbukti.
        $this->observe($dataset, $indicator, [
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_TEXT,
            'target_value' => 99.85,
            'realisasi_value' => null,
            'realisasi_text' => '95% - 105%',
            'pencapaian' => 1.0485,
            'pencapaian_formula_kind' => null,
        ]);

        $panel = $this->panelById($this->panels(), $indicator->id);

        $this->assertSame('score_only', $panel['mode']);
        $this->assertSame('unverified', $panel['score']['scale']);
        $this->assertNull($panel['score']['percent'], 'skala tak terbukti tidak boleh dikonversi');
    }

    public function test_skor_rasio_terbukti_dikali_seratus(): void
    {
        $dataset = $this->dataset();
        $indicator = $this->indicator('TJBT', 'Faktor Ketersediaan Trafo', [
            'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
        ]);

        $this->observe($dataset, $indicator, [
            'target_value' => 99.92,
            'realisasi_value' => 99.986,
            'pencapaian' => 1.0007,
        ]);

        $panel = $this->panelById($this->panels(), $indicator->id);

        $this->assertSame('numeric', $panel['mode']);
        $this->assertSame('ratio', $panel['score']['scale']);
        $this->assertEqualsWithDelta(100.07, $panel['score']['percent'], 0.001);
    }

    // -------------------------------------------- 4. grup + NKO bukan panel --

    public function test_baris_grup_menjadi_judul_seksi_dan_nko_tidak_diplot(): void
    {
        $dataset = $this->dataset();
        $group = $this->indicator('TJBT', 'Faktor Ketersediaan Trafo dan Transmisi', ['is_group' => true]);
        $child = $this->indicator('TJBT', 'a. Faktor Ketersediaan Trafo', ['parent_id' => $group->id]);
        $nko = $this->indicator('TJBT', KpiReportConverter::NKO_LABEL, [
            'category' => KpiReportConverter::INDICATOR_CATEGORY_NKO,
        ]);

        $this->observe($dataset, $group);
        $this->observe($dataset, $child, ['target_value' => 99.92, 'realisasi_value' => 99.986]);
        $this->observe($dataset, $nko, ['nilai' => 105.5, 'target_type' => 'empty', 'realisasi_type' => 'empty']);

        $payload = $this->panels();
        $allPanels = array_merge(...array_map(fn (array $s): array => $s['panels'], $payload['sections']));

        $this->assertSame([$child->id], array_column($allPanels, 'indicator_id'), 'grup dan NKO tidak boleh jadi panel');
        $this->assertSame($group->id, $payload['sections'][0]['indicator_id']);
        $this->assertSame('Faktor Ketersediaan Trafo dan Transmisi', $payload['sections'][0]['name']);
    }

    public function test_baris_tanpa_data_cukup_disembunyikan(): void
    {
        $dataset = $this->dataset();
        $empty = $this->indicator('TJBT', 'Implementasi Maturity Level');

        $this->observe($dataset, $empty, [
            'target_type' => PerformanceObservation::TYPE_EMPTY,
            'realisasi_type' => PerformanceObservation::TYPE_EMPTY,
            'target_value' => null,
            'realisasi_value' => null,
            'nilai' => 0,
        ]);

        $this->assertSame([], $this->panels()['sections']);
    }

    // --------------------------------------- 5. identitas = indicator_id ----

    public function test_identitas_panel_memakai_indicator_id_bukan_nama(): void
    {
        $dataset = $this->dataset();
        // Nama sama, unit berbeda: dua indikator berbeda, dua panel berbeda.
        $a = $this->indicator('TJBT', 'Indikator Sama');
        $b = $this->indicator('TJBB', 'Indikator Sama');

        $this->observe($dataset, $a, ['target_value' => 10, 'realisasi_value' => 9]);
        $this->observe($dataset, $b, ['target_value' => 20, 'realisasi_value' => 21]);

        config(['kpi.visible_units' => ['TJBT', 'TJBB']]);
        $panels = array_merge(...array_map(fn (array $s): array => $s['panels'], $this->panels()['sections']));

        $this->assertSame([$a->id, $b->id], array_column($panels, 'indicator_id'));
        $this->assertNotSame($panels[0]['indicator_id'], $panels[1]['indicator_id']);
    }

    public function test_unit_di_luar_visible_units_tidak_tampil(): void
    {
        $dataset = $this->dataset();
        $tjbt = $this->indicator('TJBT', 'Indikator TJBT');
        $mes = $this->indicator('MES', 'Indikator MES');

        $this->observe($dataset, $tjbt);
        $this->observe($dataset, $mes);

        // Role staf divisi: scope divisi + batas unit tampil sama-sama aktif
        // (privileged melihat lintas divisi, jadi batas unitnya diuji di sini).
        $payload = $this->staffPanels();
        $panels = array_merge(...array_map(fn (array $s): array => $s['panels'], $payload['sections']));

        $this->assertSame([$tjbt->id], array_column($panels, 'indicator_id'));
        $this->assertSame(['TJBT'], $payload['units']);
    }

    // ------------------------------------ 6. tidak bergantung pada flag ------

    public function test_payload_identik_di_kedua_read_model_dan_flag_tidak_berubah(): void
    {
        $dataset = $this->dataset();
        $indicator = $this->indicator('TJBT', 'Faktor Ketersediaan Transmisi');
        $this->observe($dataset, $indicator, ['target_value' => 99.94, 'realisasi_value' => 99.978]);

        // Data legacy sengaja ADA. Kalau widget diam-diam membaca legacy,
        // payload akan memakai 1/1 dan test ini gagal.
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $this->division->id,
            'metric_name' => 'TJBT | Faktor Ketersediaan Transmisi | target',
            'value' => 1.0,
            'period' => self::PERIOD,
        ]);

        config(['kpi.read_model' => 'legacy']);
        $legacy = $this->panels();
        $this->assertSame('legacy', config('kpi.read_model'));

        config(['kpi.read_model' => 'structured']);
        $structured = $this->panels();

        $this->assertSame($legacy, $structured);
        $this->assertSame(99.94, $legacy['sections'][0]['panels'][0]['numeric']['target']);
    }

    // -------------------------------------------------------- HTTP/render ---

    public function test_widget_ter_mount_di_layout_staff_pengusahaan_dan_merender_panel(): void
    {
        $dataset = $this->dataset();
        $indicator = $this->indicator('TJBT', 'Faktor Ketersediaan Trafo', [
            'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
        ]);
        $this->observe($dataset, $indicator, [
            'target_value' => 99.92,
            'realisasi_value' => 99.986,
            'pencapaian' => 1.0007,
        ]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->division->id]);

        $response = $this->actingAs($staff)->get(route('dashboard'))->assertOk();

        $response->assertSee('Target dan Realisasi per Indikator');
        $response->assertSee('data-indicator-id="'.$indicator->id.'"', false);
        $response->assertSee('100,1%');
        // Widget lama tetap tidak ter-mount.
        $response->assertDontSee('Target vs Realisasi');
    }

    public function test_id_410_dirender_sebagai_110_persen(): void
    {
        $dataset = $this->dataset();
        $indicator = $this->indicator('TJBT', 'Penyelesaian Dokumen Legal Aset Tanah PLN');
        $this->observe($dataset, $indicator, [
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_TEXT,
            'target_value' => 261,
            'realisasi_value' => null,
            'realisasi_text' => 'Sesuai Target : 283',
            'pencapaian' => 110,
        ]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->division->id]);

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('110%')
            ->assertDontSee('11.000%');
    }

    public function test_skor_tak_terverifikasi_dirender_tanpa_persen(): void
    {
        $dataset = $this->dataset();
        $indicator = $this->indicator('TJBT', 'Pengendalian Anggaran Investasi sesuai RKAP');
        $this->observe($dataset, $indicator, [
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_TEXT,
            'target_value' => 99.85,
            'realisasi_value' => null,
            'realisasi_text' => '95% - 105%',
            'pencapaian' => 1.0485,
        ]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->division->id]);

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Skor tidak terverifikasi')
            ->assertDontSee('104,85%');
    }

    public function test_empty_state_saat_tidak_ada_data_terstruktur(): void
    {
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->division->id]);

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Target dan Realisasi per Indikator')
            ->assertSee('Belum ada data target/realisasi terstruktur pada periode ini.');
    }
}
