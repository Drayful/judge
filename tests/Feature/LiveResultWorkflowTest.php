<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\JudgeScore;
use App\Models\Performance;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FinalProtocolService;
use App\Services\StartProtocolExporter;
use App\Support\ScoreboardUi;
use App\Support\SecretaryLiveUi;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveResultWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_duration_is_saved_for_the_performance_and_deducted_per_second(): void
    {
        Carbon::setTestNow('2026-08-03 10:00:00');
        $performance = $this->performance('individual');
        $performance->startOfficialTimer(now()->subSeconds(92));
        $performance->stopOfficialTimer();
        $performance->recalculateTotals();
        $performance->save();

        $this->assertSame(92, $performance->actual_duration_seconds);
        $this->assertSame(0.1, (float) $performance->time_penalty);
        $this->assertSame(0.1, (float) $performance->penalty);
    }

    public function test_group_duration_uses_its_own_norm(): void
    {
        Carbon::setTestNow('2026-08-03 10:00:00');
        $performance = $this->performance('group');
        $performance->startOfficialTimer(now()->subSeconds(134));
        $performance->stopOfficialTimer();

        $this->assertSame(134, $performance->actual_duration_seconds);
        $this->assertSame(0.05, (float) $performance->time_penalty);
    }

    public function test_time_judge_starts_and_stops_the_official_timer_from_the_tablet(): void
    {
        Carbon::setTestNow('2026-08-03 10:00:00');
        $performance = $this->performance();
        $performance->load('category.tournament');
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $timeJudge = User::factory()->create(['role' => 'time_judge', 'slot' => 'TIME']);

        $this->actingAs($timeJudge)
            ->postJson(route('judge.tournament.timer', $tournament), ['action' => 'start'])
            ->assertOk();

        Carbon::setTestNow('2026-08-03 10:01:32');
        $this->actingAs($timeJudge)
            ->postJson(route('judge.tournament.timer', $tournament), ['action' => 'stop'])
            ->assertOk()
            ->assertJsonPath('duration_seconds', 92)
            ->assertJsonPath('time_penalty', 0.1);

        $saved = $performance->fresh();
        $this->assertNotNull($saved->timer_started_at);
        $this->assertNotNull($saved->timer_ended_at);
        $this->assertSame(92, $saved->actual_duration_seconds);
    }

    public function test_line_judge_total_is_applied_without_separate_confirmation(): void
    {
        $performance = $this->performance('group');
        $performance->load('category.tournament');
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $lineJudge = User::factory()->create(['role' => 'line_judge', 'slot' => 'LINE1']);

        $this->actingAs($lineJudge)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertSee('Гимнастка за линию')
            ->assertSee('Мяч за линию')
            ->assertSee('Сумма сбавки')
            ->assertSee('Отправить')
            ->assertDontSee('0.50', false)
            ->assertDontSee('1.00', false)
            ->assertDontSee('2.00', false);

        $entries = json_encode([
            ['v' => 0.3, 'label' => 'Гимнастка за линию', 'counted' => true],
            ['v' => 0.3, 'label' => 'Мяч за линию', 'counted' => true],
        ], JSON_UNESCAPED_UNICODE);

        $this->actingAs($lineJudge)
            ->post(route('judge.tournament.tablet.score', $tournament), [
                'panel' => 'penalty',
                'penalty_type' => 'line',
                'score' => 0.6,
                'entries' => $entries,
            ])
            ->assertRedirect(route('judge.tournament.tablet', $tournament));

        $this->assertDatabaseHas('judge_scores', [
            'performance_id' => $performance->id,
            'judge_id' => $lineJudge->id,
            'penalty_type' => 'line',
            'score' => 0.6,
        ]);
        $this->assertSame(0.6, (float) $performance->fresh()->penalty);

        $secretary = User::factory()->create(['role' => 'secretary']);
        $this->actingAs($secretary)
            ->get(route('secretary.queue', $performance->category))
            ->assertOk()
            ->assertSee('Управление оценками')
            ->assertSee('LINE1')
            ->assertSee('0.600')
            ->assertDontSee('Общая линейная сбавка')
            ->assertDontSee('Отклонить');
    }

    public function test_group_tablets_expose_the_requested_db_and_artistry_rules(): void
    {
        $performance = $this->performance('group');
        $performance->load('category.tournament');
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);

        $aJudge = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);
        $this->actingAs($aJudge)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertSee('Синхронизация')
            ->assertSee('Контраст')
            ->assertSee('Каноническая')
            ->assertSee('Хоровая')
            ->assertSee('dynamic: opts.groupProgram ? 4 : 2', false)
            ->assertSee('const base = this.round3(this.comboStep * (this.catMax[cat] || 0))', false)
            ->assertSee('sum += this.blockPenalty(c)', false);

        $dbJudge = User::factory()->create(['role' => 'judge_d_db', 'slot' => 'DB1']);
        $this->actingAs($dbJudge)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertSee('Риски всегда в зачёте')
            ->assertSee('junior: { elements: 6, dbMax: 3, deMax: 3, dbMin: 0, deMin: 3 }', false)
            ->assertSee('senior: { elements: 9, dbMax: 5, deMax: 5, dbMin: 4, deMin: 4 }', false);
    }

    public function test_db_and_da_averages_are_persisted_when_scores_arrive(): void
    {
        $performance = $this->performance();
        $dbJudge = User::factory()->create(['role' => 'judge_d_db', 'slot' => 'DB1']);
        $daJudge = User::factory()->create(['role' => 'judge_d_da', 'slot' => 'DA1']);

        JudgeScore::create([
            'performance_id' => $performance->id,
            'judge_id' => $dbJudge->id,
            'panel' => 'd',
            'subpanel' => 'db',
            'score' => 4.2,
            'submitted_at' => now(),
        ]);
        JudgeScore::create([
            'performance_id' => $performance->id,
            'judge_id' => $daJudge->id,
            'panel' => 'd',
            'subpanel' => 'da',
            'score' => 3.4,
            'submitted_at' => now(),
        ]);

        $performance->recalculateTotals();
        $performance->save();

        $this->assertSame(4.2, (float) $performance->fresh()->db_average);
        $this->assertSame(3.4, (float) $performance->fresh()->da_average);
    }

    public function test_db1_gets_a_second_manual_average_step_visible_to_secretary(): void
    {
        $performance = $this->performance();
        $performance->load('category.tournament');
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $db1 = User::factory()->create(['role' => 'judge_d_db', 'slot' => 'DB1']);

        $this->actingAs($db1)
            ->postJson(route('judge.submit-score'), [
                'tournament_id' => $tournament->id,
                'score' => 4.2,
            ])
            ->assertOk();

        $this->actingAs($db1)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertSee('Второй этап')
            ->assertSee('Введите ручную среднюю DB');

        $this->actingAs($db1)
            ->postJson(route('judge.submit-average'), [
                'tournament_id' => $tournament->id,
                'average_score' => 4.1,
            ])
            ->assertOk()
            ->assertJsonPath('average_score', 4.1);

        $this->assertDatabaseHas('judge_scores', [
            'performance_id' => $performance->id,
            'judge_id' => $db1->id,
            'score' => 4.2,
            'average_score' => 4.1,
        ]);
        $this->actingAs($db1)
            ->getJson(route('judge.tournament.tablet.ping', $tournament))
            ->assertOk()
            ->assertJsonPath('score_submitted', true)
            ->assertJsonPath('average_submitted', true);

        $secretary = User::factory()->create(['role' => 'secretary']);
        $this->actingAs($secretary)
            ->get(route('secretary.queue', $performance->category))
            ->assertOk()
            ->assertSee('Ручная средняя DB')
            ->assertSee('4.100');
    }

    public function test_manual_db_and_da_averages_are_official_and_required_for_auto_advance(): void
    {
        $performance = $this->performance();
        $performance->category->update([
            'inactive_judge_slots' => ['DB2', 'DA2', 'A1', 'A2', 'A3', 'A4', 'E1', 'E2', 'E3', 'E4'],
        ]);
        $db1 = User::factory()->create(['role' => 'judge_d_db', 'slot' => 'DB1']);
        $da1 = User::factory()->create(['role' => 'judge_d_da', 'slot' => 'DA1']);

        $dbScore = JudgeScore::create([
            'performance_id' => $performance->id,
            'judge_id' => $db1->id,
            'panel' => 'd',
            'subpanel' => 'db',
            'score' => 4.2,
            'average_score' => 4.0,
            'submitted_at' => now(),
            'average_submitted_at' => now(),
        ]);
        $daScore = JudgeScore::create([
            'performance_id' => $performance->id,
            'judge_id' => $da1->id,
            'panel' => 'd',
            'subpanel' => 'da',
            'score' => 3.4,
            'submitted_at' => now(),
        ]);

        $performance->load(['judgeScores.judge', 'category']);
        $performance->recalculateTotals();
        $this->assertSame(4.2, (float) $performance->db_average);
        $this->assertSame(3.4, (float) $performance->da_average);
        $this->assertSame(7.4, (float) $performance->d_score);
        $this->assertFalse(SecretaryLiveUi::readyToFinalize($performance, $performance->category));

        $daScore->update(['average_score' => 3.1, 'average_submitted_at' => now()]);
        $performance->refresh()->load(['judgeScores.judge', 'category']);
        $performance->recalculateTotals();

        $this->assertSame(7.1, (float) $performance->d_score);
        $this->assertTrue(SecretaryLiveUi::readyToFinalize($performance, $performance->category));
        $this->assertNotNull($dbScore->fresh()->average_submitted_at);
    }

    public function test_zero_total_is_marked_not_performed_and_has_no_place(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $category = Category::create(['tournament_id' => $tournament->id, 'name' => '2018 A', 'program' => 'individual', 'birth_year' => 2018, 'division' => 'A']);
        $zero = Athlete::create(['last_name' => 'Нулевая', 'first_name' => 'Анна']);
        $scored = Athlete::create(['last_name' => 'Первая', 'first_name' => 'Ирина']);
        Performance::create(['category_id' => $category->id, 'athlete_id' => $zero->id, 'total' => 0, 'status' => 'done']);
        Performance::create(['category_id' => $category->id, 'athlete_id' => $scored->id, 'total' => 10.5, 'status' => 'done']);

        $rows = app(FinalProtocolService::class)->build($tournament, 2018, 'A')['rows'];
        $notPerformed = collect($rows)->firstWhere('athlete_id', $zero->id);

        $this->assertSame('not_performed', $notPerformed['status']);
        $this->assertNull($notPerformed['place']);
    }

    public function test_start_sheet_orders_streams_by_time(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        Category::create(['tournament_id' => $tournament->id, 'name' => 'Поздний поток', 'program' => 'individual', 'starts_at_label' => '10:00']);
        Category::create(['tournament_id' => $tournament->id, 'name' => 'Ранний поток', 'program' => 'individual', 'starts_at_label' => '08:00']);

        $sheet = app(StartProtocolExporter::class)->buildStartSheet($tournament)->getActiveSheet();

        $this->assertStringContainsString('Ранний поток', (string) $sheet->getCell('A4')->getValue());
    }

    public function test_score_remains_hidden_until_scoreboard_judge_accepts_it(): void
    {
        $performance = $this->performance();
        $performance->update([
            'status' => 'done',
            'd_score' => 4.0,
            'a_score' => 8.5,
            'e_score' => 8.2,
            'total' => 20.7,
            'approved_at' => now(),
        ]);
        $performance->load(['athlete.members', 'category', 'judgeScores.judge', 'inquiries']);

        $before = ScoreboardUi::performancePayload($performance->category, $performance);
        $this->assertNull($before['performance']['total']);

        $scoreboardJudge = User::factory()->create(['role' => 'scoreboard_judge']);
        $this->actingAs($scoreboardJudge)
            ->post(route('scoreboard-judge.accept', $performance))
            ->assertRedirect();

        $after = $performance->fresh()->load(['athlete.members', 'category', 'judgeScores.judge', 'inquiries']);
        $payload = ScoreboardUi::performancePayload($after->category, $after);
        $this->assertSame(20.7, (float) $payload['performance']['total']);
        $this->assertNotNull($after->scoreboard_accepted_at);
    }

    public function test_secretary_is_allowed_to_confirm_a_final_score(): void
    {
        $performance = $this->performance();
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary)
            ->post(route('secretary.performance.confirmScore', $performance))
            ->assertRedirect()
            ->assertSessionHasErrors('confirm');
    }

    public function test_tournament_management_page_renders_for_the_secretary(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary)
            ->get(route('secretary.tournament', $tournament))
            ->assertOk();
    }

    private function performance(string $program = 'individual'): Performance
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $category = Category::create(['tournament_id' => $tournament->id, 'name' => 'C', 'program' => $program]);
        $athlete = Athlete::create(['last_name' => 'Иванова', 'first_name' => 'Анна']);

        return Performance::create(['category_id' => $category->id, 'athlete_id' => $athlete->id, 'status' => 'performing']);
    }
}
