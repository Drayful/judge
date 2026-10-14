<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-3 no-print">
            <h2 class="font-semibold text-xl text-slate-100 leading-tight">Стартовый лист · {{ $tournament->name }}</h2>
            <div class="ml-auto flex flex-wrap gap-2 text-sm">
                <a href="{{ route('secretary.tournament.board', $tournament) }}" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 font-semibold text-slate-200 hover:bg-slate-800">Потоки</a>
                <button type="button" onclick="window.print()" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 font-semibold text-slate-200 hover:bg-slate-800">🖨 Печать / PDF</button>
                <a href="{{ route('secretary.tournament.start-sheet', array_filter(['tournament' => $tournament, 'date' => $selectedDate ?: null])) }}" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-slate-200 hover:bg-slate-800">⬇ Excel: лист</a>
                <a href="{{ route('secretary.tournament.start-protocol', $tournament) }}" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-slate-200 hover:bg-slate-800">⬇ протокол</a>
                <a href="{{ route('secretary.tournament.programme', $tournament) }}" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-slate-200 hover:bg-slate-800">⬇ программа</a>
            </div>
        </div>
    </x-slot>
    @include('secretary.partials.board-styles')

    <style>
        @media print {
            header, aside, nav, .no-print { display: none !important; }
            body, #app-async-page, .print-root, .print-root * { background: #fff !important; color: #000 !important; border-color: #999 !important; box-shadow: none !important; }
            .print-day { break-before: page; }
            .print-day:first-of-type { break-before: auto; }
            main, main > div { padding: 0 !important; }
            .drag-handle { display: none !important; }
        }
    </style>

    @php
        $docTitle = ['start' => 'Стартовый лист', 'protocol' => 'Стартовый протокол', 'program' => 'Программа соревнований'][$doc];
        $dayLabel = fn (string $d) => $d !== '' ? \Carbon\Carbon::parse($d)->locale('ru')->isoFormat('dddd, D MMMM YYYY') : 'Без назначенной даты';
        $isWithdrawn = fn ($p) => $p->status === 'withdrawn' || $p->withdrawn_at !== null;
    @endphp

    <div class="space-y-4">
        <div class="no-print"><x-flash /></div>

        <form method="GET" class="no-print flex flex-wrap items-end gap-3 rounded-xl border border-slate-800 bg-slate-900/70 p-4 text-xs text-slate-400">
            <label>Документ<br><select name="doc" onchange="this.form.submit()" class="mt-1 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white">
                <option value="start" @selected($doc === 'start')>Стартовый лист</option>
                <option value="protocol" @selected($doc === 'protocol')>Стартовый протокол</option>
                <option value="program" @selected($doc === 'program')>Программа соревнований</option></select></label>
            <label>День<br><select name="date" onchange="this.form.submit()" class="mt-1 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white">
                <option value="">все дни</option>
                @foreach($allDates as $d)<option value="{{ $d }}" @selected($selectedDate === $d)>{{ $dayLabel($d) }}</option>@endforeach</select></label>
            <label>Время<br><select name="times" onchange="this.form.submit()" class="mt-1 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white">
                <option value="one" @selected($timesMode === 'one')>одно на гимнастку</option>
                <option value="each" @selected($timesMode === 'each')>по каждому виду</option></select></label>
            <label>Состав команд<br><select name="members" onchange="this.form.submit()" class="mt-1 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white">
                <option value="1" @selected($showMembers)>показывать</option>
                <option value="0" @selected(! $showMembers)>скрыть</option></select></label>
            <div class="ml-auto max-w-md text-[11px] text-slate-500">Перетаскивайте блоки за ⋮⋮, чтобы поменять порядок потоков и награждений внутри дня. Порядок сохраняется и попадает в печать и Excel.</div>
        </form>

        <details class="no-print rounded-xl border border-amber-800/60 bg-amber-950/20 p-4" @if($errors->hasAny(['title', 'scheduled_on', 'starts_at', 'ends_at'])) open @endif>
            <summary class="cursor-pointer text-sm font-semibold text-amber-100">+ Добавить награждение</summary>
            <form method="POST" action="{{ route('secretary.tournament.awards.store', $tournament) }}" class="mt-3 flex flex-wrap items-end gap-3 text-xs text-slate-300">
                @csrf
                <label class="min-w-[16rem] flex-1">Название<br><input name="title" required maxlength="150" value="{{ old('title', 'Награждение') }}" placeholder="Награждение 2015 г.р., A" class="mt-1 w-full rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white"></label>
                <label>День<br><input type="date" name="scheduled_on" required value="{{ old('scheduled_on', $selectedDate ?: $allDates->first()) }}" class="mt-1 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white"></label>
                <label>Начало<br><input type="time" name="starts_at" required value="{{ old('starts_at') }}" class="mt-1 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white"></label>
                <label>Окончание<br><input type="time" name="ends_at" value="{{ old('ends_at') }}" class="mt-1 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white"></label>
                <button class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-500">Добавить</button>
            </form>
            <div class="mt-2 text-[11px] text-amber-200/70">Время любое — потоки не сдвигаются. Награждение встанет в лист по времени, потом его можно перетащить.</div>
        </details>

        <div id="order-status" class="no-print hidden rounded-lg border px-3 py-2 text-sm"></div>

        <div class="print-root space-y-8">
            @forelse($days as $date => $items)
                <section class="print-day space-y-3">
                    <div class="text-center">
                        <div class="text-xs uppercase tracking-wider text-slate-500">{{ $tournament->name }}</div>
                        <div class="mt-1 text-xl font-semibold text-white">{{ $docTitle }}</div>
                        <div class="text-sm text-slate-400 first-letter:uppercase">{{ $dayLabel($date) }}</div>
                    </div>

                    <div class="day-list space-y-3" data-date="{{ $date }}">
                        @foreach($items as $item)
                            @if($item['type'] === 'award')
                                @php $award = $item['award']; @endphp
                                <div data-key="{{ $item['key'] }}" class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-700/60 bg-amber-950/25 px-4 py-3">
                                    <span class="drag-handle no-print cursor-grab select-none text-lg text-amber-300/70" title="Перетащить">⋮⋮</span>
                                    <span class="font-mono text-sm text-amber-100">{{ $award->timeLabel() }}</span>
                                    <span class="font-semibold text-amber-50">{{ $award->title }}</span>
                                    @if($item['out_of_order'])<span class="no-print rounded bg-rose-900/60 px-2 py-0.5 text-[11px] text-rose-100">время раньше предыдущего блока</span>@endif
                                    <details class="no-print ml-auto text-xs text-slate-300">
                                        <summary class="cursor-pointer text-amber-200 hover:underline">изменить</summary>
                                        <form method="POST" action="{{ route('secretary.tournament.awards.update', [$tournament, $award]) }}" class="mt-2 flex flex-wrap items-end gap-2">
                                            @csrf @method('PATCH')
                                            <input name="title" required maxlength="150" value="{{ $award->title }}" class="w-56 rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white">
                                            <input type="date" name="scheduled_on" required value="{{ $award->scheduled_on?->format('Y-m-d') }}" class="rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white">
                                            <input type="time" name="starts_at" required value="{{ substr((string) $award->starts_at, 0, 5) }}" class="rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white">
                                            <input type="time" name="ends_at" value="{{ $award->ends_at ? substr((string) $award->ends_at, 0, 5) : '' }}" class="rounded-lg border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white">
                                            <button class="rounded-lg border border-amber-600 px-3 py-1 text-amber-100">Сохранить</button>
                                        </form>
                                        <form method="POST" action="{{ route('secretary.tournament.awards.destroy', [$tournament, $award]) }}" class="mt-2" onsubmit="return confirm('Удалить награждение «{{ e($award->title) }}»?')">
                                            @csrf @method('DELETE')
                                            <button class="text-rose-300 hover:underline">удалить награждение</button>
                                        </form>
                                    </details>
                                </div>
                            @else
                                @php
                                    $category = $item['category'];
                                    $session = $item['session'];
                                    $rows = $item['performances']->groupBy('athlete_id')->map->values();
                                    $apparatusCols = $item['performances']->pluck('apparatus')->filter()->unique()->values();
                                    $streamTitle = ($category->group?->name ?? $category->name).($category->stream_no ? ' — поток '.$category->stream_no : '');
                                    $isTeam = $category->program === 'group';
                                @endphp
                                <div data-key="{{ $item['key'] }}" class="overflow-hidden rounded-xl border border-slate-800 bg-slate-900/70">
                                    <div class="flex flex-wrap items-baseline gap-3 border-b border-slate-800 px-4 py-3">
                                        @unless($item['unassigned'])<span class="drag-handle no-print cursor-grab select-none text-lg text-slate-500" title="Перетащить">⋮⋮</span>@endunless
                                        <span class="font-semibold text-white">{{ $streamTitle }}</span>
                                        <span class="font-mono text-sm text-slate-300">{{ $item['start'] }}{{ $item['end'] ? '–'.$item['end'] : '' }}</span>
                                        <span class="text-sm text-slate-400">{{ $session ? implode(' → ', $session->apparatus ?? []) : ($item['unassigned'] ? 'не назначено на день' : ($category->apparatus ?? '')) }}</span>
                                        @if($item['out_of_order'])<span class="no-print rounded bg-rose-900/60 px-2 py-0.5 text-[11px] text-rose-100">время раньше предыдущего блока</span>@endif
                                        @if($doc === 'program')<span class="ml-auto text-sm text-slate-400">{{ $rows->reject(fn ($ps) => $ps->every($isWithdrawn))->count() }} {{ $isTeam ? 'команд' : 'участниц' }}</span>@endif
                                    </div>
                                    @if($doc !== 'program')
                                        <table class="w-full text-sm">
                                            <thead class="text-left text-[11px] uppercase tracking-wider text-slate-500">
                                                <tr>
                                                    <th class="w-10 px-4 py-2">№</th><th class="w-14">Ст.№</th>
                                                    @if($timesMode === 'each' && $apparatusCols->count() > 1)
                                                        @foreach($apparatusCols as $a)<th class="w-20">{{ $a }}</th>@endforeach
                                                    @else
                                                        <th class="w-20">Время</th>
                                                    @endif
                                                    <th>{{ $isTeam ? 'Команда' : 'Фамилия, имя' }}</th>
                                                    @unless($isTeam)<th class="w-16">Год</th>@endunless
                                                    <th>Клуб</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse($rows->values() as $i => $ps)
                                                    @php $p = $ps->first(); $athlete = $p->athlete; $withdrawn = $ps->every($isWithdrawn); @endphp
                                                    <tr class="border-t border-slate-800/80 align-top {{ $withdrawn ? 'text-slate-500' : '' }}">
                                                        <td class="px-4 py-2 font-mono text-slate-400">{{ $i + 1 }}</td>
                                                        <td class="py-2 font-mono">{{ $p->start_number }}</td>
                                                        @if($withdrawn)
                                                            <td class="py-2 text-xs" colspan="{{ $timesMode === 'each' && $apparatusCols->count() > 1 ? $apparatusCols->count() : 1 }}">снята</td>
                                                        @elseif($timesMode === 'each' && $apparatusCols->count() > 1)
                                                            @foreach($apparatusCols as $a)<td class="py-2 font-mono text-slate-300">{{ $ps->firstWhere('apparatus', $a)?->scheduled_at_label ?? '—' }}</td>@endforeach
                                                        @else
                                                            <td class="py-2 font-mono text-slate-300">{{ $ps->pluck('scheduled_at_label')->filter()->first() ?? '—' }}</td>
                                                        @endif
                                                        <td class="py-2 {{ $withdrawn ? 'line-through' : 'text-slate-100' }}">
                                                            {{ trim(($athlete?->last_name ?? '').' '.($athlete?->is_team ? '' : ($athlete?->first_name ?? ''))) }}
                                                            @if($athlete?->is_team && $showMembers && $athlete->members->isNotEmpty())
                                                                <div class="text-[11px] text-slate-400">{{ $athlete->members->map(fn ($m) => trim($m->last_name.' '.$m->first_name))->implode(', ') }}</div>
                                                            @endif
                                                        </td>
                                                        @unless($isTeam)<td class="py-2 text-slate-400">{{ $athlete?->birthdate?->year }}</td>@endunless
                                                        <td class="py-2 text-slate-400">{{ $athlete?->club }}</td>
                                                    </tr>
                                                @empty
                                                    <tr><td colspan="6" class="px-4 py-3 text-sm italic text-slate-500">Нет назначенных участниц.</td></tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>

                    @if($doc === 'protocol')
                        <div class="grid grid-cols-2 gap-8 pt-6 text-sm text-slate-400"><div>Главный судья ____________________</div><div>Главный секретарь ____________________</div></div>
                    @endif
                </section>
            @empty
                <div class="rounded-xl border border-slate-800 bg-slate-900/70 p-10 text-center text-slate-400">Потоки ещё не сформированы. Создайте их на странице <a class="text-emerald-300 underline" href="{{ route('secretary.tournament.board', $tournament) }}">«Потоки»</a>.</div>
            @endforelse
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <script>
    (() => {
        if (typeof Sortable === 'undefined') return;
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const url = @json(route('secretary.tournament.start-list.order', $tournament));
        const status = document.getElementById('order-status');
        const show = (ok, text) => {
            status.textContent = text;
            status.className = 'no-print rounded-lg border px-3 py-2 text-sm ' + (ok ? 'border-emerald-700 bg-emerald-950/50 text-emerald-100' : 'border-rose-700 bg-rose-950/50 text-rose-100');
        };
        document.querySelectorAll('.day-list').forEach(list => new Sortable(list, {
            handle: '.drag-handle', animation: 150, forceFallback: true, fallbackTolerance: 4, ghostClass: 'opacity-30', draggable: '[data-key]',
            onEnd: async () => {
                const keys = [...list.querySelectorAll(':scope > [data-key]')].map(el => el.dataset.key);
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
