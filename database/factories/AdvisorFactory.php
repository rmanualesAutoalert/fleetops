<?php

namespace Database\Factories;

use App\Models\Advisor;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Advisor> */
class AdvisorFactory extends Factory
{
    /** @var list<int>|null */
    private static ?array $branchIds = null;

    public function definition(): array
    {
        return [
            'branch_id' => fake()->randomElement(self::$branchIds ??= Branch::query()->pluck('id')->all()),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
        ];
    }
}
