<?php

namespace App\Http\Controllers;

use App\Models\Entry;
use App\Models\Group;
use App\Models\MusicTrack;
use App\Models\Performance;
use App\Models\StreamSession;
use App\Models\Tournament;
use App\Models\TournamentAward;
use App\Services\GroupPoolService;
use App\Services\StartListService;
use App\Services\StreamBuilderService;
use App\Support\PerformanceApparatus;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Страница «Потоки» (доска с перетаскиванием) и «Стартовый лист»
 * с ручным порядком блоков и свободными награждениями.
 */
class StreamsBoardController extends Controller
{
    public function __construct(
        private readonly StreamBuilderService $builder,
        private readonly StartListService $startList,
    ) {}

    public function index(Request $request, Tournament $tournament): View
    {
        $groups = $tournament->groups()
            ->withCount(['entries', 'categories'])
            ->orderByRaw("case when program = 'individual' then 0 else 1 end")
            ->orderByDesc('birth_year')
            ->orderBy('division')
            ->orderBy('order_index')
            ->get();
        $unassignedCounts = Entry::query()
            ->whereIn('group_id', $groups->pluck('id'))
            ->whereNull('stream_no')
            ->selectRaw('group_id, count(*) as c')
            ->groupBy('group_id')
            ->pluck('c', 'group_id');
        $pools = $this->pools($tournament);

        $poolKey = (string) $request->query('pool', '');
        $selectedPool = $poolKey !== '' ? $pools->firstWhere('key', $poolKey) : null;
        $selectedGroup = $selectedPool === null
            ? ($groups->firstWhere('id', (int) $request->query('group')) ?? $groups->first())
            : null;
        if ($selectedGroup === null && $selectedPool === null) {
            $selectedPool = $pools->first();
        }

        $board = $selectedGroup ? $this->boardData($selectedGroup, (int) $request->query('day', 1)) : null;

        return view('secretary.streams-board', [
            'tournament' => $tournament,
            'groups' => $groups,
            'unassignedCounts' => $unassignedCounts,
            'pools' => $pools,
            'selectedGroup' => $selectedGroup,
            'selectedPool' => $selectedPool,
            'board' => $board,
            'apparatusOptions' => PerformanceApparatus::RG_APPARATUS,
            'dayEnds' => $this->dayEnds($tournament, $selectedGroup),
        ]);
    }

    /** Создать группу из пула и сразу перейти к созданию потоков. */
    public function createGroup(Request $request, Tournament $tournament, GroupPoolService $pools): RedirectResponse
    {
        $data = $request->validate([
            'program' => ['required', 'in:individual,group'],
            'birth_year' => ['nullable', 'integer'],
            'division' => ['nullable', 'string', 'max:16'],
        ]);
        $group = $pools->createFromPool(
            $tournament,
            $data['program'],
            isset($data['birth_year']) ? (int) $data['birth_year'] : null,
            ($data['division'] ?? '') !== '' ? strtoupper(trim($data['division'])) : null,
            [],
            'per_stream',
        );

        return redirect()->route('secretary.tournament.board', ['tournament' => $tournament, 'group' => $group->id])
            ->with('status', "Группа «{$group->name}» создана. Настройте потоки.");
    }

