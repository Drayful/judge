<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\Entry;
use App\Models\Performance;
use App\Models\Tournament;
use App\Models\User;
use App\Services\StartProtocolImportService;
use App\Support\ScoreboardUi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

class GroupPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_builds_team_with_real_athlete_roster(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);

        $ss = new Spreadsheet;
        $ss->removeSheetByIndex(0);
        $s = $ss->createSheet();
        $s->setTitle('груп-ые 2014-2015');
        $s->fromArray([
            ['2014-2015 "Nova"'],
            ['Фех София 2014'],
            ['Дайрабай Раяна 2015'],
        ], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'grp').'.xlsx';
        (new XlsxWriter($ss))->save($path);

        try {
            app(StartProtocolImportService::class)->importFromPath($tournament, $path);
        } finally {
            @unlink($path);
        }

        $team = Athlete::where('is_team', true)->where('last_name', 'Nova')->firstOrFail();
        $this->assertCount(2, $team->members);
        $this->assertSame('Фех', $team->members[0]->last_name);
        $this->assertSame(2015, $team->members[1]->birthdate?->year);
        // Участницы — обычные (не команды).
        $this->assertFalse((bool) $team->members[0]->is_team);
        // Команда заведена в пул как групповое выступление.
        $this->assertDatabaseHas('entries', ['athlete_id' => $team->id, 'program' => 'group']);
    }

    public function test_imports_secretary_group_sheet_with_short_team_names_and_age_blocks(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('групповые 2020');
        $sheet->fromArray([
            ['Кербез', null, 'Федерация г. Шымкент'],
            ['Әмірәлі Айзада', 2020],
            ['Серік Алина', 2020],
            [null],
            ['г. Конаев ШХГ "Fire Star"', null, 'г. Конаев ШХГ "Fire Star"'],
            ['Донская Евангелина', 2020],
            ['Жантемір Сафия', 2020],
            [null],
            ['Групповые 2019гр', null, 'г. Усть-Каменогорск КГУ "Радмила"'],
            ['Тренев Феденевна Н.В 2019'],
            ['Ларионова Ангелина 2019'],
        ], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'group-layout').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        try {
            $stats = app(StartProtocolImportService::class)->importFromPath($tournament, $path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(3, $stats['group_teams_created']);
        $kerbez = Athlete::query()->where('is_team', true)->where('last_name', 'Кербез')->firstOrFail();
        $fireStar = Athlete::query()->where('is_team', true)->where('last_name', 'Fire Star')->firstOrFail();
        $radmila = Athlete::query()->where('is_team', true)->where('last_name', 'Радмила')->firstOrFail();

        $this->assertCount(2, $kerbez->members);
        $this->assertSame('Федерация г. Шымкент', $kerbez->club);
        $this->assertCount(2, $fireStar->members);
        $this->assertCount(2, $radmila->members);
        $this->assertSame(2019, Entry::query()->where('athlete_id', $radmila->id)->value('birth_year'));
    }

    public function test_secretary_creates_team_with_roster(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);

        $this->actingAs($secretary)
            ->post(route('secretary.tournament.teams.store', $tournament), [
                'name' => 'Grace',
                'birth_year' => 2014,
                'club' => 'ШГ Grace',
                'members' => "Иванова Мария 2014\nПетрова Аня 2015\nСидорова Оля",
            ])->assertRedirect(route('secretary.tournament.groups', $tournament));

        $team = Athlete::where('is_team', true)->where('last_name', 'Grace')->firstOrFail();
        $this->assertCount(3, $team->members);
        $this->assertDatabaseHas('entries', [
            'tournament_id' => $tournament->id, 'athlete_id' => $team->id, 'program' => 'group',
        ]);
    }

    public function test_secretary_updates_team_roster(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $this->actingAs($secretary)->post(route('secretary.tournament.teams.store', $tournament), [
            'name' => 'Nova', 'members' => "Одна Раз 2014\nДва Две 2014",
        ]);
        $team = Athlete::where('is_team', true)->firstOrFail();
        $this->assertCount(2, $team->members);

        $this->actingAs($secretary)->post(route('secretary.teams.update', $team), [
            'tournament_id' => $tournament->id,
            'name' => 'Nova',
            'members' => "Одна Раз 2014\nДва Две 2014\nТри Трое 2015",
        ])->assertRedirect();

        $this->assertCount(3, $team->fresh()->members);
    }

    public function test_scoreboard_marks_group_performance_with_roster(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty', 'is_published' => true]);
        $category = Category::create([
            'tournament_id' => $tournament->id, 'name' => 'Команды 2014',
            'program' => 'group', 'is_published' => true,
        ]);
        $team = Athlete::create(['first_name' => '—', 'last_name' => 'Nova', 'is_team' => true]);
        $m1 = Athlete::create(['first_name' => 'София', 'last_name' => 'Фех']);
        $m2 = Athlete::create(['first_name' => 'Раяна', 'last_name' => 'Дайрабай']);
        $team->members()->sync([$m1->id => ['position' => 1], $m2->id => ['position' => 2]]);

        Performance::create([
            'category_id' => $category->id, 'athlete_id' => $team->id,
            'start_number' => 1, 'order_index' => 1, 'status' => 'performing', 'apparatus' => 'Обруч',
        ]);

        $payload = ScoreboardUi::performancePayload($category, ScoreboardUi::livePerformance($category));

        $this->assertTrue($payload['performance']['is_group']);
        $this->assertCount(2, $payload['performance']['members']);
        $this->assertContains('Фех София', $payload['performance']['members']);
    }
}
