<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\JudgeScore;
use App\Models\JudgeScoreAction;
use App\Models\Performance;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JudgeLiveActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_judge_action_is_saved_without_submitting_final_score(): void
    {
        $judge = User::factory()->create(['role' => 'judge_a', 'slot' => 'A2']);
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $category = Category::create([
            'tournament_id' => $tournament->id,
            'name' => '2020 A',
            'program' => 'individual',
        ]);
        $tournament->update(['active_category_id' => $category->id]);
        $athlete = Athlete::create(['last_name' => 'Тест', 'first_name' => 'Гимнастка']);
        $performance = Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $athlete->id,
            'apparatus' => 'Мяч',
            'order_index' => 1,
            'status' => 'performing',
        ]);

        $this->actingAs($judge)
            ->postJson(route('judge.performance.live-actions', $performance), [
                'action' => 'Добавлена сбавка: Ритм',
                'draft_score' => 0.3,
                'entries' => [['v' => 0.3, 'label' => 'Ритм', 'counted' => true]],
                'age_group' => 'junior',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('judge_score_actions', [
            'performance_id' => $performance->id,
            'judge_id' => $judge->id,
            'slot' => 'A2',
            'panel' => 'a',
            'action' => 'Добавлена сбавка: Ритм',
        ]);
        $this->assertSame(0, JudgeScore::query()->where('performance_id', $performance->id)->count());
        $this->assertSame(1, JudgeScoreAction::query()->where('performance_id', $performance->id)->count());
    }
}
