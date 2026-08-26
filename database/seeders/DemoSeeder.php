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

        // Главный судья: видит то же, что секретарь; подтверждает/исправляет/возвращает оценки.
        User::query()->updateOrCreate(
            ['email' => 'chief@local.test'],
            ['name' => 'Chief Judge', 'password' => Hash::make('password'), 'role' => 'chief_judge'],
        );

        User::query()->updateOrCreate(
            ['email' => 'scoreboard@local.test'],
            ['name' => 'Scoreboard Judge', 'password' => Hash::make('password'), 'role' => 'scoreboard_judge'],
        );

        // Универсальный «judge_a» — используется во многих местах как «общий судья».
        User::query()->updateOrCreate(
            ['email' => 'judge@local.test'],
            ['name' => 'Judge A1', 'password' => Hash::make('password'), 'role' => 'judge_a', 'slot' => 'A1'],
        );

        // Полная бригада 12 + доп. судьи (LINE×2, TIME, RESP).
        // Логины: db1@..., db2@..., da1@..., da2@..., a1..a4@..., e1..e4@..., line1/2@..., time@..., resp@...
        $brigade = [
            ['email' => 'db1@local.test',   'name' => 'Judge DB1', 'role' => 'judge_d_db', 'slot' => 'DB1'],
            ['email' => 'db2@local.test',   'name' => 'Judge DB2', 'role' => 'judge_d_db', 'slot' => 'DB2'],
            ['email' => 'da1@local.test',   'name' => 'Judge DA1', 'role' => 'judge_d_da', 'slot' => 'DA1'],
            ['email' => 'da2@local.test',   'name' => 'Judge DA2', 'role' => 'judge_d_da', 'slot' => 'DA2'],
            ['email' => 'db-average@local.test', 'name' => 'Средняя DB', 'role' => 'judge_db_average', 'slot' => 'DB_AVG'],
            ['email' => 'da-average@local.test', 'name' => 'Средняя DA', 'role' => 'judge_da_average', 'slot' => 'DA_AVG'],
            ['email' => 'a1@local.test',    'name' => 'Judge A1',  'role' => 'judge_a',    'slot' => 'A1'],
            ['email' => 'a2@local.test',    'name' => 'Judge A2',  'role' => 'judge_a',    'slot' => 'A2'],
            ['email' => 'a3@local.test',    'name' => 'Judge A3',  'role' => 'judge_a',    'slot' => 'A3'],
            ['email' => 'a4@local.test',    'name' => 'Judge A4',  'role' => 'judge_a',    'slot' => 'A4'],
            ['email' => 'e1@local.test',    'name' => 'Judge E1',  'role' => 'judge_e',    'slot' => 'E1'],
            ['email' => 'e2@local.test',    'name' => 'Judge E2',  'role' => 'judge_e',    'slot' => 'E2'],
            ['email' => 'e3@local.test',    'name' => 'Judge E3',  'role' => 'judge_e',    'slot' => 'E3'],
            ['email' => 'e4@local.test',    'name' => 'Judge E4',  'role' => 'judge_e',    'slot' => 'E4'],
            ['email' => 'line1@local.test', 'name' => 'Line Judge 1', 'role' => 'line_judge', 'slot' => 'LINE1'],
            ['email' => 'line2@local.test', 'name' => 'Line Judge 2', 'role' => 'line_judge', 'slot' => 'LINE2'],
            ['email' => 'time@local.test',  'name' => 'Time Judge',   'role' => 'time_judge', 'slot' => 'TIME'],
            ['email' => 'resp@local.test',  'name' => 'Resp Judge',   'role' => 'head_judge', 'slot' => 'RESP'],
        ];

        foreach ($brigade as $row) {
            User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'password' => Hash::make('password'),
                    'role' => $row['role'],
                    'slot' => $row['slot'],
                ],
            );
        }

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
