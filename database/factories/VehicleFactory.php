<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    /** @var list<int>|null */
    private static ?array $customerIds = null;

    public function definition(): array
    {
        return [
            'customer_id' => fake()->randomElement(self::$customerIds ??= Customer::query()->pluck('id')->all()),
            'plate_number' => strtoupper(fake()->unique()->bothify('???-####')),
            'make' => fake()->randomElement(['Ford', 'Honda', 'Hyundai', 'Mitsubishi', 'Nissan', 'Toyota']),
            'model' => fake()->randomElement(['City', 'Everest', 'Fortuner', 'Mirage', 'Navara', 'Tucson']),
            'year' => fake()->numberBetween(2000, (int) date('Y')),
        ];
    }
}
