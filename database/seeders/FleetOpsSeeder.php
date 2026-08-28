<?php

namespace Database\Seeders;

use App\Models\Advisor;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ServiceRecord;
use App\Models\Vehicle;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FleetOpsSeeder extends Seeder
{
    use WithoutModelEvents;

    private const LARGE_BATCH_SIZE = 1000;

    public function run(): void
    {
        DB::connection()->disableQueryLog();

        Branch::factory(5)->create();
        Advisor::factory(40)->create();
        Customer::factory(2000)->create();
        Vehicle::factory(3000)->create();

        $this->createInChunks(Appointment::factory(), 100000);
        $this->createInChunks(ServiceRecord::factory(), 150000);
    }

    /**
     * @param  Factory<Model>  $factory
     */
    private function createInChunks(Factory $factory, int $total): void
    {
        for ($created = 0; $created < $total; $created += self::LARGE_BATCH_SIZE) {
            $factory
                ->count(min(self::LARGE_BATCH_SIZE, $total - $created))
                ->create();
        }
    }
}
