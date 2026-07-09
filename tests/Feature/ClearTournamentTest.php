<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\Entry;
use App\Models\Group;
use App\Models\Performance;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClearTournamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_clear_removes_pool_groups_and_tournament_only_athletes(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);

        // Атлет только этого турнира.
        $solo = Athlete::create(['first_name' => 'Одна', 'last_name' => 'Турнирная']);
        Entry::create([
            'tournament_id' => $tournament->id, 'athlete_id' => $solo->id,
            'program' => 'individual', 'birth_year' => 2018, 'division' => 'A',
        ]);

        // Атлет, который также участвует в другом турнире — трогать нельзя.
        $shared = Athlete::create(['first_name' => 'Общая', 'last_name' => 'Двухтурнирная']);
        $other = Tournament::create(['name' => 'Other', 'timezone' => 'Asia/Almaty']);
        Entry::create([
            'tournament_id' => $tournament->id, 'athlete_id' => $shared->id,
            'program' => 'individual', 'birth_year' => 2018, 'division' => 'A',
        ]);
        Entry::create([
            'tournament_id' => $other->id, 'athlete_id' => $shared->id,
            'program' => 'individual', 'birth_year' => 2018, 'division' => 'A',
        ]);

        // Группа + поток + выступление в очищаемом турнире.
        $group = Group::create([
            'tournament_id' => $tournament->id, 'program' => 'individual',
            'birth_year' => 2018, 'division' => 'A', 'name' => '2018 г.р., A', 'apparatus' => ['Б.П.'],
        ]);
        $category = Category::create([
            'tournament_id' => $tournament->id, 'group_id' => $group->id,
            'name' => '2018 г.р., A — Поток 1', 'program' => 'individual', 'birth_year' => 2018, 'division' => 'A',
        ]);
        Performance::create([
            'category_id' => $category->id, 'athlete_id' => $solo->id,
            'start_number' => 1, 'order_index' => 1, 'status' => 'scheduled', 'apparatus' => 'БП',
        ]);

        $this->actingAs($secretary)
            ->delete(route('secretary.tournament.categories.clear', $tournament))
            ->assertRedirect(route('secretary.tournament', $tournament));

        // Всё, что относится к турниру, вычищено.
        $this->assertSame(0, Category::where('tournament_id', $tournament->id)->count());
        $this->assertSame(0, Group::where('tournament_id', $tournament->id)->count());
        $this->assertSame(0, Entry::where('tournament_id', $tournament->id)->count());
        $this->assertSame(0, Performance::where('category_id', $category->id)->count());

        // Атлет только этого турнира удалён; общий с другим турниром — остался.
        $this->assertDatabaseMissing('athletes', ['id' => $solo->id]);
        $this->assertDatabaseHas('athletes', ['id' => $shared->id]);
        $this->assertSame(1, Entry::where('tournament_id', $other->id)->count());
    }
}
