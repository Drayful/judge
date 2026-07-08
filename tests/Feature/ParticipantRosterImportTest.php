<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\Tournament;
use App\Services\StartProtocolImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

class ParticipantRosterImportTest extends TestCase
{
    use RefreshDatabase;

    private function makeRosterFile(): string
    {
        $ss = new Spreadsheet;
        $ss->removeSheetByIndex(0);

        // Индивидуальный лист с кириллической буквой «С» → должна нормализоваться в «C».
        $s1 = $ss->createSheet();
        $s1->setTitle('2018С');
        $s1->fromArray([
            ['Иванова Мария', 2018, 'Клуб А'],
            ['Петрова Аня', 2018, 'Клуб Б'],
            ['Сидорова Ольга', 2017, 'Клуб А'], // год строки переопределяет лист
        ], null, 'A1');

        // Лист с латинской буквой + пробел.
        $s2 = $ss->createSheet();
        $s2->setTitle('2019 А');
        $s2->fromArray([
            ['Козлова Дина', 2019, 'Клуб В'],
        ], null, 'A1');

        // Групповой лист: заголовок-клуб + участницы.
        $s3 = $ss->createSheet();
        $s3->setTitle('груп 2016');
        $s3->fromArray([
            ['"MGS" г. Алматы', null],
            ['Аягоз Сафия', 2016],
            ['Дана Аружан', 2016],
        ], null, 'A1');

        // Судейский лист — должен пропускаться.
        $s4 = $ss->createSheet();
        $s4->setTitle('Лист судей');
        $s4->fromArray([['1. Джудж И.И.', 'Клуб']], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'roster').'.xlsx';
        (new XlsxWriter($ss))->save($path);

        return $path;
    }

    public function test_imports_roster_into_entry_pool(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $path = $this->makeRosterFile();

        try {
            $stats = app(StartProtocolImportService::class)->importFromPath($tournament, $path);
        } finally {
            @unlink($path);
        }

        // Лист судей пропущен.
        $this->assertSame(1, $stats['sheets_skipped']);
        $this->assertSame(3, $stats['sheets_processed']);

        // Кириллическая «С» нормализована в латинскую «C»; год строки переопределяет лист.
        $this->assertSame(2, Entry::where('birth_year', 2018)->where('division', 'C')->count());
        $this->assertSame(1, Entry::where('birth_year', 2017)->where('division', 'C')->count());

        // Латинская «А» с пробелом.
        $this->assertSame(1, Entry::where('birth_year', 2019)->where('division', 'A')->count());

        // Групповая команда: одна entry program=group с составом в meta.
        $this->assertSame(1, $stats['group_teams_created']);
        $team = Entry::where('program', 'group')->firstOrFail();
        $this->assertNotEmpty($team->meta['members'] ?? []);
        $this->assertCount(2, $team->meta['members']);
    }
}
