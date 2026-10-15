<x-app-layout>
    <x-slot name="header">
        <div class="sx sx-row-wrap" style="gap:.75rem">
            <span class="sx-label" style="display:inline-flex;align-items:center;gap:.4rem">@include('secretary.partials.sx-icon', ['name' => 'layers', 'size' => 14]) Турнир</span>
            <h2 class="sx-h2 sx-truncate" style="margin:0;max-width:40rem">Потоки · {{ $tournament->name }}</h2>
            <div class="sx-row-wrap sx-ml-auto">
                <a href="{{ route('secretary.tournament.start-list', $tournament) }}" class="sx-btn">@include('secretary.partials.sx-icon', ['name' => 'list']) Стартовый лист</a>
                <a href="{{ route('secretary.tournament.live', $tournament) }}" class="sx-btn sx-btn-primary">@include('secretary.partials.sx-icon', ['name' => 'live']) Live</a>
                <a href="{{ route('secretary.tournament.groups', $tournament) }}" class="sx-btn sx-btn-ghost" title="Прежняя страница «Группы и потоки» со всеми старыми функциями">Старый вид</a>
            </div>
        </div>
    </x-slot>
    @include('secretary.partials.board-styles')
    @include('secretary.partials.sx-styles')

    @php
        $individual = $groups->where('program', 'individual');
        $teamGroups = $groups->where('program', 'group');
        $groupLink = fn ($g) => route('secretary.tournament.board', ['tournament' => $tournament, 'group' => $g->id]);
        $plural = fn (int $n, string $one, string $few, string $many) => $n % 10 === 1 && $n % 100 !== 11 ? $one : (in_array($n % 10, [2, 3, 4], true) && ! in_array($n % 100, [12, 13, 14], true) ? $few : $many);
        $streamWord = fn (int $n) => $plural($n, 'поток', 'потока', 'потоков');
        $fmtDay = fn (?string $d) => $d ? \Carbon\Carbon::parse($d)->locale('ru')->isoFormat('dd, D MMMM') : 'без даты';
    @endphp

    <div class="sx sx-layout">
        {{-- ===== Сетка категорий слева, с поиском ===== --}}
        <aside class="sx-aside">
            <div class="sx-card sx-pad-sm" style="display:flex;flex-direction:column;gap:.75rem">
                <div class="sx-row-wrap" style="padding:.25rem .25rem 0">
                    <span class="sx-label sx-text" style="display:inline-flex;align-items:center;gap:.4rem">@include('secretary.partials.sx-icon', ['name' => 'layers', 'size' => 14]) Сетка категорий</span>
                    <span class="sx-chip sx-chip-mute sx-ml-auto">{{ $groups->count() }} групп</span>
                </div>
                <label class="sx-search">
                    @include('secretary.partials.sx-icon', ['name' => 'search', 'size' => 15])
                    <input id="group-search" type="search" placeholder="Поиск группы: 2015, B, групповые…" autocomplete="off" class="sx-input">
                </label>
                <div id="group-list" class="sx-group-list" style="overflow-y:auto;display:flex;flex-direction:column;gap:1rem;padding-right:.25rem">
                    @foreach([['Индивидуальная программа', $individual], ['Групповые упражнения', $teamGroups]] as [$title, $list])
                        @if($list->isNotEmpty())
                            <div data-section>
                                <div class="sx-row-wrap" style="padding:0 .5rem"><span class="sx-label">{{ $title }}</span><span class="sx-small sx-dim sx-ml-auto">{{ $list->count() }} кат.</span></div>
                                <div style="margin-top:.35rem;display:flex;flex-direction:column;gap:.25rem">
                                    @foreach($list as $g)
                                        @php $active = $selectedGroup?->id === $g->id; $loose = (int) ($unassignedCounts[$g->id] ?? 0); @endphp
                                        <a href="{{ $groupLink($g) }}" data-search="{{ mb_strtolower($g->name.' '.$title.' '.($g->birth_year ?? '').' '.($g->division ?? '')) }}" class="sx-group {{ $active ? 'is-on' : '' }}">
                                            <div class="sx-row-wrap" style="flex-wrap:nowrap">
                                                <span class="sx-truncate sx-text" style="font-weight:600;font-size:15px">{{ $g->name }}</span>
                                                @if($g->categories_count === 0)
                                                    <span class="sx-chip sx-chip-warn sx-ml-auto">нет потоков</span>
                                                @else
                                                    <span class="sx-chip sx-chip-ok sx-ml-auto">{{ $g->categories_count }} {{ $streamWord($g->categories_count) }}</span>
                                                @endif
                                            </div>
                                            <div class="sx-row-wrap sx-small sx-muted" style="margin-top:.2rem;gap:.35rem">
                                                @include('secretary.partials.sx-icon', ['name' => 'users', 'size' => 12])
                                                {{ $g->entries_count }} {{ $g->program === 'group' ? $plural($g->entries_count, 'команда', 'команды', 'команд') : $plural($g->entries_count, 'участница', 'участницы', 'участниц') }}
                                                @if($loose > 0 && $g->categories_count > 0)<span class="sx-sky">· {{ $loose }} не в потоке</span>@endif
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                    @if($pools->isNotEmpty())
                        <div data-section>
                            <div class="sx-row-wrap" style="padding:0 .5rem"><span class="sx-label">Без группы (из импорта)</span><span class="sx-small sx-dim sx-ml-auto">{{ $pools->count() }} кат.</span></div>
                            <div style="margin-top:.35rem;display:flex;flex-direction:column;gap:.25rem">
                                @foreach($pools as $p)
                                    <a href="{{ route('secretary.tournament.board', ['tournament' => $tournament, 'pool' => $p['key']]) }}" data-search="{{ mb_strtolower($p['name'].' '.($p['program'] === 'group' ? 'групповые' : 'индивидуальная').' пул') }}"
                                       class="sx-group is-pool {{ ($selectedPool['key'] ?? null) === $p['key'] ? 'is-on' : '' }}">
                                        <div class="sx-row-wrap" style="flex-wrap:nowrap"><span class="sx-truncate sx-text" style="font-weight:600">{{ $p['name'] }}{{ $p['program'] === 'group' ? ' · групп.' : '' }}</span><span class="sx-small sx-muted sx-ml-auto">{{ $p['count'] }}</span></div>
                                        <div class="sx-small sx-amber" style="margin-top:.15rem">группа не создана</div>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <div id="group-search-empty" class="sx-hidden sx-small sx-dim" style="padding:1rem .5rem;text-align:center">Ничего не найдено</div>
                    @if($groups->isEmpty() && $pools->isEmpty())
                        <div class="sx-small sx-muted" style="padding:1rem .5rem">Участниц ещё нет. Загрузите Excel со списком ниже.</div>
                    @endif
                </div>
                {{-- Импорт стартового протокола (Excel) --}}
                <form method="POST" action="{{ route('secretary.tournament.importStartProtocol', $tournament) }}" enctype="multipart/form-data" class="sx-inset sx-pad-sm" style="display:flex;flex-direction:column;gap:.5rem">
                    @csrf
                    <input type="hidden" name="return" value="board">
                    <label class="sx-row-wrap" style="gap:.6rem;cursor:pointer;flex-wrap:nowrap">
                        <span class="sx-icon-box">@include('secretary.partials.sx-icon', ['name' => 'download', 'size' => 18])</span>
                        <span class="sx-grow"><span class="sx-h3" style="display:block">Импорт из Excel</span><span id="import-file-name" class="sx-small sx-muted sx-truncate" style="display:block">Обновить списки — выбрать .xls / .xlsx</span></span>
                        <input type="file" name="protocol" required accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="sx-hidden"
                               onchange="document.getElementById('import-file-name').textContent = this.files[0]?.name || 'Обновить списки — выбрать .xls / .xlsx'; document.getElementById('import-submit').disabled = !this.files.length">
                    </label>
                    <button id="import-submit" class="sx-btn sx-btn-primary sx-btn-sm" disabled>Импортировать в пул</button>
                    <span class="sx-small sx-dim">Лист на каждый год + категорию («2019 А»), колонки ФИО, год, клуб. Листы «груп…» — командные. Повторы пропускаются.</span>
                </form>
            </div>
        </aside>

        <main class="sx-main">
            <x-flash />

            @if($selectedPool)
                <div class="sx-card sx-pad" style="padding:1.75rem">
                    <span class="sx-chip sx-chip-warn">Группа ещё не создана</span>
                    <h1 class="sx-h1" style="margin-top:.75rem">{{ $selectedPool['name'] }}</h1>
                    <div class="sx-body sx-muted" style="margin-top:.25rem">{{ $selectedPool['count'] }} {{ $selectedPool['program'] === 'group' ? 'команд' : 'участниц' }} из импорта</div>
                    <form method="POST" action="{{ route('secretary.tournament.board.pool', $tournament) }}" style="margin-top:1.25rem">
                        @csrf
                        <input type="hidden" name="program" value="{{ $selectedPool['program'] }}">
                        <input type="hidden" name="birth_year" value="{{ $selectedPool['birth_year'] }}">
                        <input type="hidden" name="division" value="{{ $selectedPool['division'] }}">
                        <button class="sx-btn sx-btn-primary" style="padding:.7rem 1.2rem">@include('secretary.partials.sx-icon', ['name' => 'sparkles']) Создать группу и настроить потоки</button>
                    </form>
                </div>
            @elseif(! $selectedGroup)
                <div class="sx-card sx-muted" style="padding:2.5rem;text-align:center">Выберите группу слева.</div>
            @else
                @php
                    $g = $selectedGroup;
                    $started = $board['started'];
                    $defaultDate = optional($tournament->starts_on)->format('Y-m-d') ?? now()->format('Y-m-d');
                    $isTeamGroup = $g->program === 'group';
                    $entriesWord = $isTeamGroup ? $plural($g->entries_count, 'команда', 'команды', 'команд') : $plural($g->entries_count, 'участница', 'участницы', 'участниц');
                    $formErrors = $errors->has('streams') || $errors->has('days') || $errors->has('apparatus');
                @endphp

                {{-- ===== СОЗДАНИЕ / ПЕРЕСОЗДАНИЕ ПОТОКОВ ===== --}}
                <section id="create-panel" class="{{ $board['hasStreams'] && ! $formErrors ? 'sx-hidden' : '' }}" style="display:flex;flex-direction:column;gap:1.25rem">
                    <div class="sx-row-wrap" style="align-items:flex-end;gap:1rem">
                        <div class="sx-grow">
                            <div class="sx-small sx-muted">Потоки <span class="sx-dim">›</span> <span class="sx-text">{{ $board['hasStreams'] ? 'Пересоздание потоков' : 'Настройка потоков' }}</span></div>
                            <h1 class="sx-h1" style="margin-top:.35rem">{{ $g->name }}{{ $isTeamGroup ? ' · групповые' : '' }} — {{ $board['hasStreams'] ? 'пересоздание' : 'настройка' }} потоков</h1>
                        </div>
                        <span class="sx-chip {{ $board['hasStreams'] ? 'sx-chip-warn' : 'sx-chip-ok' }}"><span class="sx-dot"></span>{{ $g->entries_count }} {{ $entriesWord }} · {{ $board['hasStreams'] ? 'текущее распределение будет заменено' : '0 потоков сформировано' }}</span>
                        @if($board['hasStreams'])
                            <button type="button" id="create-cancel" class="sx-btn">@include('secretary.partials.sx-icon', ['name' => 'x']) Отмена</button>
                        @endif
                    </div>

                    <form id="create-form" method="POST" action="{{ route('secretary.tournament.board.streams', [$tournament, $g]) }}" class="sx-create">
                        @csrf
                        <div class="sx-col">
                            {{-- 1. Виды --}}
                            <div class="sx-card sx-pad">
                                <div class="sx-row-wrap" style="gap:.6rem"><span class="sx-step">1</span><span class="sx-h2">Программа и виды (предметы)</span><span id="app-count" class="sx-small sx-em sx-ml-auto"></span></div>
                                <div class="sx-seg" style="margin-top:1rem">
                                    <label><input type="radio" name="apparatus_mode" value="fixed" @checked(! $g->usesApparatusChoice())>Известны — по порядку</label>
                                    <label><input type="radio" name="apparatus_mode" value="choice" @checked($g->usesApparatusChoice())>Вид на выбор</label>
                                </div>
                                <div data-mode="fixed" style="margin-top:.9rem">
                                    <div class="sx-small sx-muted">Нажмите виды в порядке ротации участниц: сначала все — 1-й вид, затем все — 2-й.</div>
                                    <div id="app-chips" class="sx-row-wrap" style="margin-top:.6rem"></div>
                                </div>
                                <div data-mode="choice" class="sx-hidden" style="margin-top:.9rem">
                                    <div class="sx-small sx-muted">Виды станут известны позже — укажете их на доске.</div>
                                    <div class="sx-row-wrap sx-body" style="margin-top:.6rem">Сколько видов:
                                        <span class="sx-stepper"><button type="button" data-step="apparatus_count" data-delta="-1">−</button><input name="apparatus_count" type="number" min="1" max="6" value="{{ $g->apparatus_count ?: 2 }}"><button type="button" data-step="apparatus_count" data-delta="1">+</button></span>
                                    </div>
                                </div>
                            </div>

                            {{-- 2. Деление и номера --}}
                            <div class="sx-card sx-pad">
                                <div class="sx-row-wrap" style="gap:.6rem"><span class="sx-step">2</span><span class="sx-h2">Параметры разделения и нумерация</span></div>
                                <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;margin-top:1rem">
                                    <div class="sx-field">
                                        <span class="sx-label">Число потоков</span>
                                        <span class="sx-stepper" style="align-self:flex-start"><button type="button" data-step="stream_count" data-delta="-1">−</button><input name="stream_count" type="number" min="1" max="50" value="{{ max(1, $board['streams']->count() ?: (int) ceil(max(1, $g->entries_count) / 8)) }}"><button type="button" data-step="stream_count" data-delta="1">+</button></span>
                                        <span id="pv-per" class="sx-small sx-muted"></span>
                                    </div>
                                    <div class="sx-field">
                                        <span class="sx-label">Мин на выступление</span>
                                        <span class="sx-stepper" style="align-self:flex-start"><button type="button" data-step="minutes" data-delta="-1">−</button><input name="minutes" type="number" min="1" max="60" value="{{ $board['minutes'] }}"><button type="button" data-step="minutes" data-delta="1">+</button></span>
                                        <span id="pv-min" class="sx-small sx-muted"></span>
                                    </div>
                                    <label class="sx-field">
                                        <span class="sx-label">Порядок гимнасток</span>
                                        <select name="order" class="sx-input"><option value="list">как сейчас (как в Excel)</option><option value="draw">жеребьёвка</option></select>
                                    </label>
                                    <label class="sx-field">
                                        <span class="sx-label">Стартовые номера</span>
                                        <select name="number_mode" class="sx-input">
                                            <option value="per_stream" @selected($g->number_mode !== 'continuous')>в каждом потоке с 1</option>
                                            <option value="continuous" @selected($g->number_mode === 'continuous')>сквозные по группе</option>
                                        </select>
                                    </label>
                                </div>
                            </div>

                            {{-- 3. Дни --}}
                            <div class="sx-card sx-pad">
                                <div class="sx-row-wrap" style="gap:.6rem"><span class="sx-step">3</span><span class="sx-h2">Расписание и дата ковра</span>
                                    <button type="button" id="add-day" class="sx-btn sx-btn-ghost sx-btn-sm sx-ml-auto" style="color:var(--sx-em2)">@include('secretary.partials.sx-icon', ['name' => 'plus', 'size' => 14]) Добавить день</button>
                                </div>
                                <div class="sx-small sx-muted" style="margin-top:.5rem">Разные виды можно провести в разные дни. Порядок гимнасток во всех днях общий.</div>
                                <div id="days" style="margin-top:.75rem;display:flex;flex-direction:column;gap:.6rem"></div>
                            </div>

                            <div id="hidden-inputs"></div>
                            <div>
                                <button id="create-submit" class="sx-btn sx-btn-primary sx-btn-xl">@include('secretary.partials.sx-icon', ['name' => 'sparkles', 'size' => 20]) <span id="create-submit-label">{{ $board['hasStreams'] ? 'Пересоздать потоки' : 'Создать потоки' }}</span></button>
                                <div class="sx-small sx-muted" style="text-align:center;margin-top:.5rem">Гимнастки будут распределены по потокам, номера и время рассчитаются автоматически.</div>
                            </div>
                        </div>

                        <div class="sx-col">
                            <div class="sx-card-hi sx-pad">
                                <div class="sx-row-wrap" style="gap:.6rem">
                                    <span class="sx-em">@include('secretary.partials.sx-icon', ['name' => 'eye', 'size' => 20])</span>
                                    <span class="sx-h2">Расчётное расписание</span>
                                    <span class="sx-chip sx-chip-ok">live preview</span>
                                    <span id="pv-status" class="sx-chip sx-chip-ok sx-chip-plain sx-ml-auto"></span>
                                </div>
                                <div class="sx-tiles" style="margin-top:1rem;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr))">
                                    <div class="sx-inset sx-pad-sm"><div class="sx-small sx-muted">Всего {{ $isTeamGroup ? 'команд' : 'гимнасток' }}</div><div class="sx-num">{{ $g->entries_count }}</div></div>
                                    <div class="sx-inset sx-pad-sm"><div class="sx-small sx-muted">Потоков / видов</div><div id="pv-sv" class="sx-num sx-em">—</div></div>
                                    <div class="sx-inset sx-pad-sm"><div class="sx-small sx-muted">Хронометраж</div><div id="pv-span" class="sx-num sx-sky sx-mono" style="font-size:20px">—</div><div id="pv-len" class="sx-small sx-muted"></div></div>
                                </div>
                                <div id="pv-timeline-wrap" style="margin-top:1rem">
                                    <div class="sx-row-wrap sx-small sx-muted" style="justify-content:space-between"><span id="pv-t0"></span><span id="pv-t1"></span></div>
                                    <div id="pv-timeline" class="sx-timeline" style="margin-top:.35rem"></div>
                                </div>
                            </div>
                            <div id="preview" style="display:flex;flex-direction:column;gap:.6rem"></div>
                            <div class="sx-banner sx-banner-info sx-small">
                                @include('secretary.partials.sx-icon', ['name' => 'info', 'size' => 18])
                                <span>Время каждой гимнастки считается от начала дня с шагом «мин на выступление». Награждения добавляются в стартовом листе в любое время — потоки при этом не сдвигаются.</span>
                            </div>
                        </div>
                    </form>
                </section>

                {{-- ===== ДОСКА ===== --}}
                @if($board['hasStreams'])
                    @php
                        $currentApparatus = $board['currentDayInfo']['apparatus'] ?? [];
                        $noMusic = collect($board['streams'])->flatMap(fn ($s) => $s['cards'])->filter(fn ($c) => ! $c['music'] && ! $c['withdrawn'])->pluck('name');
                        $sameClub = [];
                        foreach ($board['streams'] as $s) {
                            foreach ($s['cards'] as $i => $c) {
                                if ($i > 0 && $g->program === 'individual' && $c['club'] && $c['club'] === $s['cards'][$i - 1]['club']) {
                                    $sameClub[] = 'Поток '.$s['no'].': «'.$c['club'].'» подряд (№'.$i.' и №'.($i + 1).')';
                                }
                            }
                        }
                    @endphp
                    <section id="board-panel" class="{{ $formErrors ? 'sx-hidden' : '' }}" style="display:flex;flex-direction:column;gap:1rem">
                        <div class="sx-row-wrap" style="align-items:flex-start;gap:1rem">
                            <div class="sx-grow">
                                <div class="sx-small sx-muted">Потоки <span class="sx-dim">›</span> <span class="sx-text">Очередь группы</span></div>
                                <h1 class="sx-h1" style="margin-top:.35rem">{{ $g->name }}{{ $isTeamGroup ? ' · групповые' : '' }}</h1>
                                <div class="sx-row-wrap" style="margin-top:.6rem">
                                    <span class="sx-chip sx-chip-ok">{{ count($board['streams']) }} {{ $streamWord(count($board['streams'])) }}</span>
                                    <span class="sx-chip sx-chip-mute">{{ $g->entries_count }} {{ $entriesWord }}</span>
                                    @if($g->usesApparatusChoice())
                                        <span class="sx-chip sx-chip-warn sx-chip-plain">Вид на выбор ({{ $g->apparatus_count }}){{ $g->apparatusLabels() ? ': '.implode(', ', $g->apparatusLabels()) : '' }}</span>
                                    @else
                                        <span class="sx-chip sx-chip-warn sx-chip-plain">Порядок видов: {{ implode(' → ', $g->apparatusLabels()) }}</span>
                                    @endif
                                    <span class="sx-chip sx-chip-mute sx-chip-plain">Номера {{ $g->number_mode === 'continuous' ? 'сквозные' : 'в каждом потоке с 1' }}</span>
                                </div>
                            </div>
                            <div class="sx-row-wrap">
                                <button type="button" id="add-stream" class="sx-btn" @disabled($started)>@include('secretary.partials.sx-icon', ['name' => 'plus']) Новый поток</button>
                                <button type="button" id="shuffle" class="sx-btn" @disabled($started)>@include('secretary.partials.sx-icon', ['name' => 'dice']) Жеребьёвка</button>
                                <button type="button" id="recreate" class="sx-btn" @disabled($started)>@include('secretary.partials.sx-icon', ['name' => 'refresh']) Пересоздать потоки</button>
                                <button type="button" id="undo" class="sx-btn sx-btn-ghost" disabled>@include('secretary.partials.sx-icon', ['name' => 'undo']) Отменить</button>
                            </div>
                        </div>

                        @if($board['sessions']->isNotEmpty())
                            @php $d = $board['currentDayInfo']; @endphp
                            <div class="sx-row-wrap" style="align-items:stretch;gap:.6rem">
                                @foreach($board['sessions'] as $s)
                                    @php $on = $s['no'] === $board['currentDay']; @endphp
                                    <a href="{{ route('secretary.tournament.board', ['tournament' => $tournament, 'group' => $g->id, 'day' => $s['no']]) }}"
                                       class="sx-card sx-pad-sm {{ $on ? 'sx-accent' : '' }}" style="text-decoration:none;display:flex;align-items:center;gap:.6rem;{{ $on ? 'background:var(--sx-high);border-color:var(--sx-line2)' : '' }}">
                                        <span class="{{ $on ? 'sx-em' : 'sx-dim' }}">@include('secretary.partials.sx-icon', ['name' => 'calendar', 'size' => 18])</span>
                                        <span>
                                            <span class="sx-body {{ $on ? 'sx-em' : 'sx-text' }}" style="display:block;font-weight:600">День {{ $s['no'] }} · {{ $fmtDay($s['date']) }}</span>
                                            <span class="sx-small sx-muted">{{ implode(' → ', $s['apparatus']) ?: ($g->usesApparatusChoice() ? 'вид на выбор' : '—') }}{{ $s['start'] ? ' · с '.$s['start'] : '' }}</span>
                                        </span>
                                    </a>
                                @endforeach
                                <form method="POST" action="{{ route('secretary.tournament.board.day', [$tournament, $g, $d['no']]) }}" class="sx-card sx-pad-sm sx-ml-auto sx-row-wrap" style="align-items:flex-end;gap:.6rem">
                                    @csrf
                                    <label class="sx-field"><span class="sx-label">Дата</span><input type="date" name="date" value="{{ $d['date'] }}" required class="sx-input"></label>
                                    <label class="sx-field"><span class="sx-label">Начало</span><input type="time" name="start" value="{{ $d['start'] }}" required class="sx-input"></label>
                                    <label class="sx-field"><span class="sx-label">Мин на выход</span><input type="number" name="minutes" min="1" max="60" value="{{ $board['minutes'] }}" required class="sx-input" style="width:5rem"></label>
                                    <button class="sx-btn">@include('secretary.partials.sx-icon', ['name' => 'clock']) Пересчитать время</button>
                                </form>
                            </div>
                        @endif

                        <div id="board-alert" class="sx-hidden sx-banner sx-banner-bad sx-body"></div>

                        @if($started)
                            <div class="sx-banner sx-banner-bad">
                                <span class="sx-icon-box" style="background:rgba(244,63,94,.25);color:#fb7185">@include('secretary.partials.sx-icon', ['name' => 'lock', 'size' => 18])</span>
                                <span><span class="sx-label" style="color:#fb7185;display:block">Состав потоков зафиксирован</span><span class="sx-body">В группе уже идут выступления — перемещение, жеребьёвка и пересоздание недоступны. Порядок оставшихся участниц меняйте в Live.</span></span>
                            </div>
                        @endif
                        @if($g->usesApparatusChoice() && $g->hasPendingApparatusSelection())
                            <div class="sx-banner sx-banner-warn sx-body">@include('secretary.partials.sx-icon', ['name' => 'alert', 'size' => 18]) Вид на выбор: виды ещё не указаны — очереди пустые. Укажите их ниже.</div>
                        @endif
                        @if(count($board['unassigned']))
                            <div class="sx-banner sx-banner-info sx-body">@include('secretary.partials.sx-icon', ['name' => 'info', 'size' => 18]) {{ count($board['unassigned']) }} — не в потоке. Перетащите карточки в нужный поток.</div>
                        @endif

                        {{-- Автопроверки --}}
                        <details class="sx-card sx-pad-sm">
                            <summary style="cursor:pointer" class="sx-row-wrap">
                                <span class="sx-label">Автопроверки регламента:</span>
                                @if($noMusic->isNotEmpty())
                                    <span class="sx-chip sx-chip-bad sx-chip-plain">@include('secretary.partials.sx-icon', ['name' => 'music-off', 'size' => 12]) без музыки — {{ $noMusic->count() }}</span>
                                @else
                                    <span class="sx-chip sx-chip-ok sx-chip-plain">@include('secretary.partials.sx-icon', ['name' => 'check', 'size' => 12]) музыка у всех</span>
                                @endif
                                @if($sameClub)
                                    <span class="sx-chip sx-chip-info sx-chip-plain">@include('secretary.partials.sx-icon', ['name' => 'info', 'size' => 12]) один клуб подряд — {{ count($sameClub) }}</span>
                                @endif
                                <span class="sx-chip sx-chip-ok sx-chip-plain">@include('secretary.partials.sx-icon', ['name' => 'clock', 'size' => 12]) интервал: {{ $board['minutes'] }} мин на выход</span>
                                @if($noMusic->isNotEmpty() || $sameClub)<span class="sx-small sx-dim sx-ml-auto">подробнее ▾</span>@endif
                            </summary>
                            @if($noMusic->isNotEmpty() || $sameClub)
                                <div class="sx-small" style="margin-top:.6rem;display:flex;flex-direction:column;gap:.3rem">
                                    @if($noMusic->isNotEmpty())<div><span class="sx-rose">Нет музыки:</span> <span class="sx-muted">{{ $noMusic->implode(', ') }}</span></div>@endif
                                    @foreach($sameClub as $line)<div class="sx-sky">{{ $line }} <span class="sx-muted">— тренеру может не хватить времени</span></div>@endforeach
                                </div>
                            @endif
                        </details>

                        @if($g->usesApparatusChoice() && ! $started)
                            <form method="POST" action="{{ route('secretary.tournament.board.apparatus', [$tournament, $g]) }}" class="sx-card sx-pad-sm sx-row-wrap sx-body" style="gap:.75rem">
                                @csrf
                                <span class="sx-label">Виды группы ({{ $g->apparatus_count }}):</span>
                                @foreach($apparatusOptions as $opt)
                                    <label class="sx-row-wrap" style="gap:.3rem"><input type="checkbox" class="sx-check" name="apparatus[]" value="{{ $opt }}" @checked(in_array($opt, $g->apparatusLabels(), true))>{{ $opt }}</label>
                                @endforeach
                                <button class="sx-btn sx-btn-primary sx-btn-sm sx-ml-auto">Сохранить виды</button>
                            </form>
                        @endif

                        <div id="board" style="display:flex;flex-direction:column;gap:1rem">
                            @if(count($board['unassigned']))
                                <section class="sx-card sx-dashed sx-pad-sm" style="border-color:rgba(56,189,248,.45)" data-column="unassigned">
                                    <div class="sx-row-wrap" style="padding:.25rem .25rem .6rem">
                                        <span class="sx-h3 sx-sky">Не в потоке</span>
                                        <span class="sx-small sx-muted">добавлены вручную или повторным импортом</span>
                                        <span class="sx-chip sx-chip-info sx-ml-auto">{{ count($board['unassigned']) }}</span>
                                    </div>
                                    <div class="board-col sx-stream-list" data-col="unassigned">
                                        @foreach($board['unassigned'] as $c) @include('secretary.partials.board-card', ['c' => $c, 'inStream' => false]) @endforeach
                                    </div>
                                </section>
                            @endif
                            @foreach($board['streams'] as $s)
                                <section class="sx-card sx-pad-sm sx-stream" data-column="stream" data-stream-no="{{ $s['no'] }}">
                                    <div class="sx-row-wrap" style="padding:.25rem .25rem .6rem;gap:.75rem">
                                        <span class="sx-dot sx-em" style="width:9px;height:9px"></span>
                                        <span class="sx-h2">Поток <span data-stream-label>{{ $s['no'] }}</span><span class="sx-mono sx-muted" style="font-weight:500"> · {{ $s['start'] && $s['end'] ? $s['start'].' – '.$s['end'] : 'время не задано' }}</span></span>
                                        <span class="sx-small sx-muted">/ {{ count($s['cards']) }} {{ $isTeamGroup ? $plural(count($s['cards']), 'команда', 'команды', 'команд') : $plural(count($s['cards']), 'гимнастка', 'гимнастки', 'гимнасток') }}</span>
                                        @foreach($currentApparatus as $a)<span class="sx-chip sx-chip-warn">{{ $a }}</span>@endforeach
                                        <span class="sx-row-wrap sx-ml-auto" style="gap:.25rem">
                                            @unless($started)<span class="sx-small sx-dim" style="display:inline-flex;align-items:center;gap:.25rem">@include('secretary.partials.sx-icon', ['name' => 'grip', 'size' => 13]) перетаскивайте</span>@endunless
                                            <button type="button" data-collapse class="sx-btn sx-btn-ghost sx-btn-sm" title="Свернуть / развернуть"><span class="sx-rot" style="display:inline-flex">@include('secretary.partials.sx-icon', ['name' => 'chevron', 'size' => 15])</span></button>
                                            @unless($started)<button type="button" data-delete-stream title="Удалить поток" class="sx-btn sx-btn-ghost sx-btn-sm">@include('secretary.partials.sx-icon', ['name' => 'trash', 'size' => 15])</button>@endunless
                                        </span>
                                    </div>
                                    <div class="sx-stream-body">
                                        <div class="board-col sx-stream-list" data-col="stream">
                                            @foreach($s['cards'] as $c) @include('secretary.partials.board-card', ['c' => $c, 'inStream' => true]) @endforeach
                                        </div>
                                    </div>
                                </section>
                            @endforeach
                        </div>
                        <div class="sx-row-wrap sx-small sx-dim" style="gap:1.25rem">
                            <span>Перетаскивайте карточки между потоками — номера и время пересчитаются автоматически</span>
                            <span class="sx-amber">▌ нет музыки</span><span>🔒 уже выступила</span><span><s>снята</s> — номер сохраняется, время не занимает</span>
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
                        a.classList.toggle('sx-hidden', !ok);
                        any = any || ok;
                        if (ok) shown++;
                    });
                    section.classList.toggle('sx-hidden', !any);
                });
                document.getElementById('group-search-empty')?.classList.toggle('sx-hidden', shown > 0 || !q);
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
        const hasStreams = @json($board['hasStreams']);
        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const plural = (n, one, few, many) => n % 10 === 1 && n % 100 !== 11 ? one : [2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? few : many;

        /* ---------- форма создания ---------- */
        const form = document.getElementById('create-form');
        if (form) {
            const toMin = (t) => { const [h, m] = (t || '09:00').split(':').map(Number); return h * 60 + m; };
            const fmt = (m) => String(Math.floor(m / 60) % 24).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
            const dur = (m) => (m >= 60 ? Math.floor(m / 60) + 'ч ' : '') + String(m % 60).padStart(m >= 60 ? 2 : 1, '0') + 'м';
            const suggestStart = (date) => dayEnds[date] ? fmt(toMin(dayEnds[date]) + 5) : '09:00';
            const word = (n) => isTeam ? plural(n, 'команда', 'команды', 'команд') : plural(n, 'гимнастка', 'гимнастки', 'гимнасток');
            const fmtDate = (d) => { if (!d) return 'без даты'; const dt = new Date(d + 'T12:00'); return dt.toLocaleDateString('ru-RU', { weekday: 'short', day: 'numeric', month: 'long' }); };
            let app = [...initialApp];
            let days = existingDays.length ? existingDays.map(d => ({ ...d, apparatus: [...d.apparatus] }))
                : [{ date: defaultDate, start: suggestStart(defaultDate), apparatus: [...app] }];
            const mode = () => form.querySelector('[name=apparatus_mode]:checked').value;
            const icon = {
                cal: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
                clock: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
                users: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>',
                check: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
            };

            const render = () => {
                const choice = mode() === 'choice';
                form.querySelector('[data-mode=fixed]').classList.toggle('sx-hidden', choice);
                form.querySelector('[data-mode=choice]').classList.toggle('sx-hidden', !choice);
                document.getElementById('app-count').textContent = choice ? 'вид на выбор' : (app.length ? app.length + ' ' + plural(app.length, 'вид', 'вида', 'видов') + ' выбрано' : '');
                document.getElementById('app-chips').innerHTML = ALL_APP.map(a => {
                    const i = app.indexOf(a);
                    return `<button type="button" data-app="${esc(a)}" class="sx-app ${i >= 0 ? 'is-on' : ''}">${i >= 0 ? `<span class="sx-app-no">${i + 1}</span>` : '<span class="sx-app-ring"></span>'}${esc(a)}${i >= 0 ? icon.check : ''}</button>`;
                }).join('');
                document.getElementById('days').innerHTML = days.map((d, di) => `
                    <div class="sx-inset sx-pad-sm" style="display:flex;flex-direction:column;gap:.6rem">
                        <div class="sx-row-wrap" style="gap:.75rem">
                            <span class="sx-em">${icon.cal}</span>
                            <span class="sx-label sx-text">Соревновательный день ${di + 1}</span>
                            ${days.length > 1 ? `<button type="button" data-del-day="${di}" class="sx-btn sx-btn-ghost sx-btn-sm sx-ml-auto" title="Удалить день">✕</button>` : ''}
                        </div>
                        <div class="sx-row-wrap" style="gap:.75rem;align-items:flex-end">
                            <label class="sx-field"><span class="sx-label">Дата</span><input type="date" data-day="${di}" data-f="date" value="${esc(d.date)}" class="sx-input"></label>
                            <label class="sx-field"><span class="sx-label">Старт потока 1</span><input type="time" data-day="${di}" data-f="start" value="${esc(d.start)}" class="sx-input"></label>
                            ${dayEnds[d.date] ? `<button type="button" data-after="${di}" class="sx-btn sx-btn-ghost sx-btn-sm" style="color:var(--sx-em2)" title="Предыдущие блоки этого дня заканчиваются в ${esc(dayEnds[d.date])}">после предыдущих (${esc(fmt(toMin(dayEnds[d.date]) + 5))})</button>` : ''}
                        </div>
                        ${choice ? '<span class="sx-small sx-muted">все виды</span>' : `<div class="sx-row-wrap" style="gap:.75rem">${app.length ? '<span class="sx-small sx-muted">Виды дня:</span>' : '<span class="sx-small sx-muted">Сначала выберите виды выше</span>'}${app.map(a => `<label class="sx-row-wrap sx-body" style="gap:.3rem"><input type="checkbox" class="sx-check" data-day="${di}" data-app2="${esc(a)}" ${d.apparatus.includes(a) ? 'checked' : ''}>${esc(a)}</label>`).join('')}</div>`}
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
                const kinds = choice ? choiceCount : app.length;

                const lo = Math.min(...sizes), hi = Math.max(...sizes);
                document.getElementById('pv-per').textContent = `≈ ${lo === hi ? lo : lo + '–' + hi} ${word(hi)} на поток`;
                document.getElementById('pv-min').textContent = kinds > 1 ? `${mins} мин/вид × ${kinds} ${plural(kinds, 'вид', 'вида', 'видов')}` : `${mins} мин на выход`;
                document.getElementById('pv-sv').textContent = `${count} / ${kinds || '—'}`;

                // Первый день — для сводки и таймлайна.
                const first = days[0];
                const firstK = choice ? choiceCount : (first?.apparatus.length || 0);
                let t = toMin(first?.start);
                const t0 = t;
                const segs = sizes.map((s, i) => { const len = s * mins * Math.max(1, firstK); const seg = { i, from: t, len }; t += len; return seg; });
                const total = t - t0;
                document.getElementById('pv-span').textContent = first?.start ? `${fmt(t0)}–${fmt(t)}` : '—';
                document.getElementById('pv-len').textContent = days.length > 1 ? `день 1: ${dur(total)} · всего дней: ${days.length}` : `(${dur(total)})`;
                document.getElementById('pv-t0').textContent = first?.start ? `${fmt(t0)} старт` : '';
                document.getElementById('pv-t1').textContent = first?.start ? `${fmt(t)} финал` : '';
                document.getElementById('pv-timeline').innerHTML = total > 0 ? segs.map(s => `<span style="flex:${s.len}" title="Поток ${s.i + 1}: ${fmt(s.from)}–${fmt(s.from + s.len)}">П${s.i + 1}</span>`).join('') : '';

                const status = document.getElementById('pv-status');
                status.className = 'sx-chip sx-chip-plain sx-ml-auto ' + (errs.length ? 'sx-chip-warn' : 'sx-chip-ok');
                status.innerHTML = errs.length ? `⚠ ${errs.length} ${plural(errs.length, 'замечание', 'замечания', 'замечаний')}` : `<span class="sx-dot"></span>Конфликтов нет`;

                document.getElementById('preview').innerHTML = errs.map(e => `<div class="sx-banner sx-banner-warn sx-body">⚠ ${esc(e)}</div>`).join('') + days.map((d, di) => {
                    const k = choice ? choiceCount : d.apparatus.length; let tt = toMin(d.start);
                    const appText = choice ? 'вид на выбор' : (app.filter(a => d.apparatus.includes(a)).join(' + ') || '—');
                    let from = 1;
                    return `<div class="sx-label" style="margin-top:.4rem">День ${di + 1} · ${esc(fmtDate(d.date))} · ${esc(appText)}</div>` + sizes.map((s, i) => {
                        const len = s * mins * Math.max(1, k);
                        const nums = `№${from}–${from + s - 1}`; from += s;
                        const row = `<div class="sx-card sx-pad-sm ${i === 0 ? 'sx-accent' : ''}" style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
                            <span class="sx-icon-box" style="width:auto;height:auto;padding:.4rem .6rem;flex-direction:column;${i === 0 ? 'background:var(--sx-em);color:#022c22' : ''}"><b class="sx-small">П${i + 1}</b><span class="sx-small" style="font-size:11px">${nums}</span></span>
                            <span class="sx-grow" style="min-width:8rem"><span class="sx-h3" style="display:block">Поток ${i + 1}</span><span class="sx-small sx-muted" style="display:inline-flex;align-items:center;gap:.3rem">${icon.users} ${s} ${word(s)}</span></span>
                            <span class="sx-chip sx-chip-info sx-mono" style="font-size:12px">${fmt(tt)} – ${fmt(tt + len)}</span>
                            <span class="sx-small sx-muted" style="display:inline-flex;align-items:center;gap:.3rem">${icon.clock} ${len} мин</span>
                            <span class="sx-chip sx-chip-mute sx-chip-plain">${esc(appText)}</span>
                        </div>`;
                        tt += len; return row;
                    }).join('');
                }).join('');

                document.getElementById('create-submit').disabled = errs.length > 0;
                document.getElementById('create-submit-label').textContent = `${hasStreams ? 'Пересоздать' : 'Сформировать'} ${count} ${plural(count, 'поток', 'потока', 'потоков')}`;
            };

            form.addEventListener('click', (e) => {
                const step = e.target.closest('[data-step]');
                if (step) {
                    const input = form.querySelector(`[name="${step.dataset.step}"]`);
                    const v = Math.min(+input.max || 99, Math.max(+input.min || 1, (+input.value || 0) + (+step.dataset.delta)));
                    input.value = v; render(); return;
                }
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
                document.getElementById('create-panel').classList.add('sx-hidden');
                document.getElementById('board-panel')?.classList.remove('sx-hidden');
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
        const collapseKey = 'board-collapsed-{{ $g->id }}';
        const alertBox = document.getElementById('board-alert');
        const readUndo = () => { try { return JSON.parse(sessionStorage.getItem(undoKey) || '[]'); } catch (_) { return []; } };
        const writeUndo = (stack) => { try { sessionStorage.setItem(undoKey, JSON.stringify(stack.slice(-20))); } catch (_) {} };
        document.getElementById('undo').disabled = started || readUndo().length === 0;

        // Свёрнутые потоки запоминаются, чтобы не раскрываться после каждого перетаскивания.
        const readCollapsed = () => { try { return JSON.parse(sessionStorage.getItem(collapseKey) || '[]'); } catch (_) { return []; } };
        readCollapsed().forEach(no => boardEl.querySelector(`[data-stream-no="${no}"]`)?.classList.add('sx-collapsed'));
        boardEl.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-collapse]');
            if (!btn) return;
            const section = btn.closest('.sx-stream');
            section.classList.toggle('sx-collapsed');
            const list = [...boardEl.querySelectorAll('.sx-stream.sx-collapsed[data-stream-no]')].map(s => s.dataset.streamNo);
            try { sessionStorage.setItem(collapseKey, JSON.stringify(list)); } catch (_) {}
        });

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
        const showError = (message) => { alertBox.textContent = message; alertBox.classList.remove('sx-hidden'); };
        const save = async (state, pushUndo = true) => {
            boardEl.style.pointerEvents = 'none'; boardEl.style.opacity = '.6';
            try {
                await post(arrangeUrl, state);
                if (pushUndo) writeUndo([...readUndo(), before]);
                window.location.reload();
            } catch (err) {
                showError(err.message);
                boardEl.style.pointerEvents = ''; boardEl.style.opacity = '';
            }
        };

        if (!started && typeof Sortable !== 'undefined') {
            const bind = (col) => new Sortable(col, {
                group: 'board', animation: 150, forceFallback: true, fallbackTolerance: 4, filter: '.locked', preventOnFilter: false, ghostClass: 'sortable-ghost', draggable: '[data-entry]',
                onEnd: () => save(snapshot()),
            });
            boardEl.querySelectorAll('.board-col').forEach(bind);

            document.getElementById('add-stream').addEventListener('click', () => {
                const n = boardEl.querySelectorAll('[data-col=stream]').length + 1;
                const wrap = document.createElement('section');
                wrap.className = 'sx-card sx-dashed sx-pad-sm sx-stream';
                wrap.style.borderColor = 'rgba(16,185,129,.5)';
                wrap.dataset.column = 'stream';
                wrap.innerHTML = `<div class="sx-row-wrap" style="padding:.25rem .25rem .6rem;gap:.75rem"><span class="sx-h2">Поток ${n}</span><span class="sx-chip sx-chip-ok">новый</span></div>
                    <div class="sx-stream-body"><div class="board-col sx-stream-list sx-small sx-dim" data-col="stream" style="min-height:5rem;border:1px dashed var(--sx-line2);align-items:center;justify-content:center">Перетащите сюда гимнасток</div></div>`;
                boardEl.appendChild(wrap);
                bind(wrap.querySelector('.board-col'));
                wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });

            boardEl.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-delete-stream]');
                if (!btn) return;
                const columns = [...boardEl.querySelectorAll('[data-col=stream]')];
                const col = btn.closest('[data-column]').querySelector('[data-col=stream]');
                const idx = columns.indexOf(col);
                if (columns.length === 1) { alert('Нельзя удалить единственный поток. Используйте «Пересоздать потоки».'); return; }
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
                if (!confirm('Провести жеребьёвку? Порядок внутри каждого потока будет перемешан. Отменить можно кнопкой «Отменить».')) return;
                try { await post(shuffleUrl); writeUndo([...readUndo(), before]); window.location.reload(); }
                catch (err) { showError(err.message); }
            });
            document.getElementById('undo').addEventListener('click', () => {
                const stack = readUndo(); const prev = stack.pop();
                if (!prev) return;
                writeUndo(stack);
                save(prev, false);
            });
            document.getElementById('recreate').addEventListener('click', () => {
                document.getElementById('board-panel').classList.add('sx-hidden');
                document.getElementById('create-panel').classList.remove('sx-hidden');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
    })();
    </script>
    @endif
</x-app-layout>
