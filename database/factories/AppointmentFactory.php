<?php

namespace Database\Factories;

use App\Models\Advisor;
use App\Models\Appointment;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    /** @var list<Advisor>|null */
    private static ?array $advisors = null;

    /** @var list<Vehicle>|null */
    private static ?array $vehicles = null;

    public function definition(): array
    {
        $advisor = fake()->randomElement(
            self::$advisors ??= Advisor::query()->get(['id', 'branch_id'])->all(),
        );
        $vehicle = fake()->randomElement(
            self::$vehicles ??= Vehicle::query()->get(['id', 'customer_id'])->all(),
        );

        return [
            'branch_id' => $advisor->branch_id,
            'advisor_id' => $advisor->id,
            'customer_id' => $vehicle->customer_id,
            'vehicle_id' => $vehicle->id,
            'status' => fake()->randomElement(['booked', 'checked_in', 'in_service', 'completed', 'cancelled']),
            'bay' => fake()->optional()->bothify('Bay ##'),
            'scheduled_at' => fake()->dateTimeBetween('-1 year', '+1 month'),
        ];
    }
}
