@php
    $apparatusLabel = $currentPerformance?->apparatus ?? $category->apparatus ?? '—';
    $statusLabel = match ($streamStatus) {
        'waiting_scores' => 'ожидание оценок',
        'finalized' => 'итог зафиксирован',
        'on_deck' => 'вызвана',
        'scheduled' => 'ожидание',
        'done' => 'завершено',
        'empty' => 'поток пуст',
        default => $streamStatus,
    };
    $d = $currentPerformance?->d_score;
    $a = $currentPerformance?->a_score;
    $e = $currentPerformance?->e_score;
    $pen = $currentPerformance?->penalty;
    $sumDisplay = null;
    if ($d !== null && $a !== null && $e !== null) {
        $sumDisplay = (float) $d + (float) $a + (float) $e - (float) ($pen ?? 0);
    }
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3 w-full">
            <a class="text-sm text-emerald-400 hover:text-emerald-300" href="{{ route('secretary.tournament', $category->tournament_id) }}">← {{ $category->tournament?->name ?? 'Турнир' }}</a>
        </div>
    </x-slot>

    <div class="py-6 space-y-6 max-w-[1600px] mx-auto">
        <x-flash />

        @if($category->tournament)
            <x-card>
                <div class="font-semibold text-slate-100">Импорт стартового протокола (Excel)</div>
                <p class="text-sm text-slate-400 mt-1">
                    Тот же импорт, что на странице турнира. Данные попадают в турнир
                    <span class="text-slate-200">«{{ $category->tournament->name }}»</span>
                    <span class="font-mono text-slate-500">#{{ $category->tournament->id }}</span>
                    — название в файле Excel не выбирает турнир.
                </p>
                <form method="POST" action="{{ route('secretary.tournament.importStartProtocol', $category->tournament) }}" enctype="multipart/form-data" class="mt-4 flex flex-col sm:flex-row sm:items-end gap-3">
                    @csrf
                    <div class="flex-1 min-w-0">
                        <x-input-label for="protocol_queue" value="Файл .xls / .xlsx" />
                        <input id="protocol_queue" name="protocol" type="file" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            class="mt-1 block w-full text-sm text-slate-200 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-800 file:text-emerald-300 hover:file:bg-slate-700 border border-slate-700 rounded-lg bg-slate-950/50" />
                    </div>
                    <x-primary-button class="shrink-0 justify-center">Импортировать потоки</x-primary-button>
                </form>
            </x-card>
        @endif

        {{-- Заголовок Live --}}
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-white">Live — Секретарь</h1>
                <p class="mt-1 text-sm text-slate-400">
                    {{ $category->tournament?->name ?? 'Турнир' }}
                    @if($category->tournament)
                        <span class="text-slate-500">• ID: {{ $category->tournament->id }}</span>
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <span class="inline-flex items-center gap-2 rounded-full border border-slate-700 bg-slate-900/80 px-3 py-1.5 text-slate-200">
                    <span class="h-2 w-2 rounded-full {{ $streamStatus === 'waiting_scores' ? 'bg-amber-400 shadow-[0_0_10px_rgba(251,191,36,0.6)]' : 'bg-emerald-400' }}" title="{{ $statusLabel }}"></span>
                    Статус: <span class="font-mono text-xs text-amber-200/90">{{ $streamStatus }}</span>
                </span>
                <span class="rounded-full border border-slate-700 bg-slate-900/80 px-3 py-1.5 text-slate-300">
                    Поток <span class="font-mono text-emerald-400">#{{ $category->id }}</span>
                </span>
                <span class="rounded-full border border-slate-700 bg-slate-900/80 px-3 py-1.5 text-slate-300">
                    Предмет: <span class="text-white">{{ $apparatusLabel }}</span>
                </span>
            </div>
        </div>

        {{-- Сетка карточек --}}
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            {{-- Управление потоком --}}
            <div class="live-panel p-5 xl:col-span-1">
                <h2 class="text-base font-semibold text-white">Управление потоком</h2>
                <p class="mt-1 text-xs text-slate-500">
                    @if(isset($tournamentCategories) && $tournamentCategories->isNotEmpty() && $category->tournament)
                        Один экран на весь турнир: переключайте поток (из импорта Excel: группа → поток). Выбор предмета сохраняется в карточке выступления.
                    @else
                        Поток «{{ $category->name }}». Выбор предмета сохраняется в карточке выступления.
                    @endif
                </p>

                @if(isset($tournamentCategories) && $tournamentCategories->isNotEmpty() && $category->tournament)
                    <div class="mt-4 flex flex-wrap items-end gap-3">
                        <div class="min-w-[min(100%,280px)] flex-1">
                            <label for="stream_select" class="block text-xs font-medium text-slate-400 mb-1">Поток</label>
                            <select id="stream_select" name="stream"
                                class="block w-full rounded-xl border border-slate-700 bg-slate-950/80 px-3 py-2.5 text-sm text-slate-100 focus:ring-emerald-500 focus:border-emerald-500"
                                onchange="if (this.value) window.location.href = this.value;">
                                @foreach($tournamentCategories as $tc)
                                    <option value="{{ route('secretary.tournament.live', $category->tournament) }}?category={{ $tc->id }}"
                                        @selected($tc->id === $category->id)>
                                        Поток #{{ $tc->id }} · {{ $tc->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @endif

                @if($currentPerformance)
                    <div class="mt-4 rounded-xl border border-slate-700/80 bg-slate-950/60 p-4">
                        <div class="text-xs uppercase tracking-wider text-slate-500">Текущая гимнастка</div>
                        <div class="mt-2 text-lg font-medium text-white">
                            {{ $currentPerformance->athlete->last_name }} {{ $currentPerformance->athlete->first_name }}
                        </div>
                        <div class="mt-1 text-sm text-slate-400">
                            Год: {{ $currentPerformance->athlete->birthdate?->format('Y') ?? '—' }}
                            • Кат.: —
                            • {{ Str::limit($currentPerformance->athlete->club ?? '—', 48) }}
                        </div>
                        <div class="mt-4">
                            <label class="block text-xs font-medium text-slate-400 mb-1">Предмет для этой гимнастки</label>
                            <div class="flex flex-wrap gap-2 items-center">
                                <span class="rounded-lg border border-emerald-700/50 bg-emerald-950/40 px-3 py-2 text-sm text-emerald-100">{{ $apparatusLabel }}</span>
                                <span class="text-xs text-slate-500">Изменить можно в расширенной таблице ниже (поле «Снаряд»).</span>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="mt-4 rounded-xl border border-dashed border-slate-700 p-6 text-center text-slate-500 text-sm">
                        Нет активной гимнастки. Нажмите «Начать поток» или добавьте участниц в очередь.
                    </div>
                @endif

                <div class="mt-5 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('secretary.callNext', $category) }}">
                        @csrf
                        <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-950/40 hover:bg-emerald-500">
                            {{ $currentPerformance ? 'Следующая гимнастка' : 'Начать поток' }}
                        </button>
                    </form>
                    @if($currentPerformance)
                        <form method="POST" action="{{ route('secretary.finish', $currentPerformance) }}" onsubmit="return confirm('Завершить выступление без следующей?');">
                            @csrf
                            <button type="submit" class="rounded-xl border border-rose-800/80 bg-rose-950/50 px-4 py-2.5 text-sm font-medium text-rose-100 hover:bg-rose-900/60">
                                Завершить
                            </button>
                        </form>
                    @endif
                </div>
                @if($nextPerformance)
                    <p class="mt-3 text-xs text-slate-500">Следом: <span class="text-slate-300">{{ $nextPerformance->athlete->last_name }} {{ $nextPerformance->athlete->first_name }}</span></p>
                @endif
            </div>

            {{-- Порядок выступления --}}
            <div class="live-panel p-5">
                <h2 class="text-base font-semibold text-white">Порядок выступления потока</h2>
                <p class="mt-1 text-xs text-slate-500">Список в порядке выхода. Текущая — подсвечена.</p>
                <ul class="mt-4 max-h-72 space-y-1 overflow-y-auto pr-1 text-sm">
                    @foreach($orderedPerformances as $p)
                        @php
                            $isCurrent = $currentPerformance && $currentPerformance->id === $p->id;
                            $tag = $p->apparatus ?? $category->apparatus ?? '—';
                        @endphp
                        <li class="flex items-center gap-3 rounded-lg px-3 py-2.5 {{ $isCurrent ? 'bg-emerald-950/50 ring-1 ring-emerald-700/40' : 'bg-slate-950/40 hover:bg-slate-900/60' }}">
                            <span class="text-slate-500 w-6 text-right font-mono">{{ $loop->iteration }}</span>
                            <span class="flex-1 min-w-0 text-slate-100 truncate">{{ $p->athlete->last_name }} {{ $p->athlete->first_name }}</span>
                            <span class="shrink-0 rounded-md border border-slate-600 bg-slate-900 px-2 py-0.5 text-xs text-slate-300">{{ $tag }}</span>
                            @if($isCurrent)
                                <span class="h-2 w-2 shrink-0 rounded-full bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.7)]"></span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Принудительный вызов --}}
            <div class="live-panel p-5">
                <h2 class="text-base font-semibold text-white">Принудительный вызов гимнастки</h2>
                <p class="mt-1 text-xs text-slate-500">Быстрый поиск и вызов в текущий поток (интеграция с API — в разработке).</p>
                <div class="mt-4 space-y-3 opacity-60 pointer-events-none">
                    <x-text-input class="w-full" placeholder="Поиск по ФИО (например: Иванова)" disabled />
                    <div class="flex flex-wrap gap-2">
                        <select class="rounded-lg border-slate-700 bg-slate-950/50 text-slate-400 text-sm" disabled>
                            <option>{{ $apparatusLabel }}</option>
                        </select>
                        <x-secondary-button class="opacity-50" type="button" disabled>Вызвать</x-secondary-button>
                    </div>
                </div>
                <p class="mt-3 text-xs text-slate-600">Работает даже если гимнастки нет в потоке — при появлении API она будет добавлена в конец как StreamEntry.</p>
            </div>

            {{-- Активные судьи --}}
            <div class="live-panel p-5">
                <h2 class="text-base font-semibold text-white">Активные судьи</h2>
                <p class="mt-1 text-xs text-slate-500">Ожидание: {{ $waitingJudges }}/{{ $totalJudgeSlots }} (по текущей гимнастке)</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach($judgeSlots as $slot)
                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-700 bg-slate-950/50 px-2.5 py-1.5 text-xs font-mono text-slate-200">
                            <span class="h-2 w-2 rounded-full {{ $slot['ok'] ? 'bg-emerald-400' : 'bg-amber-400' }}"></span>
                            {{ $slot['label'] }}
                        </span>
                    @endforeach
                </div>
                <p class="mt-4 text-xs text-slate-500">Точки: <span class="text-emerald-400">зелёный</span> — оценка пришла, <span class="text-amber-400">жёлтый</span> — ещё нет.</p>
            </div>
        </div>

        {{-- Оценки --}}
        <div class="live-panel p-5">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-white">Оценки (текущая гимнастка)</h2>
                    <p class="mt-1 text-xs text-slate-500">Судьи вводят оценки в своей панели. При включённом автопереходе после слотов <span class="text-slate-400">DB1, DA1, A1–A4, E1–E4</span> поток сам перейдёт к следующей (LINE/RESP не обязательны).</p>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <form method="POST" action="{{ route('secretary.category.autoAdvance', $category) }}" class="inline">
                        @csrf
                        <input type="hidden" name="enabled" value="{{ $category->auto_advance ? 0 : 1 }}">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg border px-2.5 py-1.5 font-medium transition focus:outline-none focus:ring-2 focus:ring-emerald-500/50 {{ $category->auto_advance ? 'border-emerald-600/80 bg-emerald-950/50 text-emerald-100' : 'border-slate-600 bg-slate-900 text-slate-400 hover:border-slate-500' }}">
                            <span class="h-2 w-2 rounded-full {{ $category->auto_advance ? 'bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.6)]' : 'bg-slate-500' }}"></span>
                            Автопереход: {{ $category->auto_advance ? 'Вкл' : 'Выкл' }}
                        </button>
                    </form>
                    <span class="rounded-lg border border-slate-700 bg-slate-900 px-2.5 py-1 text-slate-300">Ожидание: <span class="text-amber-200 font-mono">{{ $waitingJudges }}/{{ $totalJudgeSlots }}</span></span>
                    <span class="rounded-lg border border-slate-700 bg-slate-900 px-2.5 py-1 text-slate-300">Итого: <span class="text-white font-mono">{{ \App\Support\SecretaryLiveUi::formatScore($sumDisplay !== null ? (float) $sumDisplay : null) }}</span></span>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 sm:grid-cols-5 gap-3">
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4 text-center">
                    <div class="text-xs text-slate-500 uppercase">D</div>
                    <div class="mt-1 text-2xl font-semibold text-white font-mono">{{ \App\Support\SecretaryLiveUi::formatScore($d !== null ? (float) $d : null) }}</div>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4 text-center">
                    <div class="text-xs text-slate-500 uppercase">A</div>
                    <div class="mt-1 text-2xl font-semibold text-white font-mono">{{ \App\Support\SecretaryLiveUi::formatScore($a !== null ? (float) $a : null) }}</div>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4 text-center">
                    <div class="text-xs text-slate-500 uppercase">E</div>
                    <div class="mt-1 text-2xl font-semibold text-white font-mono">{{ \App\Support\SecretaryLiveUi::formatScore($e !== null ? (float) $e : null) }}</div>
                </div>
                <div class="rounded-xl border border-rose-900/40 bg-rose-950/30 p-4 text-center">
                    <div class="text-xs text-rose-200/80 uppercase">Penalty</div>
                    <div class="mt-1 text-2xl font-semibold text-rose-100 font-mono">{{ \App\Support\SecretaryLiveUi::formatScore($pen !== null ? (float) $pen : null) }}</div>
                </div>
                <div class="rounded-xl border border-teal-800/50 bg-teal-950/40 p-4 text-center col-span-2 sm:col-span-1">
                    <div class="text-xs text-teal-200/80 uppercase">D + A + E − Pen</div>
                    <div class="mt-1 text-2xl font-semibold text-teal-50 font-mono">{{ \App\Support\SecretaryLiveUi::formatScore($sumDisplay !== null ? (float) $sumDisplay : null) }}</div>
                </div>
            </div>

            <div class="mt-5 overflow-x-auto rounded-xl border border-slate-800">
                <table class="w-full min-w-[720px] text-center text-xs">
                    <thead>
                        <tr>
                            @foreach($scoreMatrix['columns'] as $col)
                                @php $isPen = $scoreMatrix['penalty'][$col] ?? false; @endphp
                                <th class="px-2 py-3 font-semibold {{ $isPen ? 'bg-rose-950/80 text-rose-100' : 'bg-slate-800 text-slate-200' }} border-b border-slate-900">{{ $col }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="bg-slate-950/80">
                            @foreach($scoreMatrix['columns'] as $col)
                                @php $isPen = $scoreMatrix['penalty'][$col] ?? false; @endphp
                                <td class="px-2 py-3 font-mono text-sm {{ $isPen ? 'text-rose-100' : 'text-slate-100' }} border-t border-slate-800">{{ $scoreMatrix['values'][$col] }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- История потока --}}
        <div class="live-panel p-5">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <div>
                    <h2 class="text-base font-semibold text-white">История гимнасток потока</h2>
                    <p class="mt-1 text-xs text-slate-500">Итоги по выступлениям в этом потоке (после расчёта).</p>
                </div>
                <span class="text-xs rounded-lg border border-emerald-800/60 bg-emerald-950/40 px-3 py-1 text-emerald-100">Расхождение: Вкл</span>
            </div>
            <div class="overflow-x-auto rounded-xl border border-slate-800">
                <table class="w-full min-w-[640px] text-sm">
                    <thead>
                        <tr class="border-b border-slate-800 bg-slate-900/90 text-left text-xs uppercase tracking-wide text-slate-400">
                            <th class="px-3 py-3">#</th>
                            <th class="px-3 py-3">Гимнастка</th>
                            <th class="px-3 py-3">Предмет</th>
                            <th class="px-3 py-3 text-right">D</th>
                            <th class="px-3 py-3 text-right">A</th>
                            <th class="px-3 py-3 text-right">E</th>
                            <th class="px-3 py-3 text-right text-rose-200">Pen.</th>
                            <th class="px-3 py-3 text-right text-teal-200">Итого</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @foreach($orderedPerformances as $p)
                            <tr class="hover:bg-slate-900/50 {{ $currentPerformance && $currentPerformance->id === $p->id ? 'bg-emerald-950/20' : '' }}">
                                <td class="px-3 py-2.5 font-mono text-slate-500">{{ $loop->iteration }}</td>
                                <td class="px-3 py-2.5 text-slate-100">{{ $p->athlete->last_name }} {{ $p->athlete->first_name }}</td>
                                <td class="px-3 py-2.5 text-slate-400">{{ $p->apparatus ?? $category->apparatus ?? '—' }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-slate-200">{{ \App\Support\SecretaryLiveUi::formatScore($p->d_score !== null ? (float) $p->d_score : null) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-slate-200">{{ \App\Support\SecretaryLiveUi::formatScore($p->a_score !== null ? (float) $p->a_score : null) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-slate-200">{{ \App\Support\SecretaryLiveUi::formatScore($p->e_score !== null ? (float) $p->e_score : null) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-rose-200/90">{{ \App\Support\SecretaryLiveUi::formatScore($p->penalty !== null ? (float) $p->penalty : null) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-teal-200">{{ \App\Support\SecretaryLiveUi::formatScore($p->total !== null ? (float) $p->total : null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Расширенное управление (очередь, музыка, drag) --}}
        <details class="live-panel p-5 group" open>
            <summary class="cursor-pointer list-none text-base font-semibold text-white flex items-center gap-2">
                <span class="text-slate-500 group-open:rotate-90 transition inline-block">▸</span>
                Расширенное управление очередью
            </summary>
            <div class="mt-5 space-y-5">
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <div class="font-medium text-slate-200">Записать атлета в категорию</div>
                        <a class="text-emerald-400 hover:text-emerald-300 text-sm font-medium" href="{{ route('secretary.athletes') }}">Атлеты →</a>
                    </div>
                    <form method="POST" action="{{ route('secretary.queue.add', $category) }}" class="grid grid-cols-1 md:grid-cols-6 gap-3">
                        @csrf
                        <div class="md:col-span-2">
                            <x-input-label value="Атлет" />
                            <select name="athlete_id" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 focus:ring-emerald-500 focus:border-emerald-500" required>
                                @foreach($athletes as $a)
                                    <option value="{{ $a->id }}">{{ $a->last_name }} {{ $a->first_name }}{{ $a->club ? ' · '.$a->club : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label value="Снаряд (опц.)" />
                            <x-text-input name="apparatus" class="mt-1 block w-full" placeholder="{{ $category->apparatus ?? 'Вид 1' }}" />
                        </div>
                        <div>
                            <x-input-label value="Старт № (опц.)" />
                            <x-text-input name="start_number" type="number" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label value="Позиция (опц.)" />
                            <x-text-input name="position" type="number" class="mt-1 block w-full" placeholder="в конец" />
                        </div>
                        <div class="flex items-end justify-end">
                            <x-primary-button>Добавить</x-primary-button>
                        </div>
                    </form>
                </div>

                <div id="secretary-music-upload" class="rounded-xl border border-violet-900/40 bg-slate-950/50 p-4 scroll-mt-24">
                    <div class="font-medium text-slate-200">Музыка для выхода</div>
                    <p class="text-xs text-slate-500 mt-1 mb-3">
                        Гимнастке не нужен свой аккаунт: файл привязывается к строке выхода в этом потоке (то же хранилище, что и при загрузке спортсменкой). После дедлайна обмена музыкой загружает только секретариат / администратор.
                    </p>
                    @if($performances->isEmpty())
                        <p class="text-sm text-slate-500">В этом потоке пока нет выходов — сначала импортируйте протокол или добавьте гимнасток в очередь.</p>
                    @else
                    <form method="POST" action="{{ route('secretary.category.performance.music', $category) }}" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-12 gap-3 items-end">
                        @csrf
                        <div class="lg:col-span-5">
                            <x-input-label for="sec_perf_music" value="Выход" />
                            <select id="sec_perf_music" name="performance_id" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 focus:ring-emerald-500 focus:border-emerald-500" required>
                                @php($defPerfId = old('performance_id', optional($performances->first())->id))
                                @foreach($performances as $p)
                                    @php($lab = ($p->start_number ?? '—').' · '.($p->apparatus ?? $category->apparatus ?? '—').' · '.$p->athlete->last_name.' '.$p->athlete->first_name)
                                    <option value="{{ $p->id }}" @selected((string) $defPerfId === (string) $p->id)>{{ $lab }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('performance_id')" class="mt-2" />
                        </div>
                        <div class="lg:col-span-2">
                            <x-input-label for="sec_music_type" value="Тип" />
                            <select id="sec_music_type" name="type" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="primary">Основной</option>
                                <option value="backup">Резерв</option>
                            </select>
                        </div>
                        <div class="lg:col-span-4">
                            <x-input-label for="sec_music_file" value="Файл (mp3 / m4a / wav, до 30 МБ)" />
                            <input id="sec_music_file" name="music" type="file" required class="mt-1 block w-full text-sm text-slate-200 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-800 file:text-emerald-300" />
                            <x-input-error :messages="$errors->get('music')" class="mt-2" />
                        </div>
                        <div class="lg:col-span-1 flex justify-end">
                            <x-primary-button class="w-full justify-center">ОК</x-primary-button>
                        </div>
                    </form>
                    @endif
                </div>

                <div class="flex items-center justify-between gap-3">
                    <x-badge tone="violet">{{ $performances->count() }} выходов</x-badge>
                    <span class="text-xs text-slate-500">Перетаскивание строк сохраняет порядок.</span>
                </div>

                <div class="-mx-2 px-2">
                    <div class="hidden lg:block overflow-x-auto">
                        <table class="w-full text-sm table-fixed min-w-[900px]">
                            <thead class="text-left text-slate-400">
                                <tr class="border-b border-slate-800">
                                    <th class="py-3 pr-2 font-medium w-8"></th>
                                    <th class="py-3 pr-4 font-medium w-16">№</th>
                                    <th class="py-3 pr-4 font-medium">Спортсменка</th>
                                    <th class="py-3 pr-4 font-medium w-28">Предмет</th>
                                    <th class="py-3 pr-4 font-medium w-48">Клуб</th>
                                    <th class="py-3 pr-4 font-medium w-28">Статус</th>
                                    <th class="py-3 pr-4 font-medium w-36">Музыка</th>
                                    <th class="py-3 text-right font-medium w-56">Действия</th>
                                </tr>
                            </thead>
                            <tbody id="queue-body" class="text-slate-100 divide-y divide-slate-800">
                                @foreach($performances as $p)
                                    @php($t = $p->track)
                                    @php($inq = $p->inquiries->first())
                                    @php($tone =
                                        $p->status === 'on_deck' ? 'amber' :
                                        ($p->status === 'performing' ? 'blue' :
                                        ($p->status === 'done' ? 'green' : 'gray'))
                                    )
                                    <tr class="hover:bg-slate-800/40" data-performance-id="{{ $p->id }}">
                                        <td class="py-3 pr-2 text-slate-500">
                                            <button type="button" class="drag-handle cursor-grab active:cursor-grabbing select-none px-1" title="Перетащить">⋮⋮</button>
                                        </td>
                                        <td class="py-3 pr-4 font-medium">{{ $p->start_number ?? '—' }}</td>
                                        <td class="py-3 pr-4 font-medium">{{ $p->athlete->last_name }} {{ $p->athlete->first_name }}</td>
                                        <td class="py-3 pr-4"><x-badge :tone="$tone === 'gray' ? 'violet' : $tone">{{ $p->apparatus ?? $category->apparatus ?? '—' }}</x-badge></td>
                                        <td class="py-3 pr-4 text-slate-400 truncate">{{ $p->athlete->club ?? '—' }}</td>
                                        <td class="py-3 pr-4"><x-badge :tone="$tone">{{ $p->status }}</x-badge></td>
                                        <td class="py-3 pr-4 text-xs">
                                            @if($t)
                                                <a class="text-emerald-400 hover:underline" href="{{ route('tracks.download', $t) }}">Файл</a>
                                            @else
                                                <span class="text-slate-500">нет</span>
                                            @endif
                                            <a href="#secretary-music-upload" class="block mt-1 text-violet-400/90 hover:underline">загрузить</a>
                                        </td>
                                        <td class="py-3 text-right">
                                            <form method="POST" action="{{ route('secretary.queue.remove', $p) }}" class="inline" onsubmit="return confirm('Удалить из очереди?');">
                                                @csrf
                                                <button type="submit" class="text-xs text-rose-300 hover:underline">Удалить</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </details>
    </div>
</x-app-layout>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(() => {
    if (typeof Sortable === 'undefined') return;
    const tableBody = document.getElementById('queue-body');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const saveUrl = @json(route('secretary.queue.reorder', $category));

    const toast = (tone, text) => {
        let el = document.getElementById('queue-save-status');
        if (!el) {
            el = document.createElement('div');
            el.id = 'queue-save-status';
            document.body.appendChild(el);
        }
        el.className = 'fixed bottom-4 right-4 z-50 px-4 py-2 rounded-xl border text-sm shadow-lg ' + (
            tone === 'ok' ? 'bg-emerald-950/90 border-emerald-700 text-emerald-50' :
            tone === 'saving' ? 'bg-slate-900 border-amber-700 text-amber-100' :
            'bg-rose-950/90 border-rose-700 text-rose-50'
        );
        el.textContent = text;
        el.classList.remove('hidden');
        if (tone !== 'saving') {
            clearTimeout(el._t);
            el._t = setTimeout(() => { el.classList.add('hidden'); }, 1600);
        }
    };

    let saving = false;
    let beforeIds = [];
    const idsNow = (root) => Array.from(root.querySelectorAll('[data-performance-id]')).map((el) => Number(el.dataset.performanceId)).filter(Number.isFinite);
    const restoreOrder = (root, ids) => {
        const map = new Map();
        root.querySelectorAll('[data-performance-id]').forEach((el) => map.set(Number(el.dataset.performanceId), el));
        ids.forEach((id) => { const el = map.get(id); if (el) root.appendChild(el); });
    };

    const persist = async () => {
        if (!tableBody || saving) return;
        saving = true;
        toast('saving', 'Сохраняю порядок…');
        const ids = idsNow(tableBody);
        try {
            const res = await fetch(saveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
                body: JSON.stringify({ ids }),
            });
            if (!res.ok) {
                restoreOrder(tableBody, beforeIds);
                toast('err', 'Ошибка сохранения.');
                return;
            }
            beforeIds = idsNow(tableBody);
            toast('ok', 'Сохранено');
        } catch (e) {
            restoreOrder(tableBody, beforeIds);
            toast('err', 'Нет связи.');
        } finally {
            saving = false;
        }
    };

    if (tableBody) {
        beforeIds = idsNow(tableBody);
        new Sortable(tableBody, {
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'bg-emerald-950/40',
            onStart: () => { beforeIds = idsNow(tableBody); },
            onEnd: persist,
        });
    }
})();
</script>
<script>
(function () {
    const pingUrl = @json(route('secretary.queue.ping', $category));
    let lastRev = null;
    const intervalMs = 3000;
    setInterval(async function () {
        try {
            const r = await fetch(pingUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (!r.ok) return;
            const j = await r.json();
            if (!j.rev) return;
            if (lastRev === null) {
                lastRev = j.rev;
                return;
            }
            if (j.rev !== lastRev) {
                window.location.reload();
            }
        } catch (e) {}
    }, intervalMs);
})();
</script>
