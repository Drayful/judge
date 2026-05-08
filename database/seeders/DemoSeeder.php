<?php

namespace Database\Seeders;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\Performance;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@local.test'],
            ['name' => 'Admin', 'password' => Hash::make('password'), 'role' => 'admin'],
        );

        $secretary = User::query()->updateOrCreate(
            ['email' => 'sec@local.test'],
            ['name' => 'Secretary', 'password' => Hash::make('password'), 'role' => 'secretary'],
        );

        User::query()->updateOrCreate(
            ['email' => 'judge@local.test'],
            ['name' => 'Judge A', 'password' => Hash::make('password'), 'role' => 'judge_a'],
        );

        $athleteUser = User::query()->updateOrCreate(
            ['email' => 'athlete@local.test'],
            ['name' => 'Athlete', 'password' => Hash::make('password'), 'role' => 'athlete'],
        );

        $athlete = Athlete::query()->updateOrCreate(
            ['user_id' => $athleteUser->id],
            [
                'first_name' => 'Aigerim',
                'last_name' => 'Demo',
                'birthdate' => now()->subYears(14)->toDateString(),
                'club' => 'Demo Club',
                'coach' => 'Coach Demo',
            ],
        );

        $tournament = Tournament::query()->firstOrCreate(
            ['name' => 'Demo Tournament'],
            ['starts_on' => now()->toDateString(), 'timezone' => 'Asia/Almaty', 'is_published' => true],
        );

        $category = Category::query()->firstOrCreate(
            ['tournament_id' => $tournament->id, 'name' => 'Juniors – Hoop'],
            ['program' => 'individual', 'apparatus' => 'hoop', 'age_min' => 13, 'age_max' => 15, 'is_published' => true],
        );

        // Same athlete can appear multiple times in a start list with different apparatus/music.
        Performance::query()->firstOrCreate(
            ['category_id' => $category->id, 'athlete_id' => $athlete->id, 'order_index' => 1],
            ['start_number' => 1, 'status' => 'scheduled', 'apparatus' => 'ball'],
        );
        Performance::query()->firstOrCreate(
            ['category_id' => $category->id, 'athlete_id' => $athlete->id, 'order_index' => 4],
            ['start_number' => 4, 'status' => 'scheduled', 'apparatus' => 'clubs'],
        );
        Performance::query()->firstOrCreate(
            ['category_id' => $category->id, 'athlete_id' => $athlete->id, 'order_index' => 7],
            ['start_number' => 7, 'status' => 'scheduled', 'apparatus' => 'ribbon'],
        );
    }
}
