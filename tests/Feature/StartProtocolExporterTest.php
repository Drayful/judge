<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\Performance;
use App\Models\StreamSession;
use App\Models\Tournament;
use App\Services\StartProtocolExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class StartProtocolExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiday_stream_is_printed_in_separate_chronological_day_sections(): void
    {
        $tournament = Tournament::create([
            'name' => 'Многодневный турнир',
            'starts_on' => '2026-08-21',
            'ends_on' => '2026-08-23',
            'timezone' => 'Asia/Almaty',
        ]);
        $category = Category::create([
            'tournament_id' => $tournament->id,
            'name' => '2015 A',
            'program' => 'individual',
            'stream_no' => 1,
        ]);
        $firstSession = StreamSession::create([
            'category_id' => $category->id,
            'session_no' => 1,
            'title' => 'День 1',
            'scheduled_on' => '2026-08-21',
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'apparatus' => ['Мяч'],
        ]);
        $secondSession = StreamSession::create([
            'category_id' => $category->id,
            'session_no' => 2,
            'title' => 'День 2',
            'scheduled_on' => '2026-08-22',
            'starts_at' => '10:00',
            'ends_at' => '12:00',
            'apparatus' => ['Обруч'],
        ]);
        $athlete = Athlete::create([
            'last_name' => 'Иванова',
            'first_name' => 'Анна',
            'birthdate' => '2015-05-10',
            'club' => 'Школа',
        ]);
        foreach ([[$firstSession, '09:15'], [$secondSession, '10:20']] as $index => [$session, $time]) {
            Performance::create([
                'category_id' => $category->id,
                'stream_session_id' => $session->id,
                'athlete_id' => $athlete->id,
                'apparatus' => $session->apparatus[0],
                'start_number' => 7,
                'order_index' => $index + 1,
                'scheduled_at_label' => $time,
                'status' => 'scheduled',
            ]);
        }

        $sheet = app(StartProtocolExporter::class)->buildStartProtocol($tournament)->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);
        $rows = collect($rows);
        $allValues = collect($rows)->flatten()->map(fn ($value) => (string) $value);

        $firstDateRow = $rows->search(fn (array $row) => ($row['A'] ?? null) === 'ДЕНЬ СОРЕВНОВАНИЙ · 21.08.2026');
        $secondDateRow = $rows->search(fn (array $row) => ($row['A'] ?? null) === 'ДЕНЬ СОРЕВНОВАНИЙ · 22.08.2026');
        $this->assertIsInt($firstDateRow);
        $this->assertIsInt($secondDateRow);
        $this->assertLessThan($secondDateRow, $firstDateRow);
        $this->assertTrue($allValues->contains(fn (string $value) => str_contains($value, 'День 1 · 21.08.2026 · 09:00–11:00 · Мяч')));
        $this->assertTrue($allValues->contains(fn (string $value) => str_contains($value, 'День 2 · 22.08.2026 · 10:00–12:00 · Обруч')));
        $this->assertSame(2, $allValues->filter(fn (string $value) => $value === 'Иванова Анна')->count());
        $this->assertSame(Worksheet::BREAK_ROW, $sheet->getBreaks()['A'.$secondDateRow]);
    }
}
