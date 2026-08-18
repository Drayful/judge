<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\Entry;
use App\Models\JudgeScore;
use App\Models\Performance;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoreboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_tournament_picker(): void
    {
        $tournament = Tournament::create([
            'name' => 'Public Cup',
            'is_published' => true,
        ]);
        $category = Category::create([
            'tournament_id' => $tournament->id,
            'name' => '2015 г.р., B',
            'is_published' => true,
        ]);

        $response = $this->get(route('scoreboard.index', ['category' => $category->id]));

        $response->assertOk();
        $response->assertSee('Выберите поток');
        $response->assertSee('Public Cup');
        $response->assertSee('Общая таблица результатов');
    }

    public function test_table_page_is_shareable(): void
    {
        $tournament = Tournament::create(['name' => 'Cup', 'is_published' => true]);
        $category = Category::create([
            'tournament_id' => $tournament->id,
            'name' => 'Stream',
            'is_published' => true,
        ]);

        $this->get(route('scoreboard.table', $category))
            ->assertOk()
            ->assertSee('Скопировать ссылку')
            ->assertSee('Stream');
    }

    public function test_category_redirects_to_table(): void
    {
        $tournament = Tournament::create(['name' => 'Cup', 'is_published' => true]);
        $category = Category::create([
            'tournament_id' => $tournament->id,
            'name' => 'Stream',
            'is_published' => true,
        ]);

        $this->get(route('scoreboard.category', $category))
            ->assertRedirect(route('scoreboard.table', $category));
    }

    public function test_category_results_requires_published(): void
    {
        $tournament = Tournament::create(['name' => 'Hidden', 'is_published' => false]);
        $category = Category::create([
            'tournament_id' => $tournament->id,
            'name' => 'Hidden stream',
            'is_published' => true,
        ]);

        $this->get(route('scoreboard.table', $category))->assertNotFound();
    }

    public function test_performance_live_returns_current_athlete(): void
    {
        $tournament = Tournament::create(['name' => 'Live Cup', 'is_published' => true]);
        $category = Category::create([
            'tournament_id' => $tournament->id,
            'name' => 'Stream 1',
            'is_published' => true,
        ]);
        $athlete = Athlete::create([
            'first_name' => 'Алина',
            'last_name' => 'Тестова',
            'club' => 'СК Тест',
        ]);
        Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $athlete->id,
            'start_number' => 5,
            'order_index' => 1,
            'status' => 'performing',
            'apparatus' => 'ball',
        ]);

        $response = $this->getJson(route('scoreboard.performance.live', $category));

        $response->assertOk();
        $response->assertJsonPath('phase', 'performing');
        $response->assertJsonPath('performance.athlete', 'Тестова Алина');
        $response->assertJsonPath('performance.start_number', 5);
    }

    public function test_performance_live_includes_place_when_total_set(): void
    {
        $tournament = Tournament::create(['name' => 'Cup', 'is_published' => true]);
        $category = Category::create([
            'tournament_id' => $tournament->id,
            'name' => 'S',
            'is_published' => true,
        ]);
        $a1 = Athlete::create(['first_name' => 'A', 'last_name' => 'One']);
        $a2 = Athlete::create(['first_name' => 'B', 'last_name' => 'Two']);
        Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $a1->id,
            'order_index' => 1,
            'status' => 'published',
            'total' => 30.0,
            'd_score' => 9, 'a_score' => 10.5, 'e_score' => 10.5,
            'published_at' => now(),
            'is_counted' => true,
        ]);
        $live = Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $a2->id,
            'order_index' => 2,
            'status' => 'performing',
            'is_counted' => true,
        ]);
        JudgeScore::create([
            'performance_id' => $live->id,
            'judge_id' => User::factory()->create(['slot' => 'DB1'])->id,
            'panel' => 'd',
            'subpanel' => 'db',
            'score' => 7.5,
            'submitted_at' => now(),
        ]);
        JudgeScore::create([
            'performance_id' => $live->id,
            'judge_id' => User::factory()->create(['slot' => 'DB2'])->id,
            'panel' => 'd',
            'subpanel' => 'db',
            'score' => 7.5,
            'submitted_at' => now(),
        ]);
        JudgeScore::create([
            'performance_id' => $live->id,
            'judge_id' => User::factory()->create(['slot' => 'DA1'])->id,
            'panel' => 'd',
            'subpanel' => 'da',
            'score' => 4.5,
            'submitted_at' => now(),
        ]);
        JudgeScore::create([
            'performance_id' => $live->id,
            'judge_id' => User::factory()->create(['slot' => 'DA2'])->id,
            'panel' => 'd',
            'subpanel' => 'da',
            'score' => 4.5,
            'submitted_at' => now(),
        ]);
        JudgeScore::create([
            'performance_id' => $live->id,
            'judge_id' => User::factory()->create(['slot' => 'A1'])->id,
            'panel' => 'a',
            'score' => 8.5,
            'submitted_at' => now(),
        ]);
        JudgeScore::create([
            'performance_id' => $live->id,
            'judge_id' => User::factory()->create(['slot' => 'A2'])->id,
            'panel' => 'a',
            'score' => 8.5,
            'submitted_at' => now(),
        ]);
        JudgeScore::create([
            'performance_id' => $live->id,
            'judge_id' => User::factory()->create(['slot' => 'A3'])->id,
            'panel' => 'a',
            'score' => 8.5,
            'submitted_at' => now(),
        ]);
        JudgeScore::create([
            'performance_id' => $live->id,
            'judge_id' => User::factory()->create(['slot' => 'A4'])->id,
            'panel' => 'a',
            'score' => 8.5,
            'submitted_at' => now(),
        ]);
        JudgeScore::create([
            'performance_id' => $live->id,
            'judge_id' => User::factory()->create(['slot' => 'E1'])->id,
            'panel' => 'e',
            'score' => 8.5,
            'submitted_at' => now(),
        ]);
        JudgeScore::create([
            'performance_id' => $live->id,
            'judge_id' => User::factory()->create(['slot' => 'E2'])->id,
            'panel' => 'e',
            'score' => 8.5,
            'submitted_at' => now(),
        ]);
        JudgeScore::create([
            'performance_id' => $live->id,
            'judge_id' => User::factory()->create(['slot' => 'E3'])->id,
            'panel' => 'e',
            'score' => 8.5,
            'submitted_at' => now(),
        ]);
        JudgeScore::create([
            'performance_id' => $live->id,
            'judge_id' => User::factory()->create(['slot' => 'E4'])->id,
            'panel' => 'e',
            'score' => 8.5,
            'submitted_at' => now(),
        ]);

        // На табло результат и место видны только после принятия результата.
        $live->update(['published_at' => now()]);

        $this->getJson(route('scoreboard.performance.live', $category))
            ->assertOk()
            ->assertJsonPath('performance.place', 2);
    }

    public function test_withdrawn_athlete_excluded_from_results_table(): void
    {
        $tournament = Tournament::create(['name' => 'Cup', 'is_published' => true]);
        $category = Category::create([
            'tournament_id' => $tournament->id, 'name' => '2015 г.р., A',
            'birth_year' => 2015, 'division' => 'A', 'is_published' => true,
        ]);
        $ok = Athlete::create(['first_name' => 'Оля', 'last_name' => 'Активная']);
        $out = Athlete::create(['first_name' => 'Вера', 'last_name' => 'Снятая']);

        Performance::create([
            'category_id' => $category->id, 'athlete_id' => $ok->id, 'order_index' => 1,
            'status' => 'published', 'total' => 20.0, 'published_at' => now(), 'is_counted' => true,
        ]);
        // снятая, хотя и с оценкой/публикацией — не должна попасть в табло
        Performance::create([
            'category_id' => $category->id, 'athlete_id' => $out->id, 'order_index' => 2,
            'status' => 'withdrawn', 'total' => 25.0, 'published_at' => now(), 'is_counted' => true,
            'withdrawn_at' => now(),
        ]);

        $response = $this->getJson(route('scoreboard.category.live', $category));
        $response->assertOk();
        $response->assertJsonCount(1, 'rows');
        $response->assertJsonPath('rows.0.athlete', 'Активная Оля');
    }

    public function test_withdrawn_not_counted_in_provisional_place_of(): void
    {
        $tournament = Tournament::create(['name' => 'Cup', 'is_published' => true]);
        $category = Category::create([
            'tournament_id' => $tournament->id, 'name' => 'S', 'is_published' => true,
        ]);
        $a = Athlete::create(['first_name' => 'A', 'last_name' => 'One']);
        $b = Athlete::create(['first_name' => 'B', 'last_name' => 'Two']);
        $c = Athlete::create(['first_name' => 'C', 'last_name' => 'Live']);

        Performance::create([
            'category_id' => $category->id, 'athlete_id' => $a->id, 'order_index' => 1,
            'status' => 'published', 'total' => 20.0, 'published_at' => now(), 'is_counted' => true,
        ]);
        Performance::create([ // снятая опубликованная — не должна учитываться в «из N»
            'category_id' => $category->id, 'athlete_id' => $b->id, 'order_index' => 2,
            'status' => 'withdrawn', 'total' => 22.0, 'published_at' => now(), 'is_counted' => true,
            'withdrawn_at' => now(),
        ]);
        Performance::create([
            'category_id' => $category->id, 'athlete_id' => $c->id, 'order_index' => 3,
            'status' => 'performing', 'is_counted' => true, 'published_at' => now(),
        ]);

        // place_of = опубликованные (без снятых) + 1 = 1 + 1 = 2
        $this->getJson(route('scoreboard.performance.live', $category))
            ->assertOk()
            ->assertJsonPath('performance.place_of', 2);
    }

    public function test_results_table_includes_vidi_breakdown(): void
    {
        $tournament = Tournament::create(['name' => 'Cup', 'is_published' => true]);
        $category = Category::create([
            'tournament_id' => $tournament->id, 'name' => '2015 г.р., A',
            'birth_year' => 2015, 'division' => 'A', 'is_published' => true,
        ]);
        $athlete = Athlete::create(['first_name' => 'Мила', 'last_name' => 'Многоборка']);

        foreach ([['БП', 10.0, 1], ['Мяч', 12.0, 2]] as [$app, $total, $order]) {
            Performance::create([
                'category_id' => $category->id, 'athlete_id' => $athlete->id, 'apparatus' => $app,
                'order_index' => $order, 'status' => 'published', 'total' => $total,
                'published_at' => now(), 'is_counted' => true,
            ]);
        }

        $response = $this->getJson(route('scoreboard.category.live', $category));
        $response->assertOk();
        $response->assertJsonPath('rows.0.total', 22);
        $response->assertJsonCount(2, 'rows.0.vidi');
        $response->assertJsonPath('rows.0.vidi.0', 10);
        $response->assertJsonPath('rows.0.vidi.1', 12);
    }

    public function test_provisional_place_spans_whole_group(): void
    {
        $tournament = Tournament::create(['name' => 'Cup', 'is_published' => true]);
        $stream1 = Category::create([
            'tournament_id' => $tournament->id, 'name' => '2015 A — поток 1',
            'birth_year' => 2015, 'division' => 'A', 'is_published' => true,
        ]);
        $stream2 = Category::create([
            'tournament_id' => $tournament->id, 'name' => '2015 A — поток 2',
            'birth_year' => 2015, 'division' => 'A', 'is_published' => true,
        ]);
        $leader = Athlete::create(['first_name' => 'Л', 'last_name' => 'Лидер']);
        $live = Athlete::create(['first_name' => 'Ж', 'last_name' => 'Живая']);

        // Лидер опубликован в ДРУГОМ потоке той же группы.
        Performance::create([
            'category_id' => $stream2->id, 'athlete_id' => $leader->id, 'order_index' => 1,
            'status' => 'published', 'total' => 30.0, 'published_at' => now(), 'is_counted' => true,
        ]);
        // Текущая выступает в потоке 1, ручной итог 25 (< 30).
        Performance::create([
            'category_id' => $stream1->id, 'athlete_id' => $live->id, 'order_index' => 1,
            'status' => 'performing', 'is_counted' => true,
            'scores_overridden' => true, 'd_score' => 8, 'a_score' => 8.5, 'e_score' => 8.5, 'total' => 25.0,
            'published_at' => now(),
        ]);

        // Место 2 (лидер из другого потока учтён), «из 2».
        $this->getJson(route('scoreboard.performance.live', $stream1))
            ->assertOk()
            ->assertJsonPath('performance.place', 2)
            ->assertJsonPath('performance.place_of', 2);
    }

    public function test_live_place_breaks_equal_totals_by_e_then_a(): void
    {
        $tournament = Tournament::create(['name' => 'Cup', 'is_published' => true]);
        $category = Category::create([
            'tournament_id' => $tournament->id,
            'name' => '2015 A',
            'birth_year' => 2015,
            'division' => 'A',
            'is_published' => true,
        ]);

        foreach ([
            ['name' => 'Leader', 'total' => 25.0, 'e' => 7.0, 'a' => 7.0],
            ['name' => 'Higher A', 'total' => 20.0, 'e' => 9.0, 'a' => 8.0],
            ['name' => 'Lower A', 'total' => 20.0, 'e' => 9.0, 'a' => 6.0],
        ] as $index => $data) {
            $athlete = Athlete::create(['first_name' => $data['name'], 'last_name' => 'Published']);
            Performance::create([
                'category_id' => $category->id,
                'athlete_id' => $athlete->id,
                'order_index' => $index + 1,
                'status' => 'published',
                'is_counted' => true,
                'total' => $data['total'],
                'e_score' => $data['e'],
                'a_score' => $data['a'],
                'published_at' => now(),
            ]);
        }

        $currentAthlete = Athlete::create(['first_name' => 'Current', 'last_name' => 'Gymnast']);
        Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $currentAthlete->id,
            'order_index' => 4,
            'status' => 'performing',
            'is_counted' => true,
            'scores_overridden' => true,
            'total' => 20.0,
            'e_score' => 9.0,
            'a_score' => 7.0,
            'published_at' => now(),
        ]);

        $this->getJson(route('scoreboard.performance.live', $category))
            ->assertOk()
            ->assertJsonPath('performance.place', 3)
            ->assertJsonPath('performance.place_of', 4);
    }

    public function test_live_place_uses_excel_sheet_pool_and_sums_all_accepted_apparatus(): void
    {
        $tournament = Tournament::create(['name' => 'Cup', 'is_published' => true]);
        $stream1 = Category::create([
            'tournament_id' => $tournament->id,
            'name' => 'Поток 1',
            'birth_year' => 2015,
            'division' => 'A',
            'is_published' => true,
        ]);
        $stream2 = Category::create([
            'tournament_id' => $tournament->id,
            'name' => 'Поток 2',
            'birth_year' => 2015,
            'division' => 'A',
            'is_published' => true,
        ]);

        $leader = Athlete::create(['first_name' => 'Л', 'last_name' => 'Лидер']);
        $current = Athlete::create(['first_name' => 'Т', 'last_name' => 'Текущая']);
        $waiting = Athlete::create(['first_name' => 'О', 'last_name' => 'Ожидает']);
        $outsider = Athlete::create(['first_name' => 'Ч', 'last_name' => 'Чужая']);

        foreach ([$leader, $current, $waiting] as $index => $athlete) {
            Entry::create([
                'tournament_id' => $tournament->id,
                'athlete_id' => $athlete->id,
                'program' => 'individual',
                'order_index' => $index + 1,
                'meta' => ['sheet' => 'Лист A'],
            ]);
        }
        Entry::create([
            'tournament_id' => $tournament->id,
            'athlete_id' => $outsider->id,
            'program' => 'individual',
            'order_index' => 4,
            'meta' => ['sheet' => 'Другой лист'],
        ]);

        foreach ([[$stream1, 20.0, 'Мяч'], [$stream2, 22.0, 'Обруч']] as [$stream, $score, $apparatus]) {
            Performance::create([
                'category_id' => $stream->id,
                'athlete_id' => $leader->id,
                'apparatus' => $apparatus,
                'order_index' => 1,
                'status' => 'published',
                'total' => $score,
                'published_at' => now(),
                'is_counted' => true,
            ]);
        }
        Performance::create([
            'category_id' => $stream2->id,
            'athlete_id' => $current->id,
            'apparatus' => 'Обруч',
            'order_index' => 2,
            'status' => 'published',
            'total' => 25.0,
            'published_at' => now(),
            'is_counted' => true,
        ]);
        Performance::create([
            'category_id' => $stream2->id,
            'athlete_id' => $outsider->id,
            'apparatus' => 'Обруч',
            'order_index' => 3,
            'status' => 'published',
            'total' => 100.0,
            'published_at' => now(),
            'is_counted' => true,
        ]);
        Performance::create([
            'category_id' => $stream1->id,
            'athlete_id' => $current->id,
            'apparatus' => 'Мяч',
            'order_index' => 4,
            'status' => 'performing',
            'total' => 15.0,
            'scores_overridden' => true,
            'published_at' => now(),
            'is_counted' => true,
        ]);

        $this->getJson(route('scoreboard.performance.live', $stream1))
            ->assertOk()
            ->assertJsonPath('performance.pool_label', 'Лист A')
            ->assertJsonPath('performance.apparatus_score', 15)
            ->assertJsonPath('performance.total', 40)
            ->assertJsonPath('performance.place', 2)
            ->assertJsonPath('performance.place_of', 3);
    }

    public function test_table_ranks_by_protocol_group_not_stream(): void
    {
        $tournament = Tournament::create(['name' => 'Cup', 'is_published' => true]);
        $stream1 = Category::create([
            'tournament_id' => $tournament->id,
            'name' => '2015 г.р., A — поток 1',
            'birth_year' => 2015,
            'division' => 'A',
            'is_published' => true,
        ]);
        $stream2 = Category::create([
            'tournament_id' => $tournament->id,
            'name' => '2015 г.р., A — поток 2',
            'birth_year' => 2015,
            'division' => 'A',
            'is_published' => true,
        ]);

        $leader = Athlete::create(['first_name' => 'Анна', 'last_name' => 'Лидер']);
        $second = Athlete::create(['first_name' => 'Белла', 'last_name' => 'Вторая']);

        Performance::create([
            'category_id' => $stream1->id,
            'athlete_id' => $leader->id,
            'order_index' => 1,
            'status' => 'published',
            'total' => 20.0,
            'published_at' => now(),
            'is_counted' => true,
        ]);
        Performance::create([
            'category_id' => $stream2->id,
            'athlete_id' => $leader->id,
            'order_index' => 1,
            'status' => 'published',
            'total' => 18.5,
            'published_at' => now(),
            'is_counted' => true,
        ]);
        Performance::create([
            'category_id' => $stream1->id,
            'athlete_id' => $second->id,
            'order_index' => 2,
            'status' => 'published',
            'total' => 25.0,
            'published_at' => now(),
            'is_counted' => true,
        ]);

        $response = $this->getJson(route('scoreboard.category.live', $stream1));

        $response->assertOk();
        $response->assertJsonPath('rows.0.athlete', 'Лидер Анна');
        $response->assertJsonPath('rows.0.place', 1);
        $response->assertJsonPath('rows.0.total', 38.5);
        $response->assertJsonPath('rows.1.athlete', 'Вторая Белла');
        $response->assertJsonPath('rows.1.place', 2);
        $response->assertJsonPath('rows.1.total', 25);
    }
}
