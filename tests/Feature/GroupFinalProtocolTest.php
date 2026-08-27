<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\Entry;
use App\Models\Group;
use App\Models\Performance;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FinalProtocolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class GroupFinalProtocolTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_group_sheet_exports_ranked_team_rows_with_members_below_them(): void
    {
        $tournament = Tournament::create([
            'name' => 'Открытый Чемпионат MGS «Ана Жүрегі»',
            'starts_on' => '2026-08-21',
            'ends_on' => '2026-08-23',
            'timezone' => 'Asia/Almaty',
        ]);
        $sheetName = 'Групповые 2015-2017';

        [$firstTeam, $firstCategory] = $this->createTeam($tournament, $sheetName, 2015, 'MGS ELITE', 'г. Алматы MGS', [
            ['Марат', 'Айша', 2015],
            ['Бабаева', 'София', 2016],
        ]);
        [$secondTeam, $secondCategory] = $this->createTeam($tournament, $sheetName, 2017, 'Eagles', 'г. Астана', [
            ['Ким', 'Селена', 2017],
        ]);

        $this->scoreTeam($firstCategory, $firstTeam, [15.1, 16.2]);
        $this->scoreTeam($secondCategory, $secondTeam, [19.0, 18.0]);

        // Личная категория того же года должна оставаться отдельным протоколом.
        $individualCategory = Category::create([
            'tournament_id' => $tournament->id,
            'name' => '2015 A',
            'program' => 'individual',
            'birth_year' => 2015,
            'division' => 'A',
        ]);
        $individual = Athlete::create(['last_name' => 'Личная', 'first_name' => 'Гимнастка']);
        Performance::create([
            'category_id' => $individualCategory->id,
            'athlete_id' => $individual->id,
            'apparatus' => 'Мяч',
            'order_index' => 1,
            'status' => 'done',
            'is_counted' => true,
            'total' => 20,
        ]);

        $options = app(FinalProtocolService::class)->groups($tournament);
        $this->assertCount(2, $options);
        $groupOption = $options->firstWhere('program', 'group');
        $this->assertSame($sheetName, $groupOption['group_sheet']);
        $this->assertSame('2015-2017 г.р. Групповые', $groupOption['label']);
        $this->assertSame(2, $groupOption['athletes']);

        $data = app(FinalProtocolService::class)->buildTeams($tournament, null, null, $sheetName);
        $this->assertSame('Eagles', $data['rows'][0]['name']);
        $this->assertSame('Ким Селена', $data['rows'][0]['members'][0]['name']);
        $this->assertSame(1, $data['rows'][0]['place']);
        $this->assertSame(37.0, $data['rows'][0]['total']);

        $secretary = User::factory()->create(['role' => 'secretary']);
        $response = $this->actingAs($secretary)->get(route('secretary.tournament.protocol', $tournament).'?'.http_build_query([
            'program' => 'group',
            'group_sheet' => $sheetName,
        ]));
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'group_protocol_').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $workbook = IOFactory::createReader('Xlsx')->load($tmp);
        $worksheet = $workbook->getActiveSheet();

        $this->assertSame('Итоговый протокол 2015-2017 г.р. Групповые', $worksheet->getCell('A3')->getValue());
        $this->assertSame(['№', 'Группа', 'Год', 'Город', 'Вид 1', 'Вид 2', 'Итог', 'Место'], $worksheet->rangeToArray('A4:H4')[0]);
        $this->assertSame('Eagles', $worksheet->getCell('B5')->getValue());
        $this->assertNull($worksheet->getCell('C5')->getValue());
        $this->assertEqualsWithDelta(19.0, (float) $worksheet->getCell('E5')->getValue(), 0.0005);
        $this->assertEqualsWithDelta(18.0, (float) $worksheet->getCell('F5')->getValue(), 0.0005);
        $this->assertEqualsWithDelta(37.0, (float) $worksheet->getCell('G5')->getValue(), 0.0005);
        $this->assertSame(1, (int) $worksheet->getCell('H5')->getValue());
        $this->assertSame('Ким Селена', $worksheet->getCell('B6')->getValue());
        $this->assertSame(2017, (int) $worksheet->getCell('C6')->getValue());
        $this->assertNull($worksheet->getCell('E6')->getValue());
        $this->assertTrue($worksheet->getStyle('B5')->getFont()->getBold());
        $this->assertSame('FFD9D9D9', $worksheet->getStyle('A5')->getFill()->getStartColor()->getARGB());
        $this->assertSame('0.000', $worksheet->getStyle('E5')->getNumberFormat()->getFormatCode());

        @unlink($tmp);
    }

    /**
     * @param  list<array{0:string, 1:string, 2:int}>  $members
     * @return array{Athlete, Category}
     */
    private function createTeam(
        Tournament $tournament,
        string $sheetName,
        int $year,
        string $name,
        string $club,
        array $members,
    ): array {
        $group = Group::create([
            'tournament_id' => $tournament->id,
            'program' => 'group',
            'birth_year' => $year,
            'name' => 'Групповые '.$year,
            'apparatus' => ['Мяч', 'Лента'],
        ]);
        $category = Category::create([
            'tournament_id' => $tournament->id,
            'group_id' => $group->id,
            'name' => 'Групповые '.$year.' — Поток 1',
            'program' => 'group',
            'birth_year' => $year,
            'stream_no' => 1,
        ]);
        $team = Athlete::create([
            'last_name' => $name,
            'first_name' => '—',
            'is_team' => true,
            'club' => $club,
        ]);
        $sync = [];
        foreach ($members as $position => [$lastName, $firstName, $memberYear]) {
            $member = Athlete::create([
                'last_name' => $lastName,
                'first_name' => $firstName,
                'birthdate' => $memberYear.'-01-01',
                'club' => $club,
            ]);
            $sync[$member->id] = ['position' => $position + 1];
        }
        $team->members()->sync($sync);
        Entry::create([
            'tournament_id' => $tournament->id,
            'athlete_id' => $team->id,
            'group_id' => $group->id,
            'program' => 'group',
            'birth_year' => $year,
            'club' => $club,
            'stream_no' => 1,
            'order_index' => 1,
            'meta' => ['sheet' => $sheetName],
        ]);

        return [$team, $category];
    }

    /** @param  list<float>  $scores */
    private function scoreTeam(Category $category, Athlete $team, array $scores): void
    {
        foreach (['Мяч', 'Лента'] as $index => $apparatus) {
            Performance::create([
                'category_id' => $category->id,
                'athlete_id' => $team->id,
                'apparatus' => $apparatus,
                'order_index' => $index + 1,
                'status' => 'done',
                'is_counted' => true,
                'total' => $scores[$index],
                'e_score' => 8 + $index,
                'a_score' => 7 + $index,
            ]);
        }
    }
}
