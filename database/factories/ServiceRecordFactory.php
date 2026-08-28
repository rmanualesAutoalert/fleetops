<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\ServiceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceRecord> */
class ServiceRecordFactory extends Factory
{
    /** @var list<Appointment> */
    private static array $appointments = [];

    private static int $appointmentIndex = 0;

    private static int $lastAppointmentId = 0;

    public function definition(): array
    {
        $appointment = $this->nextAppointment();

        return [
            'appointment_id' => $appointment->id,
            'branch_id' => $appointment->branch_id,
            'advisor_id' => $appointment->advisor_id,
            'vehicle_id' => $appointment->vehicle_id,
            'service_type' => fake()->randomElement(['Oil Change', 'Preventive Maintenance', 'Brake Service', 'Engine Repair', 'Tire Service']),
            'cost' => fake()->randomFloat(2, 500, 999999.99),
            'completed_at' => fake()->dateTimeBetween('-2 years', 'now'),
            'notes' => fake()->optional()->paragraph(),
        ];
    }

    private function nextAppointment(): Appointment
    {
        if (self::$appointmentIndex >= count(self::$appointments)) {
            self::$appointments = Appointment::query()
                ->where('id', '>', self::$lastAppointmentId)
                ->orderBy('id')
                ->limit(1000)
                ->get(['id', 'branch_id', 'advisor_id', 'vehicle_id'])
                ->all();
            self::$appointmentIndex = 0;

            if (self::$appointments === []) {
                self::$lastAppointmentId = 0;

                return $this->nextAppointment();
            }

            self::$lastAppointmentId = (int) end(self::$appointments)->id;
        }

        return self::$appointments[self::$appointmentIndex++];
    }
}
