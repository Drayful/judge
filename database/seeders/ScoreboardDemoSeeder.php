<?php

namespace Database\Seeders;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\JudgeScore;
use App\Models\Performance;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Seeder;

class ScoreboardDemoSeeder extends Seeder
{
    /**
     * Демо-данные для теста табло: опубликованные результаты + гимнастка «на ковре».
     */
    public function run(): void
    {
        $tournament = Tournament::query()->firstOrCreate(
            ['name' => 'Демо · Табло'],
            [
                'starts_on' => now()->toDateString(),
                'timezone' => 'Asia/Almaty',
                'is_published' => true,
            ],
        );
        $tournament->update(['is_published' => true]);

        $category = Category::query()->updateOrCreate(
            [
                'tournament_id' => $tournament->id,
                'name' => '2015 г.р., B · Мяч — демо поток',
            ],
            [
                'program' => 'individual',
                'apparatus' => 'ball',
                'birth_year' => 2015,
                'division' => 'B',
                'age_min' => 13,
                'age_max' => 15,
                'is_published' => true,
            ],
        );

        $tournament->update(['active_category_id' => $category->id]);

        $athletes = [
            ['Алина', 'Смирнова', 'СК «Алматы»'],
            ['Милана', 'Касымова', 'СК «Астана»'],
            ['Арина', 'Нурланова', 'ДЮСШ №7'],
            ['София', 'Жумабаева', 'Галактика'],
            ['Диана', 'Омарова', 'Орлеанок'],
            ['Виктория', 'Ермекова', 'СК «Жулдыз»'],
            ['Айару', 'Елухан', 'ШХГ «Fire Star»'],
        ];

        $publishedScores = [
            [7.800, 8.900, 8.700, 0.000, 25.400],
            [7.500, 8.600, 8.500, 0.100, 24.500],
            [7.200, 8.400, 8.300, 0.000, 23.900],
            [6.900, 8.200, 8.100, 0.200, 23.000],
            [6.700, 8.000, 7.900, 0.000, 22.600],
            [6.400, 7.800, 7.700, 0.300, 21.600],
        ];

        $performances = [];

        foreach ($athletes as $i => [$first, $last, $club]) {
            $athlete = Athlete::query()->firstOrCreate(
                ['first_name' => $first, 'last_name' => $last, 'club' => $club],
                ['birthdate' => '2015-06-15'],
            );

            $performances[] = Performance::query()->updateOrCreate(
                [
                    'category_id' => $category->id,
                    'athlete_id' => $athlete->id,
                    'order_index' => $i + 1,
                ],
                [
                    'start_number' => $i + 1,
                    'apparatus' => 'ball',
                    'status' => 'scheduled',
                    'is_counted' => true,
                ],
            );
        }

        $now = now();

        foreach (array_slice($performances, 0, 6) as $i => $perf) {
            [$d, $a, $e, $pen, $total] = $publishedScores[$i];
            $perf->update([
                'status' => 'published',
                'd_score' => $d,
                'a_score' => $a,
                'e_score' => $e,
                'penalty' => $pen,
                'total' => $total,
                'finalized_at' => $now,
                'approved_at' => $now,
                'published_at' => $now,
            ]);
        }

        // Гимнастка «на ковре» — для индивидуального табло (часть оценок уже есть).
        $live = $performances[6];
        $live->update([
            'status' => 'performing',
            'started_at' => $now,
            'd_score' => null,
            'a_score' => null,
            'e_score' => null,
            'penalty' => null,
            'total' => null,
            'finalized_at' => null,
            'approved_at' => null,
            'published_at' => null,
        ]);

        JudgeScore::query()->where('performance_id', $live->id)->delete();

        $judges = [
            ['DB1', 'd', 'db', null, 3.900],
            ['DB2', 'd', 'db', null, 4.100],
            ['DA1', 'd', 'da', null, 1.800],
            ['DA2', 'd', 'da', null, 2.000],
            ['A1', 'a', null, null, 0.800],
            ['A2', 'a', null, null, 0.900],
        ];

        foreach ($judges as [$slot, $panel, $subpanel, $penaltyType, $score]) {
            $user = User::query()->where('slot', $slot)->first();
            if (! $user) {
                continue;
            }

            JudgeScore::query()->updateOrCreate(
                [
                    'performance_id' => $live->id,
                    'judge_id' => $user->id,
                    'panel' => $panel,
                    'subpanel' => $subpanel,
                    'penalty_type' => $penaltyType,
                ],
                [
                    'score' => $score,
                    'submitted_at' => $now,
                ],
            );
        }

        $live->refresh();
        $live->recalculateTotals();
        $live->save();

        // Дополнительно: опубликовать один реальный поток из импорта, если есть.
        $imported = Category::query()
            ->where('tournament_id', 1)
            ->where('name', 'like', '%2018 г.р., B, 1 вид%')
            ->first();

        if ($imported) {
            $imported->update(['is_published' => true]);
            Tournament::query()->whereKey(1)->update(['is_published' => true]);

            $rows = Performance::query()
                ->with('athlete')
                ->where('category_id', $imported->id)
                ->orderBy('order_index')
                ->limit(6)
                ->get();

            $demoTotals = [
                [7.600, 8.700, 8.600, 0.000, 24.900],
                [7.300, 8.500, 8.400, 0.100, 24.100],
                [7.000, 8.300, 8.200, 0.000, 23.500],
                [6.800, 8.100, 8.000, 0.150, 22.750],
                [6.500, 7.900, 7.800, 0.000, 22.200],
                [6.200, 7.700, 7.600, 0.250, 21.250],
            ];

            foreach ($rows as $i => $perf) {
                if (! isset($demoTotals[$i])) {
                    break;
                }
                [$d, $a, $e, $pen, $total] = $demoTotals[$i];
                $perf->update([
                    'status' => 'published',
                    'd_score' => $d,
                    'a_score' => $a,
                    'e_score' => $e,
                    'penalty' => $pen,
                    'total' => $total,
                    'finalized_at' => $now,
                    'approved_at' => $now,
                    'published_at' => $now,
                    'is_counted' => true,
                ]);
            }
        }

        $this->command?->info('Табло: турнир «'.$tournament->name.'», поток #'.$category->id);
        $this->command?->info('Результаты: '.route('scoreboard.table', $category));
        $this->command?->info('На ковре: '.route('scoreboard.performance', $category));
        if ($imported) {
            $this->command?->info('Импорт поток: '.route('scoreboard.table', $imported));
        }
    }
}
