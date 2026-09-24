<x-app-layout>
    <x-slot name="header">
        <div class="flex w-full flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-300">Независимый просмотр</div>
                <div class="text-lg font-semibold text-white">{{ $category->name }}</div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('secretary.queue.review.excel', ['category' => $category->id, 'session' => $streamSession?->id]) }}"
                   class="rounded-lg border border-sky-600/70 bg-sky-950/45 px-3 py-2 text-sm font-semibold text-sky-100 hover:bg-sky-900/60">
                    Скачать Excel
                </a>
                @unless(auth()->user()->isChiefJudge())
                <a href="{{ route('secretary.tournament.live', ['tournament' => $category->tournament_id, 'category' => $category->id, 'session' => $streamSession?->id]) }}"
                   class="rounded-lg border border-emerald-700/70 bg-emerald-950/40 px-3 py-2 text-sm text-emerald-100 hover:bg-emerald-900/50">
                    Открыть как активный Live
                </a>
                @endunless
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-[1600px] space-y-5 py-6">
        <div class="rounded-xl border border-sky-800/60 bg-sky-950/25 px-4 py-3 text-sm text-sky-100">
            Эта страница не меняет активный поток судейских планшетов. Здесь можно безопасно смотреть будущие и прошлые оценки.
        </div>

        <div class="grid gap-3 rounded-xl border border-slate-800 bg-slate-950/55 p-4 md:grid-cols-2">
            <div>
                <label for="review-stream-search" class="text-xs font-medium text-slate-400">Поиск потока</label>
                <input id="review-stream-search" type="search" placeholder="Название, год, категория или номер…"
                       class="mt-1 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-sky-500 focus:ring-sky-500">
            </div>
            <div>
                <label for="review-stream-select" class="text-xs font-medium text-slate-400">Поток</label>
                <select id="review-stream-select" class="mt-1 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-sky-500 focus:ring-sky-500">
                    <option value="" disabled hidden data-stream-placeholder>Выберите найденный поток…</option>
                    @foreach($tournamentCategories as $stream)
                        <option data-stream-option data-search="{{ Str::lower($stream->name.' '.$stream->id.' '.($stream->stream_no ?? '')) }}"
                                value="{{ route('secretary.queue.review', ['category' => $stream->id]) }}"
                                @selected($stream->id === $category->id)>
                            Поток {{ $stream->stream_no ?? '#'.$stream->id }} · {{ $stream->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            @if($categorySessions->isNotEmpty())
                <div class="md:col-span-2">
                    <label for="review-session-select" class="text-xs font-medium text-slate-400">День / сессия</label>
                    <select id="review-session-select" class="mt-1 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-sky-500 focus:ring-sky-500">
                        @foreach($categorySessions as $session)
                            <option value="{{ route('secretary.queue.review', ['category' => $category->id, 'session' => $session->id]) }}" @selected($streamSession?->id === $session->id)>
                                {{ $session->scheduled_on?->format('d.m.Y') }}@if($session->starts_at) · {{ substr($session->starts_at, 0, 5) }}@endif · {{ implode(', ', $session->apparatus ?? []) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        <div class="space-y-3">
            <div class="flex flex-wrap gap-3">
                @foreach($orderedPerformances->map(fn ($p) => $p->athlete?->birthdate?->year)->filter()->unique()->sort() as $year)
                    <a class="rounded border border-sky-700 px-3 py-2 text-sky-200" href="{{ route('secretary.queue.review.excel', ['category' => $category, 'session' => $streamSession?->id, 'birth_year' => $year]) }}">Протокол {{ $year }} г.р.</a>
                @endforeach
            </div>
            @forelse($orderedPerformances as $performance)
                @php
                    $history = $scoreHistoryByPerformance[$performance->id] ?? ['slots' => [], 'spread' => []];
                    $violating = $history['spread']['violating_slots'] ?? [];
                    $reviewDb = $performance->db_average;
                    $reviewDa = $performance->da_average;
                    $reviewPlace = $poolResultsByAthlete[(int) $performance->athlete_id]['place'] ?? null;
                    $reviewPlaceOf = $poolResultsByAthlete[(int) $performance->athlete_id]['place_of'] ?? null;
                @endphp
                <article class="rounded-xl border {{ $performance->status === 'performing' ? 'border-orange-500/80 bg-orange-950/30' : 'border-slate-800 bg-slate-950/50' }} p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-base font-semibold text-white">№ {{ $performance->start_number ?? '—' }} · {{ $performance->athlete->last_name }} {{ $performance->athlete->first_name }}</div>
                            <div class="mt-1 text-xs text-slate-400">{{ $performance->apparatus ?? '—' }} · {{ $performance->status }} · {{ $performance->athlete->club ?? '—' }}</div>
                        </div>
                        <div class="flex flex-wrap gap-2 font-mono text-xs">
                            @foreach([['DB', $reviewDb], ['DA', $reviewDa], ['A', $performance->a_score], ['E', $performance->e_score], ['Сбавка', $performance->penalty], ['Итого', $performance->total]] as [$label, $value])
                                <span class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1 text-slate-200">{{ $label }} {{ \App\Support\SecretaryLiveUi::formatScore($value !== null ? (float) $value : null, $label === 'E' ? 2 : 3) }}</span>
                            @endforeach
                            <span class="rounded-md border border-violet-700/70 bg-violet-950/40 px-2 py-1 text-violet-100">Место {{ $reviewPlace !== null && $reviewPlaceOf !== null ? $reviewPlace.'/'.$reviewPlaceOf : '—' }}</span>
                        </div>
                    </div>
                    <div class="mt-3 grid grid-cols-4 gap-1.5 sm:grid-cols-8 2xl:grid-cols-[repeat(16,minmax(0,1fr))]">
                        @foreach($historyJudgeColumns as $slot)
                            @php($score = $history['slots'][$slot] ?? null)
                            @php($isSpread = in_array($slot, $violating, true))
                            <button type="button" data-stream-history-score data-performance-id="{{ $performance->id }}" data-slot="{{ $slot }}" class="rounded-lg border px-1.5 py-2 text-center {{ $isSpread || ($score && !$score['submitted_at']) ? 'border-rose-500 bg-rose-900/75 text-white ring-1 ring-rose-400' : ($score ? 'border-emerald-900/70 bg-emerald-950/25 text-emerald-100' : 'border-slate-800 bg-slate-900/45 text-slate-600') }}">
                                <div class="font-mono text-[10px] font-bold">{{ $slot }}</div>
                                <div class="mt-0.5 font-mono text-sm font-semibold">{{ $score['display_score'] ?? '—' }}</div>
                                @if($score['returned'] ?? false)<div class="text-[9px] font-bold text-rose-200">На доработке</div>@endif
                                @if($score['same_club'] ?? false)<div class="rounded bg-amber-800 text-[9px] text-amber-100">Одна школа</div>@endif
                                @if($score && in_array($slot, ['A1','A2','A3','A4','E1','E2','E3','E4'], true))
                                    <div class="text-[9px] opacity-75">сбавка</div>
                                @endif
                            </button>
                        @endforeach
                    </div>
                    <form method="POST" action="{{ route('secretary.performance.confirmScore', $performance) }}" class="mt-3">
                        @csrf
                        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-white">Подтвердить итог</button>
                    </form>
                    <details class="mt-3 rounded-lg border border-slate-700 p-3">
                        <summary class="cursor-pointer text-slate-200">Итог вручную / возврат оценок</summary>
                        <form method="POST" action="{{ route('secretary.performance.setFinalScore', $performance) }}" class="mt-3 flex flex-wrap gap-2">
                            @csrf
                            @foreach(['d_score' => 'D', 'a_score' => 'A', 'e_score' => 'E', 'penalty' => 'Штраф'] as $field => $label)
                                <label>{{ $label }}<input type="number" name="{{ $field }}" step="{{ $field === 'e_score' ? '0.01' : '0.001' }}" min="0" max="99.999" required value="{{ $performance->$field ?? ($field === 'penalty' ? 0 : '') }}" class="block w-28 rounded bg-slate-900"></label>
                            @endforeach
                            <button class="rounded bg-amber-800 px-3">Сохранить итог</button>
                        </form>
                        <form method="POST" action="{{ route('secretary.performance.clearFinalOverride', $performance) }}" class="mt-2">@csrf<button class="text-sky-300">Вернуть расчёт по судьям</button></form>
                        <form method="POST" action="{{ route('secretary.performance.returnScores', $performance) }}" class="mt-2">
                            @csrf
                            <select name="panel" class="rounded bg-slate-900"><option value="all">Все судьи</option><option value="db">DB</option><option value="da">DA</option><option value="a">A</option><option value="e">E</option><option value="penalty">Штрафы</option></select>
                            <button class="rounded bg-rose-800 px-3 py-2">На доработку</button>
                        </form>
                    </details>
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-slate-800 p-8 text-center text-slate-500">В выбранной сессии нет выступлений.</div>
            @endforelse
        </div>
    </div>

    @include('secretary.partials.score-history')
    <script>
        (() => {
            const search = document.getElementById('review-stream-search');
            const select = document.getElementById('review-stream-select');
            const session = document.getElementById('review-session-select');
            if (search && select) {
                const placeholder = select.querySelector('[data-stream-placeholder]');
                const options = Array.from(select.querySelectorAll('[data-stream-option]')).map((option) => ({
                    option,
                    text: `${option.textContent} ${option.dataset.search || ''}`.toLocaleLowerCase('ru'),
                }));
                const navigate = async () => {
                    if (! select.value) return;
                    const url = select.value;
                    const refreshed = window.JudgeAsync
                        ? await window.JudgeAsync.refresh(url, { force: true, silent: true })
                        : false;
                    if (! refreshed) window.location.assign(url);
                };
                search.addEventListener('input', () => {
                    const needle = search.value.trim().toLocaleLowerCase('ru');
                    options.forEach(({ option, text }) => { option.hidden = needle !== '' && !text.includes(needle); });
                    if (select.selectedOptions[0]?.hidden) {
                        if (placeholder) placeholder.hidden = false;
                        select.value = '';
                    }
                });
                select.addEventListener('change', navigate);
                search.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter') return;
                    event.preventDefault();
                    const visible = options.filter(({ option }) => ! option.hidden);
                    if (! select.value && visible.length === 1) select.value = visible[0].option.value;
                    navigate();
                });
            }
            session?.addEventListener('change', () => {
                if (session.value) window.JudgeAsync?.refresh(session.value, { force: true, silent: true }) || window.location.assign(session.value);
            });
        })();
    </script>
</x-app-layout>
