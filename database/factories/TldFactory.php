<?php

namespace Database\Factories;

use App\Models\Tld;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tld>
 */
class TldFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tld' => $this->faker->unique()->lexify('???'),
            'enabled' => true,
            'featured' => false,
            'supports_transfer' => true,
            'min_years' => 1,
            'max_years' => 10,
            'sort' => 0,
        ];
    }
}