    public function createStreams(Request $request, Tournament $tournament, Group $group): RedirectResponse
    {
        $this->ensureGroup($tournament, $group);
        $data = $request->validate([
            'stream_count' => ['required', 'integer', 'min:1', 'max:50'],
            'number_mode' => ['required', 'in:per_stream,continuous'],
            'minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'order' => ['required', 'in:list,draw'],
            'apparatus_mode' => ['required', 'in:fixed,choice'],
            'apparatus' => ['nullable', 'array'],
            'apparatus.*' => ['string', 'distinct', Rule::in(PerformanceApparatus::RG_APPARATUS)],
            'apparatus_count' => ['nullable', 'required_if:apparatus_mode,choice', 'integer', 'min:1', 'max:6'],
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*.date' => ['required', 'date_format:Y-m-d'],
            'days.*.start' => ['required', 'date_format:H:i'],
            'days.*.apparatus' => ['nullable', 'array'],
            'days.*.apparatus.*' => ['string', Rule::in(PerformanceApparatus::RG_APPARATUS)],
        ], [
            'days.required' => 'Добавьте хотя бы один день.',
        ]);

        $days = collect($data['days'])->values()->map(fn (array $day) => [
            'date' => $day['date'],
            'start' => $day['start'],
            'apparatus' => array_values(array_unique($day['apparatus'] ?? [])),
        ])->all();
        $apparatus = array_values($data['apparatus'] ?? []);

        if ($data['apparatus_mode'] === 'fixed') {
            if ($apparatus === []) {
                throw ValidationException::withMessages(['apparatus' => 'Выберите хотя бы один вид.']);
            }
            $assigned = array_merge(...array_map(fn (array $day) => $day['apparatus'], $days));
            sort($assigned);
            $expected = $apparatus;
            sort($expected);
            if ($assigned !== $expected) {
                throw ValidationException::withMessages(['days' => 'Каждый вид должен быть ровно в одном дне.']);
            }
            if (collect($days)->contains(fn (array $day) => $day['apparatus'] === [])) {
                throw ValidationException::withMessages(['days' => 'В каждом дне должен быть хотя бы один вид.']);
            }
            // Порядок видов внутри дня — как порядок выхода группы.
            $days = array_map(fn (array $day) => [...$day, 'apparatus' => array_values(array_intersect($apparatus, $day['apparatus']))], $days);
        } elseif (count($days) > 1) {
            throw ValidationException::withMessages(['days' => 'Для «вида на выбор» укажите один день — виды ещё неизвестны.']);
        }

        try {
            $this->builder->createStreams($group, [
                'stream_count' => (int) $data['stream_count'],
                'number_mode' => $data['number_mode'],
                'minutes' => (int) $data['minutes'],
                'order' => $data['order'],
                'apparatus_mode' => $data['apparatus_mode'],
                'apparatus' => $apparatus,
                'apparatus_count' => isset($data['apparatus_count']) ? (int) $data['apparatus_count'] : null,
                'days' => $days,
            ]);
        } catch (DomainException $e) {
            return back()->withErrors(['streams' => $e->getMessage()])->withInput();
        }

        return redirect()->route('secretary.tournament.board', ['tournament' => $tournament, 'group' => $group->id])
            ->with('status', 'Потоки созданы. Перетаскивайте карточки, чтобы поменять порядок или поток.');
    }

