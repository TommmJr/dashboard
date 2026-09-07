<?php

namespace Database\Factories;

use App\Models\PerformanceMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerformanceMetric>
 */
class PerformanceMetricFactory extends Factory
{
    protected $model = PerformanceMetric::class;

    public function definition(): array
    {
        return [
            'metric_name' => 'Metrik '.fake()->word(),
            'value' => fake()->randomFloat(2, 1, 10000),
            'period' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'unit_id' => null,
            'division_id' => null,
            'dataset_id' => null,
        ];
    }
}
