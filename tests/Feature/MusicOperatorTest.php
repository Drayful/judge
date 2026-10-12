<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\MusicTrack;
use App\Models\Performance;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MusicOperatorTest extends TestCase
{
    use RefreshDatabase;

    private function performance(Category $category, string $lastName, int $order, string $status): Performance
    {
        $athlete = Athlete::forceCreate([
            'first_name' => 'Имя',
            'last_name' => $lastName,
            'birthdate' => '2015-01-01',
            'club' => 'Клуб',
        ]);

        return Performance::forceCreate([
            'category_id' => $category->id,
            'athlete_id' => $athlete->id,
            'order_index' => $order,
            'status' => $status,
            'is_counted' => true,
        ]);
    }

    private function track(Performance $performance, string $type = 'primary'): MusicTrack
    {
        return MusicTrack::create([
            'athlete_id' => $performance->athlete_id,
            'performance_id' => $performance->id,
            'type' => $type,
            'version' => 1,
            'is_active' => true,
            'original_name' => $type.'.mp3',
            'disk' => 'local',
            'path' => 'x/'.$type.'.mp3',
            'content_type' => 'audio/mpeg',
            'is_primary' => true,
        ]);
    }

    /** @return array{Tournament, Performance, Performance, Performance} */
    private function stream(): array
    {
        $tournament = Tournament::forceCreate(['name' => 'Кубок', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-02']);
        $category = Category::forceCreate(['tournament_id' => $tournament->id, 'name' => 'Поток 1']);
        $done = $this->performance($category, 'Выступила', 0, 'done');
        $current = $this->performance($category, 'Текущая', 1, 'performing');
        $next = $this->performance($category, 'Следующая', 2, 'scheduled');
        $tournament->forceFill(['active_category_id' => $category->id])->save();

        return [$tournament, $done, $current, $next];
    }

    public function test_operator_sees_current_and_upcoming_with_music(): void
    {
        [$tournament, , $current, $next] = $this->stream();
        $primary = $this->track($current);
        $this->track($current, 'backup');
        $operator = User::factory()->create(['role' => 'sound_operator']);

        $this->actingAs($operator)->get(route('music-operator.index'))->assertOk();

        $response = $this->actingAs($operator)
            ->getJson(route('music-operator.live', ['tournament' => $tournament->id]))
            ->assertOk()
            ->assertJsonPath('stream', 'Поток 1')
            ->assertJsonPath('current.id', $current->id)
            ->assertJsonPath('current.track.id', $primary->id)
            ->assertJsonPath('current.backup.name', 'backup.mp3')
            ->assertJsonPath('upcoming.0.id', $next->id)
            ->assertJsonPath('upcoming.0.track', null)
            ->assertJsonCount(1, 'upcoming');

        $this->assertStringContainsString('/tracks/'.$primary->id.'/play', $response->json('current.track.play_url'));
        $this->assertTrue($operator->can('download', $primary));
    }

    public function test_operator_has_no_access_to_secretary_and_judging(): void
    {
        [, , $current] = $this->stream();
        $operator = User::factory()->create(['role' => 'sound_operator']);

        $this->actingAs($operator)->get(route('secretary.queue', $current->category_id))->assertForbidden();
        $this->actingAs($operator)->post(route('secretary.category.performance.music', $current->category_id))->assertForbidden();
        $this->actingAs($operator)->get(route('scoreboard-judge.index'))->assertForbidden();
        // Роль music_operator — это судья RESP; звукорежиссёр судейского планшета не имеет.
        $this->assertNull($operator->judgePanel());
        $this->assertFalse($operator->isAnyJudge());
        $this->actingAs($operator)->get(route('judge.tournaments'))->assertForbidden();
    }

    public function test_judge_cannot_open_music_operator_page(): void
    {
        $this->stream();
        $judge = User::factory()->create(['role' => 'judge_a', 'slot' => 'A1']);

        $this->actingAs($judge)->get(route('music-operator.index'))->assertForbidden();
    }

    public function test_create_music_operator_command(): void
    {
        $this->artisan('users:sound-operator', ['email' => 'music@test.local'])
            ->expectsQuestion('Пароль (не меньше 8 символов)', 'secret-pass-1')
            ->assertSuccessful();

        $user = User::query()->where('email', 'music@test.local')->firstOrFail();
        $this->assertSame('sound_operator', $user->role);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret-pass-1', $user->password));

        User::factory()->create(['email' => 'sec@test.local', 'role' => 'secretary']);
        $this->artisan('users:sound-operator', ['email' => 'sec@test.local'])->assertFailed();
        $this->assertSame('secretary', User::query()->where('email', 'sec@test.local')->value('role'));
    }
}
