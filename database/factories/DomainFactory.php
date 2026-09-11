<?php

namespace Database\Factories;

use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->lexify('??????');

        return [
            'name' => $name,
            'domain' => $name . '.com',
            'status' => Domain::STATUS_ACTIVE,
            'action' => Domain::ACTION_REGISTER,
            'years' => 1,
            'price' => 10,
            'currency_code' => 'USD',
            'auto_renew' => true,
            'privacy' => false,
            'registered_at' => now()->subYear(),
            'expires_at' => now()->addMonths(6),
        ];
    }
}
