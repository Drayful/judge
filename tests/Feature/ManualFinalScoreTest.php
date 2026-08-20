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

class ManualFinalScoreTest extends TestCase
{
    use RefreshDatabase;

    private function performance(): Performance
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $category = Category::create([
            'tournament_id' => $tournament->id,
            'name' => '2018 г.р., A',
            'program' => 'individual',
            'birth_year' => 2018,
            'division' => 'A',
        ]);
        $athlete = Athlete::create(['first_name' => 'Имя', 'last_name' => 'Фамилия']);

        return Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $athlete->id,
            'start_number' => 1,
            'order_index' => 1,
            'status' => 'performing',
            'apparatus' => 'Мяч',
        ]);
    }

    public function test_secretary_sets_final_score_manually(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);
        $perf = $this->performance();

        $this->actingAs($secretary)
            ->post(route('secretary.performance.setFinalScore', $perf), [
                'd_score' => 5.5,
                'a_score' => 8.2,
                'e_score' => 7.1,
                'penalty' => 0.3,
            ])->assertRedirect();

        $perf->refresh();
        $this->assertTrue($perf->scores_overridden);
        $this->assertSame($secretary->id, $perf->scores_overridden_by);
        $this->assertNotNull($perf->finalized_at);
        // 5.5 + 8.2 + 7.1 - 0.3 = 20.5
        $this->assertEqualsWithDelta(20.5, (float) $perf->total, 0.0001);
    }

    public function test_chief_judge_allowed_and_judge_scores_do_not_override_manual(): void
    {
        $chief = User::factory()->create(['role' => 'chief_judge']);
        $perf = $this->performance();

        $this->actingAs($chief)
            ->post(route('secretary.performance.setFinalScore', $perf), [
                'd_score' => 6.0, 'a_score' => 9.0, 'e_score' => 9.0,
            ])->assertRedirect();

        $perf->refresh();
        $this->assertEqualsWithDelta(24.0, (float) $perf->total, 0.0001);

        // Появляется оценка судьи — итог остаётся ручным.
        $judge = User::factory()->create(['role' => 'judge_d']);
        JudgeScore::create([
            'performance_id' => $perf->id,
            'judge_id' => $judge->id,
            'panel' => 'd',
            'subpanel' => 'db',
            'slot' => 'DB1',
            'score' => 1.234,
            'submitted_at' => now(),
        ]);
        $perf->load('category');
        $perf->recalculateTotals();
        $perf->save();

        $this->assertEqualsWithDelta(6.0, (float) $perf->d_score, 0.0001);
        $this->assertEqualsWithDelta(24.0, (float) $perf->total, 0.0001);
    }

    public function test_manual_total_does_not_deduct_time_penalty_twice(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);
        $perf = $this->performance();
        $perf->update(['time_penalty' => 0.1, 'penalty' => 0.3]);

        $this->actingAs($secretary)
            ->post(route('secretary.performance.setFinalScore', $perf), [
                'd_score' => 5.5,
                'a_score' => 8.2,
                'e_score' => 7.1,
                'penalty' => 0.3,
            ])->assertRedirect();

        $this->assertEqualsWithDelta(20.5, (float) $perf->fresh()->total, 0.0001);
    }

    public function test_manual_final_score_can_be_approved_without_panel_submissions(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);
        $perf = $this->performance();

        $this->actingAs($secretary)->post(route('secretary.performance.setFinalScore', $perf), [
            'd_score' => 5.5,
            'a_score' => 8.2,
            'e_score' => 7.1,
            'penalty' => 0.3,
        ])->assertRedirect();

        $this->actingAs($secretary)
            ->post(route('supervisor.approve', $perf))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull($perf->fresh()->approved_at);
    }

    public function test_manual_final_score_can_be_confirmed_from_live_queue(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);
        $perf = $this->performance();

        $this->actingAs($secretary)->post(route('secretary.performance.setFinalScore', $perf), [
            'd_score' => 5.5,
            'a_score' => 8.2,
            'e_score' => 7.1,
            'penalty' => 0.3,
        ])->assertRedirect();

        $this->actingAs($secretary)
            ->post(route('secretary.performance.confirmScore', $perf))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull($perf->fresh()->approved_at);
    }

    public function test_secretary_can_score_a_completed_performance_without_switching_the_active_athlete(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);
        $completed = $this->performance();
        $category = $completed->category;
        $tournament = $category->tournament;
        $completed->update(['status' => 'done']);
        $currentAthlete = Athlete::create(['first_name' => 'Текущая', 'last_name' => 'Гимнастка']);
        $current = Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $currentAthlete->id,
            'start_number' => 2,
            'order_index' => 2,
            'status' => 'performing',
            'apparatus' => 'Обруч',
        ]);
        $tournament->update(['active_category_id' => $category->id]);
        $judge = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);

        $this->actingAs($secretary)
            ->get(route('secretary.queue', $category))
            ->assertOk()
            ->assertSee('data-manual-score', false)
            ->assertSee(route('secretary.performance.setFinalScore', $completed), false);

        $this->actingAs($secretary)
            ->post(route('secretary.performance.setFinalScore', $completed), [
                'd_score' => 5.5,
                'a_score' => 8.2,
                'e_score' => 7.1,
                'penalty' => 0.3,
            ])
            ->assertRedirect();

        $this->assertSame('done', $completed->fresh()->status);
        $this->assertTrue($completed->fresh()->scores_overridden);
        $this->assertEqualsWithDelta(20.5, (float) $completed->fresh()->total, 0.0001);
        $this->assertSame('performing', $current->fresh()->status);
        $this->assertSame($category->id, $tournament->fresh()->active_category_id);
        $this->actingAs($judge)
            ->getJson(route('judge.tournament.tablet.ping', $tournament))
            ->assertOk()
            ->assertJsonPath('performance_id', $current->id);
    }

    public function test_clear_override_recomputes_from_judges(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);
        $perf = $this->performance();

        $this->actingAs($secretary)->post(route('secretary.performance.setFinalScore', $perf), [
            'd_score' => 6.0, 'a_score' => 9.0, 'e_score' => 9.0,
        ]);

        $this->actingAs($secretary)
            ->post(route('secretary.performance.clearFinalOverride', $perf))
            ->assertRedirect();

        $perf->refresh();
        $this->assertFalse($perf->scores_overridden);
        $this->assertNull($perf->finalized_at);
        // Без оценок судей итог обнуляется (не хватает компонент).
        $this->assertNull($perf->total);
    }

    public function test_judge_role_forbidden(): void
    {
        $judge = User::factory()->create(['role' => 'judge_e']);
        $perf = $this->performance();

        $this->actingAs($judge)
            ->post(route('secretary.performance.setFinalScore', $perf), [
                'd_score' => 6.0, 'a_score' => 9.0, 'e_score' => 9.0,
            ])->assertForbidden();
    }
}
