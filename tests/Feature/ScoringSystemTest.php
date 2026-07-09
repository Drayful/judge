<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\JudgeScore;
use App\Models\Performance;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FinalProtocolService;
use App\Support\PerformanceApparatus;
use App\Support\SecretaryLiveUi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ScoringSystemTest extends TestCase
{
    use RefreshDatabase;

    private int $judgeSeq = 0;

    private function makeJudge(string $slot): User
    {
        $this->judgeSeq++;

        return User::forceCreate([
            'name' => 'Judge '.$slot.' '.$this->judgeSeq,
            'email' => 'judge'.$this->judgeSeq.'@test.local',
            'password' => 'x',
            'role' => 'judge',
            'slot' => $slot,
        ]);
    }

    private function makeCategory(array $overrides = []): Category
    {
        $tournament = Tournament::forceCreate([
            'name' => 'Кубок Палитры',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-06-02',
        ]);

        return Category::forceCreate(array_merge([
            'tournament_id' => $tournament->id,
            'name' => '2015 г.р., A — Поток 1',
            'birth_year' => 2015,
            'division' => 'A',
            'scoring_rules' => null,
        ], $overrides));
    }

    private function makePerformance(Category $category, ?Athlete $athlete = null, int $order = 0, ?string $apparatus = null): Performance
    {
        $athlete ??= Athlete::forceCreate([
            'first_name' => 'Имя',
            'last_name' => 'Фамилия',
            'birthdate' => '2015-05-05',
            'club' => 'Город',
        ]);

        return Performance::forceCreate([
            'category_id' => $category->id,
            'athlete_id' => $athlete->id,
            'order_index' => $order,
            'status' => 'performing',
            'is_counted' => true,
            'apparatus' => $apparatus,
        ]);
    }

    private function addScore(Performance $perf, string $panel, float $score, ?string $subpanel = null, ?string $penaltyType = null, string $slot = 'X'): void
    {
        $judge = $this->makeJudge($slot);

        JudgeScore::forceCreate([
            'performance_id' => $perf->id,
            'judge_id' => $judge->id,
            'panel' => $panel,
            'subpanel' => $subpanel,
            'penalty_type' => $penaltyType,
            'score' => $score,
            'submitted_at' => now(),
        ]);
    }

    public function test_total_full_panel_with_trimmed_mean_and_penalty(): void
    {
        $category = $this->makeCategory();
        $perf = $this->makePerformance($category);

        // D: DB (avg 5.0+5.4=5.2) + DA (avg 2.0+2.4=2.2) => D = 7.4
        $this->addScore($perf, 'd', 5.0, 'db', null, 'DB1');
        $this->addScore($perf, 'd', 5.4, 'db', null, 'DB2');
        $this->addScore($perf, 'd', 2.0, 'da', null, 'DA1');
        $this->addScore($perf, 'd', 2.4, 'da', null, 'DA2');

        // A panel: 8.0, 8.2, 8.4, 9.5 -> drop 8.0 & 9.5, avg(8.2, 8.4)=8.3
        $this->addScore($perf, 'a', 8.0, null, null, 'A1');
        $this->addScore($perf, 'a', 8.2, null, null, 'A2');
        $this->addScore($perf, 'a', 8.4, null, null, 'A3');
        $this->addScore($perf, 'a', 9.5, null, null, 'A4');

        // E panel: 7.0, 7.5, 7.5, 8.0 -> drop 7.0 & 8.0, avg(7.5,7.5)=7.5
        $this->addScore($perf, 'e', 7.0, null, null, 'E1');
        $this->addScore($perf, 'e', 7.5, null, null, 'E2');
        $this->addScore($perf, 'e', 7.5, null, null, 'E3');
        $this->addScore($perf, 'e', 8.0, null, null, 'E4');

        // Penalty: line 0.3 + time 0.5 = 0.8
        $this->addScore($perf, 'penalty', 0.3, null, 'line', 'LINE1');
        $this->addScore($perf, 'penalty', 0.5, null, 'time', 'TIME');

        $perf->load('judgeScores', 'category');
        $perf->recalculateTotals();

        $this->assertEqualsWithDelta(7.4, $perf->d_score, 0.0005, 'D = avg(DB)+avg(DA)');
        $this->assertEqualsWithDelta(8.3, $perf->a_score, 0.0005, 'A = trimmed mean');
        $this->assertEqualsWithDelta(7.5, $perf->e_score, 0.0005, 'E = trimmed mean');
        $this->assertEqualsWithDelta(0.8, $perf->penalty, 0.0005, 'penalty = sum');
        // total = 7.4 + 8.3 + 7.5 - 0.8 = 22.4
        $this->assertEqualsWithDelta(22.4, $perf->total, 0.0005, 'total = D+A+E-penalty');
    }

    public function test_zero_scores_are_not_dropped_as_null(): void
    {
        $category = $this->makeCategory();
        $perf = $this->makePerformance($category);

        // D с нулями: DB avg(0.0, 0.0)=0; DA avg(0.0,0.0)=0 -> D=0 (а не null)
        $this->addScore($perf, 'd', 0.0, 'db', null, 'DB1');
        $this->addScore($perf, 'd', 0.0, 'db', null, 'DB2');
        $this->addScore($perf, 'd', 0.0, 'da', null, 'DA1');
        $this->addScore($perf, 'd', 0.0, 'da', null, 'DA2');

        // A: 0.0, 5.0, 5.0, 10.0 -> drop 0 и 10, avg(5,5)=5
        $this->addScore($perf, 'a', 0.0, null, null, 'A1');
        $this->addScore($perf, 'a', 5.0, null, null, 'A2');
        $this->addScore($perf, 'a', 5.0, null, null, 'A3');
        $this->addScore($perf, 'a', 10.0, null, null, 'A4');

        // E: один судья ставит 0.0 -> avg=0
        $this->addScore($perf, 'e', 0.0, null, null, 'E1');

        $perf->load('judgeScores', 'category');
        $perf->recalculateTotals();

        $this->assertSame(0.0, (float) $perf->d_score, 'D=0 при всех нулях, не null');
        $this->assertEqualsWithDelta(5.0, $perf->a_score, 0.0005);
        $this->assertSame(0.0, (float) $perf->e_score, 'E=0, не null');
        // total = 0 + 5 + 0 - 0 = 5
        $this->assertEqualsWithDelta(5.0, $perf->total, 0.0005);
    }

    public function test_total_is_null_until_all_components_present(): void
    {
        $category = $this->makeCategory();
        $perf = $this->makePerformance($category);

        // Только D присутствует, A и E нет.
        $this->addScore($perf, 'd', 5.0, 'db', null, 'DB1');
        $this->addScore($perf, 'd', 2.0, 'da', null, 'DA1');

        $perf->load('judgeScores', 'category');
        $perf->recalculateTotals();

        $this->assertEqualsWithDelta(7.0, $perf->d_score, 0.0005);
        $this->assertNull($perf->a_score);
        $this->assertNull($perf->e_score);
        $this->assertNull($perf->total, 'total = null пока нет всех D/A/E');
        $this->assertNull($perf->penalty, 'penalty = null без записей штрафов (не 0)');
    }

    public function test_d_score_null_if_only_one_subpanel_present(): void
    {
        $category = $this->makeCategory();
        $perf = $this->makePerformance($category);

        // DB есть, DA нет -> D должен быть null (нужны обе компоненты)
        $this->addScore($perf, 'd', 5.0, 'db', null, 'DB1');
        $this->addScore($perf, 'a', 8.0, null, null, 'A1');
        $this->addScore($perf, 'e', 8.0, null, null, 'E1');

        $perf->load('judgeScores', 'category');
        $perf->recalculateTotals();

        $this->assertNull($perf->d_score, 'D=null если есть только DB без DA');
        $this->assertNull($perf->total, 'total=null если D неполный');
    }

    public function test_partial_a_panel_under_four_uses_plain_average(): void
    {
        $category = $this->makeCategory();
        $perf = $this->makePerformance($category);

        $this->addScore($perf, 'd', 4.0, 'db', null, 'DB1');
        $this->addScore($perf, 'd', 3.0, 'da', null, 'DA1');

        // Только 3 судьи A -> среднее без отбрасывания: (8+8.6+9.1)/3 = 8.5667
        $this->addScore($perf, 'a', 8.0, null, null, 'A1');
        $this->addScore($perf, 'a', 8.6, null, null, 'A2');
        $this->addScore($perf, 'a', 9.1, null, null, 'A3');

        // 2 судьи E -> (7+8)/2 = 7.5
        $this->addScore($perf, 'e', 7.0, null, null, 'E1');
        $this->addScore($perf, 'e', 8.0, null, null, 'E2');

        $perf->load('judgeScores', 'category');
        $perf->recalculateTotals();

        $this->assertEqualsWithDelta(7.0, $perf->d_score, 0.0005);
        $this->assertEqualsWithDelta(8.5667, $perf->a_score, 0.001);
        $this->assertEqualsWithDelta(7.5, $perf->e_score, 0.0005);
        $this->assertEqualsWithDelta(23.0667, $perf->total, 0.001);
    }

    public function test_final_protocol_places_and_vidi_sum(): void
    {
        $category = $this->makeCategory();

        // Гимнастка 1: два вида (2 выступления) -> сумма
        $a1 = Athlete::forceCreate(['first_name' => 'Анна', 'last_name' => 'Иванова', 'birthdate' => '2015-01-01', 'club' => 'Алматы']);
        $p1a = $this->makePerformance($category, $a1, 1);
        $p1b = $this->makePerformance($category, $a1, 2);
        $p1a->update(['total' => 20.000]);
        $p1b->update(['total' => 18.500]); // сумма 38.5

        // Гимнастка 2: один вид
        $a2 = Athlete::forceCreate(['first_name' => 'Белла', 'last_name' => 'Петрова', 'birthdate' => '2015-02-02', 'club' => 'Астана']);
        $p2 = $this->makePerformance($category, $a2, 3);
        $p2->update(['total' => 25.000]); // лидер по одному виду

        // Гимнастка 3: ничья с суммой гимнастки 1 -> то же место
        $a3 = Athlete::forceCreate(['first_name' => 'Вера', 'last_name' => 'Сидорова', 'birthdate' => '2015-03-03', 'club' => 'Шымкент']);
        $p3a = $this->makePerformance($category, $a3, 4);
        $p3b = $this->makePerformance($category, $a3, 5);
        $p3a->update(['total' => 38.000]);
        $p3b->update(['total' => 0.500]); // сумма 38.5 == гимнастка 1

        // Гимнастка 4: не считается (is_counted=false) -> не должна попасть
        $a4 = Athlete::forceCreate(['first_name' => 'Галя', 'last_name' => 'Котова', 'birthdate' => '2015-04-04', 'club' => 'Тараз']);
        $p4 = $this->makePerformance($category, $a4, 6);
        $p4->update(['total' => 99.000, 'is_counted' => false]);

        $service = new FinalProtocolService;
        $data = $service->build($category->tournament, 2015, 'A');

        $rows = $data['rows'];

        // 3 гимнастки (4-я исключена)
        $this->assertCount(3, $rows);
        // max видов = 2
        $this->assertSame(2, $data['max_vidi']);

        // Сортировка по убыванию: Петрова 25, потом ничья 38.5? нет — 38.5 > 25
        // Итоги: Иванова 38.5, Сидорова 38.5, Петрова 25
        $this->assertSame('Иванова Анна', $rows[0]['name']);
        $this->assertEqualsWithDelta(38.5, $rows[0]['total'], 0.0005);
        $this->assertSame(1, $rows[0]['place']);

        $this->assertEqualsWithDelta(38.5, $rows[1]['total'], 0.0005);
        $this->assertSame(1, $rows[1]['place'], 'ничья -> то же место 1');

        $this->assertSame('Петрова Белла', $rows[2]['name']);
        $this->assertEqualsWithDelta(25.0, $rows[2]['total'], 0.0005);
        $this->assertSame(2, $rows[2]['place'], 'dense rank: следующее место 2, без пропуска');
    }

    public function test_protocol_download_produces_valid_xlsx_with_values(): void
    {
        $secretary = User::forceCreate([
            'name' => 'Секретарь',
            'email' => 'sec@test.local',
            'password' => 'x',
            'role' => 'secretary',
            'slot' => null,
        ]);

        $category = $this->makeCategory();

        $a1 = Athlete::forceCreate(['first_name' => 'Анна', 'last_name' => 'Иванова', 'birthdate' => '2015-01-01', 'club' => 'Алматы']);
        $p1 = $this->makePerformance($category, $a1, 1);
        $p1->update(['total' => 38.500]);

        $a2 = Athlete::forceCreate(['first_name' => 'Белла', 'last_name' => 'Петрова', 'birthdate' => '2015-02-02', 'club' => 'Астана']);
        $p2 = $this->makePerformance($category, $a2, 2);
        $p2->update(['total' => 40.000]);

        $url = route('secretary.tournament.protocol', $category->tournament)
            .'?birth_year=2015&division=A';

        $response = $this->actingAs($secretary)->get($url);
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $bytes = $response->streamedContent();
        $this->assertNotEmpty($bytes);

        // Сохраняем во временный файл и читаем обратно — проверяем реальные значения.
        $tmp = tempnam(sys_get_temp_dir(), 'proto').'.xlsx';
        file_put_contents($tmp, $bytes);

        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($tmp);
        $sheet = $spreadsheet->getActiveSheet();

        // Заголовки таблицы на строке 5
        $this->assertSame('№', $sheet->getCell('A5')->getValue());
        $this->assertSame('Гимнастка', $sheet->getCell('B5')->getValue());
        $this->assertSame('Итог', $sheet->getCell('F5')->getValue());
        $this->assertSame('Место', $sheet->getCell('G5')->getValue());

        // Первая строка данных (строка 6): лидер Петрова 40.0, место 1
        $this->assertSame('Петрова Белла', $sheet->getCell('B6')->getValue());
        $this->assertEqualsWithDelta(40.0, (float) $sheet->getCell('F6')->getValue(), 0.0005);
        $this->assertSame(1, (int) $sheet->getCell('G6')->getValue());

        // Вторая строка (строка 7): Иванова 38.5, место 2
        $this->assertSame('Иванова Анна', $sheet->getCell('B7')->getValue());
        $this->assertEqualsWithDelta(38.5, (float) $sheet->getCell('F7')->getValue(), 0.0005);
        $this->assertSame(2, (int) $sheet->getCell('G7')->getValue());

        @unlink($tmp);
    }

    public function test_total_null_when_da_panel_inactive_but_autoadvance_ready(): void
    {
        // Сценарий: секретарь выключил DA-слоты (только сложность тела).
        $category = $this->makeCategory([
            'inactive_judge_slots' => ['DA1', 'DA2'],
            'auto_advance' => true,
        ]);
        $perf = $this->makePerformance($category);

        // Заполнены только активные слоты: DB, A, E. DA нет (выключены).
        $this->addScore($perf, 'd', 5.0, 'db', null, 'DB1');
        $this->addScore($perf, 'd', 5.0, 'db', null, 'DB2');
        $this->addScore($perf, 'a', 8.0, null, null, 'A1');
        $this->addScore($perf, 'a', 8.0, null, null, 'A2');
        $this->addScore($perf, 'a', 8.0, null, null, 'A3');
        $this->addScore($perf, 'a', 8.0, null, null, 'A4');
        $this->addScore($perf, 'e', 7.0, null, null, 'E1');
        $this->addScore($perf, 'e', 7.0, null, null, 'E2');
        $this->addScore($perf, 'e', 7.0, null, null, 'E3');
        $this->addScore($perf, 'e', 7.0, null, null, 'E4');

        $perf->load('judgeScores', 'category');

        // Автопереход считает выступление готовым (DA пропущены как неактивные)...
        $ready = SecretaryLiveUi::scoresCompleteForAutoAdvance($perf, $category);
        $this->assertTrue($ready, 'автопереход считает готовым: DA выключены');

        // ...но итог НЕ считается, т.к. D требует и DB, и DA.
        $perf->recalculateTotals();

        // Документируем фактическое поведение: D=null -> total=null.
        $this->assertNull($perf->d_score);
        $this->assertNull($perf->total, 'БАГ-риск: total=null при выключенной DA-бригаде');
    }

    public function test_total_ok_when_one_judge_per_panel_disabled(): void
    {
        // Отключаем по ОДНОМУ судье из бригад: DB2, DA2, A4, E4.
        $category = $this->makeCategory([
            'inactive_judge_slots' => ['DB2', 'DA2', 'A4', 'E4'],
            'auto_advance' => true,
        ]);
        $perf = $this->makePerformance($category);

        // Остаются: DB1, DA1, A1..A3, E1..E3.
        $this->addScore($perf, 'd', 5.0, 'db', null, 'DB1'); // DB avg = 5.0
        $this->addScore($perf, 'd', 2.0, 'da', null, 'DA1'); // DA avg = 2.0 -> D = 7.0
        $this->addScore($perf, 'a', 8.0, null, null, 'A1');
        $this->addScore($perf, 'a', 8.3, null, null, 'A2');
        $this->addScore($perf, 'a', 8.7, null, null, 'A3'); // 3 судьи -> avg 8.333
        $this->addScore($perf, 'e', 7.0, null, null, 'E1');
        $this->addScore($perf, 'e', 7.5, null, null, 'E2');
        $this->addScore($perf, 'e', 8.0, null, null, 'E3'); // 3 судьи -> avg 7.5

        $perf->load('judgeScores', 'category');

        $ready = SecretaryLiveUi::scoresCompleteForAutoAdvance($perf, $category);
        $this->assertTrue($ready, 'автопереход готов с неполными, но активными бригадами');

        $perf->recalculateTotals();

        $this->assertEqualsWithDelta(7.0, $perf->d_score, 0.0005, 'D считается: остались DB1 и DA1');
        $this->assertEqualsWithDelta(8.3333, $perf->a_score, 0.001, 'A = среднее по 3 судьям');
        $this->assertEqualsWithDelta(7.5, $perf->e_score, 0.0005, 'E = среднее по 3 судьям');
        // total = 7.0 + 8.3333 + 7.5 = 22.8333
        $this->assertEqualsWithDelta(22.8333, $perf->total, 0.001, 'итог считается нормально');
    }

    private function fillRequiredScores(Performance $perf, array $aScores = [8.0, 8.1, 8.2, 8.3], array $eScores = [7.0, 7.1, 7.2, 7.3]): void
    {
        $this->addScore($perf, 'd', 5.0, 'db', null, 'DB1');
        $this->addScore($perf, 'd', 5.0, 'db', null, 'DB2');
        $this->addScore($perf, 'd', 2.0, 'da', null, 'DA1');
        $this->addScore($perf, 'd', 2.0, 'da', null, 'DA2');

        foreach (['A1', 'A2', 'A3', 'A4'] as $i => $slot) {
            $this->addScore($perf, 'a', $aScores[$i], null, null, $slot);
        }
        foreach (['E1', 'E2', 'E3', 'E4'] as $i => $slot) {
            $this->addScore($perf, 'e', $eScores[$i], null, null, $slot);
        }
    }

    public function test_panel_spread_ok_when_difference_within_one(): void
    {
        $category = $this->makeCategory();
        $perf = $this->makePerformance($category);
        $this->fillRequiredScores($perf, [8.0, 8.2, 8.4, 8.5], [7.0, 7.5, 7.8, 8.0]);

        $perf->load('judgeScores', 'category');
        $report = SecretaryLiveUi::panelSpreadReport($perf, $category);

        $this->assertFalse($report['has_violation']);
        $this->assertTrue(SecretaryLiveUi::readyToFinalize($perf, $category));
    }

    public function test_panel_spread_violation_when_a_panel_exceeds_one(): void
    {
        $category = $this->makeCategory();
        $perf = $this->makePerformance($category);
        // A: 9.0 vs 7.5 -> spread 1.5 > 1.0
        $this->fillRequiredScores($perf, [9.0, 8.2, 8.0, 7.5], [7.0, 7.1, 7.2, 7.3]);

        $perf->load('judgeScores', 'category');
        $report = SecretaryLiveUi::panelSpreadReport($perf, $category);

        $this->assertTrue($report['has_violation']);
        $this->assertTrue(SecretaryLiveUi::requiredScoresSubmitted($perf, $category));
        $this->assertFalse(SecretaryLiveUi::readyToFinalize($perf, $category));
        $this->assertContains('A1', $report['violating_slots']);
        $this->assertEqualsWithDelta(1.5, $report['violations'][0]['spread'], 0.001);
    }

    public function test_panel_spread_exactly_one_is_allowed(): void
    {
        $category = $this->makeCategory();
        $perf = $this->makePerformance($category);
        $this->fillRequiredScores($perf, [8.0, 8.0, 8.0, 9.0], [7.0, 7.0, 7.0, 8.0]);

        $perf->load('judgeScores', 'category');
        $report = SecretaryLiveUi::panelSpreadReport($perf, $category);

        $this->assertFalse($report['has_violation'], 'разброс ровно 1.0 допустим');
        $this->assertTrue(SecretaryLiveUi::readyToFinalize($perf, $category));
    }

    public function test_panel_spread_not_checked_until_panel_complete(): void
    {
        $category = $this->makeCategory();
        $perf = $this->makePerformance($category);

        $this->addScore($perf, 'a', 9.0, null, null, 'A1');
        $this->addScore($perf, 'a', 7.0, null, null, 'A2');

        $perf->load('judgeScores', 'category');
        $report = SecretaryLiveUi::panelSpreadReport($perf, $category);

        $this->assertFalse($report['has_violation'], 'пока не все A-судьи выставили — не блокируем');
    }

    public function test_body_only_d_uses_trimmed_mean_of_four_judges(): void
    {
        $category = $this->makeCategory();
        $perf = $this->makePerformance($category, apparatus: 'БП');

        // 4.0, 5.0, 6.0, 7.0 -> drop 4.0 и 7.0 -> avg(5.0, 6.0) = 5.5
        $this->addScore($perf, 'd', 7.0, 'db', null, 'DB2');
        $this->addScore($perf, 'd', 4.0, 'db', null, 'DB1');
        $this->addScore($perf, 'd', 6.0, 'db', null, 'DA2');
        $this->addScore($perf, 'd', 5.0, 'db', null, 'DA1');

        $this->addScore($perf, 'a', 8.0, null, null, 'A1');
        $this->addScore($perf, 'a', 8.0, null, null, 'A2');
        $this->addScore($perf, 'a', 8.0, null, null, 'A3');
        $this->addScore($perf, 'a', 8.0, null, null, 'A4');
        $this->addScore($perf, 'e', 7.0, null, null, 'E1');
        $this->addScore($perf, 'e', 7.0, null, null, 'E2');
        $this->addScore($perf, 'e', 7.0, null, null, 'E3');
        $this->addScore($perf, 'e', 7.0, null, null, 'E4');

        $perf->load('judgeScores', 'category');
        $perf->recalculateTotals();

        $this->assertEqualsWithDelta(5.5, $perf->d_score, 0.0005, 'БП: trimmed mean по 4 судьям D');
        $this->assertEqualsWithDelta(20.5, $perf->total, 0.0005, 'total = D + A + E');
    }

    public function test_body_only_spread_violation_across_four_d_judges(): void
    {
        $category = $this->makeCategory();
        $perf = $this->makePerformance($category, apparatus: 'б.п.');
        $this->fillBodyOnlyDScores($perf, [3.0, 4.0, 4.5, 5.5]);
        $this->fillAeScores($perf);

        $perf->load('judgeScores', 'category');
        $report = SecretaryLiveUi::panelSpreadReport($perf, $category);

        $this->assertTrue($report['has_violation'], '3.0 vs 5.5 -> spread > 1.0 в объединённой D-панели БП');
        $this->assertFalse(SecretaryLiveUi::readyToFinalize($perf, $category));
        $this->assertContains('DB1', $report['violating_slots']);
    }

    public function test_body_only_spread_ok_within_one(): void
    {
        $category = $this->makeCategory();
        $perf = $this->makePerformance($category, apparatus: 'БП');
        $this->fillBodyOnlyDScores($perf, [5.0, 5.2, 5.4, 5.6]);
        $this->fillAeScores($perf);

        $perf->load('judgeScores', 'category');
        $report = SecretaryLiveUi::panelSpreadReport($perf, $category);

        $this->assertFalse($report['has_violation']);
        $this->assertTrue(SecretaryLiveUi::readyToFinalize($perf, $category));
    }

    private function fillBodyOnlyDScores(Performance $perf, array $scores): void
    {
        foreach (['DB1', 'DB2', 'DA1', 'DA2'] as $i => $slot) {
            $this->addScore($perf, 'd', $scores[$i], 'db', null, $slot);
        }
    }

    private function fillAeScores(Performance $perf): void
    {
        foreach (['A1', 'A2', 'A3', 'A4'] as $slot) {
            $this->addScore($perf, 'a', 8.0, null, null, $slot);
        }
        foreach (['E1', 'E2', 'E3', 'E4'] as $slot) {
            $this->addScore($perf, 'e', 7.0, null, null, $slot);
        }
    }

    public function test_apparatus_normalize_bp_labels(): void
    {
        $this->assertTrue(PerformanceApparatus::isBodyOnly('БП'));
        $this->assertTrue(PerformanceApparatus::isBodyOnly('б.п.'));
        $this->assertTrue(PerformanceApparatus::isBodyOnly('free'));
        $this->assertSame('БП', PerformanceApparatus::normalize('б/п'));
        $this->assertSame('БП', PerformanceApparatus::normalize('B'));
        $this->assertSame('БП', PerformanceApparatus::normalize('Б'));
        $this->assertTrue(PerformanceApparatus::isBodyOnlyCellMarker('B'));
        $this->assertFalse(PerformanceApparatus::isBodyOnly('Мяч'));
        $this->assertTrue(PerformanceApparatus::isExplicitApparatusLabel('Мяч'));
        $this->assertFalse(PerformanceApparatus::isExplicitApparatusLabel('B'));
        $this->assertFalse(PerformanceApparatus::isExplicitApparatusLabel('Вид 1'));
    }

    public function test_mixed_stream_bp_and_apparatus_per_performance(): void
    {
        $category = $this->makeCategory(['name' => '2018 г.р., C, Б.П. — Поток 1']);

        $bp = $this->makePerformance($category, apparatus: 'БП');
        $ball = $this->makePerformance($category, apparatus: 'Мяч');
        $generic = $this->makePerformance($category, apparatus: 'Вид 1');

        $this->assertTrue($bp->isBodyOnlyApparatus());
        $this->assertFalse($ball->isBodyOnlyApparatus());
        $this->assertFalse($generic->isBodyOnlyApparatus(), '«Вид 1» в потоке с Б.П. — не БП без явной метки');

        // БП: trimmed mean; с предметом: DB+DA
        $this->addScore($bp, 'd', 4.0, 'db', null, 'DB1');
        $this->addScore($bp, 'd', 5.0, 'db', null, 'DB2');
        $this->addScore($bp, 'd', 6.0, 'db', null, 'DA1');
        $this->addScore($bp, 'd', 7.0, 'db', null, 'DA2');
        $bp->load('judgeScores', 'category');
        $bp->recalculateTotals();
        $this->assertEqualsWithDelta(5.5, $bp->d_score, 0.0005);

        $this->addScore($ball, 'd', 5.0, 'db', null, 'DB1');
        $this->addScore($ball, 'd', 5.0, 'db', null, 'DB2');
        $this->addScore($ball, 'd', 2.0, 'da', null, 'DA1');
        $this->addScore($ball, 'd', 2.0, 'da', null, 'DA2');
        $ball->load('judgeScores', 'category');
        $ball->recalculateTotals();
        $this->assertEqualsWithDelta(7.0, $ball->d_score, 0.0005, 'Мяч: avg(DB)+avg(DA)');
    }

    public function test_body_only_stream_name_detection(): void
    {
        $this->assertTrue(PerformanceApparatus::isBodyOnlyStream('2018 г.р., C, Б.П.'));
        $this->assertTrue(PerformanceApparatus::isBodyOnlyStream('2020 г.р., Б. П. — Поток 5'));
        $this->assertFalse(PerformanceApparatus::isBodyOnlyStream('2018 г.р., C, 1 вид'));
    }

    public function test_groups_lists_year_division_with_athlete_count(): void
    {
        $category = $this->makeCategory();
        $a1 = Athlete::forceCreate(['first_name' => 'Анна', 'last_name' => 'Иванова', 'birthdate' => '2015-01-01', 'club' => 'Алматы']);
        $p1 = $this->makePerformance($category, $a1, 1);
        $p1->update(['total' => 20.0]);

        $service = new FinalProtocolService;
        $groups = $service->groups($category->tournament);

        $this->assertCount(1, $groups);
        $this->assertSame(2015, $groups[0]['birth_year']);
        $this->assertSame('A', $groups[0]['division']);
        $this->assertSame(1, $groups[0]['athletes']);
    }
}
