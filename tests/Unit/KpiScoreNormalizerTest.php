<?php

namespace Tests\Unit;

use App\Services\KpiReportConverter;
use App\Support\KpiScoreNormalizer;
use PHPUnit\Framework\TestCase;

class KpiScoreNormalizerTest extends TestCase
{
    public function test_normalize_mengonversi_skor_rasio_ke_persen(): void
    {
        $this->assertEqualsWithDelta(100.07, KpiScoreNormalizer::normalize(1.0007), 0.001);
        $this->assertEqualsWithDelta(101.43, KpiScoreNormalizer::normalize(1.0143), 0.001);
        $this->assertEqualsWithDelta(104.85, KpiScoreNormalizer::normalize(1.0485), 0.001);
        $this->assertEqualsWithDelta(110.0, KpiScoreNormalizer::normalize(1.1000), 0.001);
    }

    public function test_normalize_mempertahankan_skor_yang_sudah_persen(): void
    {
        $this->assertEqualsWithDelta(110.0, KpiScoreNormalizer::normalize(110.0), 0.001);
        $this->assertEqualsWithDelta(95.5, KpiScoreNormalizer::normalize(95.5), 0.001);
    }

    public function test_clamp_membatasi_skor_antara_0_dan_110(): void
    {
        $this->assertSame(0.0, KpiScoreNormalizer::clamp(-5.0));
        $this->assertSame(100.0, KpiScoreNormalizer::clamp(100.0));
        $this->assertSame(110.0, KpiScoreNormalizer::clamp(110.0));
        $this->assertSame(110.0, KpiScoreNormalizer::clamp(632.0));
    }

    public function test_normalize_and_clamp_menggabungkan_normalisasi_dan_pembatasan(): void
    {
        $this->assertEqualsWithDelta(104.85, KpiScoreNormalizer::normalizeAndClamp(1.0485), 0.001);
        $this->assertSame(110.0, KpiScoreNormalizer::normalizeAndClamp(632.0));
        $this->assertSame(0.0, KpiScoreNormalizer::normalizeAndClamp(-1.0));
    }

    public function test_is_ratio_mendeteksi_apakah_skor_adalah_rasio_kurang_dari_atau_sama_dengan_ambang(): void
    {
        $this->assertTrue(KpiScoreNormalizer::isRatio(1.0007));
        $this->assertTrue(KpiScoreNormalizer::isRatio(1.10));
        $this->assertFalse(KpiScoreNormalizer::isRatio(1.101));
        $this->assertFalse(KpiScoreNormalizer::isRatio(95.5));
    }

    public function test_score_scale_mengenali_formula_rasio_tervalidasi(): void
    {
        $res = KpiScoreNormalizer::scoreScale(1.0007, KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO);
        $this->assertSame('ratio', $res['scale']);
        $this->assertEqualsWithDelta(100.07, $res['percent'], 0.001);

        $resDate = KpiScoreNormalizer::scoreScale(1.0143, KpiReportConverter::PENCAPAIAN_FORMULA_DATE_DELTA);
        $this->assertSame('ratio', $resDate['scale']);
        $this->assertEqualsWithDelta(101.43, $resDate['percent'], 0.001);

        $resAvg = KpiScoreNormalizer::scoreScale(1.05, KpiReportConverter::PENCAPAIAN_FORMULA_AVERAGE);
        $this->assertSame('ratio', $resAvg['scale']);
        $this->assertEqualsWithDelta(105.0, $resAvg['percent'], 0.001);
    }

    public function test_score_scale_mengenali_skor_di_atas_1_koma_1_sebagai_persen(): void
    {
        $res = KpiScoreNormalizer::scoreScale(110.0, null);
        $this->assertSame('percent', $res['scale']);
        $this->assertEqualsWithDelta(110.0, $res['percent'], 0.001);
    }

    public function test_score_scale_menangani_skor_tanpa_formula_kind_dengan_rasio_terinferensi(): void
    {
        // Kasus 403: raw 1.0485, formula kind null.
        // Dengan normalizer terpadu, inferensi rasio PLN diterapkan konsisten dengan R6/R7.
        $res = KpiScoreNormalizer::scoreScale(1.0485, null);
        $this->assertSame('ratio', $res['scale']);
        $this->assertEqualsWithDelta(104.85, $res['percent'], 0.001);
    }

    public function test_rag_tier_membulatkan_satu_desimal_sebelum_evaluasi_batas(): void
    {
        $this->assertSame(KpiScoreNormalizer::TIER_HAMPIR, KpiScoreNormalizer::ragTier(99.94));
        $this->assertSame(KpiScoreNormalizer::TIER_TERCAPAI, KpiScoreNormalizer::ragTier(99.95));
        $this->assertSame(KpiScoreNormalizer::TIER_TERCAPAI, KpiScoreNormalizer::ragTier(99.96));
        $this->assertSame(KpiScoreNormalizer::TIER_TERCAPAI, KpiScoreNormalizer::ragTier(99.99));
        $this->assertSame(KpiScoreNormalizer::TIER_TERCAPAI, KpiScoreNormalizer::ragTier(100.0));

        $this->assertSame(KpiScoreNormalizer::TIER_PERLU, KpiScoreNormalizer::ragTier(94.94));
        $this->assertSame(KpiScoreNormalizer::TIER_HAMPIR, KpiScoreNormalizer::ragTier(94.95));
    }

