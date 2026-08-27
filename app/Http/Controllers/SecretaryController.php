<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\Entry;
use App\Models\Group;
use App\Models\JudgeScore;
use App\Models\JudgeScoreAction;
use App\Models\MusicTrack;
use App\Models\Performance;
use App\Models\StreamSession;
use App\Models\Tournament;
use App\Services\FinalProtocolExporter;
use App\Services\FinalProtocolService;
use App\Services\GroupStreamSessionService;
use App\Services\MusicTrackUploadService;
use App\Services\StartProtocolExporter;
use App\Services\StartProtocolImportService;
use App\Services\StreamAdvanceService;
use App\Services\StreamBuilderService;
use App\Services\StreamScheduleService;
use App\Support\PerformanceApparatus;
use App\Support\SecretaryLiveUi;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
        $tournament->load(['categories' => fn ($q) => $q->orderedByPerformanceTime()]);

        $poolEntriesCount = Entry::query()
            ->where('tournament_id', $tournament->id)
            ->count();

        $poolIndividualCount = Entry::query()
            ->where('tournament_id', $tournament->id)
            ->where('program', 'individual')
            ->count();

        $poolTeamCount = Entry::query()
            ->where('tournament_id', $tournament->id)
            ->where('program', 'group')
            ->count();

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

        $athletes->load(['performances.category' => fn ($q) => $q->where('tournament_id', $tournament->id)]);

        return view('secretary.tournament', [
            'tournament' => $tournament,
            'athletes' => $athletes,
            'poolEntriesCount' => $poolEntriesCount,
            'poolIndividualCount' => $poolIndividualCount,
            'poolTeamCount' => $poolTeamCount,
            'protocolGroups' => $protocols->groups($tournament),
        ]);
    }

    public function updateTournamentAthlete(Request $request, Tournament $tournament, Athlete $athlete, StreamBuilderService $builder): RedirectResponse
    {
        $belongsToTournament = Performance::query()
            ->join('categories', 'categories.id', '=', 'performances.category_id')
            ->where('categories.tournament_id', $tournament->id)
            ->where('performances.athlete_id', $athlete->id)
            ->exists();

        if (! $belongsToTournament) {
            abort(404);
        }

        $data = $request->validate([
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'iin' => ['nullable', 'string', 'regex:/^\d{12}$/', Rule::unique('athletes', 'iin')->ignore($athlete->id)],
            'stream_category_id' => ['nullable', 'integer'],
        ], [
            'iin.regex' => 'ИИН должен состоять из 12 цифр.',
            'iin.unique' => 'Этот ИИН уже указан у другой атлетки.',
        ]);

        $entry = null;
        $target = null;
        if (! empty($data['stream_category_id'])) {
            $target = Category::query()
                ->where('tournament_id', $tournament->id)
                ->whereKey((int) $data['stream_category_id'])
                ->first();

            if ($target === null || $target->group_id === null || $target->stream_no === null) {
                return back()->withErrors(['stream_category_id' => 'Выберите поток, сформированный для группы.']);
            }

            $entry = Entry::query()
                ->where('tournament_id', $tournament->id)
                ->where('athlete_id', $athlete->id)
                ->where('group_id', $target->group_id)
                ->first();

            if ($entry === null) {
                return back()->withErrors(['stream_category_id' => 'Перенос возможен только в поток той же группы.']);
            }

            $started = Performance::query()
                ->whereIn('category_id', Category::query()->where('group_id', $target->group_id)->select('id'))
                ->where('status', '!=', 'scheduled')
                ->exists();
            if ($started) {
                return back()->withErrors(['stream_category_id' => 'Нельзя менять поток после начала выступлений группы.']);
            }

        }

        DB::transaction(function () use ($athlete, $data, $entry, $target, $builder) {
            $athlete->update([
                'last_name' => trim($data['last_name']),
                'first_name' => trim($data['first_name']),
                'iin' => $data['iin'] ?: null,
            ]);

            if ($entry !== null && $target !== null) {
                $builder->moveEntryToStream($entry, (int) $target->stream_no);
            }
        });

        return redirect()->route('secretary.tournament', $tournament)
            ->with('status', 'Данные атлетки и поток обновлены.');
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
            'program' => ['nullable', 'string', 'in:individual,group'],
            'group_sheet' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', 'string', 'in:all_around,by_apparatus'],
        ]);

        $birthYear = isset($data['birth_year']) ? (int) $data['birth_year'] : null;
        $division = $data['division'] ?? null;
        $program = $data['program'] ?? null;
        $groupSheet = isset($data['group_sheet']) && trim($data['group_sheet']) !== ''
            ? trim($data['group_sheet'])
            : null;
        $mode = $data['mode'] ?? 'all_around';

        if ($groupSheet !== null) {
            $program = 'group';
        }
        if ($program === null) {
            $matchingPrograms = $tournament->categories()->get()
                ->filter(fn (Category $category) => $category->resolvedBirthYear() === $birthYear
                    && $category->resolvedDivision() === ($division !== null && trim($division) !== '' ? strtoupper(trim($division)) : null))
                ->pluck('program')
                ->unique()
                ->values();
            if ($matchingPrograms->count() === 1) {
                $program = $matchingPrograms->first();
            }
        }

        if ($program === 'group') {
            $built = $protocols->buildTeams($tournament, $birthYear, $division, $groupSheet);
            if ($built['rows'] === []) {
                abort(404, 'Нет завершённых результатов команд для этого группового протокола.');
            }
            $spreadsheet = $exporter->buildTeams($tournament, $built);
            $suffix = '_group';
        } elseif ($mode === 'by_apparatus') {
            $built = $protocols->buildByApparatus($tournament, $birthYear, $division, false, 'individual');
            $hasRows = collect($built['apparatus'])->contains(fn ($a) => $a['rows'] !== []);
            if (! $hasRows) {
                abort(404, 'Нет завершённых результатов по видам для этой категории.');
            }
            $spreadsheet = $exporter->buildByApparatus($tournament, $built);
            $suffix = '_vidy';
        } else {
            $built = $program === 'individual'
                ? $protocols->buildForProgram($tournament, $birthYear, $division, 'individual')
                : $protocols->build($tournament, $birthYear, $division);
            if ($built['rows'] === []) {
                abort(404, 'Нет завершённых результатов для этой категории.');
            }
            $spreadsheet = $exporter->build($tournament, $built);
            $suffix = '';
        }

        $fileName = 'protocol_'.$tournament->id.'_'
            .($birthYear ?? 'na').'_'
            .($division !== null && $division !== '' ? strtoupper($division) : 'na')
            .$suffix.'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new XlsxWriter($spreadsheet);
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function downloadStartSheet(Tournament $tournament, StartProtocolExporter $exporter): StreamedResponse
    {
        return $this->downloadSpreadsheet($exporter->buildStartSheet($tournament), 'start_sheet_'.$tournament->id.'.xlsx');
    }

    public function downloadStartProtocol(Tournament $tournament, StartProtocolExporter $exporter): StreamedResponse
    {
        return $this->downloadSpreadsheet($exporter->buildStartProtocol($tournament), 'start_protocol_'.$tournament->id.'.xlsx');
    }

    public function downloadProgramme(Tournament $tournament, StartProtocolExporter $exporter): StreamedResponse
    {
        return $this->downloadSpreadsheet($exporter->buildProgramme($tournament), 'programme_'.$tournament->id.'.xlsx');
    }

    private function downloadSpreadsheet(Spreadsheet $spreadsheet, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet) {
            (new XlsxWriter($spreadsheet))->save('php://output');
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
            'auto_advance' => true,
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
                $tournament->update(['active_category_id' => null, 'active_stream_session_id' => null]);
            }

            $category->delete();
        });

        return redirect()->route('secretary.tournament', $tournament)
            ->with('status', "Поток «{$name}» удалён вместе с выступлениями и оценками.");
    }

    /**
     * Полная очистка турнира: потоки (категории) с выступлениями/оценками/музыкой,
     * группы, пул участниц (entries) и привязанные к турниру атлеты.
     * Атлеты удаляются только если не задействованы в других турнирах.
     */
    public function clearTournamentCategories(Tournament $tournament): RedirectResponse
    {
        $stats = ['categories' => 0, 'groups' => 0, 'entries' => 0, 'athletes' => 0];

        DB::transaction(function () use ($tournament, &$stats) {
            // Атлеты, связанные с турниром (через пул и через выступления) — фиксируем
            // ДО удаления, потом проверим, не заняты ли они в других турнирах.
            $athleteIds = Entry::query()
                ->where('tournament_id', $tournament->id)
                ->pluck('athlete_id')
                ->merge(
                    Performance::query()
                        ->join('categories', 'categories.id', '=', 'performances.category_id')
                        ->where('categories.tournament_id', $tournament->id)
                        ->pluck('performances.athlete_id')
                )
                ->filter()
                ->unique()
                ->values();

            $categories = Category::query()
                ->where('tournament_id', $tournament->id)
                ->get();

            foreach ($categories as $cat) {
                $this->purgeCategoryMusic($cat);
                $cat->delete();
                $stats['categories']++;
            }

            $stats['groups'] = Group::query()->where('tournament_id', $tournament->id)->delete();
            $stats['entries'] = Entry::query()->where('tournament_id', $tournament->id)->delete();

            $tournament->update(['active_category_id' => null, 'active_stream_session_id' => null]);

            // Удаляем атлетов, которые после очистки больше нигде не используются.
            foreach ($athleteIds as $athleteId) {
                $usedElsewhere = Entry::query()->where('athlete_id', $athleteId)->exists()
                    || Performance::query()->where('athlete_id', $athleteId)->exists();

                if (! $usedElsewhere) {
                    Athlete::query()->whereKey($athleteId)->delete();
                    $stats['athletes']++;
                }
            }
        });

        $msg = ($stats['categories'] + $stats['groups'] + $stats['entries'] + $stats['athletes']) > 0
            ? "Турнир очищен: потоков — {$stats['categories']}, групп — {$stats['groups']}, участниц в пуле — {$stats['entries']}, атлетов удалено — {$stats['athletes']}."
            : 'В турнире нечего было очищать.';

        return redirect()->route('secretary.tournament', $tournament)->with('status', $msg);
    }

    /**
     * Страница «Группы и потоки»: пул участниц (entries) по (программа/год/категория),
     * уже созданные группы и их потоки.
     */
    public function groups(Tournament $tournament): View
    {
        $tournament->load([
            'categories' => fn ($q) => $q->orderedByPerformanceTime(),
            'groups' => fn ($q) => $q->orderBy('order_index')->orderBy('id'),
            'groups.categories' => fn ($q) => $q->orderedByPerformanceTime(),
            'groups.categories.sessions',
            'groups.entries' => fn ($q) => $q->orderBy('stream_no')->orderBy('start_number')->orderBy('order_index'),
            'groups.entries.athlete.members',
        ]);

        // Пул: entries, ещё не привязанные к группе, сгруппированные по (программа, год, категория).
        // Включаем список участниц — чтобы видеть состав ДО создания группы.
        $pool = Entry::query()
            ->with('athlete.members')
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

                $participants = $rows->map(function (Entry $e) {
                    $athlete = $e->athlete;
                    $name = $athlete
                        ? trim(($athlete->last_name ?? '').' '.($athlete->first_name ?? ''))
                        : '—';

                    return [
                        'entry_id' => $e->id,
                        'name' => $name,
                        'club' => $e->club ?? $athlete?->club,
                        'iin' => $athlete?->iin,
                        'year' => $athlete?->birthdate?->year,
                        'is_team' => (bool) $athlete?->is_team,
                        'team_id' => $athlete?->is_team ? $athlete->id : null,
                        'members' => $athlete?->is_team
                            ? $athlete->members->map(fn ($m) => trim($m->last_name.' '.$m->first_name).($m->birthdate ? ' '.$m->birthdate->year : ''))->all()
                            : [],
                    ];
                })->sortBy('name')->values();

                return [
                    'key' => $first->program.'|'.($first->birth_year ?? '0').'|'.($first->division ?? ''),
                    'target_entry_id' => $first->id,
                    'program' => $first->program,
                    'birth_year' => $first->birth_year,
                    'division' => $first->division,
                    'label' => $first->meta['label'] ?? null,
                    'count' => $rows->count(),
                    'participants' => $participants,
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

        // Команды групповой программы, импортированные из одного Excel-листа,
        // можно жеребить между системными группами как неделимые записи.
        $excelGroupShuffleSets = $tournament->groups
            ->flatMap(function (Group $group) {
                return $group->entries
                    ->filter(fn (Entry $entry) => $entry->program === 'group' && $entry->importSheet() !== null)
                    ->map(function (Entry $entry) use ($group) {
                        return [
                            'sheet' => $entry->importSheet(),
                            'group' => $group,
                            'entry' => $entry,
                        ];
                    });
            })
            ->groupBy('sheet')
            ->map(function (Collection $rows, string $sheet) {
                $groups = $rows
                    ->groupBy(fn (array $row) => $row['group']->id)
                    ->map(function (Collection $groupRows) {
                        /** @var Group $group */
                        $group = $groupRows->first()['group'];

                        return [
                            'id' => $group->id,
                            'name' => $group->name,
                            'count' => $groupRows->count(),
                        ];
                    })
                    ->values();

                return [
                    'sheet' => $sheet,
                    'count' => $rows->count(),
                    'groups' => $groups,
                    'can_shuffle' => $groups->count() >= 2 && $rows->count() >= 2,
                ];
            })
            ->sortBy('sheet')
            ->values();

        return view('secretary.groups', [
            'tournament' => $tournament,
            'pool' => $pool,
            'groupEntryCounts' => $groupEntryCounts,
            'excelGroupShuffleSets' => $excelGroupShuffleSets,
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
            'apparatus_mode' => ['nullable', 'string', 'in:fixed,choice'],
            'apparatus' => ['required_if:apparatus_mode,fixed', 'array', 'min:1'],
            'apparatus.*' => ['string', Rule::in(PerformanceApparatus::RG_APPARATUS)],
            'apparatus_count' => ['required_if:apparatus_mode,choice', 'nullable', 'integer', 'min:1', 'max:6'],
            'number_mode' => ['nullable', 'string', 'in:continuous,per_stream'],
        ], [
            'apparatus.required' => 'Выберите хотя бы один предмет.',
        ]);

        $apparatusMode = $data['apparatus_mode'] ?? 'fixed';
        $division = isset($data['division']) && trim($data['division']) !== ''
            ? strtoupper(trim($data['division']))
            : null;
        $birthYear = isset($data['birth_year']) ? (int) $data['birth_year'] : null;

        $group = DB::transaction(fn () => $this->createGroupFromPool(
            $tournament,
            $data['program'],
            $birthYear,
            $division,
            $apparatusMode === 'fixed' ? array_values($data['apparatus'] ?? []) : [],
            $data['number_mode'] ?? 'per_stream',
            $apparatusMode,
            $apparatusMode === 'choice' ? (int) $data['apparatus_count'] : null,
        ));

        $attached = Entry::query()->where('group_id', $group->id)->count();

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', "Группа «{$group->name}» создана, участниц привязано: {$attached}. Теперь сформируйте потоки.");
    }

    /**
     * Собрать турнир из файла в один клик: по каждому пулу (программа/год/категория)
     * создаётся группа с общим набором предметов и сразу нарезаются потоки с
     * каскадным расписанием (следующая группа стартует после предыдущей).
     * Повторный запуск обрабатывает только новые (непривязанные) пулы.
     */
    public function assembleTournament(Request $request, Tournament $tournament, StreamBuilderService $builder): RedirectResponse
    {
        $data = $request->validate([
            'apparatus' => ['required', 'array', 'min:1'],
            'apparatus.*' => ['string', Rule::in(PerformanceApparatus::RG_APPARATUS)],
            'group_apparatus' => ['nullable', 'array'],
            'group_apparatus.*' => ['string', Rule::in(PerformanceApparatus::RG_APPARATUS)],
            'stream_size' => ['required', 'integer', 'min:1', 'max:200'],
            'number_mode' => ['nullable', 'string', 'in:continuous,per_stream'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'minutes_per_athlete' => ['nullable', 'integer', 'min:1', 'max:60'],
        ], [
            'apparatus.required' => 'Выберите хотя бы один предмет для индивидуальных.',
        ]);

        $indivApparatus = array_values($data['apparatus']);
        // Групповым — свои предметы; если не указаны, берём индивидуальные.
        $groupApparatus = ! empty($data['group_apparatus']) ? array_values($data['group_apparatus']) : $indivApparatus;
        $streamSize = (int) $data['stream_size'];
        $numberMode = $data['number_mode'] ?? 'per_stream';
        $minutesPerAthlete = isset($data['minutes_per_athlete']) ? (int) $data['minutes_per_athlete'] : null;

        // Пулы: непривязанные entries по (программа, год, категория) в порядке дня —
        // индивидуальные раньше групповых (групповые отдельной секцией), младшие раньше.
        $pools = Entry::query()
            ->where('tournament_id', $tournament->id)
            ->whereNull('group_id')
            ->selectRaw('program, birth_year, division')
            ->groupBy('program', 'birth_year', 'division')
            ->orderByRaw("case when program = 'individual' then 0 else 1 end")
            ->orderByDesc('birth_year')
            ->orderBy('division')
            ->get();

        if ($pools->isEmpty()) {
            return back()->withErrors(['assemble' => 'В пуле нет непривязанных участниц — импортируйте список или все пулы уже разобраны по группам.']);
        }

        $groupsCreated = 0;
        $streamsCreated = 0;

        DB::transaction(function () use ($pools, $tournament, $builder, $indivApparatus, $groupApparatus, $streamSize, $numberMode, $minutesPerAthlete, $data, &$groupsCreated, &$streamsCreated) {
            $groups = collect();
            foreach ($pools as $pool) {
                $birthYear = $pool->birth_year !== null ? (int) $pool->birth_year : null;
                $division = $pool->division !== null && trim((string) $pool->division) !== ''
                    ? strtoupper(trim((string) $pool->division))
                    : null;

                $apparatus = $pool->program === 'group' ? $groupApparatus : $indivApparatus;
                $groups->push($this->createGroupFromPool($tournament, $pool->program, $birthYear, $division, $apparatus, $numberMode));
            }

            $groupsCreated = $groups->count();
            $streamsCreated = $this->cascadeStreams($groups, $streamSize, $data['start_time'] ?? null, $minutesPerAthlete, $numberMode, $builder, 'tournament:'.$tournament->id.':assembled');
        });

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', "Турнир собран: групп создано {$groupsCreated}, потоков {$streamsCreated}. Предметы/время можно поправить по каждой группе.");
    }

    /**
     * Массовое формирование потоков сразу по всем группам турнира (единый размер
     * потока + каскадное расписание дня). Предметы каждой группы сохраняются.
     */
    public function generateAllStreams(Request $request, Tournament $tournament, StreamBuilderService $builder): RedirectResponse
    {
        $data = $request->validate([
            'stream_size' => ['required', 'integer', 'min:1', 'max:200'],
            'number_mode' => ['nullable', 'string', 'in:continuous,per_stream'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'minutes_per_athlete' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $groups = $tournament->groups()
            ->orderByRaw("case when program = 'individual' then 0 else 1 end")
            ->orderByDesc('birth_year')
            ->orderBy('division')
            ->orderBy('order_index')
            ->get();

        if ($groups->isEmpty()) {
            return back()->withErrors(['streams' => 'Сначала создайте группы.']);
        }

        $minutesPerAthlete = isset($data['minutes_per_athlete']) ? (int) $data['minutes_per_athlete'] : null;
        $streamsCreated = 0;

        DB::transaction(function () use ($groups, $data, $minutesPerAthlete, $builder, $tournament, &$streamsCreated) {
            $streamsCreated = $this->cascadeStreams(
                $groups,
                (int) $data['stream_size'],
                $data['start_time'] ?? null,
                $minutesPerAthlete,
                $data['number_mode'] ?? null, // null → у каждой группы свой режим
                $builder,
                'tournament:'.$tournament->id.':all',
            );
        });

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', "Потоки сформированы по всем группам ({$groups->count()}): всего потоков {$streamsCreated}.");
    }

    /**
     * Нарезать потоки для набора групп подряд с каскадным расписанием дня:
     * следующая группа стартует, когда закончилась предыдущая.
     *
     * @param  Collection<int, Group>  $groups
     * @return int число сформированных потоков
     */
    private function cascadeStreams($groups, int $streamSize, ?string $startTime, ?int $minutesPerAthlete, ?string $numberModeOverride, StreamBuilderService $builder, string $scheduleChain): int
    {
        $streamSize = max(1, $streamSize);
        $cursor = ($startTime !== null && $minutesPerAthlete !== null)
            ? Carbon::createFromFormat('H:i', $startTime)->startOfMinute()
            : null;

        $streamsCreated = 0;
        $sequence = 0;
        foreach ($groups as $group) {
            $count = Entry::query()->where('group_id', $group->id)->count();
            $streamCount = (int) ceil($count / $streamSize);

            $times = [];
            if ($cursor !== null) {
                for ($i = 0; $i < $streamCount; $i++) {
                    $athletesInStream = min($streamSize, max(0, $count - ($i * $streamSize)));
                    $start = $cursor->format('H:i');
                    $cursor = $cursor->copy()->addMinutes($athletesInStream * $minutesPerAthlete);
                    $times[] = [
                        'start' => $start,
                        'end' => $cursor->format('H:i'),
                        'minutes_per_athlete' => $minutesPerAthlete,
                        'schedule_chain' => $scheduleChain,
                        'schedule_sequence' => ++$sequence,
                    ];
                }
            }

            $mode = $numberModeOverride ?? $group->number_mode ?? 'per_stream';
            $builder->generateStreams($group, $streamSize, $times, $mode);
            $streamsCreated += $streamCount;
        }

        return $streamsCreated;
    }

    /**
     * Создать группу из непривязанного пула и привязать подходящие entries.
     *
     * @param  list<string>  $apparatus
     */
    private function createGroupFromPool(Tournament $tournament, string $program, ?int $birthYear, ?string $division, array $apparatus, string $numberMode, string $apparatusMode = 'fixed', ?int $apparatusCount = null): Group
    {
        // метка пула («2020 и мл» и т.п.), если есть у entries.
        $label = Entry::query()
            ->where('tournament_id', $tournament->id)
            ->whereNull('group_id')
            ->where('program', $program)
            ->when($birthYear !== null, fn ($q) => $q->where('birth_year', $birthYear), fn ($q) => $q->whereNull('birth_year'))
            ->when($division !== null, fn ($q) => $q->where('division', $division), fn ($q) => $q->whereNull('division'))
            ->value('meta->label');

        $group = Group::query()->create([
            'tournament_id' => $tournament->id,
            'program' => $program,
            'birth_year' => $birthYear,
            'birth_year_label' => is_string($label) ? $label : null,
            'division' => $division,
            'name' => $this->groupName($birthYear, $division, is_string($label) ? $label : null),
            'apparatus' => $apparatus,
            'apparatus_selection_mode' => $apparatusMode,
            'apparatus_count' => $apparatusCount,
            'number_mode' => $numberMode,
            'order_index' => (int) (Group::query()->where('tournament_id', $tournament->id)->max('order_index') ?? 0) + 1,
        ]);

        Entry::query()
            ->where('tournament_id', $tournament->id)
            ->whereNull('group_id')
            ->where('program', $program)
            ->when($birthYear !== null, fn ($q) => $q->where('birth_year', $birthYear), fn ($q) => $q->whereNull('birth_year'))
            ->when($division !== null, fn ($q) => $q->where('division', $division), fn ($q) => $q->whereNull('division'))
            ->update(['group_id' => $group->id]);

        return $group;
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
            'iin' => ['nullable', 'string', 'regex:/^\d{12}$/'],
            'group_id' => ['nullable', 'integer'],
        ], [
            'iin.regex' => 'ИИН должен состоять из 12 цифр.',
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
        $iin = isset($data['iin']) && $data['iin'] !== '' ? $data['iin'] : null;

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

        DB::transaction(function () use ($tournament, $group, $program, $birthYear, $division, $club, $iin, $lastName, $firstName, $birthdate, $builder) {
            $athlete = $this->resolveOrCreateAthlete($lastName, $firstName, $birthdate, $club, $iin);

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

    private function resolveOrCreateAthlete(string $lastName, string $firstName, ?Carbon $birthdate, ?string $club, ?string $iin = null, bool $isTeam = false): Athlete
    {
        if ($iin !== null) {
            $byIin = Athlete::query()->where('iin', $iin)->first();
            if ($byIin !== null) {
                if ($club !== null && ($byIin->club === null || $byIin->club === '')) {
                    $byIin->update(['club' => $club]);
                }

                return $byIin;
            }
        }

        $q = Athlete::query()
            ->where('is_team', $isTeam)
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower($lastName)])
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($firstName)]);

        $q = $birthdate !== null ? $q->whereDate('birthdate', $birthdate) : $q->whereNull('birthdate');

        $found = $q->first();
        if ($found !== null) {
            $patch = [];
            if ($club !== null && ($found->club === null || $found->club === '')) {
                $patch['club'] = $club;
            }
            if ($iin !== null && ($found->iin === null || $found->iin === '')) {
                $patch['iin'] = $iin;
            }
            if ($patch !== []) {
                $found->update($patch);
            }

            return $found;
        }

        return Athlete::query()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birthdate' => $birthdate,
            'iin' => $iin,
            'is_team' => $isTeam,
            'club' => $club,
        ]);
    }

    /**
     * Ростер команды из текста (по одной участнице в строке: «ФИО [ГГГГ]»).
     * Возвращает sync-массив [athlete_id => ['position' => n]].
     *
     * @return array<int, array{position:int}>
     */
    private function rosterFromText(?string $text, ?string $club): array
    {
        $sync = [];
        $position = 0;
        foreach (preg_split('/\r\n|\r|\n/u', (string) $text) as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line === '') {
                continue;
            }
            $year = null;
            if (preg_match('/\b((?:19|20)\d{2})\b\s*$/u', $line, $m)) {
                $year = (int) $m[1];
                $line = trim((string) preg_replace('/\b'.$m[1].'\b\s*$/u', '', $line));
            }
            if (mb_strlen($line) < 2) {
                continue;
            }
            $parts = preg_split('/\s+/u', $line, 2);
            $last = $parts[0];
            $first = ($parts[1] ?? '') !== '' ? $parts[1] : '—';
            $birthdate = $year !== null ? Carbon::createFromDate($year, 1, 1)->startOfDay() : null;
            $member = $this->resolveOrCreateAthlete($last, $first, $birthdate, $club);
            $sync[$member->id] = ['position' => ++$position];
        }

        return $sync;
    }

    /**
     * Создать команду группового выступления (athlete.is_team) с ростером и завести
     * её в пул (Entry program=group). Если указана группа с потоками — добавить в поток.
     */
    public function storeTeam(Request $request, Tournament $tournament, StreamBuilderService $builder): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'birth_year' => ['nullable', 'integer', 'min:1990', 'max:2035'],
            'division' => ['nullable', 'string', 'max:16'],
            'club' => ['nullable', 'string', 'max:255'],
            'members' => ['nullable', 'string', 'max:5000'],
            'group_id' => ['nullable', 'integer'],
        ]);

        $group = null;
        if (! empty($data['group_id'])) {
            $group = Group::query()->where('tournament_id', $tournament->id)->find($data['group_id']);
            if ($group === null || $group->program !== 'group') {
                abort(404);
            }
        }

        $birthYear = $group?->birth_year ?? (isset($data['birth_year']) ? (int) $data['birth_year'] : null);
        $division = $group !== null
            ? $group->division
            : (isset($data['division']) && trim($data['division']) !== '' ? strtoupper(trim($data['division'])) : null);
        $club = isset($data['club']) && trim($data['club']) !== '' ? trim($data['club']) : null;
        $name = trim((string) preg_replace('/\s+/u', ' ', $data['name']));
        $birthdate = $birthYear !== null ? Carbon::createFromDate($birthYear, 1, 1)->startOfDay() : null;

        DB::transaction(function () use ($tournament, $group, $name, $birthdate, $birthYear, $division, $club, $data, $builder) {
            $team = $this->resolveOrCreateAthlete($name, '—', $birthdate, $club, null, true);
            $team->members()->sync($this->rosterFromText($data['members'] ?? null, $club));

            $entry = Entry::query()->firstOrCreate(
                ['tournament_id' => $tournament->id, 'athlete_id' => $team->id, 'program' => 'group'],
                [
                    'group_id' => $group?->id,
                    'birth_year' => $birthYear,
                    'division' => $division,
                    'club' => $club,
                    'order_index' => (int) (Entry::query()->where('tournament_id', $tournament->id)->max('order_index') ?? 0) + 1,
                    'meta' => ['manual' => true],
                ],
            );

            if ($group !== null) {
                $entry->group_id = $group->id;
                $maxStream = (int) (Category::query()->where('group_id', $group->id)->max('stream_no') ?? 0);
                if ($maxStream > 0) {
                    $entry->stream_no = $maxStream;
                }
                $entry->save();
                if ($maxStream > 0) {
                    $builder->renumber($group);
                }
            }
        });

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', "Команда «{$name}» создана (групповое выступление).");
    }

    /**
     * Обновить название и ростер команды.
     */
    public function updateTeam(Request $request, Athlete $team): RedirectResponse
    {
        abort_unless($team->is_team, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'club' => ['nullable', 'string', 'max:255'],
            'members' => ['nullable', 'string', 'max:5000'],
            'tournament_id' => ['required', 'integer'],
        ]);

        $club = isset($data['club']) && trim($data['club']) !== '' ? trim($data['club']) : $team->club;

        DB::transaction(function () use ($team, $data, $club) {
            $team->update([
                'last_name' => trim((string) preg_replace('/\s+/u', ' ', $data['name'])),
                'club' => $club,
            ]);
            $team->members()->sync($this->rosterFromText($data['members'] ?? null, $club));
        });

        return redirect()->route('secretary.tournament.groups', $data['tournament_id'])
            ->with('status', 'Состав команды обновлён.');
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
            'minutes_per_athlete' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $times = $this->buildStreamTimes(
            $group,
            (int) $data['stream_size'],
            $data['start_time'] ?? null,
            isset($data['minutes_per_athlete']) ? (int) $data['minutes_per_athlete'] : null,
        );

        $builder->generateStreams(
            $group,
            (int) $data['stream_size'],
            $times,
            $data['number_mode'] ?? $group->number_mode ?? 'per_stream',
        );

        $streams = $group->categories()->count();

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', "Потоки сформированы: {$streams}. Стартовые номера и очереди готовы.");
    }

    public function setGroupApparatus(Request $request, Tournament $tournament, Group $group, StreamBuilderService $builder): RedirectResponse
    {
        if ((int) $group->tournament_id !== (int) $tournament->id) {
            abort(404);
        }

        if (! $group->usesApparatusChoice()) {
            return back()->withErrors(['apparatus' => 'Эта группа создана с фиксированным набором предметов.']);
        }

        $data = $request->validate([
            'apparatus' => ['required', 'array', 'size:'.$group->apparatus_count],
            'apparatus.*' => ['string', 'distinct', Rule::in(PerformanceApparatus::RG_APPARATUS)],
        ], [
            'apparatus.size' => "Выберите ровно {$group->apparatus_count} предмета(ов).",
        ]);

        $hasStartedPerformances = Performance::query()
            ->whereIn('category_id', $group->categories()->select('id'))
            ->where('status', '!=', 'scheduled')
            ->exists();

        if ($hasStartedPerformances) {
            return back()->withErrors(['apparatus' => 'Нельзя изменить предметы: в одном из потоков уже начались выступления.']);
        }

        $group->update(['apparatus' => array_values($data['apparatus'])]);
        $builder->renumber($group);

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', 'Предметы сохранены, выступления и очереди обновлены.');
    }

    /**
     * Метки времени по потокам из времени начала и длительности на участницу.
     *
     * @return list<array{start:?string,end:?string,minutes_per_athlete:int,schedule_chain:string,schedule_sequence:int}>
     */
    private function buildStreamTimes(Group $group, int $streamSize, ?string $startTime, ?int $minutesPerAthlete): array
    {
        if ($startTime === null || $minutesPerAthlete === null || $streamSize < 1) {
            return [];
        }

        $athleteCount = max(0, $group->entries()->count());
        $streamCount = (int) ceil($athleteCount / $streamSize);
        if ($streamCount < 1) {
            return [];
        }

        $cursor = Carbon::createFromFormat('H:i', $startTime)->startOfMinute();
        $times = [];
        for ($i = 0; $i < $streamCount; $i++) {
            $athletesInStream = min($streamSize, max(0, $athleteCount - ($i * $streamSize)));
            $start = $cursor->format('H:i');
            $cursor = $cursor->copy()->addMinutes($athletesInStream * $minutesPerAthlete);
            $times[] = [
                'start' => $start,
                'end' => $cursor->format('H:i'),
                'minutes_per_athlete' => $minutesPerAthlete,
                'schedule_chain' => 'group:'.$group->id,
                'schedule_sequence' => $i + 1,
            ];
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
                    $tournament->update(['active_category_id' => null, 'active_stream_session_id' => null]);
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

        $hasStartedPerformances = Performance::query()
            ->whereIn('category_id', $entry->group->categories()->select('id'))
            ->where('status', '!=', 'scheduled')
            ->exists();
        if ($hasStartedPerformances) {
            return back()->withErrors(['entry' => 'Нельзя изменить поток после начала выступлений группы.']);
        }

        $builder->moveEntryToStream($entry, (int) $data['stream_no']);

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', 'Участница перенесена, номера пересчитаны.');
    }

    /**
     * Перенос участницы между однотипными группами из списка. Очереди в обеих
     * группах пересобираются только до первого начатого выступления.
     */
    public function moveEntryToGroup(Request $request, Tournament $tournament, Entry $entry, StreamBuilderService $builder): RedirectResponse
    {
        abort_unless((int) $entry->tournament_id === (int) $tournament->id, 404);

        $data = $request->validate(['target_group_id' => ['required', 'integer']]);
        $source = $entry->group;
        $target = Group::query()->where('tournament_id', $tournament->id)->find((int) $data['target_group_id']);
        if ($source === null || $target === null || $source->id === $target->id) {
            return back()->withErrors(['group_move' => 'Выберите другую существующую группу.']);
        }

        $sameKind = $source->program === $target->program
            && $source->birth_year === $target->birth_year
            && ($source->division ?? null) === ($target->division ?? null);

        $entrySheet = $entry->importSheet();
        $sameExcelSheet = $entry->program === 'group'
            && $target->program === 'group'
            && $entrySheet !== null
            && $target->entries()
                ->where('program', 'group')
                ->get(['meta'])
                ->contains(fn (Entry $targetEntry) => $targetEntry->importSheet() === $entrySheet);

        if (! $sameKind && ! $sameExcelSheet) {
            return back()->withErrors([
                'group_move' => 'Между разными годами можно переносить только групповые команды одного Excel-листа.',
            ]);
        }

        $locked = Category::query()
            ->whereIn('group_id', [$source->id, $target->id])
            ->whereHas('performances', fn ($q) => $q->where('status', '!=', 'scheduled'))
            ->exists();
        if ($locked) {
            return back()->withErrors(['group_move' => 'Нельзя менять состав после начала выступлений одной из групп.']);
        }

        DB::transaction(function () use ($entry, $source, $target, $builder) {
            $targetStream = (int) ($target->categories()->min('stream_no') ?? 1);
            $entry->update([
                'group_id' => $target->id,
                'stream_no' => max(1, $targetStream),
                'order_index' => (int) ($target->entries()->max('order_index') ?? 0) + 1,
            ]);
            $builder->renumber($source);
            $builder->renumber($target);
        });

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', ($entry->program === 'group' ? 'Команда' : 'Участница').' перенесена в другую группу; списки и очереди пересчитаны.');
    }

    /**
     * Поштучно перенести непривязанную участницу/команду в другой существующий
     * пул той же программы. Пул определяется годом рождения и категорией.
     */
    public function moveEntryToPool(Request $request, Tournament $tournament, Entry $entry): RedirectResponse
    {
        abort_unless((int) $entry->tournament_id === (int) $tournament->id, 404);

        $data = $request->validate([
            'target_entry_id' => ['required', 'integer'],
        ]);

        if ($entry->group_id !== null) {
            return back()->withErrors(['pool_move' => 'Перенос между пулами доступен только для непривязанных участниц.']);
        }

        $targetEntry = Entry::query()
            ->where('tournament_id', $tournament->id)
            ->whereNull('group_id')
            ->whereKey((int) $data['target_entry_id'])
            ->first();

        if ($targetEntry === null || $targetEntry->id === $entry->id) {
            return back()->withErrors(['pool_move' => 'Выбранный целевой пул больше не существует.']);
        }

        if ($targetEntry->program !== $entry->program) {
            return back()->withErrors(['pool_move' => 'Перенос возможен только между пулами одной программы.']);
        }

        $samePool = $targetEntry->birth_year === $entry->birth_year
            && ($targetEntry->division ?? null) === ($entry->division ?? null);
        if ($samePool) {
            return back()->withErrors(['pool_move' => 'Участница уже находится в выбранном пуле.']);
        }

        $meta = is_array($entry->meta) ? $entry->meta : [];
        $targetLabel = is_array($targetEntry->meta) ? ($targetEntry->meta['label'] ?? null) : null;
        if (is_string($targetLabel) && trim($targetLabel) !== '') {
            $meta['label'] = trim($targetLabel);
        } else {
            unset($meta['label']);
        }

        $entry->update([
            'birth_year' => $targetEntry->birth_year,
            'division' => $targetEntry->division,
            'meta' => $meta !== [] ? $meta : null,
        ]);

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', ($entry->program === 'group' ? 'Команда' : 'Участница').' перенесена в другой пул.');
    }

    /**
     * Ручная правка: пересчитать стартовые номера и очереди по текущему распределению.
     */
    public function reorderEntry(Request $request, Entry $entry, StreamBuilderService $builder): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'string', 'in:up,down'],
        ]);

        $group = $entry->group;
        $tournament = $entry->tournament;
        if ($group === null || $tournament === null || $entry->stream_no === null) {
            return back()->withErrors(['entry' => 'Участница ещё не распределена по потоку.']);
        }

        $hasStartedPerformances = Performance::query()
            ->whereIn('category_id', $group->categories()->select('id'))
            ->where('status', '!=', 'scheduled')
            ->exists();

        if ($hasStartedPerformances) {
            return back()->withErrors(['entry' => 'Нельзя изменить очередь: в этой группе уже начались выступления.']);
        }

        if (! $builder->moveEntryWithinStream($entry, $data['direction'])) {
            return back()->withErrors(['entry' => 'Эту участницу уже нельзя переместить дальше.']);
        }

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', 'Очередность выступлений обновлена.');
    }

    public function renumberGroup(Tournament $tournament, Group $group, StreamBuilderService $builder): RedirectResponse
    {
        if ((int) $group->tournament_id !== (int) $tournament->id) {
            abort(404);
        }

        $builder->renumber($group);

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', 'Номера и очереди пересчитаны.');
    }

    /**
     * Перемешать порядок участниц внутри потоков группы (жеребьёвка).
     */
    public function shuffleGroup(Tournament $tournament, Group $group, StreamBuilderService $builder): RedirectResponse
    {
        if ((int) $group->tournament_id !== (int) $tournament->id) {
            abort(404);
        }

        if (! Category::query()->where('group_id', $group->id)->exists()) {
            return back()->withErrors(['shuffle' => 'Сначала сформируйте потоки в группе.']);
        }

        $builder->shuffle($group);

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', 'Жеребьёвка выполнена: порядок в потоках перемешан.');
    }

    /**
     * Перемешать импортированные групповые команды между системными группами,
     * которые были сформированы из одного Excel-листа. Состав каждой команды
     * остаётся неизменным, размеры групп и потоков сохраняются.
     */
    public function shuffleImportedTeamsBetweenGroups(Request $request, Tournament $tournament, StreamBuilderService $builder): RedirectResponse
    {
        $data = $request->validate([
            'sheet' => ['required', 'string', 'max:255'],
        ]);

        try {
            $moved = $builder->shuffleImportedTeamsBetweenGroups($tournament, trim($data['sheet']));
        } catch (DomainException $exception) {
            return back()->withErrors(['excel_group_shuffle' => $exception->getMessage()]);
        }

        return redirect()->route('secretary.tournament.groups', $tournament)
            ->with('status', "Жеребьёвка команд из Excel выполнена: между группами перемещено {$moved}.");
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
            ->orderedByPerformanceTime()
            ->get();

        if ($categories->isEmpty()) {
            return view('secretary.tournament-live-empty', [
                'tournament' => $tournament,
            ]);
        }

        $combinedCategoryIds = $tournament->combinedLiveCategoryIds();
        $combinedRequested = $request->boolean('combined') && count($combinedCategoryIds) >= 2;
        $defaultId = $categories->first()->id;
        if ($combinedRequested) {
            $requestedId = (int) $request->query('category');
            $activeId = (int) ($tournament->active_category_id ?? 0);
            $defaultId = in_array($requestedId, $combinedCategoryIds, true)
                ? $requestedId
                : (in_array($activeId, $combinedCategoryIds, true) ? $activeId : $combinedCategoryIds[0]);
        }
        $categoryId = (int) $request->query('category', $defaultId);
        if ($combinedRequested && ! in_array($categoryId, $combinedCategoryIds, true)) {
            $categoryId = $defaultId;
        }
        $category = $categories->firstWhere('id', $categoryId);

        if ($category === null) {
            abort(404);
        }

        $session = $this->requestedStreamSession($request, $category);
        $tournament->update([
            'active_category_id' => $category->id,
            'active_stream_session_id' => $session?->id,
        ]);

        return view('secretary.queue', $this->queueViewData($category, $session));
    }

    public function storeStreamSession(Request $request, Tournament $tournament, Category $category, GroupStreamSessionService $sessions): RedirectResponse
    {
        $this->ensureCategoryInTournament($category, $tournament);
        $data = $this->validateStreamSession($request);

        try {
            $sessions->create($category, $data);
        } catch (DomainException $e) {
            return back()->withErrors(['session' => $e->getMessage()])->withInput();
        }

        return redirect()->to($this->groupSessionsReturnUrl($tournament, $category))
            ->with('status', 'Сессия добавлена во все потоки группы. Выступления по выбранным предметам распределены автоматически.');
    }

    public function updateStreamSession(Request $request, Tournament $tournament, Category $category, StreamSession $session, GroupStreamSessionService $sessions): RedirectResponse
    {
        $this->ensureCategoryInTournament($category, $tournament);
        abort_unless($session->category_id === $category->id, 404);
        $data = $this->validateStreamSession($request);

        try {
            $sessions->update($category, $session, $data);
        } catch (DomainException $e) {
            return back()->withErrors(['session' => $e->getMessage()])->withInput();
        }

        return redirect()->to($this->groupSessionsReturnUrl($tournament, $category))
            ->with('status', 'Расписание сессии обновлено во всех потоках группы.');
    }

    public function destroyStreamSession(Tournament $tournament, Category $category, StreamSession $session, GroupStreamSessionService $sessions): RedirectResponse
    {
        $this->ensureCategoryInTournament($category, $tournament);
        abort_unless($session->category_id === $category->id, 404);

        try {
            $sessions->delete($category, $session);
        } catch (DomainException $e) {
            return back()->withErrors(['session' => $e->getMessage()]);
        }

        return redirect()->to($this->groupSessionsReturnUrl($tournament, $category))
            ->with('status', 'Сессия удалена из всех потоков группы. Её выступления остались без даты.');
    }

    /** @return array<string, mixed> */
    private function validateStreamSession(Request $request): array
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:100'],
            'scheduled_on' => ['required', 'date'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i', 'after:starts_at'],
            'apparatus' => ['required', 'array', 'min:1'],
            'apparatus.*' => ['required', 'string', Rule::in(PerformanceApparatus::RG_APPARATUS)],
        ]);

        $data['apparatus'] = array_values(array_unique($data['apparatus']));

        return $data;
    }

    private function groupSessionsReturnUrl(Tournament $tournament, Category $category): string
    {
        $panelId = $category->group_id ?? $category->id;

        return route('secretary.tournament.groups', [
            'tournament' => $tournament,
            'open_group_sessions' => $panelId,
        ]).'#group-sessions-'.$panelId;
    }

    private function requestedStreamSession(Request $request, Category $category): ?StreamSession
    {
        $requestedId = $request->integer('session');
        if ($requestedId > 0) {
            return $this->findStreamSession($category, $requestedId, true);
        }

        return $category->sessions()->first();
    }

    private function findStreamSession(Category $category, mixed $sessionId, bool $required = false): ?StreamSession
    {
        if (! $sessionId) {
            return null;
        }

        $session = StreamSession::query()
            ->where('category_id', $category->id)
            ->find((int) $sessionId);

        if ($required && $session === null) {
            abort(404);
        }

        return $session;
    }

    private function ensureCategoryInTournament(Category $category, Tournament $tournament): void
    {
        abort_unless($category->tournament_id === $tournament->id, 404);
    }

    public function queue(Request $request, Category $category): View
    {
        $category->loadMissing('tournament');
        $session = $this->requestedStreamSession($request, $category);
        $category->tournament?->update([
            'active_category_id' => $category->id,
            'active_stream_session_id' => $session?->id,
        ]);

        return view('secretary.queue', $this->queueViewData($category, $session));
    }

    public function reviewQueue(Request $request, Category $category): View
    {
        $category->loadMissing('tournament');
        $session = $this->requestedStreamSession($request, $category);

        return view('secretary.stream-review', $this->queueViewData($category, $session));
    }

    /**
     * Лёгкий опрос для автообновления Live/очереди (оценки судей без WebSocket).
     */
    public function queuePing(Request $request, Category $category): JsonResponse
    {
        $category->loadMissing('tournament');
        $session = $this->requestedStreamSession($request, $category);
        $performances = Performance::query()
            ->where('category_id', $category->id)
            ->when(
                $session !== null,
                fn ($q) => $q->where('stream_session_id', $session->id),
                fn ($q) => $q->whereNull('stream_session_id'),
            )
            ->orderBy('order_index')
            ->orderBy('id')
            ->get(['id', 'status', 'order_index', 'updated_at', 'finalized_at', 'timer_started_at', 'timer_ended_at', 'actual_duration_seconds', 'time_penalty', 'd_score', 'a_score', 'e_score', 'penalty', 'total']);

        $ordered = SecretaryLiveUi::orderedPerformances($performances);
        $current = SecretaryLiveUi::currentPerformance($ordered);

        $perfSig = $performances->map(fn (Performance $p) => implode(':', [
            (string) $p->id,
            $p->status,
            (string) ($p->updated_at?->getTimestamp() ?? 0),
            (string) ($p->finalized_at?->getTimestamp() ?? 0),
            (string) ($p->timer_started_at?->getTimestamp() ?? 0),
            (string) ($p->timer_ended_at?->getTimestamp() ?? 0),
            (string) ($p->actual_duration_seconds ?? ''),
            (string) ($p->time_penalty ?? ''),
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
                ->get(['id', 'performance_id', 'judge_id', 'panel', 'subpanel', 'penalty_type', 'score', 'average_score', 'submitted_at', 'average_submitted_at', 'updated_at'])
                ->map(fn (JudgeScore $s) => implode(':', [
                    (string) $s->performance_id,
                    (string) $s->id,
                    (string) $s->judge_id,
                    $s->panel,
                    (string) ($s->subpanel ?? ''),
                    (string) ($s->penalty_type ?? ''),
                    (string) $s->score,
                    (string) ($s->average_score ?? ''),
                    (string) ($s->submitted_at?->getTimestamp() ?? 0),
                    (string) ($s->average_submitted_at?->getTimestamp() ?? 0),
                    (string) ($s->updated_at?->getTimestamp() ?? 0),
                ]))
                ->implode(';');
        }

        $catSig = $category->id.':'.($session?->id ?? 'all').':'.$category->updated_at?->getTimestamp().':'.implode(',', $category->inactiveJudgeSlotList()).':'.($category->autoAdvanceEnabled() ? '1' : '0');

        // Промежуточные нажатия судей остаются в журнале, но не должны заменять
        // всю Live-страницу. Журнал обновится при следующем значимом изменении.
        $rev = md5($perfSig."\n".$scoresDigest."\n".$catSig);

        $redirectUrl = null;
        $activeCategoryId = $category->tournament?->active_category_id;
        if ($category->tournament?->isCategoryInCombinedLiveQueue($category)
            && $category->tournament?->isCategoryInCombinedLiveQueue((int) $activeCategoryId)
            && $activeCategoryId
            && $activeCategoryId !== $category->id) {
            $redirectUrl = route('secretary.tournament.live', [
                'tournament' => $category->tournament_id,
                'category' => $activeCategoryId,
                'session' => $category->tournament?->active_stream_session_id,
                'combined' => $request->boolean('combined') ? 1 : null,
            ]);
        }

        return response()->json([
            'rev' => $rev,
            'current_performance_id' => $current?->id,
            'redirect_url' => $redirectUrl,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * @return array<string, mixed>
     */
    private function queueViewData(Category $category, ?StreamSession $session = null): array
    {
        $performances = Performance::query()
            ->with([
                'category.tournament',
                'athlete.members',
                'judgeScores.judge',
                'track',
                'trackBackup',
                'inquiries' => function ($q) {
                    $q->orderByDesc('id');
                },
            ])
            ->where('category_id', $category->id)
            ->when(
                $session !== null,
                fn ($q) => $q->where('stream_session_id', $session->id),
                fn ($q) => $q->whereNull('stream_session_id'),
            )
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
        $lastCompletedPerformance = $ordered
            ->filter(fn (Performance $performance) => $performance->id !== $currentPerformance?->id
                && ($performance->finalized_at !== null || in_array($performance->status, ['done', 'published'], true)))
            ->sortByDesc(fn (Performance $performance) => $performance->ended_at?->getTimestamp() ?? $performance->id)
            ->first();
        $streamStatus = SecretaryLiveUi::streamStatus($currentPerformance);
        $judgeSlots = SecretaryLiveUi::judgeSlots($currentPerformance, $category);
        $difficultyAverageRows = SecretaryLiveUi::difficultyAverageRows($currentPerformance);
        $difficultyAverageSlots = collect([
            'DB_AVG' => 'Средняя DB',
            'DA_AVG' => 'Средняя DA',
        ])->map(function (string $label, string $slot) use ($difficultyAverageRows) {
            $row = $difficultyAverageRows[$slot] ?? null;

            return [
                'slot' => $slot,
                'label' => $label,
                'ok' => $row?->average_submitted_at !== null && $row?->average_score !== null,
                'value' => $row?->average_score,
            ];
        })->values()->all();
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
                ->orderedByPerformanceTime()
                ->get()
            : collect();
        $combinedLiveQueue = collect();
        $combinedCategoryIds = $tournament?->combinedLiveCategoryIds() ?? [];
        $combinedStreams = collect();
        if ($tournament?->hasCombinedLiveQueue()) {
            $combinedStreams = Category::query()
                ->where('tournament_id', $category->tournament_id)
                ->whereIn('id', $combinedCategoryIds)
                ->get()
                ->sortBy(fn (Category $stream) => array_search($stream->id, $combinedCategoryIds, true))
                ->values();
        }
        $isCombinedLiveView = request()->boolean('combined')
            && count($combinedCategoryIds) >= 2
            && in_array($category->id, $combinedCategoryIds, true);
        if ($tournament?->hasCombinedLiveQueue()
            && in_array($category->id, $combinedCategoryIds, true)) {
            $sessionNo = $session?->session_no;
            $combinedLiveQueue = $combinedStreams->map(function (Category $stream) use ($sessionNo) {
                $targetSessionId = $sessionNo !== null
                    ? $stream->sessions()->where('session_no', $sessionNo)->value('id')
                    : null;
                $rows = $sessionNo !== null && $targetSessionId === null
                    ? collect()
                    : Performance::query()
                        ->with('athlete')
                        ->where('category_id', $stream->id)
                        ->when(
                            $targetSessionId !== null,
                            fn ($query) => $query->where('stream_session_id', $targetSessionId),
                            fn ($query) => $query->whereNull('stream_session_id'),
                        )
                        ->orderBy('order_index')
                        ->orderBy('id')
                        ->get();

                return [
                    'category' => $stream,
                    'session_id' => $targetSessionId,
                    'performances' => $rows,
                ];
            });
        }
        $combinedOrderedPerformances = $combinedLiveQueue
            ->flatMap(function (array $stream) {
                return SecretaryLiveUi::orderedPerformances($stream['performances'])
                    ->map(fn (Performance $performance) => [
                        'performance' => $performance,
                        'category' => $stream['category'],
                    ]);
            })
            ->values();
        $combinedStreamNames = $combinedStreams
            ->map(fn (Category $stream) => 'Поток '.($stream->stream_no ?? '#'.$stream->id))
            ->values();
        $combinedLiveQueueLabel = $combinedStreamNames->isNotEmpty()
            ? 'Объединённая очередь · '.$combinedStreamNames->implode(' + ')
            : null;
        $combinedLiveUrl = null;
        if ($tournament?->hasCombinedLiveQueue()) {
            $combinedAnchorId = in_array($category->id, $combinedCategoryIds, true)
                ? $category->id
                : (in_array((int) $tournament->active_category_id, $combinedCategoryIds, true)
                    ? (int) $tournament->active_category_id
                    : $combinedCategoryIds[0]);
            $combinedAnchor = $tournamentCategories->firstWhere('id', $combinedAnchorId);
            $combinedSessionId = null;
            if ($combinedAnchor !== null && $session?->session_no !== null) {
                $combinedSessionId = $combinedAnchor->sessions()
                    ->where('session_no', $session->session_no)
                    ->value('id');
            } elseif ($combinedAnchorId === $category->id) {
                $combinedSessionId = $session?->id;
            }
            $combinedLiveUrl = route('secretary.tournament.live', [
                'tournament' => $category->tournament_id,
                'category' => $combinedAnchorId,
                'session' => $combinedSessionId,
                'combined' => 1,
            ]);
        }

        // История по каждой гимнастке потока: все индивидуальные оценки доступны
        // прямо из общей таблицы, включая отключённые позднее судейские слоты.
        $scoreHistoryByPerformance = $ordered->mapWithKeys(function (Performance $performance) use ($category) {
            $slots = [];
            $rules = $performance->category?->scoring_rules ?? $category->scoring_rules ?? [];
            foreach (SecretaryLiveUi::scoreRowsBySlot($performance, $category, true) as $slot => $row) {
                if ($row === null) {
                    continue;
                }
                $score = $row->score !== null ? (float) $row->score : null;
                $isDeduction = in_array($row->panel, ['a', 'e'], true);
                $base = (float) ($rules[$row->panel.'_base'] ?? 10.0);
                $displayScore = $score;
                if ($isDeduction && $score !== null) {
                    $displayScore = max(0.0, $base - $score);
                }
                $slots[$slot] = [
                    'slot' => $slot,
                    'judge' => $row->judge?->name ?? '—',
                    'score' => $score !== null ? number_format($score, 3, '.', '') : '—',
                    'display_score' => $displayScore !== null ? number_format($displayScore, 3, '.', '') : '—',
                    'display_label' => $isDeduction ? 'Сбавка' : 'Оценка',
                    'age_group' => $row->age_group,
                    'submitted_at' => $row->submitted_at?->format('H:i:s'),
                    'entries' => $row->entries ?? [],
                ];
            }

            $spread = SecretaryLiveUi::panelSpreadReport($performance, $category);

            return [$performance->id => [
                'performance_id' => $performance->id,
                'athlete' => trim(($performance->athlete?->last_name ?? '').' '.($performance->athlete?->first_name ?? '')),
                'update_url' => route('secretary.performance.updateJudgeScore', $performance),
                'return_url' => route('secretary.performance.returnScores', $performance),
                'live_history_url' => route('secretary.performance.scoreLiveHistory', $performance),
                'slots' => $slots,
                'spread' => $spread,
            ]];
        })->all();
        $scoreHistory = $currentPerformance
            ? ($scoreHistoryByPerformance[$currentPerformance->id]['slots'] ?? [])
            : [];

        $liveJudgeActions = $currentPerformance
            ? JudgeScoreAction::query()
                ->with('judge:id,name')
                ->where('performance_id', $currentPerformance->id)
                ->latest('id')
                ->limit(100)
                ->get()
                ->reverse()
                ->values()
                ->map(function (JudgeScoreAction $action) {
                    $lastEntry = collect($action->entries ?? [])->last();
                    $entryLabel = is_array($lastEntry)
                        ? trim((string) ($lastEntry['label'] ?? $lastEntry['symbol'] ?? ''))
                        : '';

                    return [
                        'id' => $action->id,
                        'slot' => $action->slot ?? strtoupper($action->panel),
                        'judge' => $action->judge?->name ?? 'Судья',
                        'action' => $action->action,
                        'draft_score' => $action->draft_score !== null ? number_format($action->draft_score, 3, '.', '') : null,
                        'entry_label' => $entryLabel,
                        'entries_count' => count($action->entries ?? []),
                        'created_at' => $action->created_at?->format('H:i:s'),
                    ];
                })
                ->all()
            : [];

        return [
            'category' => $category,
            'streamSession' => $session,
            'categorySessions' => $category->sessions()->get(),
            'tournamentCategories' => $tournamentCategories,
            'combinedLiveQueue' => $combinedLiveQueue,
            'combinedOrderedPerformances' => $combinedOrderedPerformances,
            'combinedLiveQueueLabel' => $combinedLiveQueueLabel,
            'combinedLiveUrl' => $combinedLiveUrl,
            'isCombinedLiveView' => $isCombinedLiveView,
            'performances' => $performances,
            'orderedPerformances' => $ordered,
            'currentPerformance' => $currentPerformance,
            'nextPerformance' => $nextPerformance,
            'lastCompletedPerformance' => $lastCompletedPerformance,
            'streamStatus' => $streamStatus,
            'judgeSlots' => $judgeSlots,
            'difficultyAverageSlots' => $difficultyAverageSlots,
            'scoreMatrix' => $scoreMatrix,
            'panelSpread' => $panelSpread,
            'waitingJudges' => $waitingJudges,
            'totalJudgeSlots' => $totalJudgeSlots,
            'activeJudgeSlots' => $activeJudgeSlots,
            'athletes' => $athletes,
            'scoreHistory' => $scoreHistory,
            'scoreHistoryByPerformance' => $scoreHistoryByPerformance,
            'historyJudgeColumns' => SecretaryLiveUi::ALL_JUDGE_SLOTS,
            'liveJudgeActions' => $liveJudgeActions,
            'queueRev' => $this->queuePing(request(), $category)->getData(true)['rev'] ?? null,
        ];
    }

    /**
     * Подтвердить итог несмотря на расхождение оценок (секретарь / главный судья).
     */
    public function confirmScore(Performance $performance): RedirectResponse
    {
        $performance->load(['judgeScores.judge', 'category']);
        $performance->recalculateTotals();
        $category = $performance->category;

        if (! $performance->scores_overridden) {
            if (! SecretaryLiveUi::requiredScoresSubmitted($performance, $category)) {
                return back()->withErrors(['confirm' => 'Не все обязательные оценки выставлены — подтверждать пока нечего.']);
            }

            if (! SecretaryLiveUi::requiredManualAveragesSubmitted($performance, $category)) {
                return back()->withErrors(['confirm' => 'Планшеты средней DB и DA ещё не отправили официальные значения.']);
            }

            if (! SecretaryLiveUi::requiredPenaltyInputsSubmitted($performance, $category)) {
                return back()->withErrors(['confirm' => 'Не все активные штрафные позиции (LINE/TIME/RESP) завершили работу.']);
            }
        }

        if ($performance->total === null) {
            return back()->withErrors(['confirm' => 'Итог не рассчитан: проверьте состав активных панелей и оценки.']);
        }

        if ($performance->timer_started_at !== null && $performance->timer_ended_at === null) {
            return back()->withErrors(['confirm' => 'Хронометрист ещё не остановил таймер этого выступления.']);
        }

        $moved = false;
        $transactionError = null;
        DB::transaction(function () use ($performance, &$moved, &$transactionError) {
            $locked = Performance::query()->lockForUpdate()->findOrFail($performance->id);
            $locked->load(['judgeScores.judge', 'category']);
            $locked->recalculateTotals();
            $category = $locked->category;

            $normalScoreInvalid = ! $locked->scores_overridden
                && (! SecretaryLiveUi::requiredScoresSubmitted($locked, $category)
                    || ! SecretaryLiveUi::requiredManualAveragesSubmitted($locked, $category)
                    || ! SecretaryLiveUi::requiredPenaltyInputsSubmitted($locked, $category));

            if ($normalScoreInvalid
                || $locked->total === null
                || ($locked->timer_started_at !== null && $locked->timer_ended_at === null)) {
                $transactionError = 'Состав оценок изменился во время подтверждения. Проверьте результат ещё раз.';

                return;
            }

            $locked->finalized_at = now();
            $locked->approved_at = now();
            $locked->save();

            if ($category?->autoAdvanceEnabled() && $locked->status === 'performing') {
                $moved = StreamAdvanceService::advanceToNextInCategory($category, $locked->stream_session_id);
            }
        });

        if ($transactionError !== null) {
            return back()->withErrors(['confirm' => $transactionError]);
        }

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

        return DB::transaction(function () use ($performance, $data) {
            $performance = Performance::query()->lockForUpdate()->findOrFail($performance->id);
            $returned = 0;
            $label = '';
            $timerReturned = false;

            if (! empty($data['slot'])) {
                $performance->load(['judgeScores.judge', 'category']);
                $rows = SecretaryLiveUi::scoreRowsBySlot($performance, $performance->category);
                $row = $rows[$data['slot']] ?? null;

                if ($data['slot'] === 'TIME'
                    && ! SecretaryLiveUi::isSlotInactive($performance->category, 'TIME')
                    && ($performance->timer_started_at !== null || $performance->timer_ended_at !== null)) {
                    $performance->timer_started_at = null;
                    $performance->timer_ended_at = null;
                    $performance->actual_duration_seconds = null;
                    $performance->time_penalty = 0;
                    $performance->timer_revision_requested_at = now();
                    $timerReturned = true;
                    $returned = 1;
                    $label = 'TIME';
                } elseif ($row === null || $row->submitted_at === null) {
                    return back()->withErrors(['return' => 'Для слота '.$data['slot'].' нет отправленной оценки — возвращать нечего.']);
                } else {
                    $row->submitted_at = null;
                    $row->average_score = null;
                    $row->average_submitted_at = null;
                    $row->save();
                    $returned = 1;
                    $label = $data['slot'];

                }
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

                $returned = $query->update([
                    'submitted_at' => null,
                    'average_score' => null,
                    'average_submitted_at' => null,
                ]);

                if (in_array($key, ['db', 'da', 'all'], true)) {
                    $averageQuery = JudgeScore::query()
                        ->where('performance_id', $performance->id)
                        ->where('panel', 'd')
                        ->whereNotNull('average_submitted_at');
                    if ($key !== 'all') {
                        $averageQuery->where('subpanel', $key);
                    }
                    $returned += $averageQuery->update([
                        'average_score' => null,
                        'average_submitted_at' => null,
                    ]);
                }

                if (in_array($key, ['penalty', 'all'], true)) {
                    $performance->loadMissing('category');
                    if (! SecretaryLiveUi::isSlotInactive($performance->category, 'TIME')
                        && ($performance->timer_started_at !== null || $performance->timer_ended_at !== null)) {
                        $performance->timer_started_at = null;
                        $performance->timer_ended_at = null;
                        $performance->actual_duration_seconds = null;
                        $performance->time_penalty = 0;
                        $performance->timer_revision_requested_at = now();
                        $timerReturned = true;
                        $returned++;
                    }
                }
            }

            if (! $timerReturned) {
                $performance->refresh();
            }
            $performance->load(['judgeScores', 'category']);
            $performance->recalculateTotals();
            $performance->finalized_at = null;
            $performance->approved_at = null;
            $performance->published_at = null;
            $performance->scoreboard_accepted_at = null;
            $performance->scoreboard_accepted_by = null;
            $performance->save();

            return back()->with('status', 'На доработку возвращено: '.$label.' ('.$returned.' шт.). Для соответствующего судьи возвращённая оценка откроется при следующем входе на планшет.');
        });
    }

    /**
     * Текущие действия одного судейского слота для модального Live-просмотра.
     * Ничего не меняет в оценке и не переключает активный поток турнира.
     */
    public function scoreLiveHistory(Performance $performance, Request $request): JsonResponse
    {
        $data = $request->validate([
            'slot' => ['required', 'string', Rule::in(SecretaryLiveUi::ALL_JUDGE_SLOTS)],
        ]);
        $slot = strtoupper((string) $data['slot']);
        $performance->loadMissing(['athlete', 'category.tournament', 'judgeScores.judge']);
        $category = $performance->category;
        abort_unless($category !== null, 404);

        $row = SecretaryLiveUi::scoreRowsBySlot($performance, $category, true)[$slot] ?? null;
        $score = null;
        if ($row !== null) {
            $rawScore = $row->score !== null ? (float) $row->score : null;
            $isDeduction = in_array($row->panel, ['a', 'e'], true);
            $rules = $category->scoring_rules ?? [];
            $base = (float) ($rules[$row->panel.'_base'] ?? 10.0);
            $displayScore = $isDeduction && $rawScore !== null
                ? max(0.0, $base - $rawScore)
                : $rawScore;
            $score = [
                'judge' => $row->judge?->name ?? '—',
                'score' => $rawScore !== null ? number_format($rawScore, 3, '.', '') : '—',
                'display_score' => $displayScore !== null ? number_format($displayScore, 3, '.', '') : '—',
                'display_label' => $isDeduction ? 'Сбавка' : 'Оценка',
                'submitted_at' => $row->submitted_at?->format('H:i:s'),
                'age_group' => $row->age_group,
                'entries' => $row->entries ?? [],
            ];
        }

        $actions = JudgeScoreAction::query()
            ->with('judge:id,name')
            ->where('performance_id', $performance->id)
            ->where('slot', $slot)
            // Незавершённый первый шаг DB/DA не является выставленной оценкой.
            // Такие записи могли сохраниться у клиентов старой версии.
            ->where('action', 'not like', 'Выбран элемент:%')
            ->where('action', 'not like', 'Выбран тип сотрудничества:%')
            ->where('action', '!=', 'Включён режим: акробатика')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (JudgeScoreAction $action) => [
                'id' => $action->id,
                'judge' => $action->judge?->name ?? 'Судья',
                'action' => $action->action,
                'draft_score' => $action->draft_score !== null
                    ? number_format((float) $action->draft_score, 3, '.', '')
                    : null,
                'entries' => $action->entries ?? [],
                'created_at' => $action->created_at?->format('H:i:s'),
            ])
            ->all();

        return response()->json([
            'ok' => true,
            'performance_id' => $performance->id,
            'athlete' => trim(($performance->athlete?->last_name ?? '').' '.($performance->athlete?->first_name ?? '')),
            'slot' => $slot,
            'score' => $score,
            'actions' => $actions,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
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

        return DB::transaction(function () use ($performance, $data) {
            $performance = Performance::query()->lockForUpdate()->findOrFail($performance->id);
            $performance->load(['judgeScores.judge', 'category']);
            $rows = SecretaryLiveUi::scoreRowsBySlot($performance, $performance->category, true);
            $row = $rows[$data['slot']] ?? null;

            if ($row === null) {
                return back()->withErrors(['edit' => 'Для слота '.$data['slot'].' нет оценки — исправлять нечего.']);
            }

            $row->score = (float) $data['score'];
            $row->save();

            $performance->refresh();
            $performance->load(['judgeScores', 'category']);
            $performance->recalculateTotals();
            $performance->finalized_at = null;
            $performance->approved_at = null;
            $performance->published_at = null;
            $performance->scoreboard_accepted_at = null;
            $performance->scoreboard_accepted_by = null;
            $performance->save();

            return back()->with('status', 'Оценка '.$data['slot'].' исправлена на '.number_format((float) $data['score'], 3, '.', '').'.');
        });
    }

    /**
     * Выставить финальную оценку вручную (секретарь / главный судья): DB/DA/A/E/сбавка.
     * Единый d_score сохраняется как обратная совместимость для старых форм/API.
     */
    public function setFinalScore(Performance $performance, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'db_score' => ['nullable', 'required_with:da_score', 'numeric', 'min:0', 'max:99.999'],
            'da_score' => ['nullable', 'required_with:db_score', 'numeric', 'min:0', 'max:99.999'],
            'd_score' => ['nullable', 'required_without_all:db_score,da_score', 'numeric', 'min:0', 'max:99.999'],
            'a_score' => ['required', 'numeric', 'min:0', 'max:99.999'],
            'e_score' => ['required', 'numeric', 'min:0', 'max:99.999'],
            'penalty' => ['nullable', 'numeric', 'min:0', 'max:99.999'],
        ], [], [
            'db_score' => 'оценка DB',
            'da_score' => 'оценка DA',
            'd_score' => 'оценка D',
            'a_score' => 'оценка A',
            'e_score' => 'оценка E',
            'penalty' => 'сбавка',
        ]);

        $hasSplitD = isset($data['db_score'], $data['da_score']);
        $dbScore = $hasSplitD ? round((float) $data['db_score'], 3) : null;
        $daScore = $hasSplitD ? round((float) $data['da_score'], 3) : null;
        $dScore = $hasSplitD
            ? round($dbScore + $daScore, 3)
            : round((float) $data['d_score'], 3);

        $penalty = isset($data['penalty']) && $data['penalty'] !== null && $data['penalty'] !== ''
            ? round((float) $data['penalty'], 3)
            : null;

        DB::transaction(function () use ($performance, $request, $data, $penalty, $hasSplitD, $dbScore, $daScore, $dScore) {
            $performance = Performance::query()->lockForUpdate()->findOrFail($performance->id);
            $performance->d_score = $dScore;
            if ($hasSplitD) {
                $performance->db_average = $dbScore;
                $performance->da_average = $daScore;
            }
            $performance->a_score = round((float) $data['a_score'], 3);
            $performance->e_score = round((float) $data['e_score'], 3);
            $performance->penalty = $penalty;
            $performance->scores_overridden = true;
            $performance->scores_overridden_by = $request->user()?->id;
            $performance->scores_overridden_at = now();
            $performance->load('category');
            $performance->recalculateTotals();
            $performance->finalized_at = now();
            $performance->approved_at = null;
            $performance->published_at = null;
            $performance->scoreboard_accepted_at = null;
            $performance->scoreboard_accepted_by = null;
            $performance->save();
        });
        $performance->refresh();

        return back()->with('status', 'Финальная оценка выставлена вручную и зафиксирована: итог '
            .SecretaryLiveUi::formatScore($performance->total !== null ? (float) $performance->total : null).'.');
    }

    /**
     * Снять ручной режим: вернуться к расчёту итога по оценкам судей.
     */
    public function clearFinalOverride(Performance $performance): RedirectResponse
    {
        DB::transaction(function () use ($performance) {
            $performance = Performance::query()->lockForUpdate()->findOrFail($performance->id);
            $performance->scores_overridden = false;
            $performance->scores_overridden_by = null;
            $performance->scores_overridden_at = null;
            $performance->finalized_at = null;
            $performance->approved_at = null;
            $performance->published_at = null;
            $performance->scoreboard_accepted_at = null;
            $performance->scoreboard_accepted_by = null;
            $performance->load(['judgeScores', 'category']);
            $performance->recalculateTotals();
            $performance->save();
        });

        return back()->with('status', 'Ручной режим снят — итог снова считается по оценкам судей.');
    }

    /**
     * Снять выступление со старта: статус «withdrawn», стартовый номер сохраняется,
     * очередь его пропускает, в протокол не идёт. Если было текущим — вызовется следующая.
     */
    public function withdrawPerformance(Performance $performance, StreamScheduleService $schedule): RedirectResponse
    {
        if ($performance->isWithdrawn()) {
            return back()->with('status', 'Выступление уже снято.');
        }

        $advanced = false;
        DB::transaction(function () use ($performance, &$advanced) {
            $locked = Performance::query()->lockForUpdate()->findOrFail($performance->id);
            if ($locked->isWithdrawn()) {
                return;
            }

            $wasPerforming = $locked->status === 'performing';
            $locked->status = 'withdrawn';
            $locked->withdrawn_at = now();
            $locked->save();

            if ($wasPerforming) {
                $locked->loadMissing('category');
                $advanced = StreamAdvanceService::advanceToNextInCategory(
                    $locked->category,
                    $locked->stream_session_id,
                );
            }
        });
        $performance->refresh()->loadMissing('category');
        $schedule->recalculate($performance->category);

        $name = $performance->loadMissing('athlete')->athlete?->last_name ?? 'участница';

        $message = "Снята со старта: {$name} (№ {$performance->start_number} сохранён).";
        if ($advanced) {
            $message .= ' Вызвана следующая гимнастка.';
        }

        return back()->with('status', $message);
    }

    /**
     * Вернуть снятое выступление в очередь (scheduled).
     */
    public function restorePerformance(Performance $performance, StreamScheduleService $schedule): RedirectResponse
    {
        if (! $performance->isWithdrawn()) {
            return back()->with('status', 'Выступление и так в очереди.');
        }

        DB::transaction(function () use ($performance) {
            $performance = Performance::query()->lockForUpdate()->findOrFail($performance->id);
            $performance->judgeScores()->update([
                'submitted_at' => null,
                'average_score' => null,
                'average_submitted_at' => null,
            ]);
            $performance->fill([
                'status' => 'scheduled',
                'withdrawn_at' => null,
                'called_at' => null,
                'started_at' => null,
                'timer_started_at' => null,
                'timer_ended_at' => null,
                'timer_revision_requested_at' => null,
                'ended_at' => null,
                'actual_duration_seconds' => null,
                'time_penalty' => 0,
                'd_score' => null,
                'db_average' => null,
                'da_average' => null,
                'a_score' => null,
                'e_score' => null,
                'penalty' => null,
                'total' => null,
                'scores_overridden' => false,
                'scores_overridden_by' => null,
                'scores_overridden_at' => null,
                'finalized_at' => null,
                'approved_at' => null,
                'published_at' => null,
                'scoreboard_accepted_at' => null,
                'scoreboard_accepted_by' => null,
            ]);
            $performance->save();
        });
        $performance->refresh()->loadMissing('category');
        $schedule->recalculate($performance->category);

        return back()->with('status', 'Возвращена в очередь.');
    }

    public function addToQueue(Request $request, Category $category, StreamScheduleService $schedule): RedirectResponse
    {
        $data = $request->validate([
            'athlete_id' => ['required', 'integer', 'exists:athletes,id'],
            'apparatus' => ['nullable', 'string', 'max:64'],
            'start_number' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'position' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'stream_session_id' => ['nullable', 'integer'],
        ]);

        $session = $this->findStreamSession($category, $data['stream_session_id'] ?? null, isset($data['stream_session_id']));

        $maxOrder = (int) (Performance::query()
            ->where('category_id', $category->id)
            ->when(
                $session !== null,
                fn ($q) => $q->where('stream_session_id', $session->id),
                fn ($q) => $q->whereNull('stream_session_id'),
            )
            ->max('order_index') ?? 0);

        $orderIndex = isset($data['position']) && $data['position']
            ? (int) $data['position']
            : ($maxOrder + 1);

        // Make room if inserting into the middle.
        Performance::query()
            ->where('category_id', $category->id)
            ->when(
                $session !== null,
                fn ($q) => $q->where('stream_session_id', $session->id),
                fn ($q) => $q->whereNull('stream_session_id'),
            )
            ->where('order_index', '>=', $orderIndex)
            ->increment('order_index');

        Performance::query()->create([
            'category_id' => $category->id,
            'stream_session_id' => $session?->id,
            'athlete_id' => (int) $data['athlete_id'],
            'apparatus' => PerformanceApparatus::normalize($data['apparatus'] ?? null),
            'start_number' => $data['start_number'] ?? null,
            'order_index' => $orderIndex,
            'status' => 'scheduled',
        ]);
        $schedule->recalculate($category);

        return back()->with('status', 'Добавлено в очередь.');
    }

    public function reorderQueue(Request $request, Category $category, StreamScheduleService $schedule)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
            'stream_session_id' => ['nullable', 'integer'],
        ]);

        $session = $this->findStreamSession($category, $data['stream_session_id'] ?? null);

        $ids = array_values(array_map('intval', $data['ids']));
        $ids = array_values(array_unique($ids));

        $performances = Performance::query()
            ->where('category_id', $category->id)
            ->when(
                $session !== null,
                fn ($q) => $q->where('stream_session_id', $session->id),
                fn ($q) => $q->whereNull('stream_session_id'),
            )
            ->whereIn('id', $ids)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get(['id', 'status']);

        $existing = $performances->pluck('id')->all();

        sort($existing);
        $check = $ids;
        sort($check);

        foreach ($performances as $position => $performance) {
            if ($existing === $check && $performance->status !== 'scheduled' && (int) $ids[$position] !== (int) $performance->id) {
                abort(422, 'Current, called, and completed performances cannot be moved.');
            }
        }

        if ($existing !== $check) {
            abort(422, 'Некорректный список выходов для этой категории.');
        }

        DB::transaction(function () use ($ids, $category, $session) {
            $i = 1;
            foreach ($ids as $id) {
                Performance::query()
                    ->where('category_id', $category->id)
                    ->when(
                        $session !== null,
                        fn ($q) => $q->where('stream_session_id', $session->id),
                        fn ($q) => $q->whereNull('stream_session_id'),
                    )
                    ->where('id', $id)
                    ->update(['order_index' => $i]);
                $i++;
            }

            if ($session !== null) {
                $this->synchronizeSessionOrder($category, $session);
            }
        });
        $schedule->recalculate($category);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('status', 'Очередь обновлена.');
    }

    public function removeFromQueue(Performance $performance, StreamScheduleService $schedule): RedirectResponse
    {
        $category = $performance->category;
        $categoryId = $category->id;
        $removedOrder = (int) $performance->order_index;
        $performance->delete();

        Performance::query()
            ->where('category_id', $categoryId)
            ->where('order_index', '>', $removedOrder)
            ->decrement('order_index');
        $schedule->recalculate($category);

        return back()->with('status', 'Удалено из очереди.');
    }

    public function moveQueue(Performance $performance, Request $request, StreamScheduleService $schedule): RedirectResponse
    {
        $data = $request->validate([
            'dir' => ['required', 'string', 'in:up,down'],
        ]);

        $dir = $data['dir'];
        $categoryId = $performance->category_id;

        if ($performance->status !== 'scheduled') {
            return back()->withErrors(['queue' => 'Only scheduled performances can be moved.']);
        }

        $neighbor = Performance::query()
            ->where('category_id', $categoryId)
            ->where('stream_session_id', $performance->stream_session_id)
            ->where('id', '!=', $performance->id)
            ->where('status', 'scheduled')
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

        if ($performance->stream_session_id !== null) {
            $session = $this->findStreamSession($performance->category, $performance->stream_session_id, true);
            $this->synchronizeSessionOrder($performance->category, $session);
        }
        $schedule->recalculate($performance->category);

        return back()->with('status', 'Порядок обновлён во всех ещё не начатых сессиях этого потока.');
    }

    /**
     * Переносит порядок спортсменок из одной сессии во все ещё не начатые сессии
     * потока. Исторические/текущие сессии не меняем, чтобы не нарушить Live.
     */
    private function synchronizeSessionOrder(Category $category, StreamSession $source): void
    {
        $sourcePerformances = Performance::query()
            ->where('category_id', $category->id)
            ->where('stream_session_id', $source->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get(['id', 'athlete_id', 'order_index']);

        $positionByAthlete = [];
        foreach ($sourcePerformances as $position => $performance) {
            $athleteId = (int) $performance->athlete_id;
            if (! array_key_exists($athleteId, $positionByAthlete)) {
                $positionByAthlete[$athleteId] = $position;
            }
        }

        if ($positionByAthlete === []) {
            return;
        }

        foreach ($category->sessions()->whereKeyNot($source->id)->get() as $targetSession) {
            $targetQuery = Performance::query()
                ->where('category_id', $category->id)
                ->where('stream_session_id', $targetSession->id);

            if ((clone $targetQuery)->where('status', '!=', 'scheduled')->exists()) {
                continue;
            }

            $targetPerformances = $targetQuery
                ->orderBy('order_index')
                ->orderBy('id')
                ->get(['id', 'athlete_id', 'order_index']);

            $targetPerformances = $targetPerformances
                ->sortBy(fn (Performance $performance) => $positionByAthlete[(int) $performance->athlete_id] ?? PHP_INT_MAX)
                ->values();

            foreach ($targetPerformances as $position => $performance) {
                $performance->update(['order_index' => $position + 1]);
            }
        }
    }

    public function callNext(Request $request, Category $category): RedirectResponse
    {
        $session = $this->findStreamSession($category, $request->input('stream_session_id'));
        $session ??= $category->sessions()->first();
        StreamAdvanceService::advanceToNextInCategory($category, $session?->id);

        return back();
    }

    public function setAutoAdvance(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);
        $enabled = (bool) $data['enabled'];
        $category->update(['auto_advance' => $enabled]);

        return back()->with('status', $enabled
            ? 'Автопереход включён для этого потока.'
            : 'Автопереход выключен: переход выполняется только вручную.');
    }

    public function setTournamentCombinedLiveQueue(Request $request, Tournament $tournament): RedirectResponse
    {
        $data = $request->validate([
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'distinct'],
        ]);

        $requestedIds = collect($data['category_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $selectedIds = $tournament->categories()
            ->whereIn('id', $requestedIds)
            ->orderedByPerformanceTime()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($selectedIds) !== $requestedIds->count()) {
            return back()->withErrors(['combined_queue' => 'Один из выбранных потоков не относится к этому турниру.']);
        }
        if (count($selectedIds) === 1) {
            return back()->withErrors(['combined_queue' => 'Для объединения выберите минимум два потока или снимите все отметки.']);
        }

        $tournament->update(['live_queue_category_ids' => $selectedIds]);

        return redirect()->to(route('secretary.tournament.groups', $tournament).'#tournament-live-queue')
            ->with('status', count($selectedIds) >= 2
                ? 'Выбранные потоки объединены только для Live-очереди. Протоколы, места и выгрузки не меняются.'
                : 'Объединённая Live-очередь этой группы отключена.');
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

        $category->loadMissing('tournament');
        $category->tournament?->update(['inactive_judge_slots' => $current]);
        Category::query()
            ->where('tournament_id', $category->tournament_id)
            ->update(['inactive_judge_slots' => $current]);

        Performance::query()
            ->whereHas('category', fn ($query) => $query->where('tournament_id', $category->tournament_id))
            ->whereNull('approved_at')
            ->where('scores_overridden', false)
            ->each(function (Performance $performance) {
                $performance->load(['judgeScores.judge', 'category.tournament']);
                $performance->recalculateTotals();
                $performance->finalized_at = null;
                $performance->published_at = null;
                $performance->scoreboard_accepted_at = null;
                $performance->scoreboard_accepted_by = null;
                $performance->save();
            });

        $message = $shouldBeActive
            ? "Слот {$slot} включён для всего турнира."
            : "Слот {$slot} отключён для всего турнира — оценки этой позиции не требуются.";

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

    public function start(Request $request, Performance $performance): RedirectResponse
    {
        if ($request->boolean('return_previous')) {
            return $this->returnToPreviousPerformance($request, $performance);
        }

        $error = null;
        DB::transaction(function () use ($performance, &$error) {
            $locked = Performance::query()->lockForUpdate()->findOrFail($performance->id);
            if (! in_array($locked->status, ['scheduled', 'on_deck'], true)) {
                $error = 'Запустить можно только ожидающее или вызванное выступление.';

                return;
            }

            $alreadyPerforming = Performance::query()
                ->where('category_id', $locked->category_id)
                ->where('stream_session_id', $locked->stream_session_id)
                ->where('status', 'performing')
                ->whereKeyNot($locked->id)
                ->lockForUpdate()
                ->exists();
            if ($alreadyPerforming) {
                $error = 'В этой сессии уже идёт другое выступление.';

                return;
            }

            $locked->status = 'performing';
            $locked->started_at = now();
            $locked->timer_started_at = null;
            $locked->timer_ended_at = null;
            $locked->timer_revision_requested_at = null;
            $locked->ended_at = null;
            $locked->actual_duration_seconds = null;
            $locked->time_penalty = 0;
            $locked->save();
        });

        if ($error !== null) {
            return back()->withErrors(['start' => $error]);
        }

        return back();
    }

    private function returnToPreviousPerformance(Request $request, Performance $performance): RedirectResponse
    {
        $categoryId = (int) $performance->category_id;
        $sessionId = $performance->stream_session_id === null ? null : (int) $performance->stream_session_id;
        if ($request->filled('stream_session_id') && $request->integer('stream_session_id') !== $sessionId) {
            abort(404);
        }

        $error = null;
        DB::transaction(function () use ($performance, $categoryId, $sessionId, &$error): void {
            $previous = Performance::query()->lockForUpdate()->findOrFail($performance->id);
            if ($previous->status !== 'done') {
                $error = 'Вернуть можно только последнее завершённое выступление.';

                return;
            }

            $current = Performance::query()
                ->where('category_id', $categoryId)
                ->when(
                    $sessionId !== null,
                    fn ($query) => $query->where('stream_session_id', $sessionId),
                    fn ($query) => $query->whereNull('stream_session_id'),
                )
                ->where('status', 'performing')
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                $error = 'Нет текущей гимнастки, переход к которой можно отменить.';

                return;
            }

            $latestCompleted = Performance::query()
                ->where('category_id', $categoryId)
                ->when(
                    $sessionId !== null,
                    fn ($query) => $query->where('stream_session_id', $sessionId),
                    fn ($query) => $query->whereNull('stream_session_id'),
                )
                ->where('status', 'done')
                ->orderByDesc('ended_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($latestCompleted === null || $latestCompleted->id !== $previous->id) {
                $error = 'Вернуться можно только к предыдущей гимнастке.';

                return;
            }

            $currentHasActivity = $current->timer_started_at !== null
                || $current->timer_ended_at !== null
                || $current->actual_duration_seconds !== null
                || $current->finalized_at !== null
                || $current->approved_at !== null
                || $current->published_at !== null
                || $current->scores_overridden
                || $current->judgeScores()->exists()
                || $current->judgeScoreActions()->exists();

            if ($currentHasActivity) {
                $error = 'Нельзя вернуться назад: по текущей гимнастке уже запущен таймер или началось судейство.';

                return;
            }

            $current->status = 'scheduled';
            $current->called_at = null;
            $current->started_at = null;
            $current->timer_started_at = null;
            $current->timer_ended_at = null;
            $current->timer_revision_requested_at = null;
            $current->ended_at = null;
            $current->actual_duration_seconds = null;
            $current->time_penalty = 0;
            $current->save();

            $previous->status = 'performing';
            $previous->ended_at = null;
            $previous->save();
        });

        if ($error !== null) {
            return back()->withErrors(['start' => $error]);
        }

        $performance->loadMissing('category.tournament');
        $performance->category?->tournament?->update([
            'active_category_id' => $categoryId,
            'active_stream_session_id' => $sessionId,
        ]);

        return back()->with('status', 'Возвращена предыдущая гимнастка. Порядок выступления не изменён.');
    }

    public function finish(Performance $performance): RedirectResponse
    {
        $error = null;
        DB::transaction(function () use ($performance, &$error) {
            $performance = Performance::query()->lockForUpdate()->findOrFail($performance->id);
            if ($performance->status !== 'performing') {
                $error = 'Завершить можно только текущее выступление.';

                return;
            }

            if ($performance->timer_started_at !== null && $performance->timer_ended_at === null) {
                $error = 'Хронометрист ещё не остановил официальный таймер этого выступления.';

                return;
            }

            $performance->status = 'done';
            $performance->loadMissing('category');
            $performance->recordFinishedAt();
            $performance->recalculateTotals();
            $performance->save();
        });

        if ($error !== null) {
            return back()->withErrors(['finish' => $error]);
        }

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
