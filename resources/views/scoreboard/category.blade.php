<x-scoreboard-layout>
    @php
        $tournament = $category->tournament;
        $apparatusLabels = [
            'hoop'   => 'Обруч',
            'ball'   => 'Мяч',
            'clubs'  => 'Булавы',
            'ribbon' => 'Лента',
            'rope'   => 'Скакалка',
            'free'   => 'Б/П',
        ];
        $categoryApparatus = $category->apparatus
            ? ($apparatusLabels[strtolower($category->apparatus)] ?? $category->apparatus)
            : null;
    @endphp

    <div class="min-h-screen flex flex-col">
        <header class="border-b border-slate-800/80 bg-gradient-to-b from-slate-900 to-slate-950">
            <div class="max-w-[1600px] mx-auto w-full px-4 sm:px-6 lg:px-10 py-5 lg:py-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 text-xs uppercase tracking-[0.2em] text-emerald-400/80">
                            <span class="inline-flex h-2 w-2 rounded-full bg-emerald-400 live-pulse" id="livePulse"></span>
                            <span>Live · Табло</span>
                        </div>
                        @if($tournament)
                            <div class="mt-1 text-sm sm:text-base text-slate-400 truncate">{{ $tournament->name }}</div>
                        @endif
                        <h1 class="mt-1 text-2xl sm:text-3xl lg:text-4xl font-semibold text-white tracking-tight truncate">
                            {{ $category->name }}
                        </h1>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs sm:text-sm text-slate-400">
                            @if($categoryApparatus)
                                <span class="inline-flex items-center rounded-md border border-slate-700/70 bg-slate-800/70 px-2.5 py-1 font-medium text-slate-200">
                                    {{ $categoryApparatus }}
                                </span>
                            @endif
                            <span id="liveStatus" class="text-slate-500">Обновление…</span>
                            <span id="liveCount" class="text-slate-500"></span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <button type="button" id="fullscreenBtn"
                            class="hidden sm:inline-flex items-center gap-1.5 rounded-lg border border-slate-700 bg-slate-900/70 px-3 py-2 text-xs font-medium text-slate-200 hover:bg-slate-800 transition">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M4 4h4a1 1 0 010 2H6v2a1 1 0 11-2 0V4zm12 0v4a1 1 0 11-2 0V6h-2a1 1 0 110-2h4zM4 16v-4a1 1 0 112 0v2h2a1 1 0 110 2H4zm12 0h-4a1 1 0 110-2h2v-2a1 1 0 112 0v4z"/>
                            </svg>
                            <span id="fullscreenLabel">Во весь экран</span>
                        </button>
                        <a class="text-xs sm:text-sm text-emerald-400 hover:text-emerald-300" href="{{ url('/') }}">
                            На главную
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 w-full">
            <div class="max-w-[1600px] mx-auto w-full px-4 sm:px-6 lg:px-10 py-6 lg:py-8 space-y-6">

                <div id="emptyState" class="{{ $rows->isEmpty() ? '' : 'hidden' }}">
                    <div class="live-panel p-10 text-center">
                        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 text-2xl">
                            ★
                        </div>
                        <h2 class="mt-4 text-xl font-semibold text-white">Пока без результатов</h2>
                        <p class="mt-2 text-sm text-slate-400">
                            Как только секретарь опубликует первые оценки — они появятся здесь автоматически.
                        </p>
                    </div>
                </div>

                <div id="podium" class="{{ $rows->isEmpty() ? 'hidden' : '' }} hidden lg:grid grid-cols-3 gap-4">
                    @foreach($rows->take(3) as $idx => $p)
                        @php
                            $tone = [
                                0 => ['ring' => 'ring-2 ring-amber-400/70',  'badge' => 'bg-amber-400 text-amber-950',  'glow' => 'shadow-amber-500/20',  'label' => '1'],
                                1 => ['ring' => 'ring-2 ring-slate-300/60', 'badge' => 'bg-slate-300 text-slate-900', 'glow' => 'shadow-slate-400/15', 'label' => '2'],
                                2 => ['ring' => 'ring-2 ring-orange-500/60','badge' => 'bg-orange-500 text-orange-950','glow' => 'shadow-orange-500/15','label' => '3'],
                            ][$idx];
                        @endphp
                        <div data-place="{{ $idx + 1 }}"
                            class="live-panel {{ $tone['ring'] }} shadow-xl {{ $tone['glow'] }} p-5 flex flex-col gap-3">
                            <div class="flex items-center justify-between">
                                <span class="inline-flex items-center justify-center w-10 h-10 rounded-full text-lg font-bold {{ $tone['badge'] }}">
                                    {{ $tone['label'] }}
                                </span>
                                @if($p->start_number)
                                    <span class="text-xs text-slate-400">№ {{ $p->start_number }}</span>
                                @endif
                            </div>
                            <div class="min-w-0">
                                <div class="text-lg font-semibold text-white truncate">
                                    {{ $p->athlete->last_name }} {{ $p->athlete->first_name }}
                                </div>
                                <div class="text-sm text-slate-400 truncate">{{ $p->athlete->club ?? '—' }}</div>
                            </div>
                            <div class="mt-auto pt-2 border-t border-slate-800/80 flex items-end justify-between gap-3">
                                <div class="text-[10px] uppercase tracking-widest text-slate-500">Итог</div>
                                <div class="scoreboard-podium-total font-bold tabular-nums text-teal-200 text-right">
                                    {{ number_format($p->total, 3) }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div id="boardWrap" class="{{ $rows->isEmpty() ? 'hidden' : '' }} live-panel overflow-hidden">
                    <div class="hidden sm:block overflow-x-auto">
                        <table class="w-full text-sm lg:text-base table-fixed min-w-[960px]">
                            <thead class="text-left text-slate-400 uppercase tracking-wider text-xs">
                                <tr class="border-b border-slate-800">
                                    <th class="py-3 pl-5 pr-3 font-medium w-20">Место</th>
                                    <th class="py-3 pr-3 font-medium w-16">№</th>
                                    <th class="py-3 pr-3 font-medium">Спортсменка</th>
                                    <th class="py-3 pr-3 font-medium w-56">Клуб</th>
                                    <th class="py-3 pr-3 font-medium w-24">Снаряд</th>
                                    <th class="py-3 pr-3 font-medium w-20">Статус</th>
                                    <th class="py-3 pr-3 font-medium w-20 text-right">D</th>
                                    <th class="py-3 pr-3 font-medium w-20 text-right">A</th>
                                    <th class="py-3 pr-3 font-medium w-20 text-right">E</th>
                                    <th class="py-3 pr-3 font-medium w-20 text-right">Штраф</th>
                                    <th class="py-3 pr-5 font-medium w-32 text-right">Итог</th>
                                </tr>
                            </thead>
                            <tbody class="text-slate-100 divide-y divide-slate-800" id="scoreboardBody">
                                @foreach($rows as $idx => $p)
                                    @php
                                        $inq = $p->inquiries->first();
                                        $place = $idx + 1;
                                        $placeBadge = match ($place) {
                                            1 => 'bg-amber-400 text-amber-950',
                                            2 => 'bg-slate-300 text-slate-900',
                                            3 => 'bg-orange-500 text-orange-950',
                                            default => 'bg-slate-800 text-slate-200',
                                        };
                                    @endphp
                                    <tr class="hover:bg-slate-800/40 transition-colors"
                                        data-row-key="{{ $p->id }}"
                                        data-row-total="{{ $p->total }}">
                                        <td class="py-3 pl-5 pr-3">
                                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full text-sm font-bold {{ $placeBadge }}">
                                                {{ $place }}
                                            </span>
                                        </td>
                                        <td class="py-3 pr-3 font-medium tabular-nums text-slate-300">{{ $p->start_number ?? '—' }}</td>
                                        <td class="py-3 pr-3 truncate font-medium">
                                            {{ $p->athlete->last_name }} {{ $p->athlete->first_name }}
                                        </td>
                                        <td class="py-3 pr-3 text-slate-400 truncate">{{ $p->athlete->club ?? '—' }}</td>
                                        <td class="py-3 pr-3">
                                            <x-badge tone="gray">{{ $apparatusLabels[strtolower($p->apparatus ?? '')] ?? ($p->apparatus ?? '—') }}</x-badge>
                                        </td>
                                        <td class="py-3 pr-3">
                                            @if($inq && $inq->status !== 'decided')
                                                <x-badge tone="amber">inquiry</x-badge>
                                            @else
                                                <span class="text-slate-600">—</span>
                                            @endif
                                        </td>
                                        <td class="py-3 pr-3 tabular-nums text-right text-slate-300">{{ $p->d_score !== null ? number_format($p->d_score, 3) : '—' }}</td>
                                        <td class="py-3 pr-3 tabular-nums text-right text-slate-300">{{ $p->a_score !== null ? number_format($p->a_score, 3) : '—' }}</td>
                                        <td class="py-3 pr-3 tabular-nums text-right text-slate-300">{{ $p->e_score !== null ? number_format($p->e_score, 3) : '—' }}</td>
                                        <td class="py-3 pr-3 tabular-nums text-right {{ $p->penalty ? 'text-rose-300' : 'text-slate-600' }}">{{ $p->penalty !== null ? number_format($p->penalty, 3) : '—' }}</td>
                                        <td class="py-3 pr-5 scoreboard-total font-bold tabular-nums text-teal-200 text-right">
                                            {{ number_format($p->total, 3) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="sm:hidden divide-y divide-slate-800" id="scoreboardCards">
                        @foreach($rows as $idx => $p)
                            @php
                                $inq = $p->inquiries->first();
                                $place = $idx + 1;
                                $placeBadge = match ($place) {
                                    1 => 'bg-amber-400 text-amber-950',
                                    2 => 'bg-slate-300 text-slate-900',
                                    3 => 'bg-orange-500 text-orange-950',
                                    default => 'bg-slate-800 text-slate-200',
                                };
                            @endphp
                            <div class="p-4" data-row-key="{{ $p->id }}" data-row-total="{{ $p->total }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex items-start gap-3">
                                        <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-full text-sm font-bold {{ $placeBadge }}">
                                            {{ $place }}
                                        </span>
                                        <div class="min-w-0">
                                            <div class="text-xs text-slate-500">№ {{ $p->start_number ?? '—' }}</div>
                                            <div class="font-semibold text-slate-100 truncate">
                                                {{ $p->athlete->last_name }} {{ $p->athlete->first_name }}
                                            </div>
                                            <div class="text-sm text-slate-400 mt-0.5 truncate">{{ $p->athlete->club ?? '—' }}</div>
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                <x-badge tone="gray">{{ $apparatusLabels[strtolower($p->apparatus ?? '')] ?? ($p->apparatus ?? '—') }}</x-badge>
                                                @if($inq && $inq->status !== 'decided')
                                                    <x-badge tone="amber">inquiry</x-badge>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-2xl font-bold tabular-nums text-teal-200 text-right">
                                        {{ number_format($p->total, 3) }}
                                    </div>
                                </div>
                                <div class="mt-3 grid grid-cols-4 gap-2 text-xs text-slate-400 text-center">
                                    <div>
                                        <div class="uppercase tracking-wider text-[10px]">D</div>
                                        <div class="tabular-nums text-slate-200">{{ $p->d_score !== null ? number_format($p->d_score, 3) : '—' }}</div>
                                    </div>
                                    <div>
                                        <div class="uppercase tracking-wider text-[10px]">A</div>
                                        <div class="tabular-nums text-slate-200">{{ $p->a_score !== null ? number_format($p->a_score, 3) : '—' }}</div>
                                    </div>
                                    <div>
                                        <div class="uppercase tracking-wider text-[10px]">E</div>
                                        <div class="tabular-nums text-slate-200">{{ $p->e_score !== null ? number_format($p->e_score, 3) : '—' }}</div>
                                    </div>
                                    <div>
                                        <div class="uppercase tracking-wider text-[10px]">Штраф</div>
                                        <div class="tabular-nums {{ $p->penalty ? 'text-rose-300' : 'text-slate-400' }}">{{ $p->penalty !== null ? number_format($p->penalty, 3) : '—' }}</div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </main>

        <footer class="border-t border-slate-800/80 bg-slate-950/80">
            <div class="max-w-[1600px] mx-auto w-full px-4 sm:px-6 lg:px-10 py-3 text-xs text-slate-500 flex flex-wrap items-center justify-between gap-2">
                <div>Автообновление каждые 2 секунды</div>
                <div id="liveUpdatedAt"></div>
            </div>
        </footer>
    </div>

    <script>
        (function () {
            const url = @json(route('scoreboard.category.live', $category));
            const apparatusLabels = @json($apparatusLabels);

            const body = document.getElementById('scoreboardBody');
            const cards = document.getElementById('scoreboardCards');
            const podium = document.getElementById('podium');
            const empty = document.getElementById('emptyState');
            const boardWrap = document.getElementById('boardWrap');
            const status = document.getElementById('liveStatus');
            const liveCount = document.getElementById('liveCount');
            const liveUpdatedAt = document.getElementById('liveUpdatedAt');
            const livePulse = document.getElementById('livePulse');

            const fullscreenBtn = document.getElementById('fullscreenBtn');
            const fullscreenLabel = document.getElementById('fullscreenLabel');

            const prevTotals = new Map();
            // initial seed from rendered rows
            document.querySelectorAll('[data-row-key]').forEach(el => {
                prevTotals.set(el.dataset.rowKey, el.dataset.rowTotal);
            });

            let lastUpdated = null;

            function fmt3(v) {
                if (v === null || v === undefined) return '—';
                const n = Number(v);
                if (Number.isNaN(n)) return '—';
                return n.toFixed(3);
            }

            function esc(s) {
                return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            }

            function placeBadgeClass(place) {
                switch (Number(place)) {
                    case 1: return 'bg-amber-400 text-amber-950';
                    case 2: return 'bg-slate-300 text-slate-900';
                    case 3: return 'bg-orange-500 text-orange-950';
                    default: return 'bg-slate-800 text-slate-200';
                }
            }

            function podiumTone(idx) {
                return [
                    { ring: 'ring-2 ring-amber-400/70',  badge: 'bg-amber-400 text-amber-950',  glow: 'shadow-amber-500/20',  label: '1' },
                    { ring: 'ring-2 ring-slate-300/60', badge: 'bg-slate-300 text-slate-900', glow: 'shadow-slate-400/15', label: '2' },
                    { ring: 'ring-2 ring-orange-500/60', badge: 'bg-orange-500 text-orange-950', glow: 'shadow-orange-500/15', label: '3' },
                ][idx];
            }

            function apparatusLabel(code) {
                if (!code) return '—';
                return apparatusLabels[String(code).toLowerCase()] ?? code;
            }

            function renderPodium(rows) {
                if (!podium) return;
                const top3 = rows.slice(0, 3);
                if (top3.length === 0) {
                    podium.classList.add('hidden');
                    return;
                }
                podium.classList.remove('hidden');
                podium.innerHTML = top3.map((r, idx) => {
                    const t = podiumTone(idx);
                    return `
                        <div data-place="${idx + 1}" class="live-panel ${t.ring} shadow-xl ${t.glow} p-5 flex flex-col gap-3">
                            <div class="flex items-center justify-between">
                                <span class="inline-flex items-center justify-center w-10 h-10 rounded-full text-lg font-bold ${t.badge}">${t.label}</span>
                                ${r.start_number ? `<span class="text-xs text-slate-400">№ ${esc(r.start_number)}</span>` : ''}
                            </div>
                            <div class="min-w-0">
                                <div class="text-lg font-semibold text-white truncate">${esc(r.athlete)}</div>
                                <div class="text-sm text-slate-400 truncate">${esc(r.club ?? '—')}</div>
                            </div>
                            <div class="mt-auto pt-2 border-t border-slate-800/80 flex items-end justify-between gap-3">
                                <div class="text-[10px] uppercase tracking-widest text-slate-500">Итог</div>
                                <div class="scoreboard-podium-total font-bold tabular-nums text-teal-200 text-right">${fmt3(r.total)}</div>
                            </div>
                        </div>
                    `;
                }).join('');
            }

            function renderRows(rows) {
                if (rows.length === 0) {
                    empty.classList.remove('hidden');
                    boardWrap.classList.add('hidden');
                    podium?.classList.add('hidden');
                    return;
                }
                empty.classList.add('hidden');
                boardWrap.classList.remove('hidden');

                const nextTotals = new Map();
                const changed = new Set();

                body.innerHTML = rows.map((r, idx) => {
                    const key = String(r.id ?? `${r.athlete}|${r.start_number ?? ''}`);
                    const totalStr = fmt3(r.total);
                    nextTotals.set(key, totalStr);
                    const wasTotal = prevTotals.get(key);
                    if (wasTotal !== undefined && wasTotal !== totalStr) {
                        changed.add(key);
                    }

                    const placeCls = placeBadgeClass(r.place);
                    return `
                        <tr class="hover:bg-slate-800/40 transition-colors" data-row-key="${esc(key)}" data-row-total="${esc(totalStr)}">
                            <td class="py-3 pl-5 pr-3">
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full text-sm font-bold ${placeCls}">${esc(r.place)}</span>
                            </td>
                            <td class="py-3 pr-3 font-medium tabular-nums text-slate-300">${r.start_number ?? '—'}</td>
                            <td class="py-3 pr-3 truncate font-medium">${esc(r.athlete)}</td>
                            <td class="py-3 pr-3 text-slate-400 truncate">${esc(r.club ?? '—')}</td>
                            <td class="py-3 pr-3"><span class="inline-flex items-center rounded-md border border-slate-600 bg-slate-800/80 px-2 py-1 text-xs font-medium text-slate-200">${esc(apparatusLabel(r.apparatus))}</span></td>
                            <td class="py-3 pr-3">${r.inquiry_status && r.inquiry_status !== 'decided'
                                ? '<span class="inline-flex items-center rounded-md border border-amber-600/50 bg-amber-950/50 px-2 py-1 text-xs font-medium text-amber-200">inquiry</span>'
                                : '<span class="text-slate-600">—</span>'}</td>
                            <td class="py-3 pr-3 tabular-nums text-right text-slate-300">${fmt3(r.d)}</td>
                            <td class="py-3 pr-3 tabular-nums text-right text-slate-300">${fmt3(r.a)}</td>
                            <td class="py-3 pr-3 tabular-nums text-right text-slate-300">${fmt3(r.e)}</td>
                            <td class="py-3 pr-3 tabular-nums text-right ${r.penalty ? 'text-rose-300' : 'text-slate-600'}">${fmt3(r.penalty)}</td>
                            <td class="py-3 pr-5 scoreboard-total font-bold tabular-nums text-teal-200 text-right">${totalStr}</td>
                        </tr>
                    `;
                }).join('');

                if (cards) {
                    cards.innerHTML = rows.map(r => {
                        const key = String(r.id ?? `${r.athlete}|${r.start_number ?? ''}`);
                        const totalStr = fmt3(r.total);
                        const placeCls = placeBadgeClass(r.place);
                        return `
                            <div class="p-4" data-row-key="${esc(key)}" data-row-total="${esc(totalStr)}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex items-start gap-3">
                                        <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-full text-sm font-bold ${placeCls}">${esc(r.place)}</span>
                                        <div class="min-w-0">
                                            <div class="text-xs text-slate-500">№ ${r.start_number ?? '—'}</div>
                                            <div class="font-semibold text-slate-100 truncate">${esc(r.athlete)}</div>
                                            <div class="text-sm text-slate-400 mt-0.5 truncate">${esc(r.club ?? '—')}</div>
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                <span class="inline-flex items-center rounded-md border border-slate-600 bg-slate-800/80 px-2 py-1 text-xs font-medium text-slate-200">${esc(apparatusLabel(r.apparatus))}</span>
                                                ${r.inquiry_status && r.inquiry_status !== 'decided' ? '<span class="inline-flex items-center rounded-md border border-amber-600/50 bg-amber-950/50 px-2 py-1 text-xs font-medium text-amber-200">inquiry</span>' : ''}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-2xl font-bold tabular-nums text-teal-200 text-right">${totalStr}</div>
                                </div>
                                <div class="mt-3 grid grid-cols-4 gap-2 text-xs text-slate-400 text-center">
                                    <div><div class="uppercase tracking-wider text-[10px]">D</div><div class="tabular-nums text-slate-200">${fmt3(r.d)}</div></div>
                                    <div><div class="uppercase tracking-wider text-[10px]">A</div><div class="tabular-nums text-slate-200">${fmt3(r.a)}</div></div>
                                    <div><div class="uppercase tracking-wider text-[10px]">E</div><div class="tabular-nums text-slate-200">${fmt3(r.e)}</div></div>
                                    <div><div class="uppercase tracking-wider text-[10px]">Штраф</div><div class="tabular-nums ${r.penalty ? 'text-rose-300' : 'text-slate-400'}">${fmt3(r.penalty)}</div></div>
                                </div>
                            </div>
                        `;
                    }).join('');
                }

                if (changed.size > 0) {
                    requestAnimationFrame(() => {
                        document.querySelectorAll('[data-row-key]').forEach(el => {
                            if (changed.has(el.dataset.rowKey)) {
                                el.classList.add('flash-update');
                                setTimeout(() => el.classList.remove('flash-update'), 1700);
                            }
                        });
                    });
                }

                renderPodium(rows);

                prevTotals.clear();
                nextTotals.forEach((v, k) => prevTotals.set(k, v));
            }

            function renderStatus(rowsCount, errored = false, errorMsg = '') {
                if (errored) {
                    status.textContent = 'Ошибка обновления: ' + errorMsg;
                    livePulse?.classList.remove('live-pulse');
                    livePulse?.classList.add('bg-rose-500');
                    livePulse?.classList.remove('bg-emerald-400');
                    return;
                }
                status.textContent = 'Обновлено только что';
                livePulse?.classList.add('live-pulse');
                livePulse?.classList.add('bg-emerald-400');
                livePulse?.classList.remove('bg-rose-500');
                liveCount.textContent = '· ' + rowsCount + ' ' + pluralRu(rowsCount, ['участница', 'участницы', 'участниц']);
            }

            function pluralRu(n, forms) {
                const a = Math.abs(n) % 100;
                const b = a % 10;
                if (a > 10 && a < 20) return forms[2];
                if (b > 1 && b < 5) return forms[1];
                if (b === 1) return forms[0];
                return forms[2];
            }

            async function tick() {
                try {
                    const res = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();
                    lastUpdated = new Date(data.updated_at);
                    renderRows(data.rows);
                    renderStatus(data.rows.length);
                    updateTimer();
                } catch (e) {
                    renderStatus(0, true, e?.message ?? String(e));
                }
            }

            function updateTimer() {
                if (!lastUpdated) {
                    liveUpdatedAt.textContent = '';
                    return;
                }
                const sec = Math.max(0, Math.floor((Date.now() - lastUpdated.getTime()) / 1000));
                const hh = lastUpdated.toLocaleTimeString();
                if (sec < 5) {
                    status.textContent = 'Обновлено только что';
                } else {
                    status.textContent = 'Обновлено ' + sec + ' с назад';
                }
                liveUpdatedAt.textContent = 'Последнее обновление: ' + hh;
            }

            // Polling, slows down when tab is hidden.
            let pollHandle = null;
            function startPolling() {
                stopPolling();
                const interval = document.hidden ? 10000 : 2000;
                pollHandle = setInterval(tick, interval);
            }
            function stopPolling() {
                if (pollHandle) clearInterval(pollHandle);
                pollHandle = null;
            }
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) tick();
                startPolling();
            });

            setInterval(updateTimer, 1000);

            // Fullscreen toggle.
            if (fullscreenBtn) {
                fullscreenBtn.addEventListener('click', async () => {
                    try {
                        if (!document.fullscreenElement) {
                            await document.documentElement.requestFullscreen();
                        } else {
                            await document.exitFullscreen();
                        }
                    } catch (e) { /* ignore */ }
                });
                document.addEventListener('fullscreenchange', () => {
                    fullscreenLabel.textContent = document.fullscreenElement ? 'Свернуть' : 'Во весь экран';
                });
            }

            tick();
            startPolling();
        })();
    </script>
</x-scoreboard-layout>
