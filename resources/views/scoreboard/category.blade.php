<x-scoreboard-layout>
    <div class="w-full px-4 sm:px-6 lg:px-8 py-10 max-w-[1400px] mx-auto">
        <div class="flex items-end justify-between gap-4 mb-6">
            <div>
                <div class="text-sm text-slate-400">Табло</div>
                <h1 class="text-2xl font-semibold text-white">{{ $category->name }}</h1>
                <div class="text-xs text-slate-500 mt-1" id="liveStatus">
                    Live: подключение…
                </div>
            </div>
            <a class="text-sm text-emerald-400 hover:text-emerald-300" href="{{ url('/') }}">На главную</a>
        </div>

        <div class="live-panel overflow-hidden">
            <div class="p-6">
                <div class="-mx-2 px-2">
                    <div class="hidden sm:block overflow-x-auto">
                    <table class="w-full text-sm table-fixed min-w-[900px]">
                        <thead class="text-left text-slate-400">
                        <tr class="border-b border-slate-800">
                            <th class="py-3 pr-4 font-medium w-16">Место</th>
                            <th class="py-3 pr-4 font-medium w-16">№</th>
                            <th class="py-3 pr-4 font-medium">Спортсменка</th>
                            <th class="py-3 pr-4 font-medium w-48">Клуб</th>
                            <th class="py-3 pr-4 font-medium w-24">Снаряд</th>
                            <th class="py-3 pr-4 font-medium w-24">Статус</th>
                            <th class="py-3 pr-4 font-medium w-16">D</th>
                            <th class="py-3 pr-4 font-medium w-16">A</th>
                            <th class="py-3 pr-4 font-medium w-16">E</th>
                            <th class="py-3 pr-4 font-medium w-20">Штраф</th>
                            <th class="py-3 pr-4 font-medium w-20">Итог</th>
                        </tr>
                        </thead>
                        <tbody class="text-slate-100 divide-y divide-slate-800" id="scoreboardBody">
                        @foreach($rows as $idx => $p)
                            @php($inq = $p->inquiries->first())
                            <tr class="hover:bg-slate-800/40">
                                <td class="py-3 pr-4 font-medium">{{ $idx + 1 }}</td>
                                <td class="py-3 pr-4 font-medium">{{ $p->start_number ?? '—' }}</td>
                                <td class="py-3 pr-4 truncate">
                                    {{ $p->athlete->last_name }} {{ $p->athlete->first_name }}
                                </td>
                                <td class="py-3 pr-4 text-slate-400 truncate">
                                    {{ $p->athlete->club ?? '—' }}
                                </td>
                                <td class="py-3 pr-4">
                                    <x-badge tone="gray">{{ $p->apparatus ?? '—' }}</x-badge>
                                </td>
                                <td class="py-3 pr-4">
                                    @if($inq && $inq->status !== 'decided')
                                        <x-badge tone="amber">inquiry</x-badge>
                                    @else
                                        <span class="text-slate-500">—</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-4 tabular-nums">{{ $p->d_score !== null ? number_format($p->d_score, 3) : '—' }}</td>
                                <td class="py-3 pr-4 tabular-nums">{{ $p->a_score !== null ? number_format($p->a_score, 3) : '—' }}</td>
                                <td class="py-3 pr-4 tabular-nums">{{ $p->e_score !== null ? number_format($p->e_score, 3) : '—' }}</td>
                                <td class="py-3 pr-4 tabular-nums">{{ $p->penalty !== null ? number_format($p->penalty, 3) : '—' }}</td>
                                <td class="py-3 pr-4 font-semibold tabular-nums text-teal-200">{{ number_format($p->total, 3) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    </div>
                </div>

                <div class="sm:hidden space-y-3" id="scoreboardCards">
                    @foreach($rows as $idx => $p)
                        @php($inq = $p->inquiries->first())
                        <div class="border border-slate-800 rounded-xl p-4 bg-slate-950/40">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="font-medium text-slate-100 truncate">
                                        {{ $idx + 1 }} место · № {{ $p->start_number ?? '—' }}
                                    </div>
                                    <div class="mt-1 font-medium text-slate-100 truncate">
                                        {{ $p->athlete->last_name }} {{ $p->athlete->first_name }}
                                    </div>
                                    <div class="text-sm text-slate-400 mt-1 truncate">
                                        {{ $p->athlete->club ?? '—' }}
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-2 items-center">
                                        <x-badge tone="gray">{{ $p->apparatus ?? '—' }}</x-badge>
                                        @if($inq && $inq->status !== 'decided')
                                            <x-badge tone="amber">inquiry</x-badge>
                                        @endif
                                    </div>
                                </div>
                                <div class="text-sm font-semibold tabular-nums text-teal-200">
                                    {{ number_format($p->total, 3) }}
                                </div>
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-2 text-sm text-slate-300">
                                <div>D: <span class="tabular-nums">{{ $p->d_score !== null ? number_format($p->d_score, 3) : '—' }}</span></div>
                                <div>A: <span class="tabular-nums">{{ $p->a_score !== null ? number_format($p->a_score, 3) : '—' }}</span></div>
                                <div>E: <span class="tabular-nums">{{ $p->e_score !== null ? number_format($p->e_score, 3) : '—' }}</span></div>
                                <div>Штраф: <span class="tabular-nums">{{ $p->penalty !== null ? number_format($p->penalty, 3) : '—' }}</span></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const url = @json(route('scoreboard.category.live', $category));
            const body = document.getElementById('scoreboardBody');
            const status = document.getElementById('liveStatus');

            function fmt3(v) {
                if (v === null || v === undefined) return '—';
                const n = Number(v);
                if (Number.isNaN(n)) return '—';
                return n.toFixed(3);
            }

            function esc(s) {
                return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            }

            async function tick() {
                try {
                    const res = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();

                    body.innerHTML = data.rows.map(r => `
                        <tr class="hover:bg-slate-800/40">
                            <td class="py-3 pr-4 font-medium">${esc(r.place)}</td>
                            <td class="py-3 pr-4 font-medium">${r.start_number ?? '—'}</td>
                            <td class="py-3 pr-4 truncate">${esc(r.athlete)}</td>
                            <td class="py-3 pr-4 text-slate-400 truncate">${esc(r.club ?? '—')}</td>
                            <td class="py-3 pr-4"><span class="inline-flex items-center rounded-md border border-slate-600 bg-slate-800/80 px-2 py-1 text-xs font-medium text-slate-200">${esc(r.apparatus ?? '—')}</span></td>
                            <td class="py-3 pr-4">${r.inquiry_status && r.inquiry_status !== 'decided' ? '<span class="inline-flex items-center rounded-md border border-amber-600/50 bg-amber-950/50 px-2 py-1 text-xs font-medium text-amber-200">inquiry</span>' : '<span class="text-slate-500">—</span>'}</td>
                            <td class="py-3 pr-4 tabular-nums">${fmt3(r.d)}</td>
                            <td class="py-3 pr-4 tabular-nums">${fmt3(r.a)}</td>
                            <td class="py-3 pr-4 tabular-nums">${fmt3(r.e)}</td>
                            <td class="py-3 pr-4 tabular-nums">${fmt3(r.penalty)}</td>
                            <td class="py-3 pr-4 font-semibold tabular-nums text-teal-200">${fmt3(r.total)}</td>
                        </tr>
                    `).join('');

                    const cards = document.getElementById('scoreboardCards');
                    if (cards) {
                        cards.innerHTML = data.rows.map(r => `
                            <div class="border border-slate-800 rounded-xl p-4 bg-slate-950/40">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="font-medium text-slate-100 truncate">${esc(r.place)} место · № ${r.start_number ?? '—'}</div>
                                        <div class="mt-1 font-medium text-slate-100 truncate">${esc(r.athlete)}</div>
                                        <div class="text-sm text-slate-400 mt-1 truncate">${esc(r.club ?? '—')}</div>
                                        <div class="mt-2 flex flex-wrap gap-2 items-center">
                                            <span class="inline-flex items-center rounded-md border border-slate-600 bg-slate-800/80 px-2 py-1 text-xs font-medium text-slate-200">${esc(r.apparatus ?? '—')}</span>
                                            ${r.inquiry_status && r.inquiry_status !== 'decided' ? '<span class="inline-flex items-center rounded-md border border-amber-600/50 bg-amber-950/50 px-2 py-1 text-xs font-medium text-amber-200">inquiry</span>' : ''}
                                        </div>
                                    </div>
                                    <div class="text-sm font-semibold tabular-nums text-teal-200">${fmt3(r.total)}</div>
                                </div>
                                <div class="mt-3 grid grid-cols-2 gap-2 text-sm text-slate-300">
                                    <div>D: <span class="tabular-nums">${fmt3(r.d)}</span></div>
                                    <div>A: <span class="tabular-nums">${fmt3(r.a)}</span></div>
                                    <div>E: <span class="tabular-nums">${fmt3(r.e)}</span></div>
                                    <div>Штраф: <span class="tabular-nums">${fmt3(r.penalty)}</span></div>
                                </div>
                            </div>
                        `).join('');
                    }

                    status.textContent = 'Live: обновлено ' + new Date(data.updated_at).toLocaleTimeString();
                } catch (e) {
                    status.textContent = 'Live: ошибка обновления (' + (e?.message ?? e) + ')';
                }
            }

            tick();
            setInterval(tick, 2000);
        })();
    </script>
</x-scoreboard-layout>
