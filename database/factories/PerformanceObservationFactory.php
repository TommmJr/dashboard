<?php

namespace Database\Factories;

use App\Models\Dataset;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerformanceObservation>
 */
class PerformanceObservationFactory extends Factory
{
    protected $model = PerformanceObservation::class;

    public function definition(): array
    {
        return [
            'dataset_id' => Dataset::factory(),
            'indicator_id' => Indicator::factory(),
            'org_unit_id' => OrgUnit::factory(),
            'division_id' => null,
            'period' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'target_value' => fake()->randomFloat(4, 1, 1000),
            'target_text' => null,
            'target_date' => null,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_value' => fake()->randomFloat(4, 1, 1000),
            'realisasi_text' => null,
            'realisasi_date' => null,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'pencapaian' => null,
            'nilai' => null,
            'ket' => null,
            'source_sheet' => null,
            'source_row' => null,
        ];
    }

    /** Target numerik + realisasi numerik. */
    public function numeric(): static
    {
        return $this->state(fn () => []);
    }

    /** Target berupa tanggal (serial Excel yang sudah dikonversi). */
    public function dateTarget(): static
    {
        return $this->state(fn () => [
            'target_value' => null,
            'target_text' => null,
            'target_date' => '2025-08-31',
            'target_type' => PerformanceObservation::TYPE_DATE,
        ]);
    }

    /** Target berupa teks bebas. */
    public function textTarget(string $text = 'Sesuai RKAP'): static
    {
        return $this->state(fn () => [
            'target_value' => null,
            'target_text' => $text,
            'target_date' => null,
            'target_type' => PerformanceObservation::TYPE_TEXT,
        ]);
    }

    /** Target kosong: sel sumber memang tidak diisi. */
    public function emptyTarget(): static
    {
        return $this->state(fn () => [
            'target_value' => null,
            'target_text' => null,
            'target_date' => null,
            'target_type' => PerformanceObservation::TYPE_EMPTY,
        ]);
    }
}
