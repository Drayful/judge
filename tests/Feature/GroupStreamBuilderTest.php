<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\Entry;
use App\Models\Performance;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupStreamBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function secretary(): User
    {
        return User::factory()->create(['role' => 'secretary']);
    }

    private function seedPool(Tournament $tournament, int $count, int $year = 2018, string $div = 'C'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $athlete = Athlete::create(['first_name' => 'Имя'.$i, 'last_name' => 'Фамилия'.$i]);
            Entry::create([
                'tournament_id' => $tournament->id,
                'athlete_id' => $athlete->id,
                'program' => 'individual',
                'birth_year' => $year,
                'division' => $div,
                'order_index' => $i,
            ]);
        }
    }

    public function test_builder_page_renders(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $this->seedPool($tournament, 5);

        $this->actingAs($this->secretary())
            ->get(route('secretary.tournament.groups', $tournament))
            ->assertOk()
            ->assertSee('Пул участниц')
            ->assertSee('2018 г.р.');
    }

    public function test_create_group_and_generate_streams(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $this->seedPool($tournament, 26); // → потоки по 12: 12, 12, 2
        $secretary = $this->secretary();

        $this->actingAs($secretary)
            ->post(route('secretary.tournament.groups.store', $tournament), [
                'program' => 'individual',
                'birth_year' => 2018,
                'division' => 'C',
                'apparatus' => ['Б.П.', 'Мяч'],
                'number_mode' => 'continuous',
            ])->assertRedirect(route('secretary.tournament.groups', $tournament));

        $group = $tournament->groups()->firstOrFail();
        $this->assertSame(26, Entry::where('group_id', $group->id)->count());

        $this->actingAs($secretary)
            ->post(route('secretary.tournament.groups.streams', [$tournament, $group]), [
                'stream_size' => 12,
                'number_mode' => 'continuous',
                'start_time' => '08:00',
                'block_minutes' => 25,
            ])->assertRedirect(route('secretary.tournament.groups', $tournament));

        // 3 потока (12/12/2)
        $categories = Category::where('group_id', $group->id)->orderBy('stream_no')->get();
        $this->assertCount(3, $categories);

        // Каждая участница × 2 предмета = 52 выступления.
        $totalPerf = Performance::whereIn('category_id', $categories->pluck('id'))->count();
        $this->assertSame(52, $totalPerf);

        // Сквозная нумерация 1..26.
        $this->assertSame(1, (int) Entry::where('group_id', $group->id)->min('start_number'));
        $this->assertSame(26, (int) Entry::where('group_id', $group->id)->max('start_number'));

        // Время первого потока проставлено.
        $first = $categories->firstWhere('stream_no', 1);
        $this->assertSame('08:00', $first->starts_at_label);
        $this->assertSame('08:25', $first->ends_at_label);

        // Круг-за-кругом: первое выступление 1-го потока — БП стартового №1.
        $firstPerf = Performance::where('category_id', $first->id)->orderBy('order_index')->first();
        $this->assertSame('БП', $firstPerf->apparatus);
        $this->assertSame(1, (int) $firstPerf->start_number);
    }

    public function test_manual_entry_added_to_group_with_streams(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $this->seedPool($tournament, 12);
        $secretary = $this->secretary();

        $this->actingAs($secretary)->post(route('secretary.tournament.groups.store', $tournament), [
            'program' => 'individual', 'birth_year' => 2018, 'division' => 'C',
            'apparatus' => ['Б.П.'], 'number_mode' => 'continuous',
        ]);
        $group = $tournament->groups()->firstOrFail();

        $this->actingAs($secretary)->post(route('secretary.tournament.groups.streams', [$tournament, $group]), [
            'stream_size' => 12, 'start_time' => '08:00', 'block_minutes' => 25,
        ]);

        // Ручная вставка забытой участницы в группу.
        $this->actingAs($secretary)
            ->post(route('secretary.tournament.entries.store', $tournament), [
                'full_name' => 'Забытая Участница',
                'program' => 'individual',
                'group_id' => $group->id,
                'club' => 'Клуб X',
            ])->assertRedirect(route('secretary.tournament.groups', $tournament));

        // Стало 13 в группе, номер 13 у новенькой, попала в поток и в очередь.
        $this->assertSame(13, Entry::where('group_id', $group->id)->count());
        $newbie = Entry::where('group_id', $group->id)
            ->whereHas('athlete', fn ($q) => $q->where('last_name', 'Забытая'))
            ->firstOrFail();
        $this->assertSame(13, (int) $newbie->start_number);
        $this->assertNotNull($newbie->stream_no);

        $category = Category::where('group_id', $group->id)->where('stream_no', $newbie->stream_no)->firstOrFail();
        $this->assertSame(13, Performance::where('category_id', $category->id)->count());
    }

    public function test_assemble_tournament_one_click(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        // Два пула: 2018/A (5) и 2017/B (3).
        $this->seedPool($tournament, 5, 2018, 'A');
        $this->seedPool($tournament, 3, 2017, 'B');

        $this->actingAs($this->secretary())
            ->post(route('secretary.tournament.assemble', $tournament), [
                'apparatus' => ['Б.П.'],
                'stream_size' => 4,
                'start_time' => '08:00',
                'block_minutes' => 25,
                'number_mode' => 'continuous',
            ])->assertRedirect(route('secretary.tournament.groups', $tournament));

        // Две группы, весь пул привязан.
        $this->assertSame(2, $tournament->groups()->count());
        $this->assertSame(0, Entry::where('tournament_id', $tournament->id)->whereNull('group_id')->count());

        // 2018/A: 5 уч. / размер 4 → 2 потока; 2017/B: 3 уч. → 1 поток. Итого 3 потока.
        $this->assertSame(3, Category::whereIn('group_id', $tournament->groups()->pluck('id'))->count());

        // Каскад времени: первый поток дня — 08:00; следующая группа стартует после
        // двух блоков первой (08:00→08:25→08:50).
        $groupA = $tournament->groups()->where('birth_year', 2018)->firstOrFail();
        $groupB = $tournament->groups()->where('birth_year', 2017)->firstOrFail();
        $this->assertSame('08:00', Category::where('group_id', $groupA->id)->where('stream_no', 1)->value('starts_at_label'));
        $this->assertSame('08:50', Category::where('group_id', $groupB->id)->where('stream_no', 1)->value('starts_at_label'));

        // Повторный запуск — новых пулов нет, ошибка (ничего не дублируется).
        $this->actingAs($this->secretary())
            ->post(route('secretary.tournament.assemble', $tournament), [
                'apparatus' => ['Б.П.'], 'stream_size' => 4,
            ])->assertSessionHasErrors('assemble');
        $this->assertSame(2, $tournament->groups()->count());
    }
}