    public function test_format_number_membatasi_satu_desimal_tanpa_nol_ekor(): void
    {
        $this->assertSame('100', KpiScoreNormalizer::formatNumber(99.986));
        $this->assertSame('6,7', KpiScoreNormalizer::formatNumber(6.6575));
        $this->assertSame('205,2', KpiScoreNormalizer::formatNumber(205.16));
        $this->assertSame('158,5', KpiScoreNormalizer::formatNumber(158.53));
        $this->assertSame('181.979,5', KpiScoreNormalizer::formatNumber(181979.53));
        $this->assertSame('99,7', KpiScoreNormalizer::formatNumber(99.7));
        $this->assertSame('4', KpiScoreNormalizer::formatNumber(4.0));
        $this->assertSame('0', KpiScoreNormalizer::formatNumber(0.0));
        $this->assertSame('0', KpiScoreNormalizer::formatNumber(-0.01));
        $this->assertSame('100,1%', KpiScoreNormalizer::formatNumber(100.07, 1, '%'));
        $this->assertSame('100%', KpiScoreNormalizer::formatNumber(100.0, 1, '%'));
        $this->assertSame('Tidak tercatat', KpiScoreNormalizer::formatNumber(null));
    }

    public function test_describe_comparison_membangun_visual_dan_keterangan_ramah_pengguna(): void
    {
        $attb = KpiScoreNormalizer::describeComparison([
            'target' => 28795.3,
            'realisasi' => 181979.53,
            'satuan' => 'Rp. Juta',
            'polarity' => KpiReportConverter::POLARITY_HIGHER_IS_BETTER,
            'skor_kpi' => 110.0,
            'tier' => KpiScoreNormalizer::TIER_TERCAPAI,
        ]);
        $this->assertSame('Rp Juta', $attb['unit_label']);
        $this->assertSame('Rp Juta', $attb['target_hint']);
        $this->assertSame('+153.184,2 Rp Juta', $attb['diff_label']);
        $this->assertSame(100.0, $attb['realisasi_bar_pct']);
        $this->assertLessThan(20.0, $attb['target_bar_pct']);

        $nac = KpiScoreNormalizer::describeComparison([
            'target' => 205.16,
            'realisasi' => 158.53,
            'satuan' => 'Rp. Milyar',
            'polarity' => KpiReportConverter::POLARITY_LOWER_IS_BETTER,
            'skor_kpi' => 110.0,
            'tier' => KpiScoreNormalizer::TIER_TERCAPAI,
        ]);
        $this->assertSame('Nominal Rp Miliar • Makin kecil makin baik', $nac['indicator_hint']);
        $this->assertSame('Hemat 46,7 Rp Miliar', $nac['diff_label']);
        $this->assertSame(100.0, $nac['target_bar_pct']);
        $this->assertLessThan(80.0, $nac['realisasi_bar_pct']);

        $bay = KpiScoreNormalizer::describeComparison([
            'target' => 6.6575,
            'realisasi' => 4.0,
            'satuan' => 'Kali',
            'polarity' => KpiReportConverter::POLARITY_LOWER_IS_BETTER,
            'skor_kpi' => 110.0,
            'tier' => KpiScoreNormalizer::TIER_TERCAPAI,
        ]);
        $this->assertSame('Lebih sedikit 2,7 Kali', $bay['diff_label']);

        $dateRow = KpiScoreNormalizer::describeComparison([
            'target_type' => 'date',
            'realisasi_type' => 'date',
            'satuan' => 'Waktu',
            'delta_days' => -10,
            'skor_kpi' => 101.4,
            'tier' => KpiScoreNormalizer::TIER_TERCAPAI,
        ]);
        $this->assertSame('Batas tanggal', $dateRow['target_hint']);
        $this->assertSame('Tanggal tercapai', $dateRow['realisasi_hint']);
        $this->assertSame('Lebih cepat 10 hari', $dateRow['diff_label']);

        $rkap = KpiScoreNormalizer::describeComparison([
            'target' => null,
            'realisasi' => 99.85,
            'target_type' => 'text',
            'realisasi_type' => 'numeric',
            'target_text' => '95% - 105%',
            'satuan' => '%',
            'skor_kpi' => 104.9,
            'tier' => KpiScoreNormalizer::TIER_TERCAPAI,
        ]);
        $this->assertSame('95% - 105%', $rkap['target_override']);
        $this->assertSame('Target rentang (teks)', $rkap['target_hint']);
        $this->assertSame('Dalam rentang target', $rkap['diff_label']);

        $legal = KpiScoreNormalizer::describeComparison([
            'target' => 261.0,
            'realisasi' => null,
            'target_type' => 'numeric',
            'realisasi_type' => 'text',
            'realisasi_text' => "Sesuai Target : 283\nDiluar Target : 52",
            'satuan' => 'Dokumen Legal',
            'polarity' => KpiReportConverter::POLARITY_HIGHER_IS_BETTER,
            'skor_kpi' => 110.0,
            'tier' => KpiScoreNormalizer::TIER_TERCAPAI,
        ]);
        $this->assertSame('Sesuai target: 283', $legal['realisasi_override']);
        $this->assertSame('Dokumen Legal • Sumber teks (+52 di luar target)', $legal['realisasi_hint']);
        $this->assertSame('+22 Dokumen Legal', $legal['diff_label']);
    }
}
