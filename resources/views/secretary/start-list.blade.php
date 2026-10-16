<x-app-layout>
    <x-slot name="header">
        <div class="sx sx-row-wrap no-print" style="gap:.75rem">
            <span class="sx-label" style="display:inline-flex;align-items:center;gap:.4rem">@include('secretary.partials.sx-icon', ['name' => 'list', 'size' => 14]) Турнир</span>
            <h2 class="sx-h2 sx-truncate" style="margin:0;max-width:40rem">Стартовый лист · {{ $tournament->name }}</h2>
            <div class="sx-row-wrap sx-ml-auto">
                <a href="{{ route('secretary.tournament.board', $tournament) }}" class="sx-btn">@include('secretary.partials.sx-icon', ['name' => 'layers']) Потоки</a>
                <a href="{{ route('secretary.tournament.live', $tournament) }}" class="sx-btn sx-btn-primary">@include('secretary.partials.sx-icon', ['name' => 'live']) Live</a>
            </div>
        </div>
    </x-slot>
    @include('secretary.partials.board-styles')
    @include('secretary.partials.sx-styles')

    <style>
        @media print {
            header, aside, nav, .no-print { display: none !important; }
            body, #app-async-page, .print-root, .print-root * { background: #fff !important; color: #000 !important; border-color: #999 !important; box-shadow: none !important; }
            .print-day { break-before: page; }
            .print-day:first-of-type { break-before: auto; }
            main, main > div { padding: 0 !important; }
            .drag-handle { display: none !important; }
            .print-root [data-row], .print-root [data-key] { display: revert !important; }
            .sx-stream.sx-collapsed .sx-stream-body { display: block !important; }
            .sx-table th, .sx-table td { padding: 4px 6px !important; font-size: 12px !important; }
        }
    </style>

    @php
        $docTitle = ['start' => 'Стартовый лист', 'protocol' => 'Стартовый протокол', 'program' => 'Программа соревнований'][$doc];
        $dayLabel = fn (string $d) => $d !== '' ? \Carbon\Carbon::parse($d)->locale('ru')->isoFormat('dddd, D MMMM YYYY') : 'Без назначенной даты';
        $dayShort = fn (string $d) => \Carbon\Carbon::parse($d)->locale('ru')->isoFormat('D MMM');
        $isWithdrawn = fn ($p) => $p->status === 'withdrawn' || $p->withdrawn_at !== null;
        $plural = fn (int $n, string $one, string $few, string $many) => $n % 10 === 1 && $n % 100 !== 11 ? $one : (in_array($n % 10, [2, 3, 4], true) && ! in_array($n % 100, [12, 13, 14], true) ? $few : $many);
        $toMin = fn (?string $t) => $t ? ((int) substr($t, 0, 2)) * 60 + (int) substr($t, 3, 2) : null;

        // Начало/конец блока; если у потока они не заданы — по времени выходов гимнасток.
        $span = function (array $item) use ($toMin) {
            $labels = $item['performances']->pluck('scheduled_at_label')->filter()->sort()->values();
            $start = $item['start'] ?? $labels->first();
            $end = $item['end'];
            if ($end === null && $labels->isNotEmpty()) {
                $last = $toMin($labels->last()) + max(1, (int) ($item['category']->minutes_per_athlete ?? 0));
                $end = sprintf('%02d:%02d', intdiv($last, 60) % 24, $last % 60);
            }

            return [$start, $end];
        };

        // Сводка по показанным дням.
        $allItems = $days->flatten(1);
        $streamItems = $allItems->where('type', 'stream');
        $byAthlete = $streamItems->flatMap(fn ($item) => $item['performances'])->groupBy('athlete_id');
        $activeAthletes = $byAthlete->reject(fn ($ps) => $ps->every($isWithdrawn));
        $withMusic = $activeAthletes->filter(fn ($ps) => $ps->contains(fn ($p) => $p->track !== null))->count();
        $musicPct = $activeAthletes->count() ? (int) round($withMusic / $activeAthletes->count() * 100) : 0;
        $totalMinutes = $streamItems->sum(function ($item) use ($span, $toMin) {
            [$start, $end] = $span($item);

            return $start && $end ? max(0, $toMin($end) - $toMin($start)) : 0;
        });
        $streamsCount = $streamItems->where('unassigned', false)->count();
        $awardsCount = $allItems->where('type', 'award')->count();
        $problemCount = $activeAthletes->reject(fn ($ps) => $ps->contains(fn ($p) => $p->track !== null))->count();
        $fmtDuration = fn (int $m) => $m >= 60 ? intdiv($m, 60).'ч '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'м' : $m.' мин';
        $rowStatus = function ($ps) use ($isWithdrawn) {
            if ($ps->every($isWithdrawn)) return ['Снята', 'sx-chip-bad', false];
            if ($ps->contains(fn ($p) => $p->status === 'performing')) return ['На ковре', 'sx-chip-ok', true];
            if ($ps->every(fn ($p) => $isWithdrawn($p) || in_array($p->status, ['done', 'published', 'inquiry', 'under_review'], true))) return ['Выступила', 'sx-chip-mute', false];
            if ($ps->contains(fn ($p) => $p->status === 'called')) return ['Вызвана', 'sx-chip-info', false];
            return ['В строю', 'sx-chip-ok', false];
        };
        $awardErrors = $errors->hasAny(['title', 'scheduled_on', 'starts_at', 'ends_at']);
    @endphp

    <div class="sx" style="display:flex;flex-direction:column;gap:1rem">
        <div class="no-print"><x-flash /></div>

        {{-- ===== Панель инструментов ===== --}}
        <div class="no-print sx-card sx-pad-sm" style="display:flex;flex-direction:column;gap:.75rem">
            <form method="GET" class="sx-row-wrap" style="gap:.75rem">
                @if($selectedDate !== '')<input type="hidden" name="date" value="{{ $selectedDate }}">@endif
                <label class="sx-row-wrap" style="gap:.4rem"><span class="sx-muted sx-small" style="display:inline-flex;align-items:center;gap:.3rem">@include('secretary.partials.sx-icon', ['name' => 'sheet', 'size' => 14]) Документ:</span>
                    <select name="doc" onchange="this.form.submit()" class="sx-input" style="font-weight:600">
                        <option value="start" @selected($doc === 'start')>Стартовый лист</option>
                        <option value="protocol" @selected($doc === 'protocol')>Стартовый протокол</option>
                        <option value="program" @selected($doc === 'program')>Программа соревнований</option>
                    </select></label>
                <div class="sx-seg">
                    <a href="{{ request()->fullUrlWithQuery(['date' => null]) }}" class="{{ $selectedDate === '' ? 'is-on' : '' }}">Все дни</a>
                    @foreach($allDates as $i => $d)
                        <a href="{{ request()->fullUrlWithQuery(['date' => $d]) }}" class="{{ $selectedDate === $d ? 'is-on' : '' }}">@if($selectedDate === $d)<span class="sx-dot"></span>@endif День {{ $i + 1 }} · {{ $dayShort($d) }}</a>
                    @endforeach
                </div>
                <label class="sx-row-wrap" style="gap:.4rem"><span class="sx-muted sx-small" style="display:inline-flex;align-items:center;gap:.3rem">@include('secretary.partials.sx-icon', ['name' => 'clock', 'size' => 14]) Время:</span>
                    <select name="times" onchange="this.form.submit()" class="sx-input">
                        <option value="one" @selected($timesMode === 'one')>одно на гимнастку</option>
                        <option value="each" @selected($timesMode === 'each')>по каждому виду</option>
                    </select></label>
                <label class="sx-row-wrap sx-body" style="gap:.4rem;cursor:pointer"><input type="hidden" name="members" value="0"><input type="checkbox" class="sx-check" name="members" value="1" onchange="this.form.submit()" @checked($showMembers)> Состав команд</label>
                <label class="sx-row-wrap sx-body" style="gap:.4rem;cursor:pointer"><input type="hidden" name="coaches" value="0"><input type="checkbox" class="sx-check" name="coaches" value="1" onchange="this.form.submit()" @checked($showCoaches)> Тренерский штаб</label>
            </form>
            <div class="sx-row-wrap" style="gap:.6rem">
                <label class="sx-search" style="flex:1;min-width:15rem;max-width:26rem">
                    @include('secretary.partials.sx-icon', ['name' => 'search', 'size' => 15])
                    <input id="sheet-search" type="search" placeholder="Поиск по ФИО, клубу, тренеру…" autocomplete="off" class="sx-input">
                </label>
                <button type="button" id="problems-toggle" class="sx-btn {{ $problemCount ? 'sx-btn-danger' : '' }}" @disabled($problemCount === 0) aria-pressed="false">@include('secretary.partials.sx-icon', ['name' => 'alert', 'size' => 15]) Только с проблемами ({{ $problemCount }})</button>
                <span class="sx-row-wrap sx-ml-auto" style="gap:.5rem">
                    <button type="button" id="award-toggle" class="sx-btn sx-btn-amber">@include('secretary.partials.sx-icon', ['name' => 'trophy', 'size' => 15]) Награждение</button>
                    <a href="{{ route('secretary.tournament.start-sheet', array_filter(['tournament' => $tournament, 'date' => $selectedDate ?: null])) }}" class="sx-btn">@include('secretary.partials.sx-icon', ['name' => 'sheet', 'size' => 15]) Excel: лист</a>
                    <a href="{{ route('secretary.tournament.start-protocol', $tournament) }}" class="sx-btn">@include('secretary.partials.sx-icon', ['name' => 'download', 'size' => 15]) Протокол</a>
                    <a href="{{ route('secretary.tournament.programme', $tournament) }}" class="sx-btn">@include('secretary.partials.sx-icon', ['name' => 'download', 'size' => 15]) Программа</a>
                    <button type="button" onclick="window.print()" class="sx-btn sx-btn-primary">@include('secretary.partials.sx-icon', ['name' => 'print', 'size' => 15]) Печать / PDF</button>
                </span>
            </div>
        </div>

        {{-- ===== Добавить награждение (в любое время) ===== --}}
        <div id="award-panel" class="no-print sx-card sx-pad {{ $awardErrors ? '' : 'sx-hidden' }}" style="border-color:rgba(245,158,11,.4)">
            <div class="sx-row-wrap" style="gap:.6rem"><span class="sx-icon-box is-amber">@include('secretary.partials.sx-icon', ['name' => 'trophy', 'size' => 18])</span><span class="sx-h2">Новое награждение</span>
                <button type="button" data-award-close class="sx-btn sx-btn-ghost sx-btn-sm sx-ml-auto">@include('secretary.partials.sx-icon', ['name' => 'x', 'size' => 15])</button></div>
            <form method="POST" action="{{ route('secretary.tournament.awards.store', $tournament) }}" class="sx-row-wrap" style="margin-top:1rem;align-items:flex-end;gap:.75rem">
                @csrf
                <label class="sx-field" style="flex:1;min-width:16rem"><span class="sx-label">Название</span><input name="title" required maxlength="150" value="{{ old('title', 'Награждение') }}" placeholder="Награждение 2015 г.р., A" class="sx-input"></label>
                <label class="sx-field"><span class="sx-label">День</span><input type="date" name="scheduled_on" required value="{{ old('scheduled_on', $selectedDate ?: $allDates->first()) }}" class="sx-input"></label>
                <label class="sx-field"><span class="sx-label">Начало</span><input type="time" name="starts_at" required value="{{ old('starts_at') }}" class="sx-input"></label>
                <label class="sx-field"><span class="sx-label">Окончание</span><input type="time" name="ends_at" value="{{ old('ends_at') }}" class="sx-input"></label>
                <button class="sx-btn sx-btn-amber" style="padding:.55rem 1.1rem">@include('secretary.partials.sx-icon', ['name' => 'plus', 'size' => 15]) Добавить</button>
            </form>
            <div class="sx-small sx-muted" style="margin-top:.6rem">Время любое. Все выступления этого дня, начиная с начала награждения, сдвинутся на его длительность (если указано окончание); при удалении или переносе время вернётся. Награждение встанет в лист по времени, потом его можно перетащить.</div>
        </div>

        {{-- ===== Сводка ===== --}}
        <div class="no-print sx-tiles">
            <div class="sx-card sx-pad-sm sx-row-wrap" style="gap:.75rem;flex-wrap:nowrap"><span class="sx-icon-box">@include('secretary.partials.sx-icon', ['name' => 'users', 'size' => 18])</span><span><span class="sx-small sx-muted" style="display:block">Всего участниц</span><span class="sx-num">{{ $activeAthletes->count() }} заявлено</span></span></div>
            <div class="sx-card sx-pad-sm sx-row-wrap" style="gap:.75rem;flex-wrap:nowrap"><span class="sx-icon-box is-sky">@include('secretary.partials.sx-icon', ['name' => 'clock', 'size' => 18])</span><span><span class="sx-small sx-muted" style="display:block">Расчётная длина</span><span class="sx-num">{{ $fmtDuration($totalMinutes) }}</span></span></div>
            <div class="sx-card sx-pad-sm sx-row-wrap" style="gap:.75rem;flex-wrap:nowrap"><span class="sx-icon-box">@include('secretary.partials.sx-icon', ['name' => 'music', 'size' => 18])</span><span><span class="sx-small sx-muted" style="display:block">Фонограммы готовы</span><span class="sx-num {{ $musicPct === 100 ? 'sx-em' : ($musicPct >= 80 ? 'sx-amber' : 'sx-rose') }}">{{ $musicPct }}%</span> <span class="sx-small sx-muted">({{ $withMusic }} / {{ $activeAthletes->count() }})</span></span></div>
            <div class="sx-card sx-pad-sm sx-row-wrap" style="gap:.75rem;flex-wrap:nowrap"><span class="sx-icon-box is-amber">@include('secretary.partials.sx-icon', ['name' => 'layers', 'size' => 18])</span><span><span class="sx-small sx-muted" style="display:block">Блоков в листе</span><span class="sx-num">{{ $streamsCount }} {{ $plural($streamsCount, 'поток', 'потока', 'потоков') }} · {{ $awardsCount }} {{ $plural($awardsCount, 'награжд.', 'награжд.', 'награжд.') }}</span></span></div>
        </div>

        <div class="no-print sx-card sx-pad-sm sx-row-wrap sx-small" style="gap:.75rem">
            <span class="sx-em">@include('secretary.partials.sx-icon', ['name' => 'grip', 'size' => 16])</span>
            <span class="sx-muted sx-grow">Перетаскивайте блоки за ручку, чтобы изменить порядок выхода потоков и награждений внутри дня. Порядок сохраняется и попадает в печать и Excel.</span>
            <span id="order-status" class="sx-chip sx-chip-ok"><span class="sx-dot"></span>порядок сохранён</span>
        </div>

        <div class="print-root" style="display:flex;flex-direction:column;gap:2rem">
            @forelse($days as $date => $items)
                <section class="print-day" style="display:flex;flex-direction:column;gap:.9rem">
                    <div style="text-align:center">
                        <div class="sx-label">{{ $tournament->name }}</div>
                        <div class="sx-h2" style="margin-top:.25rem;font-size:20px">{{ $docTitle }}</div>
                        <div class="sx-body sx-muted first-letter:uppercase">{{ $dayLabel($date) }}</div>
                    </div>

                    <div class="day-list" data-date="{{ $date }}" style="display:flex;flex-direction:column;gap:.9rem">
                        @foreach($items as $item)
                            @if($item['type'] === 'award')
                                @php $award = $item['award']; @endphp
                                <div data-key="{{ $item['key'] }}" data-award class="sx-card sx-dashed sx-pad-sm sx-row-wrap" style="gap:.75rem;border-color:rgba(245,158,11,.5);background:rgba(120,53,15,.12)">
                                    <span class="drag-handle no-print sx-grip" style="cursor:grab" title="Перетащить">@include('secretary.partials.sx-icon', ['name' => 'grip', 'size' => 18])</span>
                                    <span class="sx-icon-box is-amber">@include('secretary.partials.sx-icon', ['name' => 'trophy', 'size' => 18])</span>
                                    <span class="sx-grow"><span class="sx-h3" style="display:block">{{ $award->title }}</span><span class="sx-chip sx-chip-warn" style="margin-top:.2rem">награждение</span></span>
                                    @if($item['out_of_order'])<span class="no-print sx-chip sx-chip-bad sx-chip-plain">время раньше предыдущего блока</span>@endif
                                    <span class="sx-mono sx-amber" style="font-size:18px;font-weight:700;display:inline-flex;align-items:center;gap:.4rem">@include('secretary.partials.sx-icon', ['name' => 'clock', 'size' => 16]){{ $award->timeLabel() }}</span>
                                    <details class="no-print sx-small" style="position:relative">
                                        <summary class="sx-btn sx-btn-ghost sx-btn-sm" title="Изменить">@include('secretary.partials.sx-icon', ['name' => 'edit', 'size' => 15])</summary>
                                        <div class="sx-card-hi sx-pad-sm" style="position:absolute;right:0;top:2.4rem;z-index:20;width:min(34rem,90vw);box-shadow:0 8px 24px -4px rgba(0,0,0,.45)">
                                            <form method="POST" action="{{ route('secretary.tournament.awards.update', [$tournament, $award]) }}" class="sx-row-wrap" style="align-items:flex-end;gap:.5rem">
                                                @csrf @method('PATCH')
                                                <label class="sx-field" style="flex:1;min-width:12rem"><span class="sx-label">Название</span><input name="title" required maxlength="150" value="{{ $award->title }}" class="sx-input"></label>
                                                <label class="sx-field"><span class="sx-label">День</span><input type="date" name="scheduled_on" required value="{{ $award->scheduled_on?->format('Y-m-d') }}" class="sx-input"></label>
                                                <label class="sx-field"><span class="sx-label">Начало</span><input type="time" name="starts_at" required value="{{ substr((string) $award->starts_at, 0, 5) }}" class="sx-input"></label>
                                                <label class="sx-field"><span class="sx-label">Конец</span><input type="time" name="ends_at" value="{{ $award->ends_at ? substr((string) $award->ends_at, 0, 5) : '' }}" class="sx-input"></label>
                                                <button class="sx-btn sx-btn-amber">Сохранить</button>
                                            </form>
                                            <form method="POST" action="{{ route('secretary.tournament.awards.destroy', [$tournament, $award]) }}" style="margin-top:.6rem" onsubmit="return confirm('Удалить награждение «{{ e($award->title) }}»?')">
                                                @csrf @method('DELETE')
                                                <button class="sx-btn sx-btn-danger sx-btn-sm">@include('secretary.partials.sx-icon', ['name' => 'trash', 'size' => 14]) Удалить награждение</button>
                                            </form>
                                        </div>
                                    </details>
                                </div>
                            @else
                                @php
                                    $category = $item['category'];
                                    $session = $item['session'];
                                    $rows = $item['performances']->groupBy('athlete_id')->map->values();
                                    $apparatusCols = $item['performances']->pluck('apparatus')->filter()->unique()->values();
                                    $apparatusText = $session ? implode(' → ', $session->apparatus ?? []) : ($item['unassigned'] ? 'не назначено на день' : ($category->apparatus ?? ''));
                                    $streamTitle = ($category->group?->name ?? $category->name).($category->stream_no ? ' — Поток '.$category->stream_no : '');
                                    $isTeam = $category->program === 'group';
                                    $activeRows = $rows->reject(fn ($ps) => $ps->every($isWithdrawn))->count();
                                    $blockProblems = $rows->filter(fn ($ps) => ! $ps->every($isWithdrawn) && ! $ps->contains(fn ($p) => $p->track !== null))->count();
                                    $perApparatus = $timesMode === 'each' && $apparatusCols->count() > 1;
                                    [$blockStart, $blockEnd] = $item['unassigned'] ? [null, null] : $span($item);
                                @endphp
                                <div data-key="{{ $item['key'] }}" class="sx-card sx-stream" style="overflow:hidden">
                                    <div class="sx-row-wrap" style="padding:.85rem 1rem;gap:.75rem">
                                        @unless($item['unassigned'])<span class="drag-handle no-print sx-grip" style="cursor:grab" title="Перетащить">@include('secretary.partials.sx-icon', ['name' => 'grip', 'size' => 18])</span>@endunless
                                        <span class="sx-icon-box no-print">@include('secretary.partials.sx-icon', ['name' => 'users', 'size' => 18])</span>
                                        <span class="sx-grow">
                                            <span class="sx-h2" style="display:block">{{ $streamTitle }}@if($apparatusText !== '')<span class="sx-muted" style="font-weight:500"> · {{ $apparatusText }}</span>@endif</span>
                                            <span class="sx-row-wrap sx-small sx-muted" style="gap:.9rem;margin-top:.15rem">
                                                <span style="display:inline-flex;align-items:center;gap:.3rem">@include('secretary.partials.sx-icon', ['name' => 'calendar', 'size' => 13]) {{ $date !== '' ? $dayLabel($date) : 'без даты' }}</span>
                                                <span style="display:inline-flex;align-items:center;gap:.3rem">@include('secretary.partials.sx-icon', ['name' => 'users', 'size' => 13]) {{ $activeRows }} {{ $isTeam ? $plural($activeRows, 'команда', 'команды', 'команд') : $plural($activeRows, 'участница', 'участницы', 'участниц') }}</span>
                                                @if($blockStart)<span class="sx-sky" style="display:inline-flex;align-items:center;gap:.3rem">@include('secretary.partials.sx-icon', ['name' => 'clock', 'size' => 13]) Время: {{ $blockStart }}{{ $blockEnd ? ' – '.$blockEnd : '' }}</span>@endif
                                            </span>
                                        </span>
                                        @if($item['out_of_order'])<span class="no-print sx-chip sx-chip-bad sx-chip-plain">время раньше предыдущего блока</span>@endif
                                        @if($blockProblems)<span class="no-print sx-chip sx-chip-bad sx-chip-plain">@include('secretary.partials.sx-icon', ['name' => 'music-off', 'size' => 12]) без музыки: {{ $blockProblems }}</span>@endif
                                        @if($doc !== 'program')
                                            <button type="button" data-collapse class="no-print sx-btn sx-btn-ghost sx-btn-sm" title="Свернуть / развернуть"><span class="sx-rot" style="display:inline-flex">@include('secretary.partials.sx-icon', ['name' => 'chevron', 'size' => 16])</span></button>
                                        @endif
                                    </div>
                                    @if($doc !== 'program')
                                        <div class="sx-stream-body sx-scroll-x">
                                            <table class="sx-table">
                                                <thead>
                                                    <tr>
                                                        <th style="width:2.5rem">№</th><th style="width:3.5rem">Ст.№</th>
                                                        @if($perApparatus)
                                                            @foreach($apparatusCols as $a)<th style="width:5rem">{{ $a }}</th>@endforeach
                                                        @else
                                                            <th style="width:5rem">Время</th>
                                                        @endif
                                                        <th>{{ $isTeam ? 'Команда' : 'Фамилия, имя спортсменки' }}</th>
                                                        @unless($isTeam)<th style="width:4rem">Год</th>@endunless
                                                        <th>Клуб / город</th>
                                                        @if($showCoaches)<th>Тренерский штаб</th>@endif
                                                        <th class="no-print">Статус</th>
                                                        <th class="no-print" style="text-align:right;padding-right:1rem">Музыка</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @forelse($rows->values() as $i => $ps)
                                                        @php
                                                            $p = $ps->first(); $athlete = $p->athlete; $withdrawn = $ps->every($isWithdrawn);
                                                            $track = $ps->first(fn ($x) => $x->track !== null)?->track;
                                                            [$statusText, $statusClass, $isLive] = $rowStatus($ps);
                                                            $fullName = trim(($athlete?->last_name ?? '').' '.($athlete?->is_team ? '' : ($athlete?->first_name ?? '')));
                                                        @endphp
                                                        <tr data-row data-search="{{ mb_strtolower($fullName.' '.($athlete?->club ?? '').' '.($athlete?->coach ?? '')) }}" @if(! $withdrawn && ! $track) data-problem @endif class="{{ $withdrawn ? 'is-out' : '' }} {{ $isLive ? 'is-live' : '' }}">
                                                            <td class="sx-mono sx-muted">{{ $i + 1 }}</td>
                                                            <td class="sx-mono sx-text" style="font-weight:700">{{ $p->start_number }}</td>
                                                            @if($withdrawn)
                                                                <td class="sx-small" colspan="{{ $perApparatus ? $apparatusCols->count() : 1 }}">снята</td>
                                                            @elseif($perApparatus)
                                                                @foreach($apparatusCols as $a)<td class="sx-mono">{{ $ps->firstWhere('apparatus', $a)?->scheduled_at_label ?? '—' }}</td>@endforeach
                                                            @else
                                                                <td class="sx-mono {{ $isLive ? 'sx-em' : '' }}" style="font-weight:600">{{ $ps->pluck('scheduled_at_label')->filter()->first() ?? '—' }}</td>
                                                            @endif
                                                            <td class="{{ $withdrawn ? 'sx-strike' : 'sx-text' }}" style="font-weight:600">
                                                                {{ $fullName }}
                                                                @if($isLive)<span class="no-print sx-chip sx-chip-ok" style="margin-left:.35rem">на ковре</span>@endif
                                                                @if($athlete?->is_team && $showMembers && $athlete->members->isNotEmpty())
                                                                    <div class="sx-small sx-muted" style="font-weight:400">{{ $athlete->members->map(fn ($m) => trim($m->last_name.' '.$m->first_name))->implode(', ') }}</div>
                                                                @endif
                                                            </td>
                                                            @unless($isTeam)<td class="sx-mono sx-muted">{{ $athlete?->birthdate?->year }}</td>@endunless
                                                            <td class="sx-muted">{{ $athlete?->club }}</td>
                                                            @if($showCoaches)<td class="sx-muted">{{ $athlete?->coach }}</td>@endif
                                                            <td class="no-print"><span class="sx-chip {{ $statusClass }} sx-chip-plain"><span class="sx-dot"></span>{{ $statusText }}</span></td>
                                                            <td class="no-print" style="text-align:right;padding-right:1rem">
                                                                @if($withdrawn)
                                                                    <span class="sx-dim">—</span>
                                                                @elseif($track)
                                                                    <span class="sx-chip sx-chip-info sx-chip-plain" title="{{ $track->original_name }}">@include('secretary.partials.sx-icon', ['name' => 'music', 'size' => 12]) MP3 загружен</span>
                                                                @else
                                                                    <span class="sx-chip sx-chip-bad sx-chip-plain">@include('secretary.partials.sx-icon', ['name' => 'music-off', 'size' => 12]) Нет музыки</span>
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="9" class="sx-muted" style="font-style:italic">Нет назначенных участниц.</td></tr>
                                                    @endforelse
                                                </tbody>
                                            </table>
                                            <div data-empty-filter class="sx-hidden sx-small sx-muted" style="padding:.75rem 1rem">Нет совпадений в этом потоке.</div>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>

                    @if($doc === 'protocol')
                        <div class="sx-body sx-muted" style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;padding-top:1.5rem"><div>Главный судья ____________________</div><div>Главный секретарь ____________________</div></div>
                    @endif
                </section>
            @empty
                <div class="sx-card sx-muted" style="padding:2.5rem;text-align:center">Потоки ещё не сформированы. Создайте их на странице <a class="sx-em" style="text-decoration:underline" href="{{ route('secretary.tournament.board', $tournament) }}">«Потоки»</a>.</div>
            @endforelse
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <script>
    (() => {
        /* ---------- награждение: показать / скрыть форму ---------- */
        const awardPanel = document.getElementById('award-panel');
        document.getElementById('award-toggle')?.addEventListener('click', () => {
            awardPanel.classList.toggle('sx-hidden');
            if (!awardPanel.classList.contains('sx-hidden')) awardPanel.querySelector('[name=starts_at]')?.focus();
        });
        awardPanel?.querySelector('[data-award-close]')?.addEventListener('click', () => awardPanel.classList.add('sx-hidden'));

        /* ---------- свернуть / развернуть поток ---------- */
        document.querySelectorAll('[data-collapse]').forEach(btn => btn.addEventListener('click', () => btn.closest('.sx-stream').classList.toggle('sx-collapsed')));

        /* ---------- поиск и «только с проблемами» ---------- */
        const search = document.getElementById('sheet-search');
        const problemsBtn = document.getElementById('problems-toggle');
        let onlyProblems = false;
        const applyFilter = () => {
            const q = (search?.value || '').trim().toLowerCase();
            const active = q !== '' || onlyProblems;
            document.querySelectorAll('.day-list > [data-key]').forEach(block => {
                if (block.hasAttribute('data-award')) { block.classList.toggle('sx-hidden', active); return; }
                const rows = [...block.querySelectorAll('[data-row]')];
                let visible = 0;
                rows.forEach(row => {
                    const ok = (!q || q.split(/\s+/).every(part => row.dataset.search.includes(part))) && (!onlyProblems || row.hasAttribute('data-problem'));
                    row.classList.toggle('sx-hidden', !ok);
                    if (ok) visible++;
                });
                block.classList.toggle('sx-hidden', active && visible === 0);
                block.querySelector('[data-empty-filter]')?.classList.toggle('sx-hidden', !(active && visible === 0));
            });
        };
        search?.addEventListener('input', applyFilter);
        problemsBtn?.addEventListener('click', () => {
            onlyProblems = !onlyProblems;
            problemsBtn.setAttribute('aria-pressed', String(onlyProblems));
            problemsBtn.style.outline = onlyProblems ? '2px solid #f43f5e' : '';
            applyFilter();
        });

        /* ---------- порядок блоков дня ---------- */
        if (typeof Sortable === 'undefined') return;
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const url = @json(route('secretary.tournament.start-list.order', $tournament));
        const status = document.getElementById('order-status');
        const show = (ok, text) => {
            status.className = 'sx-chip sx-chip-plain ' + (ok ? 'sx-chip-ok' : 'sx-chip-bad');
            status.textContent = text;
        };
        document.querySelectorAll('.day-list').forEach(list => new Sortable(list, {
            handle: '.drag-handle', animation: 150, forceFallback: true, fallbackTolerance: 4, ghostClass: 'sortable-ghost', draggable: '[data-key]',
            onEnd: async () => {
                const keys = [...list.querySelectorAll(':scope > [data-key]')].map(el => el.dataset.key);
                show(true, 'сохранение…');
                try {
                    const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) }, body: JSON.stringify({ date: list.dataset.date, keys }) });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || data.ok === false) throw new Error(data.error || data.message || 'Не удалось сохранить порядок.');
                    window.location.reload();
                } catch (err) { show(false, err.message); }
            },
        }));
    })();
    </script>
</x-app-layout>
