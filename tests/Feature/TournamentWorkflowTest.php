<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\Entry;
use App\Models\Group;
use App\Models\JudgeScore;
use App\Models\Performance;
use App\Models\StreamSession;
use App\Models\Tournament;
use App\Models\User;
use App\Services\StartProtocolExporter;
use App\Services\StreamAdvanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TournamentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function performance(): Performance
    {
        $t = Tournament::create(['name' => 'Test']);
        $c = Category::create(['tournament_id' => $t->id, 'name' => 'Stream']);
        $a = Athlete::create(['last_name' => 'Test', 'first_name' => 'Athlete']);

        return Performance::create(['category_id' => $c->id, 'athlete_id' => $a->id, 'status' => 'performing']);
    }

    public function test_e_is_rounded_before_total_in_manual_mode(): void
    {
        $p = $this->performance();
        $p->forceFill(['scores_overridden' => true, 'd_score' => 4, 'a_score' => 8, 'e_score' => 8.125]);
        $p->recalculateTotals();
        $this->assertEquals(8.13, $p->e_score);
        $this->assertEquals(20.13, $p->total);
    }

    public function test_editing_deduction_stores_converted_score_and_history(): void
    {
        $p = $this->performance();
        $judge = User::factory()->create(['role' => 'judge_e', 'slot' => 'E1']);
        $row = JudgeScore::create(['performance_id' => $p->id, 'judge_id' => $judge->id, 'panel' => 'e', 'score' => 7, 'submitted_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => 'chief_judge']))
            ->post(route('secretary.performance.updateJudgeScore', $p), ['slot' => 'E1', 'score' => 2.5, 'input_mode' => 'deduction'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEquals(7.5, $row->fresh()->score);
        $this->assertTrue($p->judgeScoreActions()->where('action', 'like', '%3.000 → 2.500%')->exists());
        $this->assertSame('performing', $p->fresh()->status);
    }

    public function test_missing_score_can_be_entered_for_assigned_judge(): void
    {
        $p = $this->performance();
        User::factory()->create(['role' => 'judge_e', 'slot' => 'E1']);
        $this->actingAs(User::factory()->create(['role' => 'secretary']))
            ->post(route('secretary.performance.updateJudgeScore', $p), ['slot' => 'E1', 'score' => 3, 'input_mode' => 'deduction'])
            ->assertSessionHasNoErrors();
        $this->assertEquals(7, $p->judgeScores()->firstOrFail()->score);
    }

    public function test_chief_can_review_but_cannot_control_queue(): void
    {
        $p = $this->performance();
        $this->actingAs(User::factory()->create(['role' => 'chief_judge']));
        $this->get(route('secretary.queue.review', $p->category))->assertOk()->assertSee('data-stream-history-score', false);
        $this->get(route('secretary.tournament.live', $p->category->tournament))->assertForbidden();
        $this->post(route('secretary.callNext', $p->category))->assertForbidden();
        $this->post(route('workflow.stream.state', $p->category), ['closed' => 1])->assertForbidden();
        $this->assertSame('performing', $p->fresh()->status);
    }

    public function test_close_reopen_is_explicit_and_preserves_results(): void
    {
        $p = $this->performance();
        $this->actingAs(User::factory()->create(['role' => 'secretary']));
        $this->post(route('workflow.stream.state', $p->category), ['closed' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($p->category->fresh()->closed_at);
        $this->assertFalse(StreamAdvanceService::advanceToNextInCategory($p->category));
        $this->post(route('workflow.stream.state', $p->category), ['closed' => 0])->assertSessionHasNoErrors();
        $this->assertNull($p->category->fresh()->closed_at);
    }

    public function test_award_updates_shift_by_delta_and_do_not_affect_other_days(): void
    {
        $p = $this->performance();
        $sessions = [];
        foreach ([['2026-09-24', '09:00', '10:00'], ['2026-09-24', '10:00', '11:00'], ['2026-09-25', '10:00', '11:00']] as $i => [$date, $start, $end]) {
            $sessions[] = StreamSession::create(['category_id' => $p->category_id, 'session_no' => $i + 1, 'scheduled_on' => $date, 'starts_at' => $start, 'ends_at' => $end, 'apparatus' => []]);
        }
        $this->actingAs(User::factory()->create(['role' => 'secretary']));
        $this->post(route('workflow.award', $sessions[0]), ['minutes' => 20])->assertSessionHasNoErrors();
        $this->assertSame('10:20', substr($sessions[1]->fresh()->starts_at, 0, 5));
        $this->post(route('workflow.award', $sessions[0]), ['minutes' => 10])->assertSessionHasNoErrors();
        $this->assertSame('10:10', substr($sessions[1]->fresh()->starts_at, 0, 5));
        $this->assertSame('10:00', substr($sessions[2]->fresh()->starts_at, 0, 5));
        $book = app(StartProtocolExporter::class)->buildProgramme($p->category->tournament);
        $this->assertContains('2026-09-24', $book->getSheetNames());
        $this->assertContains('2026-09-25', $book->getSheetNames());
    }

    public function test_cancel_scoreboard_keeps_score_and_approval(): void
    {
        $p = $this->performance();
        $p->forceFill(['total' => 25, 'approved_at' => now(), 'published_at' => now(), 'scoreboard_accepted_at' => now(), 'status' => 'published'])->save();
        $this->actingAs(User::factory()->create(['role' => 'scoreboard_judge']))
            ->postJson(route('scoreboard-judge.cancel', $p))->assertOk();
        $this->assertNull($p->fresh()->scoreboard_accepted_at);
        $this->assertNotNull($p->fresh()->approved_at);
        $this->assertEquals(25, $p->fresh()->total);
    }

    public function test_tablet_binding_is_unique_and_score_identity_is_retained(): void
    {
        $p = $this->performance();
        $t = $p->category->tournament;
        $roster = DB::table('tournament_judges')->insertGetId(['tournament_id' => $t->id, 'name' => 'Иванова Анна', 'club' => 'School']);
        $judge = User::factory()->create(['role' => 'judge_e', 'slot' => 'E1']);
        $this->actingAs($judge)->post(route('workflow.judges.bind', $t), ['judge_id' => $roster])->assertSessionHasNoErrors();
        $score = JudgeScore::create(['performance_id' => $p->id, 'judge_id' => $judge->id, 'panel' => 'e', 'score' => 7]);
        $this->assertSame('Иванова Анна', $score->judge_name);
        $this->actingAs(User::factory()->create(['role' => 'judge_e', 'slot' => 'E2']))
            ->post(route('workflow.judges.bind', $t), ['judge_id' => $roster])->assertSessionHasErrors('judge_id');
    }

    public function test_regular_e_panel_rounds_to_two_decimals(): void
    {
        $p = $this->performance();
        foreach ([8.00, 8.12, 8.13, 8.50] as $i => $value) {
            $judge = User::factory()->create(['role' => 'judge_e', 'slot' => 'E'.($i + 1)]);
            JudgeScore::create(['performance_id' => $p->id, 'judge_id' => $judge->id, 'panel' => 'e', 'score' => $value, 'submitted_at' => now()]);
        }
        $p->recalculateTotals();
        $this->assertEquals(8.13, $p->e_score);
    }

    public function test_return_and_resubmission_are_visible_in_history(): void
    {
        $p = $this->performance();
        $judge = User::factory()->create(['role' => 'judge_e', 'slot' => 'E1']);
        $row = JudgeScore::create(['performance_id' => $p->id, 'judge_id' => $judge->id, 'panel' => 'e', 'score' => 7, 'submitted_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => 'chief_judge']))
            ->post(route('secretary.performance.returnScores', $p), ['panel' => 'e'])->assertSessionHasNoErrors();
        $this->assertNotNull($row->fresh()->returned_at);
        $this->getJson(route('secretary.performance.scoreLiveHistory', ['performance' => $p, 'slot' => 'E1']))
            ->assertOk()->assertJsonPath('score.returned', true);
        $row->refresh()->forceFill(['score' => 8, 'submitted_at' => now()])->save();
        $this->assertNull($row->fresh()->returned_at);
        $this->assertTrue($p->judgeScoreActions()->where('action', 'like', '%3.000 → 2.000%')->exists());
    }

    public function test_award_crossing_midnight_is_rejected_atomically(): void
    {
        $p = $this->performance();
        $session = StreamSession::create(['category_id' => $p->category_id, 'session_no' => 1, 'scheduled_on' => '2026-09-24', 'starts_at' => '23:00', 'ends_at' => '23:50', 'apparatus' => []]);
        $this->actingAs(User::factory()->create(['role' => 'secretary']))
            ->post(route('workflow.award', $session), ['minutes' => 30])->assertSessionHasErrors('minutes');
        $this->assertEquals(0, $session->fresh()->award_minutes);
    }

    public function test_new_streams_keep_the_selected_day(): void
    {
        $t = Tournament::create(['name' => 'Days']);
        $group = Group::create(['tournament_id' => $t->id, 'name' => 'Group', 'apparatus' => ['Мяч']]);
        $athlete = Athlete::create(['last_name' => 'Day', 'first_name' => 'Athlete']);
        Entry::create(['tournament_id' => $t->id, 'group_id' => $group->id, 'athlete_id' => $athlete->id, 'program' => 'individual']);
        $this->actingAs(User::factory()->create(['role' => 'secretary']))
            ->post(route('secretary.tournament.groups.streams', [$t, $group]), ['stream_size' => 10, 'start_time' => '09:00', 'minutes_per_athlete' => 2, 'scheduled_on' => '2026-09-24'])
            ->assertSessionHasNoErrors();
        $session = StreamSession::firstOrFail();
        $this->assertSame('2026-09-24', $session->scheduled_on->format('Y-m-d'));
        $this->assertSame('09:00', substr($session->starts_at, 0, 5));
        $this->assertSame($session->id, Performance::firstOrFail()->stream_session_id);
        $this->get(route('secretary.tournament.groups', $t))->assertOk()->assertSee('День 1');
    }

    public function test_day_start_sheet_excludes_other_days(): void
    {
        $p = $this->performance();
        foreach (['2026-09-24', '2026-09-25'] as $i => $day) {
            $s = StreamSession::create(['category_id' => $p->category_id, 'session_no' => $i + 1, 'scheduled_on' => $day, 'starts_at' => '09:00', 'ends_at' => '10:00', 'apparatus' => []]);
            $athlete = Athlete::create(['last_name' => 'OnlyDay'.($i + 1), 'first_name' => 'Test']);
            Performance::create(['category_id' => $p->category_id, 'athlete_id' => $athlete->id, 'stream_session_id' => $s->id]);
        }
        $book = app(StartProtocolExporter::class)->buildStartSheet($p->category->tournament, '2026-09-24');
        $content = json_encode($book->getActiveSheet()->toArray());
        $this->assertStringContainsString('OnlyDay1', $content);
        $this->assertStringNotContainsString('OnlyDay2', $content);
    }

    public function test_difficulty_average_edit_and_panel_return_have_history(): void
    {
        $p = $this->performance();
        $judge = User::factory()->create(['role' => 'judge_d', 'slot' => 'DB_AVG']);
        $row = JudgeScore::create([
            'performance_id' => $p->id, 'judge_id' => $judge->id,
            'panel' => 'd', 'subpanel' => 'db',
            'average_score' => 4, 'average_submitted_at' => now(),
        ]);
        $this->actingAs(User::factory()->create(['role' => 'chief_judge']))
            ->post(route('secretary.performance.updateJudgeScore', $p), ['slot' => 'DB_AVG', 'score' => 4.5])
            ->assertSessionHasNoErrors();
        $this->assertEquals(4.5, $row->fresh()->average_score);
        $this->assertTrue($p->judgeScoreActions()->where('action', 'like', '%4.000 → 4.500%')->exists());
        $this->post(route('secretary.performance.returnScores', $p), ['panel' => 'db'])->assertSessionHasNoErrors();
        $this->assertNotNull($row->fresh()->returned_at);
        $this->assertNull($row->fresh()->average_submitted_at);
        $this->assertTrue($p->judgeScoreActions()->where('action', 'like', '%Возврат на доработку: 4.500%')->exists());
    }
}
