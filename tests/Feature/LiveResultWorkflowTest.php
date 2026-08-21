<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\JudgeScore;
use App\Models\JudgeScoreAction;
use App\Models\Performance;
use App\Models\StreamSession;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FinalProtocolService;
use App\Services\StartProtocolExporter;
use App\Services\StreamAdvanceService;
use App\Support\ScoreboardUi;
use App\Support\SecretaryLiveUi;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_duration_with_microseconds_is_saved_as_whole_seconds(): void
    {
        $performance = $this->performance('individual');
        $startedAt = Carbon::parse('2026-08-18 09:07:56.000000');
        $finishedAt = Carbon::parse('2026-08-18 09:08:48.842468');

        $performance->startOfficialTimer($startedAt);
        $performance->stopOfficialTimer($finishedAt);
        $performance->save();

        $saved = $performance->fresh();
        $this->assertSame(52, $saved->actual_duration_seconds);
        $this->assertSame(1.15, (float) $saved->time_penalty);
    }

    public function test_time_judge_starts_and_stops_the_official_timer_from_the_tablet(): void
    {
        Carbon::setTestNow('2026-08-03 10:00:00.654321');
        $performance = $this->performance();
        $performance->load('category.tournament');
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $timeJudge = User::factory()->create(['role' => 'time_judge', 'slot' => 'TIME']);

        $this->actingAs($timeJudge)
            ->postJson(route('judge.tournament.timer', $tournament), [
                'action' => 'start',
                'performance_id' => $performance->id,
            ])
            ->assertOk();

        $this->assertSame('654321', $performance->fresh()->timer_started_at->format('u'));

        Carbon::setTestNow('2026-08-03 10:01:32.654321');
        $this->actingAs($timeJudge)
            ->postJson(route('judge.tournament.timer', $tournament), [
                'action' => 'stop',
                'performance_id' => $performance->id,
            ])
            ->assertOk()
            ->assertJsonPath('duration_seconds', 92)
            ->assertJsonPath('time_penalty', 0.1);

        $saved = $performance->fresh();
        $this->assertNotNull($saved->timer_started_at);
        $this->assertNotNull($saved->timer_ended_at);
        $this->assertSame(92, $saved->actual_duration_seconds);
        $this->assertSame('654321', $saved->timer_ended_at->format('u'));
    }

    public function test_database_processing_delay_is_not_added_to_official_duration(): void
    {
        Carbon::setTestNow('2026-08-03 10:00:00');
        $performance = $this->performance();
        $performance->startOfficialTimer();
        $performance->save();
        $performance->load('category.tournament');
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $timeJudge = User::factory()->create(['role' => 'time_judge', 'slot' => 'TIME']);

        Carbon::setTestNow('2026-08-03 10:01:32');
        $processingDelaySimulated = false;
        DB::listen(function (QueryExecuted $query) use (&$processingDelaySimulated): void {
            if (! $processingDelaySimulated && str_contains($query->sql, 'performances')) {
                $processingDelaySimulated = true;
                Carbon::setTestNow('2026-08-03 10:01:40');
            }
        });

        $this->actingAs($timeJudge)
            ->postJson(route('judge.tournament.timer', $tournament), [
                'action' => 'stop',
                'performance_id' => $performance->id,
            ])
            ->assertOk()
            ->assertJsonPath('duration_seconds', 92);

        $saved = $performance->fresh();
        $this->assertTrue($processingDelaySimulated);
        $this->assertSame(92, $saved->actual_duration_seconds);
        $this->assertTrue($saved->timer_ended_at->equalTo(Carbon::parse('2026-08-03 10:01:32')));
    }

    public function test_time_command_from_a_stale_tablet_cannot_change_the_next_performance(): void
    {
        $first = $this->performance();
        $category = $first->category;
        $tournament = $category->tournament;
        $tournament->update(['active_category_id' => $category->id]);
        $secondAthlete = Athlete::create(['last_name' => 'Second', 'first_name' => 'Athlete']);
        $second = Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $secondAthlete->id,
            'order_index' => 2,
            'status' => 'scheduled',
        ]);
        $timeJudge = User::factory()->create(['role' => 'time_judge', 'slot' => 'TIME']);

        $first->update(['status' => 'done']);
        $second->update(['status' => 'performing']);

        $this->actingAs($timeJudge)
            ->postJson(route('judge.tournament.timer', $tournament), [
                'action' => 'start',
                'performance_id' => $first->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'Выступление уже сменилось. Обновите планшет и повторите действие для текущей гимнастки.');

        $this->assertNull($first->fresh()->timer_started_at);
        $this->assertNull($second->fresh()->timer_started_at);
    }

    public function test_time_judge_gets_a_clear_error_when_stopping_before_start(): void
    {
        $performance = $this->performance();
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $timeJudge = User::factory()->create(['role' => 'time_judge', 'slot' => 'TIME']);

        $this->actingAs($timeJudge)
            ->postJson(route('judge.tournament.timer', $tournament), [
                'action' => 'stop',
                'performance_id' => $performance->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Сначала нажмите «Старт».');

        $this->assertNull($performance->fresh()->timer_ended_at);
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
            ->assertSee('Канонадная')
            ->assertSee('Хорал')
            ->assertSee('не отмечено = −0.30')
            ->assertSee('text-3xl leading-none text-emerald-200', false)
            ->assertDontSee('Убрать')
            ->assertSee('Рисунки построений')
            ->assertSee('Амплитуда построений')
            ->assertSee('Гимнастка без предмета 5+ сек.')
            ->assertSee('Нет контакта с предметом в начале/конце')
            ->assertSee("togglePenalty(0.6, 'interrupt')", false)
            ->assertSee("togglePenalty(0.3, 'faceExpr')", false)
            ->assertSee("togglePenalty(0.3, 'formationDesign')", false)
            ->assertSee('Конструкция / поднятое положение')
            ->assertSee("oneTimeCreditCats: opts.groupProgram", false)
            ->assertSee('dynamic: opts.groupProgram ? 4 : 2', false)
            ->assertSee('collectiveSync: opts.groupProgram ? 1 : 0', false)
            ->assertSee('const step = this.creditValue(cat)', false)
            ->assertSee('sum += this.blockPenalty(c)', false);

        $dbJudge = User::factory()->create(['role' => 'judge_d_db', 'slot' => 'DB1']);
        $this->actingAs($dbJudge)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertSee('Риски всегда в зачёте')
            ->assertSee('junior: { elements: 6, dbMax: 3, deMax: 3, dbMin: 0, deMin: 3 }', false)
            ->assertSee('senior: { elements: 9, dbMax: 5, deMax: 5, dbMin: 4, deMin: 4 }', false);
    }

    public function test_a_and_e_tablets_limit_scores_and_deductions_to_ten_points(): void
    {
        $performance = $this->performance();
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);

        foreach ([['judge_a', 'A1'], ['judge_e', 'E1']] as [$role, $slot]) {
            $judge = User::factory()->create(['role' => $role, 'slot' => $slot]);

            $this->actingAs($judge)
                ->get(route('judge.tournament.tablet', $tournament))
                ->assertOk()
                ->assertSee('deductionLimit: 10', false)
                ->assertSee('projectedDeduction > this.deductionLimit', false)
                ->assertSee("Math.min(this.deductionLimit, total)", false);

            $this->actingAs($judge)
                ->postJson(route('judge.submit-score'), [
                    'tournament_id' => $tournament->id,
                    'score' => 10.001,
                ])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Оценка бригад A и E должна быть в диапазоне от 0 до 10 баллов.');

            $this->assertDatabaseMissing('judge_scores', [
                'performance_id' => $performance->id,
                'judge_id' => $judge->id,
            ]);
        }

        $eJudge = User::query()->where('role', 'judge_e')->firstOrFail();
        $this->actingAs($eJudge)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertSee('data-e-large-athlete-name', false)
            ->assertSee('data-e-large-controls', false)
            ->assertSee('text-7xl md:text-8xl xl:text-9xl', false);

        $aJudge = User::query()->where('role', 'judge_a')->firstOrFail();
        $this->actingAs($aJudge)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertDontSee('data-e-large-athlete-name', false)
            ->assertDontSee('data-e-large-controls', false);
    }

    public function test_individual_a_tablet_contains_every_fig_artistry_penalty(): void
    {
        $performance = $this->performance();
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $judge = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);

        $this->actingAs($judge)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertSee('Соединения')
            ->assertSee('Ритм')
            ->assertSee('Площадка')
            ->assertSee('Прерывание непрерывности 4+ сек.')
            ->assertSee('Музыкальное вступление')
            ->assertSee('Музыкальные нормы')
            ->assertSee('Конец')
            ->assertSee('Экспрессия лица')
            ->assertSee("togglePenalty(0.3, 'faceExpr')", false)
            ->assertSee('togglePenalty(0.6', false)
            ->assertSee('logicVersion: 2', false)
            ->assertDontSee('Личные заметки судьи')
            ->assertSee('openFinalScoreNumpad()', false)
            ->assertSee('Вставить')
            ->assertSee("selectPenalty('bodyExpr', 0.6)", false)
            ->assertDontSee("selectPenalty('bodyExpr', 1", false)
            ->assertDontSee('Соответствие муз.характеру')
            ->assertDontSee('Музыкальная динамика')
            ->assertDontSee('Связь упражнения');
    }

    public function test_a_tablet_saves_full_categorized_history(): void
    {
        $performance = $this->performance();
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $judge = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);
        $entries = collect(range(1, 65))->map(fn (int $index) => [
            'v' => 0.1,
            'cat' => $index <= 20 ? 'connections' : 'rhythm',
            'label' => $index <= 20 ? 'Соединения' : 'Ритм',
            'counted' => true,
        ])->all();
        $this->actingAs($judge)
            ->postJson(route('judge.submit-score'), [
                'tournament_id' => $tournament->id,
                'score' => 6.0,
                'entries' => json_encode($entries, JSON_UNESCAPED_UNICODE),
            ])
            ->assertOk();

        $saved = JudgeScore::query()->where('performance_id', $performance->id)->where('judge_id', $judge->id)->firstOrFail();
        $this->assertCount(65, $saved->entries);
        $this->assertSame('connections', $saved->entries[0]['cat']);
    }

    public function test_returned_a_score_without_history_does_not_duplicate_automatic_deductions(): void
    {
        $performance = $this->performance();
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $judge = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);

        JudgeScore::create([
            'performance_id' => $performance->id,
            'judge_id' => $judge->id,
            'panel' => 'a',
            'score' => 8.2,
            'entries' => null,
            'submitted_at' => null,
        ]);

        $this->actingAs($judge)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertSee('hasInitial: true', false)
            ->assertSee('this.base - opts.initial', false)
            ->assertSee("this.panel === 'a'", false)
            ->assertSee('restored - this.comboPenalty()', false)
            ->assertSee('initial: 8.2', false);
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
            'inactive_judge_slots' => ['DB2', 'DA2', 'A2', 'A3', 'A4', 'E2', 'E3', 'E4', 'LINE1', 'LINE2', 'TIME', 'RESP'],
        ]);
        $db1 = User::factory()->create(['role' => 'judge_d_db', 'slot' => 'DB1']);
        $da1 = User::factory()->create(['role' => 'judge_d_da', 'slot' => 'DA1']);
        $a1 = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);
        $e1 = User::factory()->create(['role' => 'judge_e', 'slot' => 'E1']);

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
        JudgeScore::create([
            'performance_id' => $performance->id,
            'judge_id' => $a1->id,
            'panel' => 'a',
            'score' => 8.0,
            'submitted_at' => now(),
        ]);
        JudgeScore::create([
            'performance_id' => $performance->id,
            'judge_id' => $e1->id,
            'panel' => 'e',
            'score' => 7.0,
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

    public function test_judge_cannot_change_a_published_result_without_return_for_revision(): void
    {
        $performance = $this->performance();
        $performance->update([
            'status' => 'published',
            'finalized_at' => now(),
            'approved_at' => now(),
            'published_at' => now(),
        ]);
        $judge = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);

        $this->actingAs($judge)
            ->post(route('judge.score', $performance), ['score' => 8.5])
            ->assertStatus(422);

        $this->assertDatabaseMissing('judge_scores', [
            'performance_id' => $performance->id,
            'judge_id' => $judge->id,
        ]);
    }

    public function test_returned_score_stays_available_to_its_judge_after_queue_advanced(): void
    {
        $returnedPerformance = $this->performance();
        $category = $returnedPerformance->category;
        $tournament = $category->tournament;
        $tournament->update(['active_category_id' => $category->id]);
        $returnedPerformance->update([
            'status' => 'done',
            'finalized_at' => now(),
            'approved_at' => now(),
            'published_at' => now(),
        ]);
        $nextAthlete = Athlete::create(['last_name' => 'Петрова', 'first_name' => 'Елена']);
        Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $nextAthlete->id,
            'order_index' => 2,
            'status' => 'performing',
        ]);
        $judge = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);
        JudgeScore::create([
            'performance_id' => $returnedPerformance->id,
            'judge_id' => $judge->id,
            'panel' => 'a',
            'score' => 8.2,
            'submitted_at' => now(),
        ]);
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary)
            ->post(route('secretary.performance.returnScores', $returnedPerformance), ['slot' => 'A1'])
            ->assertRedirect();

        $this->actingAs($judge)
            ->getJson(route('judge.tournament.tablet.ping', $tournament))
            ->assertOk()
            ->assertJsonPath('performance_id', $returnedPerformance->id)
            ->assertJsonPath('score_submitted', false);
    }

    public function test_tablet_uses_the_session_selected_by_the_secretary(): void
    {
        $first = $this->performance();
        $category = $first->category;
        $tournament = $category->tournament;
        $sessionOne = StreamSession::create([
            'category_id' => $category->id,
            'session_no' => 1,
            'scheduled_on' => '2026-08-18',
            'apparatus' => ['Мяч'],
        ]);
        $sessionTwo = StreamSession::create([
            'category_id' => $category->id,
            'session_no' => 2,
            'scheduled_on' => '2026-08-19',
            'apparatus' => ['Обруч'],
        ]);
        $first->update(['stream_session_id' => $sessionOne->id]);
        $secondAthlete = Athlete::create(['last_name' => 'Second', 'first_name' => 'Athlete']);
        $second = Performance::create([
            'category_id' => $category->id,
            'stream_session_id' => $sessionTwo->id,
            'athlete_id' => $secondAthlete->id,
            'order_index' => 2,
            'status' => 'performing',
        ]);
        $tournament->update([
            'active_category_id' => $category->id,
            'active_stream_session_id' => $sessionTwo->id,
        ]);
        $judge = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);

        $this->actingAs($judge)
            ->getJson(route('judge.tournament.tablet.ping', $tournament))
            ->assertOk()
            ->assertJsonPath('performance_id', $second->id);

        $this->actingAs($judge)
            ->post(route('judge.score', $first), ['score' => 8.0])
            ->assertStatus(422);

        $this->actingAs($judge)
            ->postJson(route('judge.performance.live-actions', $first), ['action' => 'draft'])
            ->assertStatus(422);
    }

    public function test_inactive_judge_slot_cannot_submit_a_score(): void
    {
        $performance = $this->performance();
        $category = $performance->category;
        $category->update(['inactive_judge_slots' => ['A1', 'TIME']]);
        $tournament = $category->tournament;
        $tournament->update(['active_category_id' => $category->id]);
        $judge = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);

        $this->actingAs($judge)
            ->postJson(route('judge.submit-score'), [
                'tournament_id' => $tournament->id,
                'score' => 8.0,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('judge_scores', [
            'performance_id' => $performance->id,
            'judge_id' => $judge->id,
        ]);

        $timeJudge = User::factory()->create(['role' => 'time_judge', 'slot' => 'TIME']);
        $this->actingAs($timeJudge)
            ->postJson(route('judge.tournament.timer', $tournament), [
                'action' => 'start',
                'performance_id' => $performance->id,
            ])
            ->assertStatus(422);
    }

    public function test_legacy_auto_advance_never_starts_a_performance_from_a_session(): void
    {
        $current = $this->performance();
        $category = $current->category;
        $session = StreamSession::create([
            'category_id' => $category->id,
            'session_no' => 1,
            'scheduled_on' => '2026-08-18',
            'apparatus' => ['Мяч'],
        ]);
        $legacyAthlete = Athlete::create(['last_name' => 'Legacy', 'first_name' => 'Next']);
        $legacyNext = Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $legacyAthlete->id,
            'order_index' => 2,
            'status' => 'scheduled',
        ]);
        $sessionAthlete = Athlete::create(['last_name' => 'Session', 'first_name' => 'Next']);
        $sessionNext = Performance::create([
            'category_id' => $category->id,
            'stream_session_id' => $session->id,
            'athlete_id' => $sessionAthlete->id,
            'order_index' => 1,
            'status' => 'scheduled',
        ]);

        $this->assertTrue(StreamAdvanceService::advanceToNextInCategory($category, null));
        $this->assertSame('done', $current->fresh()->status);
        $this->assertSame('performing', $legacyNext->fresh()->status);
        $this->assertSame('scheduled', $sessionNext->fresh()->status);
    }

    public function test_manual_queue_addition_is_assigned_to_the_open_session(): void
    {
        $existing = $this->performance();
        $existing->update(['status' => 'scheduled']);
        $category = $existing->category;
        $session = StreamSession::create([
            'category_id' => $category->id,
            'session_no' => 1,
            'scheduled_on' => '2026-08-18',
            'apparatus' => ['Мяч'],
        ]);
        $existing->update(['stream_session_id' => $session->id, 'order_index' => 1]);
        $athlete = Athlete::create(['last_name' => 'Added', 'first_name' => 'Athlete']);
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary)
            ->post(route('secretary.queue.add', $category), [
                'athlete_id' => $athlete->id,
                'apparatus' => 'Мяч',
                'stream_session_id' => $session->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('performances', [
            'category_id' => $category->id,
            'stream_session_id' => $session->id,
            'athlete_id' => $athlete->id,
            'order_index' => 2,
            'status' => 'scheduled',
        ]);
    }

    public function test_scheduled_performance_is_not_exposed_as_current_to_judges(): void
    {
        $performance = $this->performance();
        $performance->update(['status' => 'scheduled']);
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $judge = User::factory()->create(['role' => 'judge_e', 'slot' => 'E1']);

        $this->actingAs($judge)
            ->getJson(route('judge.tournament.tablet.ping', $tournament))
            ->assertOk()
            ->assertJsonPath('performance_id', null);
    }

    public function test_secretary_can_return_official_time_for_revision(): void
    {
        Carbon::setTestNow('2026-08-18 10:00:00');
        $performance = $this->performance();
        $performance->update([
            'status' => 'done',
            'timer_started_at' => now()->subSeconds(90),
            'timer_ended_at' => now(),
            'actual_duration_seconds' => 90,
            'finalized_at' => now(),
            'approved_at' => now(),
        ]);
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary)
            ->post(route('secretary.performance.returnScores', $performance), ['slot' => 'TIME'])
            ->assertRedirect();

        $this->assertNotNull($performance->fresh()->timer_revision_requested_at);
        $timeJudge = User::factory()->create(['role' => 'time_judge', 'slot' => 'TIME']);
        $this->actingAs($timeJudge)
            ->getJson(route('judge.tournament.tablet.ping', $tournament))
            ->assertJsonPath('performance_id', $performance->id);

        $this->actingAs($timeJudge)
            ->postJson(route('judge.tournament.timer', $tournament), [
                'action' => 'start',
                'performance_id' => $performance->id,
            ])
            ->assertOk();
        Carbon::setTestNow('2026-08-18 10:01:31');
        $this->actingAs($timeJudge)
            ->postJson(route('judge.tournament.timer', $tournament), [
                'action' => 'stop',
                'performance_id' => $performance->id,
            ])
            ->assertOk()
            ->assertJsonPath('duration_seconds', 91);

        $saved = $performance->fresh();
        $this->assertNull($saved->timer_revision_requested_at);
        $this->assertSame(91, $saved->actual_duration_seconds);
    }

    public function test_tablet_revision_changes_after_secretary_edits_a_score(): void
    {
        $performance = $this->performance();
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $judge = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);
        JudgeScore::create([
            'performance_id' => $performance->id,
            'judge_id' => $judge->id,
            'panel' => 'a',
            'score' => 8.0,
            'submitted_at' => now(),
        ]);

        $before = $this->actingAs($judge)
            ->getJson(route('judge.tournament.tablet.ping', $tournament))
            ->json('rev');
        $secretary = User::factory()->create(['role' => 'secretary']);
        $this->actingAs($secretary)
            ->post(route('secretary.performance.updateJudgeScore', $performance), [
                'slot' => 'A1',
                'score' => 8.5,
            ])
            ->assertRedirect();
        $after = $this->actingAs($judge)
            ->getJson(route('judge.tournament.tablet.ping', $tournament))
            ->json('rev');

        $this->assertNotSame($before, $after);
    }

    public function test_score_from_another_panel_does_not_reset_current_judge_draft(): void
    {
        $performance = $this->performance();
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $aJudge = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);
        $dbJudge = User::factory()->create(['role' => 'judge_d_db', 'slot' => 'DB2']);

        $aRevisionBefore = $this->actingAs($aJudge)
            ->getJson(route('judge.tournament.tablet.ping', $tournament))
            ->assertOk()
            ->json('rev');
        $dbRevisionBefore = $this->actingAs($dbJudge)
            ->getJson(route('judge.tournament.tablet.ping', $tournament))
            ->assertOk()
            ->json('rev');

        $this->actingAs($dbJudge)
            ->postJson(route('judge.submit-score'), [
                'tournament_id' => $tournament->id,
                'score' => 4.2,
            ])
            ->assertOk();

        $aPingAfter = $this->actingAs($aJudge)
            ->getJson(route('judge.tournament.tablet.ping', $tournament))
            ->assertOk();
        $dbRevisionAfter = $this->actingAs($dbJudge)
            ->getJson(route('judge.tournament.tablet.ping', $tournament))
            ->assertOk()
            ->json('rev');

        $this->assertSame($performance->id, $aPingAfter->json('performance_id'));
        $this->assertSame($aRevisionBefore, $aPingAfter->json('rev'));
        $this->assertNotSame($dbRevisionBefore, $dbRevisionAfter);
    }

    public function test_secretary_queue_revision_changes_for_scores_but_not_draft_actions(): void
    {
        $performance = $this->performance();
        $secretary = User::factory()->create(['role' => 'secretary']);
        $dbJudge = User::factory()->create(['role' => 'judge_d_db', 'slot' => 'DB1']);
        $lineJudge = User::factory()->create(['role' => 'line_judge', 'slot' => 'LINE1']);
        $pingUrl = route('secretary.queue.ping', $performance->category);

        $initialRevision = $this->actingAs($secretary)
            ->getJson($pingUrl)
            ->assertOk()
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->json('rev');

        $dbScore = JudgeScore::create([
            'performance_id' => $performance->id,
            'judge_id' => $dbJudge->id,
            'panel' => 'd',
            'subpanel' => 'db',
            'score' => 7.5,
            'submitted_at' => now(),
        ]);
        $scoreRevision = $this->actingAs($secretary)->getJson($pingUrl)->json('rev');

        $dbScore->update([
            'average_score' => 7.4,
            'average_submitted_at' => now(),
        ]);
        $averageRevision = $this->actingAs($secretary)->getJson($pingUrl)->json('rev');

        JudgeScore::create([
            'performance_id' => $performance->id,
            'judge_id' => $lineJudge->id,
            'panel' => 'penalty',
            'penalty_type' => 'line',
            'score' => 0.3,
            'submitted_at' => now(),
        ]);
        $penaltyRevision = $this->actingAs($secretary)->getJson($pingUrl)->json('rev');

        JudgeScoreAction::create([
            'performance_id' => $performance->id,
            'judge_id' => $dbJudge->id,
            'slot' => 'DB1',
            'panel' => 'd',
            'subpanel' => 'db',
            'action' => 'add',
            'draft_score' => 7.6,
        ]);
        $liveActionRevision = $this->actingAs($secretary)->getJson($pingUrl)->json('rev');

        $this->assertNotSame($initialRevision, $scoreRevision);
        $this->assertNotSame($scoreRevision, $averageRevision);
        $this->assertNotSame($averageRevision, $penaltyRevision);
        $this->assertSame($penaltyRevision, $liveActionRevision);
    }

    public function test_stream_history_lists_every_judge_score_and_keeps_controls_inside_async_page(): void
    {
        $first = $this->performance();
        $category = $first->category;
        $category->update(['inactive_judge_slots' => ['DB1']]);
        $secondAthlete = Athlete::create(['last_name' => 'Петрова', 'first_name' => 'Елена']);
        $second = Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $secondAthlete->id,
            'status' => 'done',
            'order_index' => 2,
        ]);
        $db1 = User::factory()->create(['role' => 'judge_d_db', 'slot' => 'DB1']);
        $a1 = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);
        JudgeScore::create([
            'performance_id' => $first->id,
            'judge_id' => $db1->id,
            'panel' => 'd',
            'subpanel' => 'db',
            'score' => 3.125,
            'submitted_at' => now(),
        ]);
        JudgeScore::create([
            'performance_id' => $second->id,
            'judge_id' => $a1->id,
            'panel' => 'a',
            'score' => 8.375,
            'submitted_at' => now(),
        ]);
        $secretary = User::factory()->create(['role' => 'secretary']);

        $response = $this->actingAs($secretary)->get(route('secretary.queue', $category));

        $response->assertOk()
            ->assertSee('DB1')
            ->assertSee('DA2')
            ->assertSee('A1')
            ->assertSee('3.125')
            ->assertSee('8.375')
            ->assertSee('data-stream-history-score', false)
            ->assertSee('data-stream-history-layout="responsive"', false)
            ->assertSee('grid-cols-4', false)
            ->assertDontSee('min-w-[2260px]', false)
            ->assertSee('data-performance-id="'.$first->id.'"', false)
            ->assertSee('data-performance-id="'.$second->id.'"', false);
        $this->assertMatchesRegularExpression(
            '/const toggleUrl.*<\/div>\s*<\/body>/s',
            $response->getContent(),
            'Обработчик отключения судей должен повторно запускаться внутри асинхронно заменяемой области.',
        );

        $this->actingAs($secretary)
            ->post(route('secretary.performance.updateJudgeScore', $first), [
                'slot' => 'DB1',
                'score' => 3.333,
            ])
            ->assertRedirect();
        $this->assertEqualsWithDelta(3.333, (float) JudgeScore::query()->where('performance_id', $first->id)->value('score'), 0.0005);
    }

    public function test_secretary_can_return_to_previous_participant_without_reordering_queue(): void
    {
        $first = $this->performance();
        $category = $first->category;
        $first->update([
            'start_number' => 11,
            'order_index' => 1,
        ]);
        $secondAthlete = Athlete::create(['last_name' => 'Петрова', 'first_name' => 'Елена']);
        $second = Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $secondAthlete->id,
            'status' => 'scheduled',
            'start_number' => 12,
            'order_index' => 2,
        ]);
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->assertTrue(StreamAdvanceService::advanceToNextInCategory($category));

        $this->actingAs($secretary)
            ->post(route('secretary.start', $first), ['return_previous' => 1])
            ->assertRedirect()
            ->assertSessionHas('status', 'Возвращена предыдущая гимнастка. Порядок выступления не изменён.');

        $this->assertSame('performing', $first->fresh()->status);
        $this->assertNull($first->fresh()->ended_at);
        $this->assertSame('scheduled', $second->fresh()->status);
        $this->assertNull($second->fresh()->called_at);
        $this->assertNull($second->fresh()->started_at);
        $this->assertSame(1, $first->fresh()->order_index);
        $this->assertSame(2, $second->fresh()->order_index);
        $this->assertSame(11, $first->fresh()->start_number);
        $this->assertSame(12, $second->fresh()->start_number);
    }

    public function test_return_to_previous_is_blocked_after_the_new_current_participant_has_activity(): void
    {
        $first = $this->performance();
        $first->update(['order_index' => 1]);
        $category = $first->category;
        $secondAthlete = Athlete::create(['last_name' => 'Орлова', 'first_name' => 'Ирина']);
        $second = Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $secondAthlete->id,
            'status' => 'scheduled',
            'order_index' => 2,
        ]);
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->assertTrue(StreamAdvanceService::advanceToNextInCategory($category));
        $second->update(['timer_started_at' => now()]);

        $this->actingAs($secretary)
            ->post(route('secretary.start', $first), ['return_previous' => 1])
            ->assertRedirect()
            ->assertSessionHasErrors('start');

        $this->assertSame('done', $first->fresh()->status);
        $this->assertSame('performing', $second->fresh()->status);
        $this->assertSame(1, $first->fresh()->order_index);
        $this->assertSame(2, $second->fresh()->order_index);
    }

    public function test_live_queue_renders_previous_participant_button_after_advancing(): void
    {
        $first = $this->performance();
        $first->update(['order_index' => 1]);
        $category = $first->category;
        $secondAthlete = Athlete::create(['last_name' => 'Сидорова', 'first_name' => 'Мария']);
        Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $secondAthlete->id,
            'status' => 'scheduled',
            'order_index' => 2,
        ]);
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->assertTrue(StreamAdvanceService::advanceToNextInCategory($category));

        $this->actingAs($secretary)
            ->get(route('secretary.queue', $category))
            ->assertOk()
            ->assertSee('Предыдущая гимнастка')
            ->assertSee('name="return_previous" value="1"', false)
            ->assertSee(route('secretary.start', $first), false)
            ->assertDontSee('В Live');
    }

    public function test_superior_jury_is_not_treated_as_a_tablet_judge(): void
    {
        $jury = User::factory()->create(['role' => 'superior_jury']);

        $this->assertFalse($jury->isAnyJudge());
        $this->assertNull($jury->judgePanel());
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
