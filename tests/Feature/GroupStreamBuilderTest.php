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
}
