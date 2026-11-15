<?php

namespace App\Support;

use App\Services\KpiReportConverter;
use Illuminate\Container\Container;

/**
 * Normalizer terpadu untuk skala dan batas skor KPI PLN.
 *
 * Mengonsolidasikan Keputusan Bisnis 2 Pak Gama (2026-10-25) dan Sesi 99:
 * 1. Skor workbook tersimpan dalam dua format: rasio (<= 1.10) dan persen (> 1.10).
 * 2. Ambang batas rasio maksimum adalah 1.10 (110.0%).
 * 3. Nilai skor agregat unit (R6/R9) di-clamp pada [0..110%].
 * 4. Pasangan numerik R7 tetap rasio murni tanpa clamp untuk visibilitas deviasi operasional.
 */
class KpiScoreNormalizer
{
    public const RATIO_MAX = 1.10;

    public const SCORE_MIN = 0.0;

    public const SCORE_MAX = 110.0;

    public const TIER_TERCAPAI = 'tercapai';

    public const TIER_HAMPIR = 'hampir';

    public const TIER_PERLU = 'perlu';

    public const DEFAULT_TERCAPAI_MIN = 100.0;

    public const DEFAULT_HAMPIR_MIN = 95.0;

    /**
     * Memeriksa apakah nilai mentah merupakan skala rasio (<= 1.10).
     */
    public static function isRatio(float $score): bool
    {
        return $score <= self::RATIO_MAX;
    }

    /**
     * Normalisasi nilai mentah pencapaian dari tabel observasi ke skala persen (0..N).
     */
    public static function normalize(float $score): float
    {
        return self::isRatio($score) ? $score * 100.0 : $score;
    }

    /**
     * Batasi skor dalam batas resmi NKO [0..110%].
     */
    public static function clamp(float $score, float $min = self::SCORE_MIN, float $max = self::SCORE_MAX): float
    {
        return max($min, min($max, $score));
    }

    /**
     * Normalisasi nilai mentah ke persen dan terapkan batas resmi NKO [0..110%].
     */
    public static function normalizeAndClamp(float $score, float $min = self::SCORE_MIN, float $max = self::SCORE_MAX): float
    {
        return self::clamp(self::normalize($score), $min, $max);
    }

    /**
     * Evaluasi skala dan persentase untuk panel Target vs Realisasi.
     *
     * @return array{raw: float, scale: string, percent: float|null}
     */
    public static function scoreScale(float $raw, ?string $formulaKind = null): array
    {
        $ratioKinds = [
            KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
            KpiReportConverter::PENCAPAIAN_FORMULA_AVERAGE,
            KpiReportConverter::PENCAPAIAN_FORMULA_DATE_DELTA,
        ];

        if (in_array($formulaKind, $ratioKinds, true)) {
            return ['raw' => $raw, 'scale' => 'ratio', 'percent' => $raw * 100.0];
        }

        if ($raw > self::RATIO_MAX) {
            return ['raw' => $raw, 'scale' => 'percent', 'percent' => $raw];
        }

        // Skor <= 1.10 tanpa metadata formula (seperti indikator #403 & #413 pada file lampau):
        // Selaras dengan keputusan 2026-10-25 di mana seluruh observasi Excel dibaca sebagai rasio.
        return ['raw' => $raw, 'scale' => 'ratio', 'percent' => $raw * 100.0];
    }

    /**
     * Ambil konfigurasi ambang batas RAG dengan fallback aman ke standar 100/95.
     *
     * @return array{tercapai_min: float, hampir_min: float}
     */
    public static function ragThresholds(): array
    {
        $container = Container::getInstance();
        $configured = ($container !== null && $container->bound('config'))
            ? config('kpi.rag_thresholds')
            : null;
        if (! is_array($configured)) {
            return [
                'tercapai_min' => self::DEFAULT_TERCAPAI_MIN,
                'hampir_min' => self::DEFAULT_HAMPIR_MIN,
            ];
        }

        $tercapaiMin = isset($configured['tercapai_min']) && is_numeric($configured['tercapai_min'])
            ? (float) $configured['tercapai_min']
            : self::DEFAULT_TERCAPAI_MIN;

        $hampirMin = isset($configured['hampir_min']) && is_numeric($configured['hampir_min'])
            ? (float) $configured['hampir_min']
            : self::DEFAULT_HAMPIR_MIN;

        return [
            'tercapai_min' => $tercapaiMin,
            'hampir_min' => $hampirMin,
        ];
    }

    /**
     * Tentukan kategori status RAG dari nilai persen pencapaian.
     */
    public static function ragTier(float $percentScore): string
    {
        $thresholds = self::ragThresholds();
        $roundedScore = round($percentScore, 1);

        if ($roundedScore >= $thresholds['tercapai_min']) {
            return self::TIER_TERCAPAI;
        }

        if ($roundedScore >= $thresholds['hampir_min']) {
            return self::TIER_HAMPIR;
        }

        return self::TIER_PERLU;
    }
}
