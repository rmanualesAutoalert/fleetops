<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Advisor;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        DB::transaction(function (): void {
            $user = User::firstOrNew(['email' => 'advisor@fleetops.test']);

            if ($user->exists && $user->role !== UserRole::ServiceAdvisor) {
                throw new LogicException('The demo email belongs to a non-advisor user. Choose a different demo account.');
            }

            if ($user->exists && $user->advisor()->exists()) {
                return;
            }

            // Reuse a seeded advisor without replacing another login's assignment.
            $advisor = Advisor::query()->whereNull('user_id')->orderBy('id')->first();

            if ($advisor === null) {
                $branch = Branch::query()->orderBy('id')->first() ?? Branch::factory()->create();
                $advisor = new Advisor;
                $advisor->branch()->associate($branch);
                $advisor->name = 'FleetOps Demo Advisor';
                $advisor->email = 'advisor@fleetops.test';
                $advisor->phone = '0000000000';
            }

            // Preserve existing credentials when this seeder is run again.
            if (! $user->exists) {
                $user->name = $advisor->name;
                $user->password = 'password';
                $user->email_verified_at = now();
                $user->role = UserRole::ServiceAdvisor;
                $user->save();
            }

            $advisor->user()->associate($user);
            $advisor->save();
        });
    }
}
