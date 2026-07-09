<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
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

        $this->getJson(route('scoreboard.performance.live', $category))
            ->assertOk()
            ->assertJsonPath('performance.place', 2);
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
