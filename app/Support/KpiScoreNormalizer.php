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

    /**
     * Format angka KPI ke tampilan Indonesia (desimal koma, ribuan titik)
     * dengan nol ekor dan koma ujung dipangkas.
     */
    public static function formatNumber(?float $value, int $decimals = 1, string $suffix = '', string $nullLabel = 'Tidak tercatat'): string
    {
        if ($value === null) {
            return $nullLabel;
        }

        $formatted = number_format($value, $decimals, ',', '.');
        $trimmed = str_contains($formatted, ',')
            ? rtrim(rtrim($formatted, '0'), ',')
            : $formatted;

        if ($trimmed === '-0') {
            $trimmed = '0';
        }

        return $trimmed.$suffix;
    }

    /**
     * Normalisasi label satuan agar rapi untuk pembaca non-analis.
     */
    public static function normalizeUnit(?string $satuan): ?string
    {
        $clean = preg_replace('/\s+/', ' ', trim((string) $satuan));
        if ($clean === '' || mb_strtolower($clean) === 'tidak tercatat') {
            return null;
        }

        $lower = mb_strtolower($clean);
        if (in_array($lower, ['rp. milyar', 'rp milyar', 'rp. miliar', 'rp miliar'], true)) {
            return 'Rp Miliar';
        }
        if (in_array($lower, ['rp. juta', 'rp juta'], true)) {
            return 'Rp Juta';
        }

        return preg_replace('/\bRp\.\s*/i', 'Rp ', $clean);
    }

    /**
     * Rapikan teks sumber dari Excel dan buang placeholder kosong/strip tunggal.
     */
    public static function normalizeSourceText(?string $text): ?string
    {
        $clean = preg_replace('/\s+/', ' ', trim((string) $text));
        if ($clean === '' || in_array($clean, ['-', '--', '—', '–'], true)) {
            return null;
        }

        return $clean;
    }

    /**
     * Bangun metadata visual perbandingan Target vs Realisasi dan teks bantu kecil
     * dalam satuan asli agar mudah dipahami tanpa rasio persen >100% yang membingungkan.
     *
     * @param  array<string, mixed>  $ctx
     * @return array{
     *     unit_label: string|null,
     *     is_money: bool,
     *     is_lower_better: bool,
     *     indicator_hint: string|null,
     *     target_hint: string|null,
     *     realisasi_hint: string|null,
     *     target_override: string|null,
     *     realisasi_override: string|null,
     *     target_bar_pct: float|null,
     *     realisasi_bar_pct: float|null,
     *     diff_label: string
     * }
     */
    public static function describeComparison(array $ctx): array
    {
        $unit = self::normalizeUnit($ctx['satuan'] ?? null);
        $polarity = $ctx['polarity'] ?? null;
        $isLowerBetter = $polarity === KpiReportConverter::POLARITY_LOWER_IS_BETTER;
        $targetType = (string) ($ctx['target_type'] ?? 'numeric');
        $realisasiType = (string) ($ctx['realisasi_type'] ?? 'numeric');
        $isDateRow = $targetType === 'date' || $realisasiType === 'date';

        $target = isset($ctx['target']) && is_numeric($ctx['target']) ? (float) $ctx['target'] : null;
        $realisasi = isset($ctx['realisasi']) && is_numeric($ctx['realisasi']) ? (float) $ctx['realisasi'] : null;
        $targetText = self::normalizeSourceText($ctx['target_text'] ?? null);
        $realisasiText = self::normalizeSourceText($ctx['realisasi_text'] ?? null);
        $skorKpi = isset($ctx['skor_kpi']) && is_numeric($ctx['skor_kpi']) ? (float) $ctx['skor_kpi'] : null;
        $tier = (string) ($ctx['tier'] ?? 'unverified');

        $isMoney = $unit !== null && str_contains(mb_strtolower($unit), 'rp');
        $isTime = $unit !== null && (mb_strtolower($unit) === 'jam' || str_contains(mb_strtolower($unit), 'waktu'));
        $unitSuffix = $unit === '%' ? '%' : ($unit !== null ? ' '.$unit : '');

        $deltaDays = isset($ctx['delta_days']) && is_numeric($ctx['delta_days'])
            ? (int) $ctx['delta_days']
            : self::computeDeltaDays($ctx['target_date'] ?? null, $ctx['realisasi_date'] ?? null);

        $indicatorHint = self::buildIndicatorHint($unit, $isMoney, $isTime, $isLowerBetter, $isDateRow, $targetText, $realisasiText);
        [$targetOverride, $targetHint, $parsedTargetFromText] = self::parseTextMeta($targetText, $unit, true);
        [$realisasiOverride, $realisasiHint, $parsedRealFromText] = self::parseTextMeta($realisasiText, $unit, false);

        if ($target !== null || $targetType === 'date') {
            $targetOverride = null;
        }
        if ($realisasi !== null || $realisasiType === 'date') {
            $realisasiOverride = null;
        }

        if ($targetType === 'date') {
            $targetHint = 'Batas tanggal';
        } elseif ($targetOverride === null && ! $isDateRow) {
            $targetHint = $unit;
        }

        if ($realisasiType === 'date') {
            $realisasiHint = 'Tanggal tercapai';
        } elseif ($realisasiOverride === null && ! $isDateRow) {
            $realisasiHint = $unit;
        }

        $numTarget = $target ?? $parsedTargetFromText;
        $numRealisasi = $realisasi ?? $parsedRealFromText;

        $targetBarPct = null;
        $realisasiBarPct = null;
        $diffLabel = 'Belum ada perbandingan';

        if ($isDateRow) {
            [$targetBarPct, $realisasiBarPct, $diffLabel] = self::describeDateComparison($deltaDays, $tier);
        } elseif ($numTarget !== null && $numRealisasi !== null && $numTarget >= 0 && $numRealisasi >= 0) {
            [$targetBarPct, $realisasiBarPct, $diffLabel] = self::describeNumericComparison(
                $numTarget,
                $numRealisasi,
                $isLowerBetter,
                $isMoney,
                $isTime,
                $unitSuffix,
            );
        } elseif ($skorKpi !== null) {
            $targetBarPct = 90.9;
            $realisasiBarPct = round(max(4.0, min(100.0, ($skorKpi / self::SCORE_MAX) * 100.0)), 1);
            if ($targetOverride !== null || $realisasiOverride !== null) {
                $diffLabel = $tier === self::TIER_TERCAPAI ? 'Dalam rentang target' : 'Di luar rentang target';
            } else {
                $diffLabel = match ($tier) {
                    self::TIER_TERCAPAI => 'Memenuhi target',
                    self::TIER_HAMPIR => 'Mendekati target',
                    self::TIER_PERLU => 'Di bawah target',
                    default => 'Belum terverifikasi',
                };
            }
        }

        return [
            'unit_label' => $unit,
            'is_money' => $isMoney,
            'is_lower_better' => $isLowerBetter,
            'indicator_hint' => $indicatorHint,
            'target_hint' => $targetHint,
            'realisasi_hint' => $realisasiHint,
            'target_override' => $targetOverride,
            'realisasi_override' => $realisasiOverride,
            'target_bar_pct' => $targetBarPct,
            'realisasi_bar_pct' => $realisasiBarPct,
            'diff_label' => $diffLabel,
        ];
    }

    private static function buildIndicatorHint(
        ?string $unit,
        bool $isMoney,
        bool $isTime,
        bool $isLowerBetter,
        bool $isDateRow,
        ?string $targetText,
        ?string $realisasiText,
    ): ?string {
        if ($isDateRow) {
            return 'Data tanggal • Lebih cepat makin baik';
        }
        if ($isMoney) {
            return $isLowerBetter
                ? 'Nominal '.$unit.' • Makin kecil makin baik'
                : 'Nominal '.$unit;
        }
        if ($isLowerBetter) {
            $direction = $isTime ? 'Makin cepat makin baik' : 'Makin kecil makin baik';

            return $unit !== null ? $unit.' • '.$direction : $direction;
        }
        if ($targetText !== null || $realisasiText !== null) {
            return $unit !== null ? $unit.' • Sumber teks' : 'Sumber teks';
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: float|null}
     */
    private static function parseTextMeta(?string $text, ?string $unit, bool $isTarget): array
    {
        if ($text === null) {
            return [null, null, null];
        }

        if (preg_match('/Sesuai\s+Target\s*:\s*([0-9]+(?:[.,][0-9]+)?)(?:.*?(?:Diluar|Di\s+luar)\s+Target\s*:\s*([0-9]+(?:[.,][0-9]+)?))?/i', $text, $m)) {
            $prefix = $unit !== null ? $unit.' • ' : '';
            $hint = isset($m[2])
                ? $prefix.'Sumber teks (+'.$m[2].' di luar target)'
                : $prefix.'Sumber teks';
            $numVal = is_numeric($m[1]) ? (float) $m[1] : null;

            return ['Sesuai target: '.$m[1], $hint, $numVal];
        }

        $hint = $isTarget ? 'Target rentang (teks)' : 'Sumber teks';

        return [$text, $hint, null];
    }

    private static function computeDeltaDays(?string $targetDate, ?string $realisasiDate): ?int
    {
        $targetTs = self::parseDateTimestamp($targetDate);
        $realTs = self::parseDateTimestamp($realisasiDate);

        if ($targetTs === null || $realTs === null) {
            return null;
        }

        return (int) round(($realTs - $targetTs) / 86400);
    }

    private static function parseDateTimestamp(?string $date): ?int
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        $clean = substr(trim($date), 0, 10);
        foreach (['!Y-m-d', '!d/m/Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $clean);
            if ($parsed !== false) {
                return $parsed->getTimestamp();
            }
        }

        return null;
    }

    /**
     * @return array{0: float, 1: float, 2: string}
     */
    private static function describeDateComparison(?int $deltaDays, string $tier): array
    {
        if ($deltaDays === null) {
            $label = $tier === self::TIER_TERCAPAI ? 'Memenuhi batas tanggal' : 'Tanggal tercatat';

            return [100.0, 100.0, $label];
        }

        if ($deltaDays < 0) {
            return [100.0, 76.0, 'Lebih cepat '.abs($deltaDays).' hari'];
        }

        if ($deltaDays === 0) {
            return [100.0, 100.0, 'Tepat waktu'];
        }

        return [76.0, 100.0, 'Lebih lambat '.$deltaDays.' hari'];
    }

    /**
     * @return array{0: float, 1: float, 2: string}
     */
    private static function describeNumericComparison(
        float $target,
        float $realisasi,
        bool $isLowerBetter,
        bool $isMoney,
        bool $isTime,
        string $unitSuffix,
    ): array {
        $max = max($target, $realisasi);
        $targetBarPct = $max > 0 ? round(max(6.0, ($target / $max) * 100.0), 1) : 100.0;
        $realisasiBarPct = $max > 0
            ? ($realisasi > 0 ? round(max(4.0, ($realisasi / $max) * 100.0), 1) : 0.0)
            : 100.0;

        // Hitung selisih dari nilai bulat 1 desimal agar selalu konsisten dengan angka yang tampil.
        $diff = round(round($realisasi, 1) - round($target, 1), 1);

        if (abs($diff) < 0.05) {
            return [$targetBarPct, $realisasiBarPct, $isLowerBetter ? 'Sesuai batas target' : 'Sesuai target'];
        }

        if ($isLowerBetter) {
            if ($diff < 0) {
                $prefix = $isMoney ? 'Hemat ' : ($isTime ? 'Lebih cepat ' : 'Lebih sedikit ');

                return [$targetBarPct, $realisasiBarPct, $prefix.self::formatNumber(abs($diff), 1, $unitSuffix)];
            }

            $prefix = $isTime ? 'Lebih lambat +' : 'Melebihi batas +';

            return [$targetBarPct, $realisasiBarPct, $prefix.self::formatNumber($diff, 1, $unitSuffix)];
        }

        $sign = $diff > 0 ? '+' : '-';

        return [$targetBarPct, $realisasiBarPct, $sign.self::formatNumber(abs($diff), 1, $unitSuffix)];
    }
}
