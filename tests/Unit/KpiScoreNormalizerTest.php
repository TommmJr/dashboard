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
}
