<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\Entry;
use App\Models\Group;
use App\Models\JudgeScore;
use App\Models\MusicTrack;
use App\Models\Performance;
use App\Models\Tournament;
use App\Services\FinalProtocolExporter;
use App\Services\FinalProtocolService;
use App\Services\MusicTrackUploadService;
use App\Services\StartProtocolImportService;
use App\Services\StreamAdvanceService;
use App\Services\StreamBuilderService;
use App\Support\PerformanceApparatus;
use App\Support\SecretaryLiveUi;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecretaryController extends Controller
{
    public function tournaments(): View
    {
        $tournaments = Tournament::query()
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('secretary.tournaments', [
            'tournaments' => $tournaments,
        ]);
    }

    public function storeTournament(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $tournament = Tournament::query()->create([
            'name' => $data['name'],
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
            'timezone' => $data['timezone'] ?: 'Asia/Almaty',
            'is_published' => (bool) ($data['is_published'] ?? false),
        ]);

        return redirect()->route('secretary.tournament', $tournament)
            ->with('status', 'Турнир создан.');
    }

    public function tournament(Tournament $tournament, FinalProtocolService $protocols): View
    {
        $tournament->load(['categories' => function ($q) {
            $q->orderByDesc('id');
        }]);

        $athletes = Athlete::query()
            ->select('athletes.*')
            ->join('performances', 'performances.athlete_id', '=', 'athletes.id')
            ->join('categories', 'categories.id', '=', 'performances.category_id')
            ->where('categories.tournament_id', $tournament->id)
            ->distinct()
            ->orderBy('athletes.last_name')
            ->orderBy('athletes.first_name')
            ->limit(500)
            ->get();

        return view('secretary.tournament', [
            'tournament' => $tournament,
            'athletes' => $athletes,
            'protocolGroups' => $protocols->groups($tournament),
        ]);
    }

    /**
     * Скачать итоговый протокол одной группы (год рождения + категория) в XLSX.
     */
    public function downloadProtocol(
        Request $request,
        Tournament $tournament,
        FinalProtocolService $protocols,
        FinalProtocolExporter $exporter,
    ): StreamedResponse {
        $data = $request->validate([
            'birth_year' => ['nullable', 'integer'],
            'division' => ['nullable', 'string', 'max:16'],
        ]);

        $birthYear = isset($data['birth_year']) ? (int) $data['birth_year'] : null;
        $division = $data['division'] ?? null;

        $built = $protocols->build($tournament, $birthYear, $division);

        if ($built['rows'] === []) {
            abort(404, 'Нет завершённых результатов для этой категории.');
        }

        $spreadsheet = $exporter->build($tournament, $built);

        $fileName = 'protocol_'.$tournament->id.'_'
            .($birthYear ?? 'na').'_'
            .($division !== null && $division !== '' ? strtoupper($division) : 'na')
            .'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new XlsxWriter($spreadsheet);
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function importStartProtocol(Request $request, Tournament $tournament, StartProtocolImportService $importer): RedirectResponse
    {
        $request->validate([
            'protocol' => ['required', Rule::file()->max(15360)->extensions(['xls', 'xlsx'])],
        ], [
            'protocol.required' => 'Выберите файл списка участвующих.',
            'protocol.extensions' => 'Допустимые расширения: .xls, .xlsx.',
        ]);

        $path = $request->file('protocol')->getRealPath();
        if ($path === false) {
            return back()->withErrors(['protocol' => 'Не удалось прочитать загруженный файл.']);
        }

        try {
            $stats = $importer->importFromPath($tournament, $path);
        } catch (\Throwable $e) {
            return back()->withErrors(['protocol' => $e->getMessage()]);
        }

        $message = sprintf(
            'Импорт завершён: листов обработано %d, пропущено %d; участниц в пул добавлено %d (новых в базе %d), команд %d; пропущено дублей %d. Дальше — «Группы и потоки».',
            $stats['sheets_processed'],
            $stats['sheets_skipped'],
            $stats['entries_created'],
            $stats['athletes_created'],
            $stats['group_teams_created'],
            $stats['entries_skipped'],
        );

        return redirect()->route('secretary.tournament.groups', $tournament)->with('status', $message);
    }

    public function storeCategory(Request $request, Tournament $tournament): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'program' => ['required', 'string', 'in:individual,group'],
            'apparatus' => ['nullable', 'string', 'max:64'],
            'birth_year' => ['required', 'integer', 'min:1990', 'max:2035'],
            'division' => ['nullable', 'string', 'max:16'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $division = isset($data['division']) && trim($data['division']) !== ''
            ? strtoupper(trim($data['division']))
            : null;

        $category = Category::query()->create([
            'tournament_id' => $tournament->id,
            'name' => $data['name'],
            'program' => $data['program'],
            'apparatus' => $data['apparatus'] ?? null,
            'birth_year' => (int) $data['birth_year'],
            'division' => $division,
            'is_published' => (bool) ($data['is_published'] ?? false),
        ]);

        return redirect()
            ->to(route('secretary.tournament.live', $tournament).'?category='.$category->id)
            ->with('status', 'Категория создана. Можно добавлять атлетов в очередь.');
    }

    /**
     * Удалить одну категорию (поток) внутри турнира. Cascade удалит выступления,
     * оценки судей и запросы; музыкальные файлы и записи music_tracks чистим вручную
     * (FK у этой таблицы намеренно отсутствует — см. миграцию music_tracks).
     */
    public function destroyCategory(Tournament $tournament, Category $category): RedirectResponse
    {
        if ((int) $category->tournament_id !== (int) $tournament->id) {
            abort(404);
        }

        $name = $category->name;

        DB::transaction(function () use ($tournament, $category) {
            $this->purgeCategoryMusic($category);

            if ((int) ($tournament->active_category_id ?? 0) === (int) $category->id) {
                $tournament->update(['active_category_id' => null]);
            }

            $category->delete();
        });

        return redirect()->route('secretary.tournament', $tournament)
            ->with('status', "Поток «{$name}» удалён вместе с выступлениями и оценками.");
    }

    /**
     * Очистить турнир от всех потоков (категорий) разом.
     */
    public function clearTournamentCategories(Tournament $tournament): RedirectResponse
    {
        $deleted = 0;

        DB::transaction(function () use ($tournament, &$deleted) {
            $categories = Category::query()
                ->where('tournament_id', $tournament->id)
                ->get();

            foreach ($categories as $cat) {
                $this->purgeCategoryMusic($cat);
                $cat->delete();
                $deleted++;
            }

            $tournament->update(['active_category_id' => null]);
        });

        $msg = $deleted > 0
            ? "Турнир очищен: удалено потоков — {$deleted}."
            : 'В турнире не было потоков для удаления.';

        return redirect()->route('secretary.tournament', $tournament)->with('status', $msg);
    }

    /**
     * Страница «Группы и потоки»: пул участниц (entries) по (программа/год/категория),
     * уже созданные группы и их потоки.
     */
    public function groups(Tournament $tournament): View
    {
        $tournament->load([
            'groups' => fn ($q) => $q->orderBy('order_index')->orderBy('id'),
            'groups.categories' => fn ($q) => $q->orderBy('stream_no'),
        ]);

        // Пул: entries, ещё не привязанные к группе, сгруппированные по (программа, год, категория).
        $pool = Entry::query()
            ->where('tournament_id', $tournament->id)
            ->whereNull('group_id')
            ->orderBy('program')
            ->orderByDesc('birth_year')
            ->orderBy('division')
            ->get()
            ->groupBy(fn (Entry $e) => $e->program.'|'.($e->birth_year ?? '0').'|'.($e->division ?? ''))
            ->map(function ($rows) {
                /** @var Entry $first */
                $first = $rows->first();

                return [
                    'program' => $first->program,
                    'birth_year' => $first->birth_year,
                    'division' => $first->division,
                    'label' => $first->meta['label'] ?? null,
                    'count' => $rows->count(),
                ];
            })
            ->values();

        // Счётчики привязанного пула на группу.
        $groupEntryCounts = Entry::query()
            ->where('tournament_id', $tournament->id)
            ->whereNotNull('group_id')
            ->selectRaw('group_id, count(*) as c')
            ->groupBy('group_id')
            ->pluck('c', 'group_id');

        return view('secretary.groups', [
            'tournament' => $tournament,
            'pool' => $pool,
            'groupEntryCounts' => $groupEntryCounts,
            'apparatusOptions' => PerformanceApparatus::RG_APPARATUS,
        ]);
    }

    /**
     * Создать группу из пула: (год + категория + набор предметов). Привязывает
     * подходящие непривязанные entries.
     */
    public function storeGroup(Request $request, Tournament $tournament): RedirectResponse
    {
        $data = $request->validate([
            'program' => ['required', 'string', 'in:individual,group'],
            'birth_year' => ['nullable', 'integer', 'min:1990', 'max:2035'],
            'division' => ['nullable', 'string', 'max:16'],
            'apparatus' => ['required', 'array', 'min:1'],
            'apparatus.*' => ['string', Rule::in(PerformanceApparatus::RG_APPARATUS)],
            'number_mode' => ['nullable', 'string', 'in:continuous,per_stream'],
        ], [
            'apparatus.required' => 'Выберите хотя бы один предмет.',
        ]);

        $division = isset($data['division']) && trim($data['division']) !== ''
            ? strtoupper(trim($data['division']))
            : null;
        $birthYear = isset($data['birth_year']) ? (int) $data['birth_year'] : null;

        $group = DB::transaction(function () use ($tournament, $data, $division, $birthYear) {
            // метка пула («2020 и мл» и т.п.), если есть у entries.
            $label = Entry::query()
                ->where('tournament_id', $tournament->id)
                ->whereNull('group_id')
                ->where('program', $data['program'])
                ->when($birthYear !== null, fn ($q) => $q->where('birth_year', $birthYear))
                ->when($division !== null, fn ($q) => $q->where('division', $division))
                ->value('meta->label');

            $name = $this->groupName($birthYear, $division, $label);

            $group = Group::query()->create([
                'tournament_id' => $tournament->id,
                'program' => $data['program'],
                'birth_year' => $birthYear,
                'birth_year_label' => is_string($label) ? $label : null,
                'division' => $division,
                'name' => $name,
                'apparatus' => array_values($data['apparatus']),
                'number_mode' => $data['number_mode'] ?? 'continuous',
                'order_index' => (int) (Group::query()->where('tournament_id', $tournament->id)->max('order_index') ?? 0) + 1,
            ]);

            Entry::query()
                ->where('tournament_id', $tournament->id)
                ->whereNull('group_id')
                ->where('program', $data['program'])
                ->when($birthYear !== null, fn ($q) => $q->where('birth_year', $birthYear), fn ($q) => $q->whereNull('birth_year'))
                ->when($division !== null, fn ($q) => $q->where('division', $division), fn ($q) => $q->whereNull('division'))
                ->update(['group_id' => $group->id]);

            return $group;
        });

        $attached = Entry::query()->where('group_id', $group->id)->count();

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', "Группа «{$group->name}» создана, участниц привязано: {$attached}. Теперь сформируйте потоки.");
    }

    /**
     * Ручная вставка участницы/команды, если импорт кого-то не добавил.
     * Без group_id — в пул; с group_id — сразу в группу (и, если потоки уже
     * сформированы, добавляется в последний поток с пересчётом номеров).
     */
    public function storeEntry(Request $request, Tournament $tournament, StreamBuilderService $builder): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'program' => ['required', 'string', 'in:individual,group'],
            'birth_year' => ['nullable', 'integer', 'min:1990', 'max:2035'],
            'division' => ['nullable', 'string', 'max:16'],
            'club' => ['nullable', 'string', 'max:255'],
            'group_id' => ['nullable', 'integer'],
        ]);

        $group = null;
        if (! empty($data['group_id'])) {
            $group = Group::query()
                ->where('tournament_id', $tournament->id)
                ->find($data['group_id']);
            if ($group === null) {
                abort(404);
            }
        }

        // Программа/год/категория: у группы берём из неё, иначе из формы.
        $program = $group?->program ?? $data['program'];
        $birthYear = $group?->birth_year ?? (isset($data['birth_year']) ? (int) $data['birth_year'] : null);
        $division = $group !== null
            ? $group->division
            : (isset($data['division']) && trim($data['division']) !== '' ? strtoupper(trim($data['division'])) : null);
        $club = isset($data['club']) && trim($data['club']) !== '' ? trim($data['club']) : null;

        $fullName = trim((string) preg_replace('/\s+/u', ' ', $data['full_name']));
        if ($program === 'group') {
            // Команда: имя целиком в фамилию.
            $lastName = $fullName;
            $firstName = '—';
        } else {
            $parts = preg_split('/\s+/u', $fullName, 2);
            $lastName = $parts[0] ?? $fullName;
            $firstName = ($parts[1] ?? '') !== '' ? $parts[1] : '—';
        }

        $birthdate = $birthYear !== null ? Carbon::createFromDate($birthYear, 1, 1)->startOfDay() : null;

        DB::transaction(function () use ($tournament, $group, $program, $birthYear, $division, $club, $lastName, $firstName, $birthdate, $builder) {
            $athlete = $this->resolveOrCreateAthlete($lastName, $firstName, $birthdate, $club);

            $entry = Entry::query()->create([
                'tournament_id' => $tournament->id,
                'athlete_id' => $athlete->id,
                'group_id' => $group?->id,
                'program' => $program,
                'birth_year' => $birthYear,
                'division' => $division,
                'club' => $club,
                'order_index' => (int) (Entry::query()->where('tournament_id', $tournament->id)->max('order_index') ?? 0) + 1,
                'meta' => ['manual' => true],
            ]);

            // Если у группы уже есть потоки — добавляем в последний и пересчитываем.
            if ($group !== null) {
                $maxStream = (int) (Category::query()->where('group_id', $group->id)->max('stream_no') ?? 0);
                if ($maxStream > 0) {
                    $entry->stream_no = $maxStream;
                    $entry->save();
                    $builder->renumber($group);
                }
            }
        });

        $where = $group !== null ? "группу «{$group->name}»" : 'пул';
        $msg = "Участница добавлена в {$where}.";
        if ($group !== null && Category::query()->where('group_id', $group->id)->exists()) {
            $msg .= ' Добавлена в последний поток, номера пересчитаны.';
        }

        return redirect()->route('secretary.tournament.groups', $tournament)->with('status', $msg);
    }

    private function resolveOrCreateAthlete(string $lastName, string $firstName, ?Carbon $birthdate, ?string $club): Athlete
    {
        $q = Athlete::query()
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower($lastName)])
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($firstName)]);

        $q = $birthdate !== null ? $q->whereDate('birthdate', $birthdate) : $q->whereNull('birthdate');

        $found = $q->first();
        if ($found !== null) {
            if ($club !== null && ($found->club === null || $found->club === '')) {
                $found->update(['club' => $club]);
            }

            return $found;
        }

        return Athlete::query()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birthdate' => $birthdate,
            'club' => $club,
        ]);
    }

    /**
     * Сформировать потоки группы (авто-разбивка по размеру + очереди).
     */
    public function generateStreams(Request $request, Tournament $tournament, Group $group, StreamBuilderService $builder): RedirectResponse
    {
        if ((int) $group->tournament_id !== (int) $tournament->id) {
            abort(404);
        }

        $data = $request->validate([
            'stream_size' => ['required', 'integer', 'min:1', 'max:200'],
            'number_mode' => ['nullable', 'string', 'in:continuous,per_stream'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'block_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);

        $times = $this->buildStreamTimes(
            $group,
            (int) $data['stream_size'],
            $data['start_time'] ?? null,
            isset($data['block_minutes']) ? (int) $data['block_minutes'] : null,
        );

        $builder->generateStreams(
            $group,
            (int) $data['stream_size'],
            $times,
            $data['number_mode'] ?? $group->number_mode ?? 'continuous',
        );

        $streams = $group->categories()->count();

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', "Потоки сформированы: {$streams}. Стартовые номера и очереди готовы.");
    }

    /**
     * Метки времени по потокам из «время начала + длина блока».
     *
     * @return list<array{start:?string,end:?string}>
     */
    private function buildStreamTimes(Group $group, int $streamSize, ?string $startTime, ?int $blockMinutes): array
    {
        if ($startTime === null || $blockMinutes === null || $streamSize < 1) {
            return [];
        }

        $count = (int) ceil(max(0, $group->entries()->count()) / $streamSize);
        if ($count < 1) {
            return [];
        }

        $cursor = Carbon::createFromFormat('H:i', $startTime)->startOfMinute();
        $times = [];
        for ($i = 0; $i < $count; $i++) {
            $start = $cursor->format('H:i');
            $cursor = $cursor->copy()->addMinutes($blockMinutes);
            $times[] = ['start' => $start, 'end' => $cursor->format('H:i')];
        }

        return $times;
    }

    /**
     * Удалить группу: отвязать пул и снести её потоки (с выступлениями/музыкой).
     */
    public function destroyGroup(Tournament $tournament, Group $group): RedirectResponse
    {
        if ((int) $group->tournament_id !== (int) $tournament->id) {
            abort(404);
        }

        $name = $group->name;

        DB::transaction(function () use ($tournament, $group) {
            foreach ($group->categories as $cat) {
                $this->purgeCategoryMusic($cat);
                if ((int) ($tournament->active_category_id ?? 0) === (int) $cat->id) {
                    $tournament->update(['active_category_id' => null]);
                }
                $cat->delete();
            }

            // entries возвращаются в пул (group_id → null через nullOnDelete).
            $group->delete();
        });

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', "Группа «{$name}» удалена, участницы возвращены в пул.");
    }

    /**
     * Ручная правка: перенести участницу в другой поток группы и пересобрать номера/очереди.
     */
    public function moveEntry(Request $request, Entry $entry, StreamBuilderService $builder): RedirectResponse
    {
        $data = $request->validate([
            'stream_no' => ['required', 'integer', 'min:1', 'max:200'],
        ]);

        $tournament = $entry->tournament;
        if ($entry->group_id === null || $tournament === null) {
            return back()->withErrors(['entry' => 'Участница ещё не привязана к группе с потоками.']);
        }

        $builder->moveEntryToStream($entry, (int) $data['stream_no']);

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', 'Участница перенесена, номера пересчитаны.');
    }

    /**
     * Ручная правка: пересчитать стартовые номера и очереди по текущему распределению.
     */
    public function renumberGroup(Tournament $tournament, Group $group, StreamBuilderService $builder): RedirectResponse
    {
        if ((int) $group->tournament_id !== (int) $tournament->id) {
            abort(404);
        }

        $builder->renumber($group);

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', 'Номера и очереди пересчитаны.');
    }

    private function groupName(?int $birthYear, ?string $division, ?string $label): string
    {
        $yearPart = is_string($label) && trim($label) !== ''
            ? trim($label)
            : ($birthYear ? $birthYear.' г.р.' : 'Без года');

        return $division ? $yearPart.', '.$division : $yearPart;
    }

    /**
     * Снести все music_tracks, относящиеся к performances данной категории,
     * включая попытку удалить файлы из хранилища (best-effort, ошибки игнорируем —
     * запись всё равно уйдёт, орфанной не останется).
     */
    private function purgeCategoryMusic(Category $category): void
    {
        $performanceIds = Performance::query()
            ->where('category_id', $category->id)
            ->pluck('id');

        if ($performanceIds->isEmpty()) {
            return;
        }

        MusicTrack::query()
            ->whereIn('performance_id', $performanceIds)
            ->get(['id', 'disk', 'path'])
            ->each(function (MusicTrack $track) {
                if ($track->disk && $track->path) {
                    try {
                        Storage::disk($track->disk)->delete($track->path);
                    } catch (\Throwable $e) {
                        // best-effort
                    }
                }
                $track->delete();
            });
    }

    public function athletes(): View
    {
        $athletes = Athlete::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(200)
            ->get();

        return view('secretary.athletes', [
            'athletes' => $athletes,
        ]);
    }

    public function storeAthlete(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birthdate' => ['nullable', 'date'],
            'club' => ['nullable', 'string', 'max:255'],
            'coach' => ['nullable', 'string', 'max:255'],
        ]);

        Athlete::query()->create($data);

        return back()->with('status', 'Атлет добавлен.');
    }

    public function categories(): View
    {
        $categories = Category::query()
            ->with('tournament')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('secretary.categories', [
            'categories' => $categories,
        ]);
    }

    public function tournamentLive(Request $request, Tournament $tournament): View
    {
        $categories = Category::query()
            ->where('tournament_id', $tournament->id)
            ->orderBy('id')
            ->get();

        if ($categories->isEmpty()) {
            return view('secretary.tournament-live-empty', [
                'tournament' => $tournament,
            ]);
        }

        $defaultId = $categories->first()->id;
        $categoryId = (int) $request->query('category', $defaultId);
        $category = $categories->firstWhere('id', $categoryId);

        if ($category === null) {
            abort(404);
        }

        $tournament->update(['active_category_id' => $category->id]);

        return view('secretary.queue', $this->queueViewData($category));
    }

    public function queue(Category $category): View
    {
        $category->loadMissing('tournament');
        $category->tournament?->update(['active_category_id' => $category->id]);

        return view('secretary.queue', $this->queueViewData($category));
    }

    /**
     * Лёгкий опрос для автообновления Live/очереди (оценки судей без WebSocket).
     */
    public function queuePing(Category $category): JsonResponse
    {
        $performances = Performance::query()
            ->where('category_id', $category->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get(['id', 'status', 'order_index', 'updated_at', 'finalized_at', 'd_score', 'a_score', 'e_score', 'penalty', 'total']);

        $ordered = SecretaryLiveUi::orderedPerformances($performances);
        $current = SecretaryLiveUi::currentPerformance($ordered);

        $perfSig = $performances->map(fn (Performance $p) => implode(':', [
            (string) $p->id,
            $p->status,
            (string) ($p->updated_at?->getTimestamp() ?? 0),
            (string) ($p->finalized_at?->getTimestamp() ?? 0),
            (string) ($p->d_score ?? ''),
            (string) ($p->a_score ?? ''),
            (string) ($p->e_score ?? ''),
            (string) ($p->penalty ?? ''),
            (string) ($p->total ?? ''),
        ]))->implode('|');

        $pids = $performances->pluck('id');
        $scoresDigest = '';
        if ($pids->isNotEmpty()) {
            $scoresDigest = JudgeScore::query()
                ->whereIn('performance_id', $pids)
                ->orderBy('performance_id')
                ->orderBy('id')
                ->get(['id', 'performance_id', 'judge_id', 'panel', 'subpanel', 'penalty_type', 'score', 'submitted_at', 'updated_at'])
                ->map(fn (JudgeScore $s) => implode(':', [
                    (string) $s->performance_id,
                    (string) $s->id,
                    (string) $s->judge_id,
                    $s->panel,
                    (string) ($s->subpanel ?? ''),
                    (string) ($s->penalty_type ?? ''),
                    (string) $s->score,
                    (string) ($s->submitted_at?->getTimestamp() ?? 0),
                    (string) ($s->updated_at?->getTimestamp() ?? 0),
                ]))
                ->implode(';');
        }

        $catSig = $category->id.':'.$category->updated_at?->getTimestamp().':'.implode(',', $category->inactiveJudgeSlotList()).':'.($category->auto_advance ? '1' : '0');

        $rev = md5($perfSig."\n".$scoresDigest."\n".$catSig);

        return response()->json([
            'rev' => $rev,
            'current_performance_id' => $current?->id,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * @return array<string, mixed>
     */
    private function queueViewData(Category $category): array
    {
        $performances = Performance::query()
            ->with([
                'category.tournament',
                'athlete',
                'judgeScores',
                'track',
                'trackBackup',
                'inquiries' => function ($q) {
                    $q->orderByDesc('id');
                },
            ])
            ->where('category_id', $category->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        $athletes = Athlete::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(200)
            ->get();

        $ordered = SecretaryLiveUi::orderedPerformances($performances);
        $currentPerformance = SecretaryLiveUi::currentPerformance($ordered);
        $nextPerformance = SecretaryLiveUi::nextAfter($ordered, $currentPerformance);
        $streamStatus = SecretaryLiveUi::streamStatus($currentPerformance);
        $judgeSlots = SecretaryLiveUi::judgeSlots($currentPerformance, $category);
        $scoreMatrix = SecretaryLiveUi::fixedScoreMatrix($currentPerformance, $category);
        $panelSpread = SecretaryLiveUi::panelSpreadReport($currentPerformance, $category);
        $waitingJudges = collect($judgeSlots)->filter(fn ($s) => ! $s['ok'] && ! ($s['inactive'] ?? false))->count();
        $activeJudgeSlots = collect($judgeSlots)->filter(fn ($s) => ! ($s['inactive'] ?? false))->count();
        $totalJudgeSlots = count($judgeSlots);

        $category->loadMissing('tournament');

        $tournament = $category->tournament;
        $tournamentCategories = $tournament
            ? Category::query()
                ->where('tournament_id', $tournament->id)
                ->orderBy('id')
                ->get()
            : collect();

        $protocolGroups = $tournament
            ? app(FinalProtocolService::class)->groups($tournament)
            : collect();

        // История выставления оценок по слотам (для модалки по клику на оценку).
        $scoreHistory = [];
        foreach (SecretaryLiveUi::scoreRowsBySlot($currentPerformance, $category) as $slot => $row) {
            if ($row === null) {
                continue;
            }
            $scoreHistory[$slot] = [
                'slot' => $slot,
                'judge' => $row->judge?->name ?? '—',
                'score' => $row->score !== null ? number_format((float) $row->score, 3, '.', '') : '—',
                'age_group' => $row->age_group,
                'submitted_at' => $row->submitted_at?->format('H:i:s'),
                'entries' => $row->entries ?? [],
            ];
        }

        return [
            'category' => $category,
            'tournamentCategories' => $tournamentCategories,
            'protocolGroups' => $protocolGroups,
            'performances' => $performances,
            'orderedPerformances' => $ordered,
            'currentPerformance' => $currentPerformance,
            'nextPerformance' => $nextPerformance,
            'streamStatus' => $streamStatus,
            'judgeSlots' => $judgeSlots,
            'scoreMatrix' => $scoreMatrix,
            'panelSpread' => $panelSpread,
            'waitingJudges' => $waitingJudges,
            'totalJudgeSlots' => $totalJudgeSlots,
            'activeJudgeSlots' => $activeJudgeSlots,
            'athletes' => $athletes,
            'scoreHistory' => $scoreHistory,
        ];
    }

    /**
     * Подтвердить итог несмотря на расхождение оценок (секретарь / главный судья).
     */
    public function confirmScore(Performance $performance): RedirectResponse
    {
        $performance->load(['judgeScores', 'category']);
        $category = $performance->category;

        if (! SecretaryLiveUi::requiredScoresSubmitted($performance, $category)) {
            return back()->withErrors(['confirm' => 'Не все обязательные оценки выставлены — подтверждать пока нечего.']);
        }

        $moved = false;
        DB::transaction(function () use ($performance, $category, &$moved) {
            $performance->recalculateTotals();
            $performance->finalized_at = now();
            $performance->save();

            if ($category?->auto_advance) {
                $moved = StreamAdvanceService::advanceToNextInCategory($category);
            }
        });

        $msg = 'Итог подтверждён и зафиксирован.';
        if ($moved) {
            $msg .= ' Вызвана следующая гимнастка.';
        }

        return back()->with('status', $msg);
    }

    /**
     * Вернуть оценку на доработку: один слот, панель (db/da/a/e/penalty) или все сразу.
     */
    public function returnScores(Performance $performance, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'panel' => ['nullable', Rule::in(['db', 'da', 'a', 'e', 'penalty', 'all'])],
            'slot' => ['nullable', 'string', Rule::in(SecretaryLiveUi::ALL_JUDGE_SLOTS)],
        ]);

        if (empty($data['panel']) && empty($data['slot'])) {
            return back()->withErrors(['return' => 'Укажите слот или панель для возврата на доработку.']);
        }

        $returned = 0;
        $label = '';

        if (! empty($data['slot'])) {
            $performance->load(['judgeScores.judge', 'category']);
            $rows = SecretaryLiveUi::scoreRowsBySlot($performance, $performance->category);
            $row = $rows[$data['slot']] ?? null;

            if ($row === null || $row->submitted_at === null) {
                return back()->withErrors(['return' => 'Для слота '.$data['slot'].' нет отправленной оценки — возвращать нечего.']);
            }

            $row->submitted_at = null;
            $row->save();
            $returned = 1;
            $label = $data['slot'];
        } else {
            $key = $data['panel'];
            $query = JudgeScore::query()
                ->where('performance_id', $performance->id)
                ->whereNotNull('submitted_at');

            if (in_array($key, ['db', 'da'], true)) {
                $query->where('panel', 'd')->where('subpanel', $key);
                $label = strtoupper($key);
            } elseif ($key === 'penalty') {
                $query->where('panel', 'penalty');
                $label = 'штрафы (LINE/TIME/RESP)';
            } elseif ($key === 'all') {
                $label = 'все оценки';
            } else {
                $query->where('panel', $key);
                $label = strtoupper($key);
            }

            $returned = $query->update(['submitted_at' => null]);
        }

        $performance->refresh();
        $performance->load(['judgeScores', 'category']);
        $performance->recalculateTotals();
        $performance->finalized_at = null;
        $performance->save();

        return back()->with('status', 'На доработку возвращено: '.$label.' ('.$returned.' шт.). Судьи увидят планшет ввода снова.');
    }

    /**
     * Исправить оценку конкретного судьи (секретарь / главный судья).
     */
    public function updateJudgeScore(Performance $performance, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'slot' => ['required', 'string', Rule::in(SecretaryLiveUi::ALL_JUDGE_SLOTS)],
            'score' => ['required', 'numeric', 'min:0', 'max:99.999'],
        ]);

        $performance->load(['judgeScores.judge', 'category']);
        $rows = SecretaryLiveUi::scoreRowsBySlot($performance, $performance->category);
        $row = $rows[$data['slot']] ?? null;

        if ($row === null) {
            return back()->withErrors(['edit' => 'Для слота '.$data['slot'].' нет оценки — исправлять нечего.']);
        }

        $row->score = (float) $data['score'];
        $row->save();

        $performance->refresh();
        $performance->load(['judgeScores', 'category']);
        $performance->recalculateTotals();
        $performance->save();

        return back()->with('status', 'Оценка '.$data['slot'].' исправлена на '.number_format((float) $data['score'], 3, '.', '').'.');
    }

    /**
     * Выставить финальную оценку вручную (секретарь / главный судья): D/A/E/штраф
     * задаются напрямую, оценки судей больше не пересчитывают итог, пока действует
     * ручной режим. Итог фиксируется сразу.
     */
    public function setFinalScore(Performance $performance, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'd_score' => ['required', 'numeric', 'min:0', 'max:99.999'],
            'a_score' => ['required', 'numeric', 'min:0', 'max:99.999'],
            'e_score' => ['required', 'numeric', 'min:0', 'max:99.999'],
            'penalty' => ['nullable', 'numeric', 'min:0', 'max:99.999'],
        ], [], [
            'd_score' => 'оценка D',
            'a_score' => 'оценка A',
            'e_score' => 'оценка E',
            'penalty' => 'штраф',
        ]);

        $penalty = isset($data['penalty']) && $data['penalty'] !== null && $data['penalty'] !== ''
            ? round((float) $data['penalty'], 3)
            : null;

        DB::transaction(function () use ($performance, $request, $data, $penalty) {
            $performance->d_score = round((float) $data['d_score'], 3);
            $performance->a_score = round((float) $data['a_score'], 3);
            $performance->e_score = round((float) $data['e_score'], 3);
            $performance->penalty = $penalty;
            $performance->scores_overridden = true;
            $performance->scores_overridden_by = $request->user()?->id;
            $performance->scores_overridden_at = now();
            $performance->load('category');
            $performance->recalculateTotals();
            $performance->finalized_at = now();
            $performance->save();
        });

        return back()->with('status', 'Финальная оценка выставлена вручную и зафиксирована: итог '
            .SecretaryLiveUi::formatScore($performance->total !== null ? (float) $performance->total : null).'.');
    }

    /**
     * Снять ручной режим: вернуться к расчёту итога по оценкам судей.
     */
    public function clearFinalOverride(Performance $performance): RedirectResponse
    {
        DB::transaction(function () use ($performance) {
            $performance->scores_overridden = false;
            $performance->scores_overridden_by = null;
            $performance->scores_overridden_at = null;
            $performance->finalized_at = null;
            $performance->load(['judgeScores', 'category']);
            $performance->recalculateTotals();
            $performance->save();
        });

        return back()->with('status', 'Ручной режим снят — итог снова считается по оценкам судей.');
    }

    public function addToQueue(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'athlete_id' => ['required', 'integer', 'exists:athletes,id'],
            'apparatus' => ['nullable', 'string', 'max:64'],
            'start_number' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'position' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ]);

        $maxOrder = (int) (Performance::query()
            ->where('category_id', $category->id)
            ->max('order_index') ?? 0);

        $orderIndex = isset($data['position']) && $data['position']
            ? (int) $data['position']
            : ($maxOrder + 1);

        // Make room if inserting into the middle.
        Performance::query()
            ->where('category_id', $category->id)
            ->where('order_index', '>=', $orderIndex)
            ->increment('order_index');

        Performance::query()->create([
            'category_id' => $category->id,
            'athlete_id' => (int) $data['athlete_id'],
            'apparatus' => PerformanceApparatus::normalize($data['apparatus'] ?? null),
            'start_number' => $data['start_number'] ?? null,
            'order_index' => $orderIndex,
            'status' => 'scheduled',
        ]);

        return back()->with('status', 'Добавлено в очередь.');
    }

    public function reorderQueue(Request $request, Category $category)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ]);

        $ids = array_values(array_map('intval', $data['ids']));
        $ids = array_values(array_unique($ids));

        $existing = Performance::query()
            ->where('category_id', $category->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        sort($existing);
        $check = $ids;
        sort($check);

        if ($existing !== $check) {
            abort(422, 'Некорректный список выходов для этой категории.');
        }

        DB::transaction(function () use ($ids, $category) {
            $i = 1;
            foreach ($ids as $id) {
                Performance::query()
                    ->where('category_id', $category->id)
                    ->where('id', $id)
                    ->update(['order_index' => $i]);
                $i++;
            }
        });

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('status', 'Очередь обновлена.');
    }

    public function removeFromQueue(Performance $performance): RedirectResponse
    {
        $categoryId = $performance->category_id;
        $removedOrder = (int) $performance->order_index;
        $performance->delete();

        Performance::query()
            ->where('category_id', $categoryId)
            ->where('order_index', '>', $removedOrder)
            ->decrement('order_index');

        return back()->with('status', 'Удалено из очереди.');
    }

    public function moveQueue(Performance $performance, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'dir' => ['required', 'string', 'in:up,down'],
        ]);

        $dir = $data['dir'];
        $categoryId = $performance->category_id;

        $neighbor = Performance::query()
            ->where('category_id', $categoryId)
            ->where('id', '!=', $performance->id)
            ->when($dir === 'up', function ($q) use ($performance) {
                $q->where('order_index', '<', $performance->order_index)->orderByDesc('order_index')->orderByDesc('id');
            })
            ->when($dir === 'down', function ($q) use ($performance) {
                $q->where('order_index', '>', $performance->order_index)->orderBy('order_index')->orderBy('id');
            })
            ->first();

        if (! $neighbor) {
            return back();
        }

        $a = (int) $performance->order_index;
        $b = (int) $neighbor->order_index;

        $performance->order_index = $b;
        $neighbor->order_index = $a;
        $performance->save();
        $neighbor->save();

        return back();
    }

    public function callNext(Category $category): RedirectResponse
    {
        StreamAdvanceService::advanceToNextInCategory($category);

        return back();
    }

    public function setAutoAdvance(Request $request, Category $category): RedirectResponse
    {
        $request->validate([
            'enabled' => ['required', 'in:0,1'],
        ]);

        $category->auto_advance = (int) $request->input('enabled') === 1;
        $category->save();

        return back()->with('status', $category->auto_advance
            ? 'Автопереход включён: после всех основных оценок поток перейдёт к следующей гимнастке.'
            : 'Автопереход выключен.');
    }

    /**
     * Включает/выключает слот судьи (на случай неполного состава бригады).
     */
    public function toggleJudgeSlot(Request $request, Category $category)
    {
        $data = $request->validate([
            'slot' => ['required', 'string', Rule::in(SecretaryLiveUi::ALL_JUDGE_SLOTS)],
            'active' => ['required', 'in:0,1'],
        ]);

        $slot = strtoupper((string) $data['slot']);
        $shouldBeActive = (int) $data['active'] === 1;

        $current = $category->inactiveJudgeSlotList();

        if ($shouldBeActive) {
            $current = array_values(array_filter($current, static fn ($s) => $s !== $slot));
        } elseif (! in_array($slot, $current, true)) {
            $current[] = $slot;
        }

        $category->inactive_judge_slots = $current;
        $category->save();

        $message = $shouldBeActive
            ? "Слот {$slot} включён."
            : "Слот {$slot} отключён — оценки этой позиции не требуются.";

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'slot' => $slot,
                'active' => $shouldBeActive,
                'inactive_slots' => $current,
                'message' => $message,
            ]);
        }

        return back()->with('status', $message);
    }

    public function start(Performance $performance): RedirectResponse
    {
        $performance->status = 'performing';
        $performance->started_at = now();
        $performance->save();

        return back();
    }

    public function finish(Performance $performance): RedirectResponse
    {
        $performance->status = 'done';
        $performance->ended_at = now();
        $performance->save();

        return back();
    }

    /**
     * Загрузка музыки для выхода без аккаунта гимнастки (файл привязывается к performance / athlete_id).
     */
    public function uploadPerformanceMusic(Request $request, Category $category, MusicTrackUploadService $uploader): RedirectResponse
    {
        $data = $request->validate([
            'performance_id' => ['required', 'integer'],
            'type' => ['nullable', 'string', 'in:primary,backup'],
            'music' => ['required', 'file', 'mimetypes:audio/mpeg,audio/mp4,audio/x-m4a,audio/wav', 'max:30720'],
        ]);

        $performance = Performance::query()
            ->where('category_id', $category->id)
            ->where('id', (int) $data['performance_id'])
            ->firstOrFail();

        $type = (string) ($request->input('type') ?: 'primary');
        $file = $request->file('music');

        try {
            $uploader->store($performance, $file, $request->user(), $type);
        } catch (DomainException $e) {
            return back()->withErrors(['music' => $e->getMessage()]);
        }

        return back()->with('status', 'Музыка загружена для выхода №'.($performance->start_number ?? $performance->id).'.');
    }
}
