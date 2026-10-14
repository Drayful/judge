<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-3">
            <h2 class="font-semibold text-xl text-slate-100 leading-tight">Потоки · {{ $tournament->name }}</h2>
            <div class="ml-auto flex flex-wrap gap-2 text-sm">
                <a href="{{ route('secretary.tournament.start-list', $tournament) }}" class="rounded-lg border border-emerald-700 bg-emerald-950/40 px-3 py-2 font-semibold text-emerald-100 hover:bg-emerald-900/50">Стартовый лист</a>
                <a href="{{ route('secretary.tournament.live', $tournament) }}" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 font-semibold text-slate-200 hover:bg-slate-800">Live</a>
                <a href="{{ route('secretary.tournament.groups', $tournament) }}" class="rounded-lg border border-slate-800 px-3 py-2 text-slate-400 hover:text-white" title="Прежняя страница «Группы и потоки» со всеми старыми функциями">Старый вид</a>
            </div>
        </div>
    </x-slot>
    @include('secretary.partials.board-styles')

    @php
        $individual = $groups->where('program', 'individual');
        $teamGroups = $groups->where('program', 'group');
        $groupLink = fn ($g) => route('secretary.tournament.board', ['tournament' => $tournament, 'group' => $g->id]);
        $streamWord = fn (int $n) => $n % 10 === 1 && $n % 100 !== 11 ? 'поток' : (in_array($n % 10, [2, 3, 4], true) && ! in_array($n % 100, [12, 13, 14], true) ? 'потока' : 'потоков');
        $fmtDay = fn (?string $d) => $d ? \Carbon\Carbon::parse($d)->locale('ru')->isoFormat('dd, D MMMM') : 'без даты';
    @endphp

    <div class="flex flex-col gap-5 lg:flex-row">
        {{-- ===== Группы слева, с поиском ===== --}}
        <aside class="lg:w-72 shrink-0">
            <div class="lg:sticky lg:top-24 space-y-3 rounded-xl border border-slate-800 bg-slate-900/70 p-3">
                <input id="group-search" type="search" placeholder="Поиск группы: 2015, B, групповые…" autocomplete="off"
                       class="w-full rounded-lg border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white placeholder:text-slate-500 focus:border-emerald-500 focus:ring-emerald-500">
                <div id="group-list" class="max-h-[70vh] space-y-3 overflow-y-auto pr-1">
                    @foreach([['Индивидуальная программа', $individual], ['Групповые упражнения', $teamGroups]] as [$title, $list])
                        @if($list->isNotEmpty())
                            <div data-section>
                                <div class="px-2 text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $title }}</div>
                                <div class="mt-1 space-y-1">
                                    @foreach($list as $g)
                                        @php $active = $selectedGroup?->id === $g->id; $loose = (int) ($unassignedCounts[$g->id] ?? 0); @endphp
                                        <a href="{{ $groupLink($g) }}" data-search="{{ mb_strtolower($g->name.' '.$title.' '.($g->birth_year ?? '').' '.($g->division ?? '')) }}"
                                           class="block rounded-lg px-3 py-2 text-sm {{ $active ? 'bg-slate-800 text-white ring-1 ring-emerald-600/60' : 'text-slate-300 hover:bg-slate-800/60' }}">
                                            <div class="flex justify-between gap-2"><span class="truncate">{{ $g->name }}</span><span class="text-xs text-slate-500">{{ $g->entries_count }}</span></div>
                                            <div class="text-[11px]">
                                                @if($g->categories_count === 0)
                                                    <span class="text-amber-300">нет потоков</span>
                                                @else
                                                    <span class="text-emerald-300">{{ $g->categories_count }} {{ $streamWord($g->categories_count) }}</span>
                                                @endif
                                                @if($loose > 0 && $g->categories_count > 0)<span class="text-sky-300"> · {{ $loose }} не в потоке</span>@endif
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                    @if($pools->isNotEmpty())
                        <div data-section>
                            <div class="px-2 text-[10px] font-semibold uppercase tracking-wider text-slate-500">Без группы (из импорта)</div>
                            <div class="mt-1 space-y-1">
                                @foreach($pools as $p)
                                    <a href="{{ route('secretary.tournament.board', ['tournament' => $tournament, 'pool' => $p['key']]) }}" data-search="{{ mb_strtolower($p['name'].' '.($p['program'] === 'group' ? 'групповые' : 'индивидуальная').' пул') }}"
                                       class="block rounded-lg border border-dashed px-3 py-2 text-sm {{ ($selectedPool['key'] ?? null) === $p['key'] ? 'border-amber-500 text-white' : 'border-slate-700 text-slate-400 hover:text-white' }}">
                                        <div class="flex justify-between"><span>{{ $p['name'] }}{{ $p['program'] === 'group' ? ' · групп.' : '' }}</span><span class="text-xs">{{ $p['count'] }}</span></div>
                                        <div class="text-[11px] text-amber-300">группа не создана</div>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <div id="group-search-empty" class="hidden px-2 py-4 text-center text-xs text-slate-500">Ничего не найдено</div>
                    @if($groups->isEmpty() && $pools->isEmpty())
                        <div class="px-2 py-4 text-xs text-slate-400">Участниц ещё нет. Импортируйте Excel на <a class="text-emerald-300 underline" href="{{ route('secretary.tournament', $tournament) }}">странице турнира</a>.</div>
                    @endif
                </div>
            </div>
        </aside>

        <main class="min-w-0 flex-1 space-y-4">
            <x-flash />

            @if($selectedPool)
                <div class="rounded-xl border border-slate-800 bg-slate-900/70 p-6">
                    <div class="text-xs uppercase tracking-wider text-amber-300">Группа ещё не создана</div>
                    <div class="mt-1 text-2xl font-semibold text-white">{{ $selectedPool['name'] }}</div>
                    <div class="text-sm text-slate-400">{{ $selectedPool['count'] }} {{ $selectedPool['program'] === 'group' ? 'команд' : 'участниц' }} из импорта</div>
                    <form method="POST" action="{{ route('secretary.tournament.board.pool', $tournament) }}" class="mt-4">
                        @csrf
                        <input type="hidden" name="program" value="{{ $selectedPool['program'] }}">
                        <input type="hidden" name="birth_year" value="{{ $selectedPool['birth_year'] }}">
                        <input type="hidden" name="division" value="{{ $selectedPool['division'] }}">
                        <button class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Создать группу и настроить потоки</button>
                    </form>
                </div>
            @elseif(! $selectedGroup)
                <div class="rounded-xl border border-slate-800 bg-slate-900/70 p-10 text-center text-slate-400">Выберите группу слева.</div>
            @else
                @php
                    $g = $selectedGroup;
                    $started = $board['started'];
                    $defaultDate = optional($tournament->starts_on)->format('Y-m-d') ?? now()->format('Y-m-d');
                @endphp

                {{-- ===== СОЗДАНИЕ / ПЕРЕСОЗДАНИЕ ПОТОКОВ ===== --}}
                <section id="create-panel" class="{{ $board['hasStreams'] && ! $errors->has('streams') && ! $errors->has('days') && ! $errors->has('apparatus') ? 'hidden' : '' }}">
                    <form id="create-form" method="POST" action="{{ route('secretary.tournament.board.streams', [$tournament, $g]) }}" class="mx-auto max-w-3xl space-y-6 rounded-xl border border-slate-800 bg-slate-900/70 p-6">
                        @csrf
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs uppercase tracking-wider text-emerald-300">{{ $board['hasStreams'] ? 'Пересоздание потоков' : 'Создание потоков' }}</div>
                                <div class="mt-1 text-2xl font-semibold text-white">{{ $g->name }}{{ $g->program === 'group' ? ' · групповые' : '' }}</div>
                                <div class="text-sm text-slate-400">{{ $g->entries_count }} {{ $g->program === 'group' ? 'команд' : 'участниц' }}{{ $board['hasStreams'] ? ' · текущее распределение будет заменено' : ' · потоков ещё нет' }}</div>
                            </div>
                            @if($board['hasStreams'])
                                <button type="button" id="create-cancel" class="rounded-lg border border-slate-700 px-3 py-1.5 text-sm text-slate-300 hover:bg-slate-800">Отмена</button>
                            @endif
                        </div>

                        <div>
                            <div class="text-sm font-semibold text-slate-200">1. Виды</div>
                            <div class="mt-2 flex flex-wrap gap-2 text-sm">
                                <label class="cursor-pointer rounded-lg border border-slate-700 px-3 py-2 has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-950/40"><input type="radio" name="apparatus_mode" value="fixed" class="mr-1" @checked(! $g->usesApparatusChoice())>Известны — по порядку</label>
                                <label class="cursor-pointer rounded-lg border border-slate-700 px-3 py-2 has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-950/40"><input type="radio" name="apparatus_mode" value="choice" class="mr-1" @checked($g->usesApparatusChoice())>Вид на выбор — укажу позже</label>
                            </div>
                            <div data-mode="fixed" class="mt-3">
                                <div class="text-xs text-slate-500">Нажимайте виды в порядке выхода. Все выступают по кругу: сначала все — 1-й вид, затем все — 2-й.</div>
                                <div id="app-chips" class="mt-2 flex flex-wrap gap-2"></div>
                            </div>
                            <div data-mode="choice" class="mt-3 hidden text-sm text-slate-300">Сколько видов:
                                <input name="apparatus_count" type="number" min="1" max="6" value="{{ $g->apparatus_count ?: 2 }}" class="ml-1 w-16 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-white">
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-semibold text-slate-200">2. Деление на потоки и номера</div>
                            <div class="mt-2 flex flex-wrap items-end gap-4 text-xs text-slate-400">
                                <label>Потоков<br><input name="stream_count" type="number" min="1" max="50" value="{{ max(1, $board['streams']->count() ?: (int) ceil(max(1, $g->entries_count) / 8)) }}" class="mt-1 w-20 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white"></label>
                                <label>Порядок<br><select name="order" class="mt-1 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white"><option value="list">как сейчас (как в Excel)</option><option value="draw">жеребьёвка</option></select></label>
                                <label>Стартовые номера<br><select name="number_mode" class="mt-1 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white">
                                    <option value="per_stream" @selected($g->number_mode !== 'continuous')>в каждом потоке с 1</option>
                                    <option value="continuous" @selected($g->number_mode === 'continuous')>сквозные по группе</option></select></label>
                                <label>Мин на выход<br><input name="minutes" type="number" min="1" max="60" value="{{ $board['minutes'] }}" class="mt-1 w-20 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white"></label>
                            </div>
                        </div>

                        <div>
                            <div class="flex items-center justify-between"><div class="text-sm font-semibold text-slate-200">3. Дни</div><button type="button" id="add-day" class="text-xs text-emerald-300 hover:underline">+ ещё день</button></div>
                            <div class="text-xs text-slate-500">Разные виды можно провести в разные дни. Порядок гимнасток во всех днях общий.</div>
                            <div id="days" class="mt-3 space-y-2"></div>
                        </div>

                        <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                            <div class="text-xs uppercase tracking-wider text-slate-500">Так получится</div>
                            <div id="preview" class="mt-2 space-y-1 text-sm"></div>
                        </div>
                        <div id="hidden-inputs"></div>
                        <div class="flex justify-end"><button id="create-submit" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-40">{{ $board['hasStreams'] ? 'Пересоздать потоки' : 'Создать потоки' }}</button></div>
                    </form>
                </section>

                {{-- ===== ДОСКА ===== --}}
                @if($board['hasStreams'])
                    <section id="board-panel" class="{{ $errors->has('streams') || $errors->has('days') || $errors->has('apparatus') ? 'hidden' : '' }} space-y-4">
                        <div class="space-y-3 rounded-xl border border-slate-800 bg-slate-900/70 p-4">
                            <div class="flex flex-wrap items-center gap-3">
                                <div>
                                    <div class="text-lg font-semibold text-white">{{ $g->name }}{{ $g->program === 'group' ? ' · групповые' : '' }}</div>
                                    <div class="text-xs text-slate-400">
                                        {{ $g->entries_count }} {{ $g->program === 'group' ? 'команд' : 'участниц' }} ·
                                        @if($g->usesApparatusChoice()) вид на выбор ({{ $g->apparatus_count }}){{ $g->apparatusLabels() ? ': '.implode(', ', $g->apparatusLabels()) : '' }} @else {{ implode(' → ', $g->apparatusLabels()) }} @endif
                                        · номера {{ $g->number_mode === 'continuous' ? 'сквозные' : 'в каждом потоке с 1' }}
                                    </div>
                                </div>
                                <div class="ml-auto flex flex-wrap gap-2">
                                    <button type="button" id="add-stream" class="rounded-lg border border-emerald-700 bg-emerald-950/40 px-3 py-2 text-sm font-semibold text-emerald-100 hover:bg-emerald-900/50 disabled:opacity-40" @disabled($started)>+ Поток</button>
                                    <button type="button" id="shuffle" class="rounded-lg border border-violet-700 bg-violet-950/50 px-3 py-2 text-sm font-semibold text-violet-100 hover:bg-violet-900/60 disabled:opacity-40" @disabled($started)>🎲 Жеребьёвка</button>
                                    <button type="button" id="recreate" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-800 disabled:opacity-40" @disabled($started)>Пересоздать…</button>
                                    <button type="button" id="undo" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-800 disabled:opacity-40" disabled>↶ Отменить</button>
                                </div>
                            </div>

                            @if($board['sessions']->isNotEmpty())
                                <div class="flex flex-wrap items-stretch gap-2 border-t border-slate-800 pt-3">
                                    @foreach($board['sessions'] as $s)
                                        <a href="{{ route('secretary.tournament.board', ['tournament' => $tournament, 'group' => $g->id, 'day' => $s['no']]) }}"
                                           class="rounded-lg border px-3 py-2 text-xs {{ $s['no'] === $board['currentDay'] ? 'border-emerald-500 bg-emerald-950/40 text-white' : 'border-slate-700 bg-slate-950/40 text-slate-400 hover:border-slate-500' }}">
                                            <div class="font-semibold">День {{ $s['no'] }} · {{ $fmtDay($s['date']) }}</div>
                                            <div>{{ implode(', ', $s['apparatus']) ?: ($g->usesApparatusChoice() ? 'вид на выбор' : '—') }}</div>
                                        </a>
                                    @endforeach
                                    @php $d = $board['currentDayInfo']; @endphp
                                    <form method="POST" action="{{ route('secretary.tournament.board.day', [$tournament, $g, $d['no']]) }}" class="ml-auto flex flex-wrap items-end gap-3 text-xs text-slate-400">
                                        @csrf
                                        <label>Дата<br><input type="date" name="date" value="{{ $d['date'] }}" required class="mt-1 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white"></label>
                                        <label>Начало<br><input type="time" name="start" value="{{ $d['start'] }}" required class="mt-1 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white"></label>
                                        <label>Мин на выход<br><input type="number" name="minutes" min="1" max="60" value="{{ $board['minutes'] }}" required class="mt-1 w-16 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white"></label>
                                        <button class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-1.5 text-sm font-semibold text-slate-200 hover:bg-slate-800">Пересчитать время</button>
                                    </form>
                                </div>
                            @endif
                        </div>

                        <div id="board-alert" class="hidden rounded-lg border border-rose-800/70 bg-rose-950/40 px-3 py-2 text-sm text-rose-100"></div>
                        @php
                            $warnings = [];
                            if ($started) $warnings[] = ['rose', 'В группе уже идут выступления — состав потоков заморожен. Порядок оставшихся меняйте в Live.'];
                            if ($g->usesApparatusChoice() && $g->hasPendingApparatusSelection()) $warnings[] = ['amber', 'Вид на выбор: виды ещё не указаны — очереди пустые. Укажите их ниже.'];
                            if (count($board['unassigned'])) $warnings[] = ['sky', count($board['unassigned']).' — не в потоке. Перетащите карточки в нужный поток.'];
                            $noMusic = collect($board['streams'])->flatMap(fn ($s) => $s['cards'])->filter(fn ($c) => ! $c['music'] && ! $c['withdrawn'])->pluck('name');
                            if ($noMusic->isNotEmpty()) $warnings[] = ['amber', 'Нет музыки: '.$noMusic->implode(', ')];
                            foreach ($board['streams'] as $s) {
                                foreach ($s['cards'] as $i => $c) {
                                    if ($i > 0 && $g->program === 'individual' && $c['club'] && $c['club'] === $s['cards'][$i - 1]['club']) {
                                        $warnings[] = ['sky', 'Поток '.$s['no'].': «'.$c['club'].'» подряд (№'.$i.' и №'.($i + 1).') — тренеру может не хватить времени'];
                                    }
                                }
                            }
                        @endphp
                        @foreach($warnings as [$tone, $text])
                            <div class="rounded-lg border px-3 py-2 text-sm {{ ['rose' => 'border-rose-800/70 bg-rose-950/30 text-rose-100', 'amber' => 'border-amber-800/70 bg-amber-950/30 text-amber-100', 'sky' => 'border-sky-800/70 bg-sky-950/30 text-sky-100'][$tone] }}">{{ $text }}</div>
                        @endforeach
                        @if($g->usesApparatusChoice() && ! $started)
                            <form method="POST" action="{{ route('secretary.tournament.board.apparatus', [$tournament, $g]) }}" class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2 text-sm text-slate-300">
                                @csrf
                                <span>Виды группы ({{ $g->apparatus_count }}):</span>
                                @foreach($apparatusOptions as $opt)
                                    <label class="flex items-center gap-1"><input type="checkbox" name="apparatus[]" value="{{ $opt }}" @checked(in_array($opt, $g->apparatusLabels(), true))>{{ $opt }}</label>
                                @endforeach
                                <button class="ml-auto rounded-lg border border-emerald-700 px-3 py-1 text-emerald-100 hover:bg-emerald-900/40">Сохранить виды</button>
                            </form>
                        @endif

                        <div id="board" class="grid gap-4" style="grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));">
                            @if(count($board['unassigned']))
                                <div class="rounded-xl border border-dashed border-sky-800/70 bg-sky-950/10 p-3" data-column="unassigned">
                                    <div class="flex items-center gap-2 px-1"><span class="font-semibold text-sky-200">Не в потоке</span><span class="ml-auto rounded-md bg-sky-950 px-2 py-0.5 text-[11px] text-sky-300">{{ count($board['unassigned']) }}</span></div>
                                    <div class="px-1 text-[11px] text-slate-500">добавлены вручную или повторным импортом</div>
                                    <div class="board-col mt-2 min-h-[60px] space-y-1.5" data-col="unassigned">
                                        @foreach($board['unassigned'] as $c) @include('secretary.partials.board-card', ['c' => $c, 'inStream' => false]) @endforeach
                                    </div>
                                </div>
                            @endif
                            @foreach($board['streams'] as $s)
                                <div class="rounded-xl border border-slate-800 bg-slate-900/70 p-3" data-column="stream">
                                    <div class="flex items-center gap-2 px-1">
                                        <span class="font-semibold text-white">Поток <span data-stream-label>{{ $s['no'] }}</span></span>
                                        <span class="font-mono text-xs text-slate-400">{{ $s['start'] && $s['end'] ? $s['start'].'–'.$s['end'] : '—' }}</span>
                                        <span class="ml-auto rounded-md bg-slate-800 px-2 py-0.5 text-[11px] font-semibold text-slate-300">{{ count($s['cards']) }}</span>
                                        @unless($started)<button type="button" data-delete-stream title="Удалить поток" class="rounded-md px-1.5 text-slate-500 hover:bg-rose-950/60 hover:text-rose-300">✕</button>@endunless
                                    </div>
                                    <div class="board-col mt-2 min-h-[70px] space-y-1.5 rounded-lg" data-col="stream">
                                        @foreach($s['cards'] as $c) @include('secretary.partials.board-card', ['c' => $c, 'inStream' => true]) @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex flex-wrap gap-5 text-xs text-slate-500">
                            <span>Перетаскивайте карточки между потоками — номера и время пересчитаются автоматически</span>
                            <span><span class="text-amber-300">♪✕</span> нет музыки</span><span>🔒 уже выступила</span><span><s>снята</s> — номер сохраняется, время не занимает</span>
                        </div>
                    </section>
                @endif
            @endif
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <script>
    (() => {
        // Поиск групп слева
        const search = document.getElementById('group-search');
        if (search) {
            const filter = () => {
                const q = search.value.trim().toLowerCase();
                let shown = 0;
                document.querySelectorAll('#group-list [data-section]').forEach(section => {
                    let any = false;
                    section.querySelectorAll('[data-search]').forEach(a => {
                        const ok = !q || q.split(/\s+/).every(part => a.dataset.search.includes(part));
                        a.classList.toggle('hidden', !ok);
                        any = any || ok;
                        if (ok) shown++;
                    });
                    section.classList.toggle('hidden', !any);
                });
                document.getElementById('group-search-empty')?.classList.toggle('hidden', shown > 0 || !q);
                try { sessionStorage.setItem('board-group-search', search.value); } catch (_) {}
            };
            try { search.value = sessionStorage.getItem('board-group-search') || ''; } catch (_) {}
            search.addEventListener('input', filter);
            filter();
        }
    })();
    </script>

    @if($selectedGroup)
    <script>
    (() => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const ALL_APP = @json($apparatusOptions);
        const initialApp = @json($g->usesApparatusChoice() ? [] : $g->apparatusLabels());
        const existingDays = @json($board['sessions']->map(fn ($s) => ['date' => $s['date'], 'start' => $s['start'], 'apparatus' => $s['apparatus']])->values());
        const dayEnds = @json($dayEnds);
        const entriesCount = {{ (int) $g->entries_count }};
        const isTeam = @json($g->program === 'group');
        const defaultDate = @json($defaultDate);

        /* ---------- форма создания ---------- */
        const form = document.getElementById('create-form');
        if (form) {
            const toMin = (t) => { const [h, m] = (t || '09:00').split(':').map(Number); return h * 60 + m; };
            const fmt = (m) => String(Math.floor(m / 60) % 24).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
            const suggestStart = (date) => dayEnds[date] ? fmt(toMin(dayEnds[date]) + 5) : '09:00';
            const word = (n) => isTeam ? (n % 10 === 1 && n % 100 !== 11 ? 'команда' : [2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? 'команды' : 'команд')
                : (n % 10 === 1 && n % 100 !== 11 ? 'гимнастка' : [2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? 'гимнастки' : 'гимнасток');
            let app = [...initialApp];
            let days = existingDays.length ? existingDays.map(d => ({ ...d, apparatus: [...d.apparatus] }))
                : [{ date: defaultDate, start: suggestStart(defaultDate), apparatus: [...app] }];
            const mode = () => form.querySelector('[name=apparatus_mode]:checked').value;
            const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

            const render = () => {
                const choice = mode() === 'choice';
                form.querySelector('[data-mode=fixed]').classList.toggle('hidden', choice);
                form.querySelector('[data-mode=choice]').classList.toggle('hidden', !choice);
                document.getElementById('app-chips').innerHTML = ALL_APP.map(a => {
                    const i = app.indexOf(a);
                    return `<button type="button" data-app="${esc(a)}" class="rounded-lg border px-3 py-2 text-sm font-semibold ${i >= 0 ? 'border-emerald-500 bg-emerald-900/50 text-white' : 'border-slate-700 bg-slate-950/60 text-slate-300 hover:border-slate-500'}">${i >= 0 ? `<span class="mr-1 rounded bg-emerald-600 px-1.5 text-xs">${i + 1}</span>` : ''}${esc(a)}</button>`;
                }).join('');
                document.getElementById('days').innerHTML = days.map((d, di) => `
                    <div class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-800 bg-slate-950/40 px-3 py-2 text-xs text-slate-400">
                        <span class="font-semibold text-slate-200">День ${di + 1}</span>
                        <input type="date" data-day="${di}" data-f="date" value="${esc(d.date)}" class="rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white">
                        <label>начало <input type="time" data-day="${di}" data-f="start" value="${esc(d.start)}" class="rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white"></label>
                        ${dayEnds[d.date] ? `<button type="button" data-after="${di}" class="text-emerald-300 hover:underline" title="Предыдущие блоки этого дня заканчиваются в ${esc(dayEnds[d.date])}">после предыдущих (${esc(fmt(toMin(dayEnds[d.date]) + 5))})</button>` : ''}
                        ${choice ? '<span class="text-slate-500">все виды</span>' : app.map(a => `<label class="flex items-center gap-1"><input type="checkbox" data-day="${di}" data-app2="${esc(a)}" ${d.apparatus.includes(a) ? 'checked' : ''}>${esc(a)}</label>`).join('')}
                        ${days.length > 1 ? `<button type="button" data-del-day="${di}" class="ml-auto text-slate-500 hover:text-rose-300">✕</button>` : ''}
                    </div>`).join('');

                const count = Math.max(1, Math.min(entriesCount || 1, +form.stream_count.value || 1));
                const sizes = Array.from({ length: count }, (_, i) => Math.floor(entriesCount / count) + (i < entriesCount % count ? 1 : 0));
                const mins = Math.max(1, +form.minutes.value || 3);
                const errs = [];
                if (!choice && !app.length) errs.push('Выберите хотя бы один вид');
                const missing = choice ? [] : app.filter(a => !days.some(d => d.apparatus.includes(a)));
                if (missing.length) errs.push('Не назначен день для: ' + missing.join(', '));
                if (!choice && days.some(d => !d.apparatus.length)) errs.push('В каком-то дне нет ни одного вида');
                if (choice && days.length > 1) errs.push('Для «вида на выбор» оставьте один день');
                if (days.some(d => !d.date || !d.start)) errs.push('Укажите дату и начало каждого дня');
                const choiceCount = Math.max(1, +form.apparatus_count.value || 1);
                document.getElementById('preview').innerHTML = errs.map(e => `<div class="text-amber-300">⚠ ${esc(e)}</div>`).join('') + days.map(d => {
                    const k = choice ? choiceCount : d.apparatus.length; let t = toMin(d.start);
                    return `<div class="pt-1 text-xs uppercase tracking-wider text-slate-500">${esc(d.date)} · ${choice ? 'вид на выбор' : esc(app.filter(a => d.apparatus.includes(a)).join(' → ') || '—')}</div>` + sizes.map((s, i) => {
                        const len = s * mins * Math.max(1, k); const row = `<div class="flex gap-3"><span class="w-20 font-semibold text-white">Поток ${i + 1}</span><span class="text-slate-300">${s} ${word(s)}</span><span class="ml-auto font-mono text-slate-400">${fmt(t)}–${fmt(t + len)}</span></div>`; t += len; return row; }).join('');
                }).join('');
                document.getElementById('create-submit').disabled = errs.length > 0;
            };

            form.addEventListener('click', (e) => {
                const chip = e.target.closest('[data-app]');
                if (chip) {
                    const a = chip.dataset.app;
                    if (app.includes(a)) { app = app.filter(x => x !== a); days.forEach(d => d.apparatus = d.apparatus.filter(x => x !== a)); }
                    else { app.push(a); days[days.length - 1].apparatus.push(a); }
                    render();
                }
                const del = e.target.closest('[data-del-day]');
                if (del) { const [x] = days.splice(+del.dataset.delDay, 1); days[0].apparatus.push(...x.apparatus); render(); }
                const after = e.target.closest('[data-after]');
                if (after) { const d = days[+after.dataset.after]; d.start = suggestStart(d.date); render(); }
            });
            form.addEventListener('change', (e) => {
                const el = e.target;
                if (el.dataset.app2) { const a = el.dataset.app2; days.forEach(d => d.apparatus = d.apparatus.filter(x => x !== a)); if (el.checked) days[+el.dataset.day].apparatus.push(a); }
                else if (el.dataset.f) { days[+el.dataset.day][el.dataset.f] = el.value; }
                render();
            });
            form.addEventListener('input', (e) => { if (['stream_count', 'minutes', 'apparatus_count'].includes(e.target.name)) render(); });
            document.getElementById('add-day').addEventListener('click', () => {
                const last = days[days.length - 1]; const dt = new Date((last.date || defaultDate) + 'T12:00'); dt.setDate(dt.getDate() + 1);
                const date = `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;
                days.push({ date, start: suggestStart(date), apparatus: [] }); render();
            });
            form.addEventListener('submit', (e) => {
                if (document.getElementById('create-submit').disabled) { e.preventDefault(); return; }
                const choice = mode() === 'choice';
                const box = document.getElementById('hidden-inputs');
                const add = (name, value) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = name; i.value = value; box.appendChild(i); };
                box.innerHTML = '';
                if (!choice) app.forEach(a => add('apparatus[]', a));
                days.forEach((d, i) => { add(`days[${i}][date]`, d.date); add(`days[${i}][start]`, d.start); if (!choice) d.apparatus.forEach(a => add(`days[${i}][apparatus][]`, a)); });
            });
            document.getElementById('create-cancel')?.addEventListener('click', () => {
                document.getElementById('create-panel').classList.add('hidden');
                document.getElementById('board-panel')?.classList.remove('hidden');
            });
            render();
        }

        /* ---------- доска ---------- */
        const boardEl = document.getElementById('board');
        if (!boardEl) return;
        const started = @json($started);
        const arrangeUrl = @json(route('secretary.tournament.board.arrange', [$tournament, $g]));
        const shuffleUrl = @json(route('secretary.tournament.board.shuffle', [$tournament, $g]));
        const undoKey = 'board-undo-{{ $g->id }}';
        const alertBox = document.getElementById('board-alert');
        const readUndo = () => { try { return JSON.parse(sessionStorage.getItem(undoKey) || '[]'); } catch (_) { return []; } };
        const writeUndo = (stack) => { try { sessionStorage.setItem(undoKey, JSON.stringify(stack.slice(-20))); } catch (_) {} };
        document.getElementById('undo').disabled = started || readUndo().length === 0;

        const snapshot = () => ({
            streams: [...boardEl.querySelectorAll('[data-col=stream]')].map(col => [...col.querySelectorAll('[data-entry]')].map(c => +c.dataset.entry)),
            unassigned: [...boardEl.querySelectorAll('[data-col=unassigned] [data-entry]')].map(c => +c.dataset.entry),
        });
        let before = snapshot();

        const post = async (url, body) => {
            const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) }, body: JSON.stringify(body || {}) });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.ok === false) throw new Error(data.error || data.message || 'Не удалось сохранить.');
        };
        const save = async (state, pushUndo = true) => {
            boardEl.classList.add('pointer-events-none', 'opacity-60');
            try {
                await post(arrangeUrl, state);
                if (pushUndo) writeUndo([...readUndo(), before]);
                window.location.reload();
            } catch (err) {
                alertBox.textContent = err.message; alertBox.classList.remove('hidden');
                boardEl.classList.remove('pointer-events-none', 'opacity-60');
            }
        };

        if (!started && typeof Sortable !== 'undefined') {
            const bind = (col) => new Sortable(col, {
                group: 'board', animation: 150, forceFallback: true, fallbackTolerance: 4, filter: '.locked', preventOnFilter: false, ghostClass: 'opacity-30', draggable: '[data-entry]',
                onEnd: () => save(snapshot()),
            });
            boardEl.querySelectorAll('.board-col').forEach(bind);

            document.getElementById('add-stream').addEventListener('click', () => {
                const n = boardEl.querySelectorAll('[data-col=stream]').length + 1;
                const wrap = document.createElement('div');
                wrap.className = 'rounded-xl border border-dashed border-emerald-800/70 bg-slate-900/50 p-3';
                wrap.dataset.column = 'stream';
                wrap.innerHTML = `<div class="flex items-center gap-2 px-1"><span class="font-semibold text-white">Поток ${n}</span><span class="text-xs text-emerald-300">новый</span></div>
                    <div class="board-col mt-2 flex min-h-[90px] items-center justify-center rounded-lg border border-dashed border-slate-700 text-xs text-slate-500" data-col="stream">Перетащите сюда гимнасток</div>`;
                boardEl.appendChild(wrap);
                bind(wrap.querySelector('.board-col'));
            });

            boardEl.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-delete-stream]');
                if (!btn) return;
                const columns = [...boardEl.querySelectorAll('[data-col=stream]')];
                const col = btn.closest('[data-column]').querySelector('[data-col=stream]');
                const idx = columns.indexOf(col);
                if (columns.length === 1) { alert('Нельзя удалить единственный поток. Используйте «Пересоздать…».'); return; }
                if (col.querySelector('.locked')) { alert('В потоке есть выступившие — удалить нельзя.'); return; }
                const cards = [...col.querySelectorAll('[data-entry]')];
                const to = idx > 0 ? idx - 1 : 1;
                if (cards.length && !confirm(`Удалить поток ${idx + 1}? Участницы (${cards.length}) перейдут в конец потока ${to + 1}. Номера пересчитаются.`)) return;
                const state = snapshot();
                state.streams[to].push(...state.streams[idx]);
                state.streams.splice(idx, 1);
                save(state);
            });

            document.getElementById('shuffle').addEventListener('click', async () => {
                if (!confirm('Провести жеребьёвку? Порядок внутри каждого потока будет перемешан. Отменить можно кнопкой «↶ Отменить».')) return;
                try { await post(shuffleUrl); writeUndo([...readUndo(), before]); window.location.reload(); }
                catch (err) { alertBox.textContent = err.message; alertBox.classList.remove('hidden'); }
            });
            document.getElementById('undo').addEventListener('click', () => {
                const stack = readUndo(); const prev = stack.pop();
                if (!prev) return;
                writeUndo(stack);
                save(prev, false);
            });
            document.getElementById('recreate').addEventListener('click', () => {
                document.getElementById('board-panel').classList.add('hidden');
                document.getElementById('create-panel').classList.remove('hidden');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
    })();
    </script>
    @endif
</x-app-layout>
