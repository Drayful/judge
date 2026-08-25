<x-app-layout>
    <x-slot name="header">
        <div class="flex w-full flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-300">Независимый просмотр</div>
                <div class="text-lg font-semibold text-white">{{ $category->name }}</div>
            </div>
            <a href="{{ route('secretary.tournament.live', ['tournament' => $category->tournament_id, 'category' => $category->id, 'session' => $streamSession?->id]) }}"
               class="rounded-lg border border-emerald-700/70 bg-emerald-950/40 px-3 py-2 text-sm text-emerald-100 hover:bg-emerald-900/50">
                Открыть как активный Live
            </a>
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
            @forelse($orderedPerformances as $performance)
                @php
                    $history = $scoreHistoryByPerformance[$performance->id] ?? ['slots' => [], 'spread' => []];
                    $violating = $history['spread']['violating_slots'] ?? [];
                    $reviewRows = \App\Support\SecretaryLiveUi::scoreRowsBySlot($performance, $category, true);
                    $reviewDb1 = $reviewRows['DB1'] ?? null;
                    $reviewDa1 = $reviewRows['DA1'] ?? null;
                    $reviewDb = $reviewDb1?->average_submitted_at !== null ? $reviewDb1?->average_score : $performance->db_average;
                    $reviewDa = $reviewDa1?->average_submitted_at !== null ? $reviewDa1?->average_score : $performance->da_average;
                @endphp
                <article class="rounded-xl border {{ $performance->status === 'performing' ? 'border-orange-500/80 bg-orange-950/30' : 'border-slate-800 bg-slate-950/50' }} p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-base font-semibold text-white">№ {{ $performance->start_number ?? '—' }} · {{ $performance->athlete->last_name }} {{ $performance->athlete->first_name }}</div>
                            <div class="mt-1 text-xs text-slate-400">{{ $performance->apparatus ?? '—' }} · {{ $performance->status }} · {{ $performance->athlete->club ?? '—' }}</div>
                        </div>
                        <div class="flex flex-wrap gap-2 font-mono text-xs">
                            @foreach([['DB', $reviewDb], ['DA', $reviewDa], ['A', $performance->a_score], ['E', $performance->e_score], ['Сбавка', $performance->penalty], ['Итого', $performance->total]] as [$label, $value])
                                <span class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1 text-slate-200">{{ $label }} {{ \App\Support\SecretaryLiveUi::formatScore($value !== null ? (float) $value : null) }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div class="mt-3 grid grid-cols-4 gap-1.5 sm:grid-cols-8 2xl:grid-cols-[repeat(16,minmax(0,1fr))]">
                        @foreach($historyJudgeColumns as $slot)
                            @php($score = $history['slots'][$slot] ?? null)
                            @php($isSpread = in_array($slot, $violating, true))
                            <div class="rounded-lg border px-1.5 py-2 text-center {{ $isSpread ? 'border-rose-500 bg-rose-900/75 text-white ring-1 ring-rose-400' : ($score ? 'border-emerald-900/70 bg-emerald-950/25 text-emerald-100' : 'border-slate-800 bg-slate-900/45 text-slate-600') }}">
                                <div class="font-mono text-[10px] font-bold">{{ $slot }}</div>
                                <div class="mt-0.5 font-mono text-sm font-semibold">{{ $score['display_score'] ?? '—' }}</div>
                                @if($score && in_array($slot, ['A1','A2','A3','A4','E1','E2','E3','E4'], true))
                                    <div class="text-[9px] opacity-75">сбавка</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-slate-800 p-8 text-center text-slate-500">В выбранной сессии нет выступлений.</div>
            @endforelse
        </div>
    </div>

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
