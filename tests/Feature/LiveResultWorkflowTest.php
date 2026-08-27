<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\Group;
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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class LiveResultWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_difficulty_average_accounts_are_created_by_migration(): void
    {
        $dbAverage = User::query()->where('email', 'db-average@local.test')->firstOrFail();
        $daAverage = User::query()->where('email', 'da-average@local.test')->firstOrFail();

        $this->assertSame('judge_db_average', $dbAverage->role);
        $this->assertSame('DB_AVG', $dbAverage->slot);
        $this->assertSame('judge_da_average', $daAverage->role);
        $this->assertSame('DA_AVG', $daAverage->slot);
        $this->assertTrue(Hash::check('password', $dbAverage->password));
        $this->assertTrue(Hash::check('password', $daAverage->password));
        $this->actingAs($dbAverage)->get(route('judge.tournaments'))->assertOk();
        $this->actingAs($daAverage)->get(route('judge.tournaments'))->assertOk();
    }

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
            ->assertSee('История гимнасток потока')
            ->assertSee('Вернуть панель целиком')
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
            ->assertSee('oneTimeCreditCats: opts.groupProgram', false)
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
            ->assertSee('senior: { elements: 9, dbMax: 5, deMax: 5, dbMin: 4, deMin: 4 }', false)
            ->assertSee('if (! a.notDone)', false);

        $daJudge = User::factory()->create(['role' => 'judge_d_da', 'slot' => 'DA1']);
        $this->actingAs($daJudge)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertSee('DC:')
            ->assertSee("if (sym === 'CC') cc += 1;", false)
            ->assertSee("else if (sym === 'CR') cr += 1;", false)
            ->assertSee('else if (this.isDcMulti(sym)) multi += 1;', false);
    }

    public function test_individual_db_risks_do_not_use_regular_element_slots(): void
    {
        $performance = $this->performance();
        $performance->load('category.tournament');
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);

        $dbJudge = User::factory()->create(['role' => 'judge_d_db', 'slot' => 'DB1']);

        $this->actingAs($dbJudge)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertSee('junior: { elements: 6, risks: 3 }', false)
            ->assertSee('senior: { elements: 8, risks: 4 }', false)
            ->assertSee('if (risks >= lim.risks) continue;', false)
            ->assertSee('} else if (used >= lim.elements) {', false)
            ->assertSee("if (isRisk) {\n                            risks += 1;\n                        } else {\n                            used += 1;", false)
            ->assertDontSee('if (used >= lim.elements) break;', false);
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
                ->assertSee('Math.min(this.deductionLimit, total)', false);

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
            ->assertSee('data-judge-fullscreen', false)
            ->assertSee('Весь экран')
            ->assertSee('data-e-large-athlete-name', false)
            ->assertSee('data-e-large-controls', false)
            ->assertSee('text-7xl md:text-8xl xl:text-9xl', false)
            ->assertSee('min-h-20', false);

        $aJudge = User::query()->where('role', 'judge_a')->firstOrFail();
        $this->actingAs($aJudge)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertDontSee('data-e-large-athlete-name', false)
            ->assertDontSee('data-e-large-controls', false)
            ->assertSee('height: ((cat.dance / catMax.dance) * 100)', false)
            ->assertSee('height: ((cat.dynamic / catMax.dynamic) * 100)', false);
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

    public function test_individual_db_and_da_scores_do_not_create_official_averages(): void
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

        $this->assertNull($performance->fresh()->db_average);
        $this->assertNull($performance->fresh()->da_average);
        $this->assertNull($performance->fresh()->d_score);
    }

    public function test_independent_db_average_tablet_sets_the_official_db_score(): void
    {
        $performance = $this->performance();
        $performance->load('category.tournament');
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $db1 = User::factory()->create(['role' => 'judge_d_db', 'slot' => 'DB1']);
        $dbAverage = User::factory()->create(['role' => 'judge_db_average', 'slot' => 'DB_AVG']);

        $this->actingAs($db1)
            ->postJson(route('judge.submit-score'), [
                'tournament_id' => $tournament->id,
                'score' => 4.2,
            ])
            ->assertOk();

        $this->actingAs($dbAverage)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertSee('Введите среднюю DB')
            ->assertSee('сразу станет официальной оценкой DB');

        $this->actingAs($dbAverage)
            ->postJson(route('judge.submit-average'), [
                'tournament_id' => $tournament->id,
                'average_score' => 4.125,
            ])
            ->assertOk()
            ->assertJsonPath('average_score', 4.125);
        $this->actingAs($dbAverage)
            ->postJson(route('judge.submit-average'), [
                'tournament_id' => $tournament->id,
                'average_score' => 9.9,
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('judge_scores', [
            'performance_id' => $performance->id,
            'judge_id' => $dbAverage->id,
            'average_score' => 4.125,
        ]);
        $this->assertDatabaseHas('judge_scores', [
            'performance_id' => $performance->id,
            'judge_id' => $db1->id,
            'score' => 4.2,
            'average_score' => null,
        ]);
        $this->actingAs($dbAverage)
            ->getJson(route('judge.tournament.tablet.ping', $tournament))
            ->assertOk()
            ->assertJsonPath('score_submitted', false)
            ->assertJsonPath('average_submitted', true);

        $secretary = User::factory()->create(['role' => 'secretary']);
        $this->actingAs($secretary)
            ->get(route('secretary.queue', $performance->category))
            ->assertOk()
            ->assertSee('DB')
            ->assertSee('4.125');
    }

    public function test_individual_db_and_da_scores_do_not_reset_official_averages(): void
    {
        $performance = $this->performance();
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);

        foreach ([
            ['judge_db_average', 'DB_AVG', 4.1],
            ['judge_da_average', 'DA_AVG', 3.3],
        ] as [$role, $slot, $average]) {
            $averageJudge = User::factory()->create(['role' => $role, 'slot' => $slot]);
            $this->actingAs($averageJudge)
                ->postJson(route('judge.submit-average'), [
                    'tournament_id' => $tournament->id,
                    'average_score' => $average,
                ])
                ->assertOk();
        }

        foreach ([
            ['judge_d_db', 'DB1', 4.2, 'DB_AVG', 4.1],
            ['judge_d_db', 'DB2', 4.4, 'DB_AVG', 4.1],
            ['judge_d_da', 'DA1', 3.4, 'DA_AVG', 3.3],
            ['judge_d_da', 'DA2', 3.6, 'DA_AVG', 3.3],
        ] as [$role, $slot, $score, $averageSlot, $expectedAverage]) {
            $secondJudge = User::factory()->create(['role' => $role, 'slot' => $slot]);

            $this->actingAs($secondJudge)
                ->postJson(route('judge.submit-score'), [
                    'tournament_id' => $tournament->id,
                    'score' => $score,
                ])
                ->assertOk();

            $averageScore = SecretaryLiveUi::difficultyAverageRows(
                $performance->fresh()->load(['judgeScores.judge', 'category']),
            )[$averageSlot];

            $this->assertEqualsWithDelta($expectedAverage, (float) $averageScore->average_score, 0.0005);
            $this->assertNotNull($averageScore->average_submitted_at);
        }
    }

    public function test_average_tablets_work_when_all_individual_db_and_da_judges_are_disabled(): void
    {
        $performance = $this->performance();
        $category = $performance->category;
        $category->update([
            'inactive_judge_slots' => ['DB1', 'DB2', 'DA1', 'DA2', 'A2', 'A3', 'A4', 'E2', 'E3', 'E4', 'LINE1', 'LINE2', 'TIME', 'RESP'],
        ]);
        $tournament = $category->tournament;
        $tournament->update(['active_category_id' => $category->id]);
        $dbAverage = User::factory()->create(['role' => 'judge_db_average', 'slot' => 'DB_AVG']);
        $daAverage = User::factory()->create(['role' => 'judge_da_average', 'slot' => 'DA_AVG']);

        $this->actingAs($dbAverage)->postJson(route('judge.submit-average'), [
            'tournament_id' => $tournament->id,
            'average_score' => 4.6,
        ])->assertOk();
        $this->actingAs($daAverage)->postJson(route('judge.submit-average'), [
            'tournament_id' => $tournament->id,
            'average_score' => 3.2,
        ])->assertOk();

        foreach ([['judge_a', 'A1', 'a', 8.0], ['judge_e', 'E1', 'e', 7.0]] as [$role, $slot, $panel, $score]) {
            $judge = User::factory()->create(['role' => $role, 'slot' => $slot]);
            $this->actingAs($judge)->postJson(route('judge.submit-score'), [
                'tournament_id' => $tournament->id,
                'score' => $score,
            ])->assertOk();
        }

        $performance->refresh()->load(['judgeScores.judge', 'category']);
        $performance->recalculateTotals();

        $this->assertSame(4.6, (float) $performance->db_average);
        $this->assertSame(3.2, (float) $performance->da_average);
        $this->assertSame(7.8, (float) $performance->d_score);
        $this->assertTrue(SecretaryLiveUi::readyToFinalize($performance, $category));
    }

    public function test_average_tablet_does_not_offer_panel_return(): void
    {
        $performance = $this->performance();
        $category = $performance->category;
        $tournament = $category->tournament;
        $tournament->update(['active_category_id' => $category->id]);
        $dbAverage = User::factory()->create(['role' => 'judge_db_average', 'slot' => 'DB_AVG']);

        $this->actingAs($dbAverage)->postJson(route('judge.submit-average'), [
            'tournament_id' => $tournament->id,
            'average_score' => 4.3,
        ])->assertOk();

        $this->actingAs($dbAverage)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertDontSee('Вернуть всю бригаду');

        $this->assertFalse(Route::has('judge.return-difficulty-panel'));
    }

    public function test_independent_db_and_da_averages_are_official_and_required_for_auto_advance(): void
    {
        $performance = $this->performance();
        $performance->category->update([
            'inactive_judge_slots' => ['DB2', 'DA2', 'A2', 'A3', 'A4', 'E2', 'E3', 'E4', 'LINE1', 'LINE2', 'TIME', 'RESP'],
        ]);
        $db1 = User::factory()->create(['role' => 'judge_d_db', 'slot' => 'DB1']);
        $da1 = User::factory()->create(['role' => 'judge_d_da', 'slot' => 'DA1']);
        $dbAverageJudge = User::factory()->create(['role' => 'judge_db_average', 'slot' => 'DB_AVG']);
        $daAverageJudge = User::factory()->create(['role' => 'judge_da_average', 'slot' => 'DA_AVG']);
        $a1 = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);
        $e1 = User::factory()->create(['role' => 'judge_e', 'slot' => 'E1']);

        JudgeScore::create([
            'performance_id' => $performance->id,
            'judge_id' => $db1->id,
            'panel' => 'd',
            'subpanel' => 'db',
            'score' => 4.2,
            'submitted_at' => now(),
        ]);
        JudgeScore::create([
            'performance_id' => $performance->id,
            'judge_id' => $da1->id,
            'panel' => 'd',
            'subpanel' => 'da',
            'score' => 3.4,
            'submitted_at' => now(),
        ]);
        $dbAverage = JudgeScore::create([
            'performance_id' => $performance->id,
            'judge_id' => $dbAverageJudge->id,
            'panel' => 'd',
            'subpanel' => 'db',
            'average_score' => 4.0,
            'average_submitted_at' => now(),
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
        $this->assertSame(4.0, (float) $performance->db_average);
        $this->assertNull($performance->da_average);
        $this->assertNull($performance->d_score);
        $this->assertFalse(SecretaryLiveUi::readyToFinalize($performance, $performance->category));

        JudgeScore::create([
            'performance_id' => $performance->id,
            'judge_id' => $daAverageJudge->id,
            'panel' => 'd',
            'subpanel' => 'da',
            'average_score' => 3.1,
            'average_submitted_at' => now(),
        ]);
        $performance->refresh()->load(['judgeScores.judge', 'category']);
        $performance->recalculateTotals();

        $this->assertSame(7.1, (float) $performance->d_score);
        $this->assertTrue(SecretaryLiveUi::readyToFinalize($performance, $performance->category));
        $this->assertNotNull($dbAverage->fresh()->average_submitted_at);
    }

    public function test_panel_spread_warning_does_not_block_auto_advance(): void
    {
        $performance = $this->performance();
        $category = $performance->category;
        $category->update([
            'auto_advance' => true,
            'inactive_judge_slots' => ['DB2', 'DA2', 'A3', 'A4', 'E2', 'E3', 'E4', 'LINE1', 'LINE2', 'TIME', 'RESP'],
        ]);
        $tournament = $category->tournament;
        $tournament->update(['active_category_id' => $category->id]);

        foreach ([
            ['judge_d_db', 'DB1', 'd', 'db', 4.2],
            ['judge_d_da', 'DA1', 'd', 'da', 3.4],
            ['judge_a', 'A1', 'a', null, 9.0],
            ['judge_e', 'E1', 'e', null, 7.0],
        ] as [$role, $slot, $panel, $subpanel, $score]) {
            $judge = User::factory()->create(['role' => $role, 'slot' => $slot]);
            JudgeScore::create([
                'performance_id' => $performance->id,
                'judge_id' => $judge->id,
                'panel' => $panel,
                'subpanel' => $subpanel,
                'score' => $score,
                'submitted_at' => now(),
            ]);
        }
        foreach ([
            ['judge_db_average', 'DB_AVG', 'db', 4.1],
            ['judge_da_average', 'DA_AVG', 'da', 3.3],
        ] as [$role, $slot, $subpanel, $average]) {
            $judge = User::factory()->create(['role' => $role, 'slot' => $slot]);
            JudgeScore::create([
                'performance_id' => $performance->id,
                'judge_id' => $judge->id,
                'panel' => 'd',
                'subpanel' => $subpanel,
                'average_score' => $average,
                'average_submitted_at' => now(),
            ]);
        }

        $nextAthlete = Athlete::create(['last_name' => 'Следующая', 'first_name' => 'Гимнастка']);
        $next = Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $nextAthlete->id,
            'status' => 'scheduled',
            'order_index' => 2,
        ]);
        $a2 = User::factory()->create(['role' => 'judge_a', 'slot' => 'A2']);

        $this->actingAs($a2)
            ->postJson(route('judge.submit-score'), [
                'tournament_id' => $tournament->id,
                'score' => 7.5,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Разброс > 1')
                && str_contains($message, 'Автопереход: вызвана следующая гимнастка.'));

        $this->assertSame('done', $performance->fresh()->status);
        $this->assertSame('performing', $next->fresh()->status);
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

    public function test_secretary_can_poll_live_actions_for_one_specific_judge_slot(): void
    {
        $performance = $this->performance();
        $a1 = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);
        $e1 = User::factory()->create(['role' => 'judge_e', 'slot' => 'E1']);
        JudgeScoreAction::create([
            'performance_id' => $performance->id,
            'judge_id' => $a1->id,
            'slot' => 'A1',
            'panel' => 'a',
            'action' => 'Добавлена сбавка 0.3',
            'draft_score' => 0.3,
            'entries' => [['v' => 0.3, 'label' => 'Техника', 'counted' => true]],
        ]);
        JudgeScoreAction::create([
            'performance_id' => $performance->id,
            'judge_id' => $e1->id,
            'slot' => 'E1',
            'panel' => 'e',
            'action' => 'Чужое действие',
            'draft_score' => 0.5,
        ]);
        JudgeScoreAction::create([
            'performance_id' => $performance->id,
            'judge_id' => $a1->id,
            'slot' => 'A1',
            'panel' => 'a',
            'action' => 'Выбран элемент: Прыжок',
            'draft_score' => 0,
        ]);
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary)
            ->getJson(route('secretary.performance.scoreLiveHistory', $performance).'?slot=A1')
            ->assertOk()
            ->assertJsonPath('slot', 'A1')
            ->assertJsonPath('actions.0.judge', $a1->name)
            ->assertJsonPath('actions.0.draft_score', '0.300')
            ->assertJsonPath('actions.0.entries.0.label', 'Техника')
            ->assertJsonCount(1, 'actions');
    }

    public function test_stream_history_opens_live_view_even_before_final_score_arrives(): void
    {
        $performance = $this->performance();
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary)
            ->get(route('secretary.queue', $performance->category))
            ->assertOk()
            ->assertSee('data-stream-history-score', false)
            ->assertSee('data-slot="A1"', false)
            ->assertSee('score-live-history')
            ->assertSee('обновление каждую секунду');
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

    public function test_stream_history_keeps_spread_warning_after_auto_advance_is_allowed(): void
    {
        $performance = $this->performance();
        $category = $performance->category;
        $category->update([
            'inactive_judge_slots' => ['A3', 'A4', 'LINE1', 'LINE2', 'TIME', 'RESP'],
        ]);

        foreach ([['A1', 9.0], ['A2', 7.5]] as [$slot, $score]) {
            $judge = User::factory()->create(['role' => 'judge_a', 'slot' => $slot]);
            JudgeScore::create([
                'performance_id' => $performance->id,
                'judge_id' => $judge->id,
                'panel' => 'a',
                'score' => $score,
                'submitted_at' => now(),
            ]);
        }

        $secretary = User::factory()->create(['role' => 'secretary']);
        $this->actingAs($secretary)
            ->get(route('secretary.queue', $category))
            ->assertOk()
            ->assertDontSee('data-stream-history-spread-warning', false)
            ->assertSee('border-rose-500 bg-rose-900/75', false)
            ->assertSee('A1')
            ->assertSee('A2');
    }

    public function test_read_only_stream_review_does_not_replace_the_active_live_queue(): void
    {
        $first = $this->performance();
        $tournament = $first->category->tournament;
        $second = Category::create([
            'tournament_id' => $tournament->id,
            'name' => 'Просматриваемый поток',
            'program' => 'individual',
        ]);
        $tournament->update(['active_category_id' => $first->category_id]);
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary)
            ->get(route('secretary.queue.review', $second))
            ->assertOk()
            ->assertSee('Независимый просмотр')
            ->assertSee('не меняет активный поток');

        $this->assertSame($first->category_id, $tournament->fresh()->active_category_id);
    }

    public function test_stream_review_shows_pool_places_and_downloads_them_to_excel(): void
    {
        $first = $this->performance();
        $first->athlete->update(['last_name' => 'Лидер', 'first_name' => 'Алина']);
        $first->update([
            'status' => 'done',
            'apparatus' => 'Мяч',
            'start_number' => 11,
            'total' => 18.75,
            'e_score' => 8.5,
            'a_score' => 8.0,
            'is_counted' => true,
        ]);
        $secondAthlete = Athlete::create(['last_name' => 'Вторая', 'first_name' => 'Диана']);
        Performance::create([
            'category_id' => $first->category_id,
            'athlete_id' => $secondAthlete->id,
            'status' => 'done',
            'apparatus' => 'Мяч',
            'start_number' => 12,
            'order_index' => 2,
            'total' => 17.5,
            'e_score' => 8.0,
            'a_score' => 7.5,
            'is_counted' => true,
        ]);
        foreach (range(3, 20) as $position) {
            $athlete = Athlete::create(['last_name' => 'Участница', 'first_name' => (string) $position]);
            Performance::create([
                'category_id' => $first->category_id,
                'athlete_id' => $athlete->id,
                'status' => 'scheduled',
                'apparatus' => 'Мяч',
                'start_number' => 10 + $position,
                'order_index' => $position,
            ]);
        }
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary)
            ->get(route('secretary.queue.review', $first->category))
            ->assertOk()
            ->assertSee('Скачать Excel')
            ->assertSee('Место 1/20')
            ->assertSee('Место 2/20');

        $this->actingAs($secretary)
            ->get(route('secretary.queue', $first->category))
            ->assertOk()
            ->assertSee('Место')
            ->assertSee('>1/20</div>', false)
            ->assertSee('>2/20</div>', false);

        $response = $this->actingAs($secretary)
            ->get(route('secretary.queue.review.excel', $first->category));
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'stream_review_').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::createReader('Xlsx')->load($tmp)->getActiveSheet();
        $this->assertSame(['№', 'ФИО гимнастки', 'Предмет', 'Оценка', 'Место'], $sheet->rangeToArray('A5:E5')[0]);
        $this->assertSame('Лидер Алина', $sheet->getCell('B6')->getValue());
        $this->assertEqualsWithDelta(18.75, (float) $sheet->getCell('D6')->getValue(), 0.0005);
        $this->assertSame('1/20', $sheet->getCell('E6')->getValue());
        $this->assertSame('Вторая Диана', $sheet->getCell('B7')->getValue());
        $this->assertSame('2/20', $sheet->getCell('E7')->getValue());
        $this->assertSame('0.000', $sheet->getStyle('D6')->getNumberFormat()->getFormatCode());
        @unlink($tmp);
    }

    public function test_disabled_judge_slots_are_saved_for_the_whole_tournament(): void
    {
        $first = $this->performance();
        $tournament = $first->category->tournament;
        $second = Category::create([
            'tournament_id' => $tournament->id,
            'name' => 'Второй поток',
            'program' => 'individual',
        ]);
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary)
            ->postJson(route('secretary.category.judgeSlot.toggle', $first->category), [
                'slot' => 'E4',
                'active' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('active', false);

        $this->assertContains('E4', $tournament->fresh()->inactiveJudgeSlotList());
        $this->assertFalse($second->fresh()->isJudgeSlotActive('E4'));

        $this->actingAs($secretary)
            ->postJson(route('secretary.category.judgeSlot.toggle', $second), [
                'slot' => 'E4',
                'active' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('active', true);

        $this->assertTrue($first->category->fresh()->isJudgeSlotActive('E4'));
    }

    public function test_auto_advance_switch_controls_the_selected_stream(): void
    {
        $performance = $this->performance();
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary)
            ->post(route('secretary.category.autoAdvance', $performance->category), ['enabled' => 1])
            ->assertRedirect();
        $this->assertTrue($performance->category->fresh()->auto_advance);

        $this->actingAs($secretary)
            ->post(route('secretary.category.autoAdvance', $performance->category), ['enabled' => 0])
            ->assertRedirect();
        $this->assertFalse($performance->category->fresh()->auto_advance);
    }

    public function test_combined_live_queue_advances_to_the_next_stream_without_reassigning_results(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $group = Group::create([
            'tournament_id' => $tournament->id,
            'name' => '2016 B',
            'program' => 'individual',
        ]);
        $otherGroup = Group::create([
            'tournament_id' => $tournament->id,
            'name' => '2017 A',
            'program' => 'individual',
        ]);
        $firstCategory = Category::create([
            'tournament_id' => $tournament->id,
            'group_id' => $group->id,
            'name' => 'Поток 1',
            'program' => 'individual',
            'stream_no' => 1,
        ]);
        $secondCategory = Category::create([
            'tournament_id' => $tournament->id,
            'group_id' => $group->id,
            'name' => 'Поток 2',
            'program' => 'individual',
            'stream_no' => 2,
        ]);
        $thirdCategory = Category::create([
            'tournament_id' => $tournament->id,
            'group_id' => $otherGroup->id,
            'name' => 'Поток 3',
            'program' => 'individual',
            'stream_no' => 3,
        ]);
        $first = Performance::create([
            'category_id' => $firstCategory->id,
            'athlete_id' => Athlete::create(['last_name' => 'Первая', 'first_name' => 'Анна'])->id,
            'status' => 'performing',
            'order_index' => 1,
        ]);
        $second = Performance::create([
            'category_id' => $secondCategory->id,
            'athlete_id' => Athlete::create(['last_name' => 'Вторая', 'first_name' => 'Мария'])->id,
            'status' => 'scheduled',
            'order_index' => 1,
        ]);
        $third = Performance::create([
            'category_id' => $thirdCategory->id,
            'athlete_id' => Athlete::create(['last_name' => 'Третья', 'first_name' => 'Елена'])->id,
            'status' => 'scheduled',
            'order_index' => 1,
        ]);
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary)
            ->post(route('secretary.tournament.liveQueue', $tournament), [
                'category_ids' => [$firstCategory->id],
            ])
            ->assertSessionHasErrors('combined_queue');
        $this->actingAs($secretary)
            ->post(route('secretary.tournament.liveQueue', $tournament), [
                'category_ids' => [$firstCategory->id, $thirdCategory->id],
            ])
            ->assertRedirect(route('secretary.tournament.groups', $tournament).'#tournament-live-queue');
        $this->actingAs($secretary)
            ->get(route('secretary.tournament.groups', $tournament))
            ->assertOk()
            ->assertSee('Объединённая Live-очередь')
            ->assertSee(route('secretary.tournament.liveQueue', $tournament), false);

        $this->actingAs($secretary)
            ->get(route('secretary.tournament.live', [
                'tournament' => $tournament,
                'category' => $firstCategory,
                'combined' => 1,
            ]))
            ->assertOk()
            ->assertSee('data-combined-live-option', false)
            ->assertSee('Порядок выступления объединённой очереди')
            ->assertSee('Объединённая очередь · Поток 1 + Поток 3')
            ->assertSee('Первая Анна')
            ->assertSee('Третья Елена');

        $this->assertSame($firstCategory->id, $first->fresh()->category_id);
        $this->assertSame($thirdCategory->id, $third->fresh()->category_id);

        $this->assertTrue(StreamAdvanceService::advanceToNextInCategory($firstCategory));
        $this->assertSame('done', $first->fresh()->status);
        $this->assertSame('scheduled', $second->fresh()->status);
        $this->assertSame('performing', $third->fresh()->status);
        $this->assertSame($thirdCategory->id, $third->fresh()->category_id);
        $this->assertSame([$firstCategory->id, $thirdCategory->id], $tournament->fresh()->combinedLiveCategoryIds());
        $this->assertSame($thirdCategory->id, $tournament->fresh()->active_category_id);

        $this->actingAs($secretary)
            ->post(route('secretary.tournament.liveQueue', $tournament))
            ->assertRedirect(route('secretary.tournament.groups', $tournament).'#tournament-live-queue');
        $this->assertSame([], $tournament->fresh()->combinedLiveCategoryIds());
    }

    public function test_a_and_e_history_shows_the_submitted_deduction_but_keeps_raw_score_for_editing(): void
    {
        $performance = $this->performance();
        $judge = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);
        JudgeScore::create([
            'performance_id' => $performance->id,
            'judge_id' => $judge->id,
            'panel' => 'a',
            'score' => 8.75,
            'submitted_at' => now(),
        ]);
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary)
            ->get(route('secretary.queue', $performance->category))
            ->assertOk()
            ->assertSee('1.250')
            ->assertSee('8.750');
    }

    public function test_da_not_done_action_supports_regular_element_and_acrobatics_modes(): void
    {
        $performance = $this->performance();
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $judge = User::factory()->create(['role' => 'judge_d_da', 'slot' => 'DA1']);

        $this->actingAs($judge)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertDontSee('Сначала нажмите «Акробатика»')
            ->assertSee('acro: isAcro', false)
            ->assertSee("label: isAcro ? 'Акробатика' : 'Элемент'", false)
            ->assertSee("acroPending ? 'акробатика не сделана · 0' : 'элемент не сделан · 0'", false);
    }

    public function test_judge_can_select_a_specific_history_entry_for_removal_and_db_stops_at_two_points(): void
    {
        $performance = $this->performance();
        $tournament = $performance->category->tournament;
        $tournament->update(['active_category_id' => $performance->category_id]);
        $judge = User::factory()->create(['role' => 'judge_d_db', 'slot' => 'DB1']);

        $this->actingAs($judge)
            ->get(route('judge.tournament.tablet', $tournament))
            ->assertOk()
            ->assertSee('data-db-score-limit="2.0"', false)
            ->assertSee('data-selectable-score-history', false)
            ->assertSee('x-for="(a, i) in actions"', false)
            ->assertSee('toggleActionSelection(a)', false)
            ->assertSee('historySelectionClass(a)', false)
            ->assertSee('!bg-red-600', false)
            ->assertSee('this.removeAction(this.actions[0])', false)
            ->assertDontSee('assignValue(2.1)', false)
            ->assertDontSee('assignValue(2.5)', false);

        foreach (['_tablet_a', '_tablet_da', '_tablet_da_group', '_tablet_d_group', '_tablet_e'] as $partial) {
            $markup = file_get_contents(resource_path("views/judge/partials/{$partial}.blade.php"));
            $this->assertStringContainsString('data-selectable-score-history', $markup);
        }
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
