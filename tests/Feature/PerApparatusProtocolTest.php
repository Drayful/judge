<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\Performance;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FinalProtocolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerApparatusProtocolTest extends TestCase
{
    use RefreshDatabase;

    private function setupCategory(): array
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $category = Category::create([
            'tournament_id' => $tournament->id, 'name' => '2018 г.р., A',
            'program' => 'individual', 'birth_year' => 2018, 'division' => 'A',
        ]);
        $a1 = Athlete::create(['first_name' => 'Аня', 'last_name' => 'Первая', 'club' => 'Клуб-1']);
        $a2 = Athlete::create(['first_name' => 'Оля', 'last_name' => 'Вторая', 'club' => 'Клуб-2']);

        $mk = function ($athlete, $apparatus, $total, $order) use ($category) {
            Performance::create([
                'category_id' => $category->id, 'athlete_id' => $athlete->id,
                'apparatus' => $apparatus, 'start_number' => $athlete->id, 'order_index' => $order,
                'status' => 'done', 'is_counted' => true, 'total' => $total,
            ]);
        };
        // a1: БП 20.0, Мяч 18.0 ; a2: БП 19.0, Мяч 21.0
        $mk($a1, 'БП', 20.0, 1);
        $mk($a2, 'БП', 19.0, 2);
        $mk($a1, 'Мяч', 18.0, 3);
        $mk($a2, 'Мяч', 21.0, 4);

        return [$tournament, $category, $a1, $a2];
    }

    public function test_by_apparatus_ranks_each_apparatus_separately(): void
    {
        [$tournament, , $a1, $a2] = $this->setupCategory();

        $data = app(FinalProtocolService::class)->buildByApparatus($tournament, 2018, 'A');

        $byLabel = collect($data['apparatus'])->keyBy('label');
        $this->assertTrue($byLabel->has('БП'));
        $this->assertTrue($byLabel->has('Мяч'));

        // БП: Первая (20) — 1 место, Вторая (19) — 2 место
        $bp = collect($byLabel['БП']['rows'])->keyBy('athlete_id');
        $this->assertSame(1, $bp[$a1->id]['place']);
        $this->assertSame(2, $bp[$a2->id]['place']);

        // Мяч: Вторая (21) — 1 место, Первая (18) — 2 место
        $ball = collect($byLabel['Мяч']['rows'])->keyBy('athlete_id');
        $this->assertSame(1, $ball[$a2->id]['place']);
        $this->assertSame(2, $ball[$a1->id]['place']);
    }

    public function test_by_apparatus_breaks_equal_scores_by_e_then_a(): void
    {
        [$tournament, $category, $a1, $a2] = $this->setupCategory();
        Performance::query()->where('athlete_id', $a1->id)->where('apparatus', 'Мяч')->update([
            'total' => 20.0,
            'e_score' => 9.0,
            'a_score' => 7.0,
        ]);
        Performance::query()->where('athlete_id', $a2->id)->where('apparatus', 'Мяч')->update([
            'total' => 20.0,
            'e_score' => 9.0,
            'a_score' => 8.0,
        ]);
        $a3 = Athlete::create(['first_name' => 'Лена', 'last_name' => 'Третья']);
        Performance::create([
            'category_id' => $category->id,
            'athlete_id' => $a3->id,
            'apparatus' => 'Мяч',
            'order_index' => 5,
            'status' => 'done',
            'is_counted' => true,
            'total' => 20.0,
            'e_score' => 8.0,
            'a_score' => 10.0,
        ]);

        $ball = collect(app(FinalProtocolService::class)->buildByApparatus($tournament, 2018, 'A')['apparatus'])
            ->firstWhere('label', 'Мяч')['rows'];
        $places = collect($ball)->keyBy('athlete_id');

        $this->assertSame(1, $places[$a2->id]['place']);
        $this->assertSame(2, $places[$a1->id]['place']);
        $this->assertSame(3, $places[$a3->id]['place']);
    }

    public function test_download_by_apparatus_returns_xlsx(): void
    {
        [$tournament] = $this->setupCategory();
        $secretary = User::factory()->create(['role' => 'secretary']);

        $resp = $this->actingAs($secretary)->get(
            route('secretary.tournament.protocol', $tournament).'?birth_year=2018&division=A&mode=by_apparatus'
        );

        $resp->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            $resp->headers->get('content-type').$resp->headers->get('content-disposition')
        );
    }
}
