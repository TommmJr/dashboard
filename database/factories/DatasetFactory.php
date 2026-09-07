<?php

namespace Database\Factories;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Dataset>
 */
class DatasetFactory extends Factory
{
    protected $model = Dataset::class;

    public function definition(): array
    {
        $originalName = 'laporan-'.Str::random(6).'.csv';

        return [
            'division_id' => Division::factory(),
            'user_id' => User::factory(),
            'filename' => Str::random(24).'.csv',
            'original_name' => $originalName,
            'mime_type' => 'text/csv',
            'size' => fake()->numberBetween(1024, 2_000_000),
            'status' => Dataset::STATUS_READY,
            'error_message' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => Dataset::STATUS_ARCHIVED]);
    }
}
