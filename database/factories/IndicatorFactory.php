<?php

namespace Database\Factories;

use App\Models\Indicator;
use App\Models\OrgUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Indicator>
 */
class IndicatorFactory extends Factory
{
    protected $model = Indicator::class;

    public function definition(): array
    {
        return [
            'org_unit_id' => OrgUnit::factory(),
            'name' => 'Indikator '.fake()->unique()->word(),
            'parent_id' => null,
            'aggregation_code' => null,
            'polarity' => null,
            'formula' => null,
            'satuan' => null,
            'satuan_normalized' => null,
            'bobot' => null,
            'bobot_value' => null,
            'bobot_min' => null,
            'bobot_max' => null,
            'category' => null,
            'is_group' => false,
            'pencapaian_formula_kind' => null,
        ];
    }

    /**
     * Baris grup/induk: pemilik bobot, punya anak.
     */
    public function group(): static
    {
        return $this->state(fn () => ['is_group' => true]);
    }

    /**
     * Polarity higher_is_better. Evidence source: kolom C = 3.
     */
    public function higherIsBetter(): static
    {
        return $this->state(fn () => ['polarity' => 'higher_is_better', 'aggregation_code' => '3']);
    }

    /**
     * Polarity lower_is_better. Evidence source: kolom C = 1.
     */
    public function lowerIsBetter(): static
    {
        return $this->state(fn () => ['polarity' => 'lower_is_better', 'aggregation_code' => '1']);
    }

    /**
     * Bobot numeric (sebagian besar baris induk).
     */
    public function bobotNumeric(float $value = 20.0): static
    {
        return $this->state(fn () => ['bobot' => (string) $value, 'bobot_value' => $value]);
    }

    /**
     * Bobot band/teks: dipakai apa adanya sebagai string, value/min/max NULL
     * karena sumbernya tidak bisa diturunkan dengan aman.
     */
    public function bobotBand(string $raw = 'Max -8 Max +1,5'): static
    {
        return $this->state(fn () => [
            'bobot' => $raw,
            'bobot_value' => null,
            'bobot_min' => null,
            'bobot_max' => null,
        ]);
    }
}
