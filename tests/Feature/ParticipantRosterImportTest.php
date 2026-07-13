<?php

namespace Tests\Feature;

use App\Models\Athlete;
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
        // Колонка D — ИИН (12 цифр); у Сидоровой 11 цифр (потерян ведущий ноль).
        $s1 = $ss->createSheet();
        $s1->setTitle('2018С');
        $s1->fromArray([
            ['Иванова Мария', 2018, 'Клуб А', '181234567890'],
            ['Петрова Аня', 2018, 'Клуб Б', 121212121212],
            ['Сидорова Ольга', 2017, 'Клуб А', 51234567890], // 11 цифр → дополнится до 12
        ], null, 'A1');

        // Лист с латинской буквой + пробел.
        $s2 = $ss->createSheet();
        $s2->setTitle('2019 А');
        $s2->fromArray([
            ['Козлова Дина', 2019, 'Клуб В'],
        ], null, 'A1');

        // Групповой лист: три стиля заголовков (кавычки, год+кавычки, год+без кавычек).
        $s3 = $ss->createSheet();
        $s3->setTitle('груп-ые 2014-2015');
        $s3->fromArray([
            ['2014-2015 "Nova"'],
            ['Фех София 2014'],
            ['Дастанкызы Сабина 2014'],
            [''],
            ['2015 "Eveline"'],
            ['Донаева Гулмира 2015'],
            ['Новрузова Нурай 2015'],
            [''],
            ['2014-2015 IMPULSE'],   // без кавычек — тоже заголовок
            ['Айткулова Линна'],      // без года у участниц
            ['Нурлан Айсана'],
            ['Марат Айша'],
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

        // Кириллическая «С» нормализована в латинскую «C». Категория — по ЛИСТУ:
        // все три строки листа «2018С» попадают в 2018/C, даже строка с годом 2017.
        $this->assertSame(3, Entry::where('birth_year', 2018)->where('division', 'C')->count());
        $this->assertSame(0, Entry::where('birth_year', 2017)->count());

        // При этом реальный год рождения сохранён в дате рождения атлета.
        $sidorova = Athlete::where('last_name', 'Сидорова')->firstOrFail();
        $this->assertSame(2017, $sidorova->birthdate->year);

        // ИИН распознан из колонки D; 11-значный дополнен ведущим нулём до 12.
        $this->assertSame('181234567890', Athlete::where('last_name', 'Иванова')->value('iin'));
        $this->assertSame('051234567890', $sidorova->iin);

        // Латинская «А» с пробелом.
        $this->assertSame(1, Entry::where('birth_year', 2019)->where('division', 'A')->count());

        // Три команды: Nova, Eveline и IMPULSE (последний — заголовок без кавычек).
        $this->assertSame(3, $stats['group_teams_created']);

        $names = Entry::where('program', 'group')
            ->with('athlete')
            ->get()
            ->map(fn (Entry $e) => $e->athlete->last_name)
            ->all();
        $this->assertContains('Nova', $names);
        $this->assertContains('Eveline', $names);
        $this->assertContains('IMPULSE', $names);

        // Участницы IMPULSE (без года) не приклеились к Eveline.
        $impulse = Entry::where('program', 'group')
            ->whereHas('athlete', fn ($q) => $q->where('last_name', 'IMPULSE'))
            ->firstOrFail();
        $this->assertCount(3, $impulse->meta['members']);

        $eveline = Entry::where('program', 'group')
            ->whereHas('athlete', fn ($q) => $q->where('last_name', 'Eveline'))
            ->firstOrFail();
        $this->assertCount(2, $eveline->meta['members']);
    }

    public function test_iin_is_authoritative_for_dedup(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        // Существующий атлет с ИИН, но другим написанием ФИО.
        $existing = Athlete::create(['first_name' => 'Мария', 'last_name' => 'Иванова', 'iin' => '181234567890']);

        $ss = new Spreadsheet;
        $ss->removeSheetByIndex(0);
        $s = $ss->createSheet();
        $s->setTitle('2018A');
        $s->fromArray([
            ['Иваннова Марья', 2018, 'Клуб', '181234567890'], // опечатка в ФИО, тот же ИИН
        ], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'iin').'.xlsx';
        (new XlsxWriter($ss))->save($path);

        try {
            $stats = app(StartProtocolImportService::class)->importFromPath($tournament, $path);
        } finally {
            @unlink($path);
        }

        // Новый атлет не создан — привязались к существующему по ИИН.
        $this->assertSame(0, $stats['athletes_created']);
        $this->assertSame(1, Athlete::where('iin', '181234567890')->count());
        $this->assertSame($existing->id, Entry::where('tournament_id', $tournament->id)->value('athlete_id'));
    }
}
