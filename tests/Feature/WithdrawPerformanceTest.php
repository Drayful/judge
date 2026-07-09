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

class WithdrawPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function makePerformance(float $total = 25.0, int $start = 5): Performance
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $category = Category::create([
            'tournament_id' => $tournament->id, 'name' => '2018 г.р., A',
            'program' => 'individual', 'birth_year' => 2018, 'division' => 'A',
        ]);
        $athlete = Athlete::create(['first_name' => 'Имя', 'last_name' => 'Фамилия']);

        return Performance::create([
            'category_id' => $category->id, 'athlete_id' => $athlete->id,
            'apparatus' => 'БП', 'start_number' => $start, 'order_index' => 1,
            'status' => 'performing', 'is_counted' => true, 'total' => $total,
        ]);
    }

    public function test_secretary_withdraws_and_number_is_kept(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);
        $perf = $this->makePerformance(total: 25.0, start: 5);

        $this->actingAs($secretary)
            ->post(route('secretary.performance.withdraw', $perf))
            ->assertRedirect();

        $perf->refresh();
        $this->assertSame('withdrawn', $perf->status);
        $this->assertNotNull($perf->withdrawn_at);
        $this->assertSame(5, (int) $perf->start_number); // номер сохранён
        $this->assertTrue($perf->isWithdrawn());
    }

    public function test_withdrawn_excluded_from_protocol(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);
        $perf = $this->makePerformance(total: 25.0);
        $tournament = $perf->category->tournament;

        // До снятия — в протоколе есть строка.
        $before = app(FinalProtocolService::class)->build($tournament, 2018, 'A');
        $this->assertCount(1, $before['rows']);

        $this->actingAs($secretary)->post(route('secretary.performance.withdraw', $perf));

        // После снятия — пусто.
        $after = app(FinalProtocolService::class)->build($tournament, 2018, 'A');
        $this->assertCount(0, $after['rows']);
    }

    public function test_restore_returns_to_queue(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);
        $perf = $this->makePerformance();

        $this->actingAs($secretary)->post(route('secretary.performance.withdraw', $perf));
        $this->actingAs($secretary)->post(route('secretary.performance.restore', $perf))->assertRedirect();

        $perf->refresh();
        $this->assertSame('scheduled', $perf->status);
        $this->assertNull($perf->withdrawn_at);
    }

    public function test_judge_forbidden(): void
    {
        $judge = User::factory()->create(['role' => 'judge_e']);
        $perf = $this->makePerformance();

        $this->actingAs($judge)
            ->post(route('secretary.performance.withdraw', $perf))
            ->assertForbidden();
    }
}
