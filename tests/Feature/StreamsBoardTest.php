<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\Entry;
use App\Models\Group;
use App\Models\MusicTrack;
use App\Models\Performance;
use App\Models\StreamSession;
use App\Models\Tournament;
use App\Models\TournamentAward;
use App\Models\User;
use App\Services\StartListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamsBoardTest extends TestCase
{
    use RefreshDatabase;

    private Tournament $tournament;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tournament = Tournament::create(['name' => 'Кубок', 'starts_on' => '2026-10-18', 'ends_on' => '2026-10-19']);
        $this->actingAs(User::factory()->create(['role' => 'secretary']));
    }

    private function pool(int $count): void
    {
        foreach (range(1, $count) as $i) {
            $athlete = Athlete::create(['last_name' => 'Гимнастка'.$i, 'first_name' => 'Имя', 'birthdate' => '2015-01-01', 'club' => 'Клуб '.($i % 3)]);
            Entry::create(['tournament_id' => $this->tournament->id, 'athlete_id' => $athlete->id, 'program' => 'individual', 'birth_year' => 2015, 'division' => 'A', 'order_index' => $i]);
        }
    }

    private function groupWithStreams(int $count = 5, int $streams = 2): Group
    {
        $this->pool($count);
        $this->post(route('secretary.tournament.board.pool', $this->tournament), ['program' => 'individual', 'birth_year' => 2015, 'division' => 'A'])->assertSessionHasNoErrors();
        $group = Group::query()->firstOrFail();
        $this->post(route('secretary.tournament.board.streams', [$this->tournament, $group]), [
            'stream_count' => $streams, 'number_mode' => 'per_stream', 'minutes' => 3, 'order' => 'list',
            'apparatus_mode' => 'fixed', 'apparatus' => ['Мяч', 'Лента', 'Обруч'],
            'days' => [
                ['date' => '2026-10-18', 'start' => '09:00', 'apparatus' => ['Мяч', 'Лента']],
                ['date' => '2026-10-19', 'start' => '10:00', 'apparatus' => ['Обруч']],
            ],
        ])->assertSessionHasNoErrors();

        return $group->fresh();
    }

    public function test_streams_are_split_evenly_and_each_day_has_its_own_times(): void
    {
        $group = $this->groupWithStreams();

        $this->assertSame('sessions', $group->schedule_mode);
        $this->assertSame([3, 2], Category::query()->where('group_id', $group->id)->orderBy('stream_no')->get()->map(fn ($c) => Entry::where('group_id', $group->id)->where('stream_no', $c->stream_no)->count())->all());

        $first = Category::query()->where('group_id', $group->id)->where('stream_no', 1)->firstOrFail();
        $second = Category::query()->where('group_id', $group->id)->where('stream_no', 2)->firstOrFail();
        $day1 = $first->sessions()->where('session_no', 1)->firstOrFail();
        $day2 = $first->sessions()->where('session_no', 2)->firstOrFail();

        // День 1: 3 гимнастки × 2 вида × 3 мин = 18 мин, второй поток сразу после первого.
        $this->assertSame('09:00', substr($day1->starts_at, 0, 5));
        $this->assertSame('09:18', substr($day1->ends_at, 0, 5));
        $this->assertSame('09:18', substr($second->sessions()->where('session_no', 1)->value('starts_at'), 0, 5));
        // День 2 начинается со своего времени, а не продолжает первый день.
        $this->assertSame('10:00', substr($day2->starts_at, 0, 5));
        $this->assertSame('10:09', substr($day2->ends_at, 0, 5));
        $this->assertSame(['Обруч'], $day2->apparatus);
        $this->assertSame(3, Performance::query()->where('stream_session_id', $day2->id)->count());
        $this->assertSame('10:06', Performance::query()->where('stream_session_id', $day2->id)->orderByDesc('order_index')->value('scheduled_at_label'));
    }

    public function test_apparatus_must_be_assigned_to_exactly_one_day(): void
    {
        $this->pool(3);
        $this->post(route('secretary.tournament.board.pool', $this->tournament), ['program' => 'individual', 'birth_year' => 2015, 'division' => 'A']);
        $group = Group::query()->firstOrFail();

        $this->post(route('secretary.tournament.board.streams', [$this->tournament, $group]), [
            'stream_count' => 1, 'number_mode' => 'per_stream', 'minutes' => 3, 'order' => 'list',
            'apparatus_mode' => 'fixed', 'apparatus' => ['Мяч', 'Лента'],
            'days' => [['date' => '2026-10-18', 'start' => '09:00', 'apparatus' => ['Мяч']]],
        ])->assertSessionHasErrors('days');
        $this->assertSame(0, Category::query()->count());
    }

    public function test_dragging_moves_entry_renumbers_and_keeps_music(): void
    {
        $group = $this->groupWithStreams();
        $entries = Entry::query()->where('group_id', $group->id)->orderBy('stream_no')->orderBy('order_index')->get();
        $moving = $entries->firstWhere('stream_no', 1);
        $performance = Performance::query()->where('athlete_id', $moving->athlete_id)->where('apparatus', 'Мяч')->firstOrFail();
        $track = MusicTrack::create(['athlete_id' => $moving->athlete_id, 'performance_id' => $performance->id, 'type' => 'primary', 'version' => 1, 'is_active' => true, 'original_name' => 'ball.mp3', 'disk' => 'local', 'path' => 'x.mp3', 'is_primary' => true]);

        $stream1 = $entries->where('stream_no', 1)->pluck('id')->reject(fn ($id) => $id === $moving->id)->values()->all();
        $stream2 = [...$entries->where('stream_no', 2)->pluck('id')->all(), $moving->id];
        $this->postJson(route('secretary.tournament.board.arrange', [$this->tournament, $group]), ['streams' => [$stream1, $stream2], 'unassigned' => []])
            ->assertOk()->assertJsonPath('ok', true);

        $moving->refresh();
        $this->assertSame(2, $moving->stream_no);
        $this->assertSame(3, $moving->start_number);
        $newPerformance = Performance::query()->findOrFail($track->fresh()->performance_id);
        $this->assertSame((int) $moving->athlete_id, (int) $newPerformance->athlete_id);
        $this->assertSame('Мяч', $newPerformance->apparatus);
        $this->assertSame(2, $newPerformance->category->stream_no);
    }

    public function test_arrange_is_rejected_after_group_started_but_withdrawal_does_not_lock(): void
    {
        $group = $this->groupWithStreams();
        $entries = Entry::query()->where('group_id', $group->id)->orderBy('stream_no')->orderBy('order_index')->get();
        $state = ['streams' => [$entries->where('stream_no', 1)->pluck('id')->reverse()->values()->all(), $entries->where('stream_no', 2)->pluck('id')->values()->all()], 'unassigned' => []];

        Performance::query()->where('athlete_id', $entries->last()->athlete_id)->update(['status' => 'withdrawn']);
        $this->postJson(route('secretary.tournament.board.arrange', [$this->tournament, $group]), $state)->assertOk();

        Performance::query()->where('athlete_id', $entries->first()->athlete_id)->limit(1)->update(['status' => 'done']);
        $this->postJson(route('secretary.tournament.board.arrange', [$this->tournament, $group]), $state)->assertStatus(422);
    }

    public function test_board_and_start_list_pages_render(): void
    {
        $group = $this->groupWithStreams();
        $this->get(route('secretary.tournament.board', ['tournament' => $this->tournament, 'group' => $group->id]))
            ->assertOk()->assertSee('Поиск группы', false)->assertSee('Гимнастка1')->assertSee('Пересоздать');
        $this->get(route('secretary.tournament.start-list', ['tournament' => $this->tournament, 'times' => 'each']))
            ->assertOk()->assertSee('Стартовый лист')->assertSee('Гимнастка5');

        $this->actingAs(User::factory()->create(['role' => 'chief_judge']))
            ->get(route('secretary.tournament.board', $this->tournament))->assertForbidden();
    }

    public function test_award_is_placed_by_time_and_blocks_can_be_reordered(): void
    {
        $this->groupWithStreams();
        $sessions = StreamSession::query()->whereDate('scheduled_on', '2026-10-18')->orderBy('starts_at')->get();
        $this->post(route('secretary.tournament.awards.store', $this->tournament), ['title' => 'Награждение 2015 A', 'scheduled_on' => '2026-10-18', 'starts_at' => '09:20', 'ends_at' => '09:40'])->assertSessionHasNoErrors();
        $award = TournamentAward::query()->firstOrFail();

        $keys = fn () => app(StartListService::class)->days($this->tournament)->get('2026-10-18')->pluck('key')->all();
        $this->assertSame(['session:'.$sessions[0]->id, 'session:'.$sessions[1]->id, 'award:'.$award->id], $keys());

        // Секретарь ставит награждение между потоками.
        $wanted = ['session:'.$sessions[0]->id, 'award:'.$award->id, 'session:'.$sessions[1]->id];
        $this->postJson(route('secretary.tournament.start-list.order', $this->tournament), ['date' => '2026-10-18', 'keys' => $wanted])->assertOk();
        $this->assertSame($wanted, $keys());
        $day = app(StartListService::class)->days($this->tournament)->get('2026-10-18');
        $this->assertTrue($day[2]['out_of_order'], 'второй поток (09:18) после награждения 09:20 помечается');

        // Новое награждение встаёт по времени среди уже упорядоченных.
        $this->post(route('secretary.tournament.awards.store', $this->tournament), ['title' => 'Открытие', 'scheduled_on' => '2026-10-18', 'starts_at' => '08:45']);
        $this->assertSame('award:'.TournamentAward::query()->where('title', 'Открытие')->value('id'), $keys()[0]);

        // Устаревший список блоков отклоняется.
        $this->postJson(route('secretary.tournament.start-list.order', $this->tournament), ['date' => '2026-10-18', 'keys' => $wanted])->assertStatus(422);

        // Excel использует тот же порядок.
        $rows = collect(app(\App\Services\StartProtocolExporter::class)->buildStartSheet($this->tournament, '2026-10-18')->getActiveSheet()->toArray())->flatten()->filter()->values();
        $this->assertLessThan($rows->search(fn ($v) => str_contains((string) $v, 'Награждение 2015 A')), $rows->search(fn ($v) => str_contains((string) $v, 'Открытие')));
    }
}