    public function arrange(Request $request, Tournament $tournament, Group $group): JsonResponse
    {
        $this->ensureGroup($tournament, $group);
        $data = $request->validate([
            'streams' => ['present', 'array'],
            'streams.*' => ['present', 'array'],
            'streams.*.*' => ['integer'],
            'unassigned' => ['present', 'array'],
            'unassigned.*' => ['integer'],
        ]);

        try {
            $this->builder->arrange(
                $group,
                array_map(fn ($ids) => array_map('intval', $ids), array_values($data['streams'])),
                array_map('intval', $data['unassigned']),
            );
        } catch (DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function shuffle(Tournament $tournament, Group $group): JsonResponse
    {
        $this->ensureGroup($tournament, $group);
        if ($this->builder->hasStarted($group)) {
            return response()->json(['ok' => false, 'error' => 'В группе уже начались выступления — жеребьёвка недоступна.'], 422);
        }
        $this->builder->shuffle($group);

        return response()->json(['ok' => true]);
    }

    public function updateDay(Request $request, Tournament $tournament, Group $group, int $day): RedirectResponse
    {
        $this->ensureGroup($tournament, $group);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['required', 'date_format:H:i'],
            'minutes' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        try {
            $this->builder->updateDay($group, $day, $data['date'], $data['start'], (int) $data['minutes']);
        } catch (DomainException $e) {
            return back()->withErrors(['day' => $e->getMessage()]);
        }

        return redirect()->route('secretary.tournament.board', ['tournament' => $tournament, 'group' => $group->id, 'day' => $day])
            ->with('status', 'Время дня пересчитано.');
    }

    /** «Вид на выбор»: секретарь указывает виды, когда они стали известны. */
    public function setApparatus(Request $request, Tournament $tournament, Group $group): RedirectResponse
    {
        $this->ensureGroup($tournament, $group);
        abort_unless($group->usesApparatusChoice(), 422);
        $data = $request->validate([
            'apparatus' => ['required', 'array', 'size:'.(int) $group->apparatus_count],
            'apparatus.*' => ['string', 'distinct', Rule::in(PerformanceApparatus::RG_APPARATUS)],
        ], ['apparatus.size' => 'Выберите ровно '.(int) $group->apparatus_count.' вида.']);

        if ($this->builder->hasStarted($group)) {
            return back()->withErrors(['apparatus' => 'В группе уже начались выступления.']);
        }
        $group->update(['apparatus' => array_values($data['apparatus'])]);
        StreamSession::query()->whereIn('category_id', $group->categories()->select('id'))->update(['apparatus' => array_values($data['apparatus'])]);
        $this->builder->renumber($group->fresh());

        return redirect()->route('secretary.tournament.board', ['tournament' => $tournament, 'group' => $group->id])
            ->with('status', 'Виды сохранены, очереди и время обновлены.');
    }

    /* ======================= Стартовый лист ======================= */

    public function startList(Request $request, Tournament $tournament): View
    {
        $days = $this->startList->days($tournament);
        $date = (string) $request->query('date', '');

        return view('secretary.start-list', [
            'tournament' => $tournament,
            'days' => $date !== '' ? $days->only([$date]) : $days,
            'allDates' => $days->keys()->filter()->values(),
            'selectedDate' => $date,
            'doc' => in_array($request->query('doc'), ['start', 'protocol', 'program'], true) ? $request->query('doc') : 'start',
            'timesMode' => $request->query('times') === 'each' ? 'each' : 'one',
            'showMembers' => $request->query('members', '1') === '1',
        ]);
    }

    public function saveOrder(Request $request, Tournament $tournament): JsonResponse
    {
        $data = $request->validate([
            'date' => ['present', 'nullable', 'string'],
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['string', 'regex:/^(session|category|award):\d+$/'],
        ]);

        try {
            $this->startList->saveOrder($tournament, (string) ($data['date'] ?? ''), array_values($data['keys']));
        } catch (DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function storeAward(Request $request, Tournament $tournament): RedirectResponse
    {
        $award = $tournament->awards()->create($this->validateAward($request));
        $this->startList->placeAwardByTime($award);

        return $this->backToStartList($tournament, $award, 'Награждение добавлено.');
    }

    public function updateAward(Request $request, Tournament $tournament, TournamentAward $award): RedirectResponse
    {
        abort_unless((int) $award->tournament_id === (int) $tournament->id, 404);
        $data = $this->validateAward($request);
        $moved = $award->scheduled_on?->format('Y-m-d') !== $data['scheduled_on']
            || substr((string) $award->starts_at, 0, 5) !== $data['starts_at'];
        $award->update($data);
        if ($moved) {
            $this->startList->placeAwardByTime($award);
        }

        return $this->backToStartList($tournament, $award, 'Награждение сохранено.');
    }

    public function destroyAward(Tournament $tournament, TournamentAward $award): RedirectResponse
    {
        abort_unless((int) $award->tournament_id === (int) $tournament->id, 404);
        $award->delete();

        return back()->with('status', 'Награждение удалено.');
    }

    /* ======================= Данные ======================= */

    /** @return array<string, mixed> */
    private function boardData(Group $group, int $day): array
    {
        $categories = $group->categories()->with('sessions')->orderBy('stream_no')->get();
        $categoryIds = $categories->pluck('id');
        $performances = Performance::query()->whereIn('category_id', $categoryIds)->orderBy('order_index')->get();
        $byAthlete = $performances->groupBy('athlete_id');
        $musicAthletes = MusicTrack::query()
            ->whereIn('performance_id', $performances->pluck('id'))
            ->where('is_active', true)
            ->pluck('athlete_id')
            ->flip();

        $sessions = $categories->flatMap->sessions
            ->groupBy('session_no')
            ->sortKeys()
            ->map(function (Collection $rows, $no) {
                $first = $rows->sortBy(fn (StreamSession $session) => $session->category_id)->first();

                return [
                    'no' => (int) $no,
                    'date' => $first->scheduled_on?->format('Y-m-d'),
                    'start' => $first->starts_at ? substr((string) $first->starts_at, 0, 5) : null,
                    'apparatus' => $first->apparatus ?? [],
                    'ids' => $rows->pluck('id')->all(),
                ];
            })
            ->values();
        $current = $sessions->firstWhere('no', $day) ?? $sessions->first();
        $sessionIds = collect($current['ids'] ?? []);

        $card = function (Entry $entry) use ($byAthlete, $musicAthletes, $sessionIds) {
            $athlete = $entry->athlete;
            $own = $byAthlete->get($entry->athlete_id, collect());
            $inDay = $own->whereIn('stream_session_id', $sessionIds);

            return [
                'id' => $entry->id,
                'name' => trim(($athlete?->last_name ?? '').' '.($athlete?->is_team ? '' : ($athlete?->first_name ?? ''))),
                'year' => $athlete?->is_team ? null : $athlete?->birthdate?->year,
                'club' => $entry->club ?? $athlete?->club,
                'team' => (bool) $athlete?->is_team,
                'members' => $athlete?->is_team
                    ? $athlete->members->map(fn ($m) => trim($m->last_name.' '.$m->first_name).($m->birthdate ? ' ('.$m->birthdate->year.')' : ''))->all()
                    : [],
                'number' => $entry->start_number,
                'music' => $musicAthletes->has($entry->athlete_id),
                'locked' => $own->contains(fn (Performance $p) => ! in_array($p->status, ['scheduled', 'withdrawn'], true)),
                'withdrawn' => $own->isNotEmpty() && $own->every(fn (Performance $p) => $p->status === 'withdrawn' || $p->withdrawn_at !== null),
                'times' => $inDay->filter(fn (Performance $p) => $p->scheduled_at_label !== null)
                    ->map(fn (Performance $p) => ['apparatus' => $p->apparatus, 'time' => $p->scheduled_at_label])->values()->all(),
            ];
        };

        $entries = $group->entries()->with('athlete.members')->orderBy('stream_no')->orderBy('order_index')->orderBy('id')->get();
        $streams = $categories->map(function ($category) use ($entries, $card, $current) {
            $session = $category->sessions->firstWhere('session_no', $current['no'] ?? null);

            return [
                'no' => $category->stream_no,
                'start' => $session?->starts_at ? substr((string) $session->starts_at, 0, 5) : $category->starts_at_label,
                'end' => $session?->ends_at ? substr((string) $session->ends_at, 0, 5) : $category->ends_at_label,
                'cards' => $entries->where('stream_no', $category->stream_no)->values()->map($card)->all(),
            ];
        })->values();
        // Записи с номером потока, которого уже нет (защитно) — тоже показываем.
        $orphanStreams = $entries->whereNotNull('stream_no')->whereNotIn('stream_no', $categories->pluck('stream_no'))->values();

        return [
            'group' => $group,
            'sessions' => $sessions,
            'currentDay' => $current['no'] ?? null,
            'currentDayInfo' => $current,
            'streams' => $streams,
            'unassigned' => $entries->whereNull('stream_no')->merge($orphanStreams)->values()->map($card)->all(),
            'minutes' => (int) ($categories->first()?->minutes_per_athlete ?? 3),
            'started' => $this->builder->hasStarted($group),
            'hasStreams' => $categories->isNotEmpty(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function pools(Tournament $tournament): Collection
    {
        return Entry::query()
            ->where('tournament_id', $tournament->id)
            ->whereNull('group_id')
            ->selectRaw('program, birth_year, division, count(*) as c')
            ->groupBy('program', 'birth_year', 'division')
            ->orderByRaw("case when program = 'individual' then 0 else 1 end")
            ->orderByDesc('birth_year')
            ->orderBy('division')
            ->get()
            ->map(fn ($row) => [
                'key' => $row->program.'|'.($row->birth_year ?? '').'|'.($row->division ?? ''),
                'program' => $row->program,
                'birth_year' => $row->birth_year,
                'division' => $row->division,
                'count' => (int) $row->c,
                'name' => app(GroupPoolService::class)->groupName($row->birth_year !== null ? (int) $row->birth_year : null, $row->division, null),
            ]);
    }

    /**
     * Когда заканчивается каждый день (без учёта выбранной группы) —
     * подсказка «начать после предыдущей группы».
     *
     * @return array<string, string>
     */
    private function dayEnds(Tournament $tournament, ?Group $except): array
    {
        return $this->startList->days($tournament)
            ->filter(fn ($items, $date) => $date !== '')
            ->map(fn (Collection $items) => $items
                ->filter(fn (array $item) => $item['type'] === 'award' || $item['category']->group_id !== $except?->id)
                ->pluck('end')->filter()->max())
            ->filter()
            ->all();
    }

    private function ensureGroup(Tournament $tournament, Group $group): void
    {
        abort_unless((int) $group->tournament_id === (int) $tournament->id, 404);
    }

    /** @return array<string, mixed> */
    private function validateAward(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'scheduled_on' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i', 'after:starts_at'],
        ], [
            'ends_at.after' => 'Окончание награждения должно быть позже начала.',
        ]);
        $data['ends_at'] = $data['ends_at'] ?? null;

        return $data;
    }

    private function backToStartList(Tournament $tournament, TournamentAward $award, string $message): RedirectResponse
    {
        return redirect()->to(url()->previous() ?: route('secretary.tournament.start-list', $tournament))
            ->with('status', $message);
    }
}
