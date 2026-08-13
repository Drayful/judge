<main class="sb-main max-w-6xl mx-auto w-full">
    <div id="emptyState" class="{{ $rows->isEmpty() ? '' : 'hidden' }} flex-1 flex items-center justify-center">
        <div class="text-center">
            <div class="text-5xl mb-4 opacity-30">★</div>
            <p class="text-lg text-slate-300 font-medium">Пока без результатов</p>
            <p class="mt-2 text-sm text-slate-500">Оценки появятся после публикации секретарём</p>
        </div>
    </div>

    <div id="boardRoot" class="{{ $rows->isEmpty() ? 'hidden' : '' }} flex-1 min-h-0 flex flex-col gap-4">
        <div id="podium" class="sb-podium">
            @foreach($rows->where('status', 'ranked')->take(3)->values() as $idx => $p)
                @php
                    $place = $p->place;
                    $cardClass = match ($idx) {
                        0 => 'sb-podium-card sb-podium-card--gold order-2 sm:order-2',
                        1 => 'sb-podium-card sb-podium-card--silver order-1 sm:order-1',
                        2 => 'sb-podium-card sb-podium-card--bronze order-3 sm:order-3',
                        default => 'sb-podium-card',
                    };
                    $medalClass = match ($place) {
                        1 => 'sb-medal sb-medal--gold',
                        2 => 'sb-medal sb-medal--silver',
                        3 => 'sb-medal sb-medal--bronze',
                        default => 'sb-medal',
                    };
                @endphp
                <div class="{{ $cardClass }}" data-podium-place="{{ $place }}">
                    <span class="{{ $medalClass }}">{{ $place }}</span>
                    <div class="mt-2 sm:mt-3 text-sm sm:text-base font-semibold text-white truncate leading-snug px-1">
                        {{ $p->athlete->last_name }} {{ $p->athlete->first_name }}
                    </div>
                    <div class="mt-1 text-xs text-slate-400 truncate hidden sm:block px-1">{{ $p->athlete->club }}</div>
                    <div class="mt-2 sm:mt-3 text-2xl sm:text-3xl font-bold tabular-nums text-teal-300" data-podium-total>
                        {{ number_format($p->total, 3) }}
                    </div>
                </div>
            @endforeach
        </div>

        <div id="rowsList" class="sb-rows">
            @foreach($rows as $p)
                @php
                    $place = $p->place;
                    $placeCls = match ($place) {
                        1 => 'bg-amber-400 text-amber-950',
                        2 => 'bg-slate-300 text-slate-900',
                        3 => 'bg-orange-500 text-orange-950',
                        default => 'bg-slate-700 text-slate-200',
                    };
                @endphp
                <div class="sb-row" data-row-key="{{ $p->id }}" data-row-total="{{ $p->total }}">
                    <span class="sb-place {{ $placeCls }}">{{ $p->status === 'not_performed' ? '—' : $place }}</span>
                    <span class="hidden sm:block text-sm tabular-nums text-slate-500 font-medium">{{ $p->start_number ?? '—' }}</span>
                    <div class="min-w-0">
                        <div class="text-sm sm:text-base font-semibold text-slate-100 truncate">
                            {{ $p->athlete->last_name }} {{ $p->athlete->first_name }}
                        </div>
                        <div class="text-xs text-slate-500 truncate hidden sm:block">{{ $p->athlete->club ?? '' }}</div>
                        @if($p->status === 'not_performed')
                            <div class="text-xs text-amber-300">Не выступила</div>
                        @endif
                    </div>
                    <div class="hidden sm:flex gap-3 text-xs tabular-nums text-slate-400">
                        <span>Вид {{ $p->apparatus ?? '—' }}: {{ $p->apparatus_score !== null ? number_format($p->apparatus_score, 3) : '—' }}</span>
                        @if(count($p->vidi ?? []) > 1)
                            <span>Виды: {{ collect($p->vidi)->map(fn ($v) => number_format($v, 3))->implode(' + ') }}</span>
                        @else
                            <span>D {{ $p->d_score !== null ? number_format($p->d_score, 3) : '—' }}</span>
                            <span>A {{ $p->a_score !== null ? number_format($p->a_score, 3) : '—' }}</span>
                            <span>E {{ $p->e_score !== null ? number_format($p->e_score, 3) : '—' }}</span>
                        @endif
                    </div>
                    <div class="sb-total">{{ number_format($p->total, 3) }}</div>
                </div>
            @endforeach
        </div>
    </div>
</main>

<footer class="sb-footer max-w-6xl mx-auto w-full">
    <span id="liveStatus">Live</span>
    <span id="liveUpdatedAt"></span>
</footer>

<script>
(function () {
    const url = @json($liveUrl);
    const empty = document.getElementById('emptyState');
    const boardRoot = document.getElementById('boardRoot');
    const podium = document.getElementById('podium');
    const rowsList = document.getElementById('rowsList');
    const status = document.getElementById('liveStatus');
    const liveUpdatedAt = document.getElementById('liveUpdatedAt');
    const prevTotals = new Map();
    let acceptedIds = new Set();
    let hasLiveSnapshot = false;
    document.querySelectorAll('[data-row-key]').forEach(el => prevTotals.set(el.dataset.rowKey, el.dataset.rowTotal));

    const podiumOrder = [1, 0, 2];
    const podiumCardClass = ['sb-podium-card sb-podium-card--silver order-1', 'sb-podium-card sb-podium-card--gold order-2', 'sb-podium-card sb-podium-card--bronze order-3'];

    function fmt3(v) {
        if (v === null || v === undefined) return '—';
        const n = Number(v);
        return Number.isNaN(n) ? '—' : n.toFixed(3);
    }
    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function placeCls(place) {
        switch (Number(place)) {
            case 1: return 'bg-amber-400 text-amber-950';
            case 2: return 'bg-slate-300 text-slate-900';
            case 3: return 'bg-orange-500 text-orange-950';
            default: return 'bg-slate-700 text-slate-200';
        }
    }

    function announce(row) {
        if (!('speechSynthesis' in window) || row.status === 'not_performed') return;
        window.speechSynthesis.cancel();
        const place = row.place ? `место ${row.place}. ` : '';
        const kind = row.apparatus ? `Вид ${row.apparatus}: ${fmt3(row.apparatus_score)}. ` : '';
        window.speechSynthesis.speak(new SpeechSynthesisUtterance(`${place}${row.athlete}. ${kind}Итог ${fmt3(row.total)}.`));
    }

    function render(rows) {
        if (!rows.length) {
            empty.classList.remove('hidden');
            boardRoot.classList.add('hidden');
            return;
        }
        empty.classList.add('hidden');
        boardRoot.classList.remove('hidden');

        const top3 = rows.filter(r => r.status !== 'not_performed').slice(0, 3);
        podium.innerHTML = top3.map((r, i) => {
            const place = Number(r.place);
            const medal = place === 1 ? 'sb-medal sb-medal--gold'
                : place === 2 ? 'sb-medal sb-medal--silver'
                : place === 3 ? 'sb-medal sb-medal--bronze'
                : 'sb-medal';
            return `
            <div class="${podiumCardClass[i] || 'sb-podium-card'}">
                <span class="${medal}">${esc(r.place)}</span>
                <div class="mt-2 sm:mt-3 text-sm sm:text-base font-semibold text-white truncate leading-snug px-1">${esc(r.athlete)}</div>
                <div class="mt-1 text-xs text-slate-400 truncate hidden sm:block px-1">${esc(r.club ?? '')}</div>
                <div class="mt-2 sm:mt-3 text-2xl sm:text-3xl font-bold tabular-nums text-teal-300">${fmt3(r.total)}</div>
            </div>
        `;
        }).join('');

        const changed = new Set();
        rowsList.innerHTML = rows.map(r => {
            const key = String(r.id);
            const totalStr = fmt3(r.total);
            if (prevTotals.has(key) && prevTotals.get(key) !== totalStr) changed.add(key);
            return `
                <div class="sb-row" data-row-key="${esc(key)}" data-row-total="${esc(totalStr)}">
                    <span class="sb-place ${placeCls(r.place)}">${r.status === 'not_performed' ? '—' : esc(r.place)}</span>
                    <span class="hidden sm:block text-sm tabular-nums text-slate-500 font-medium">${r.start_number ?? '—'}</span>
                    <div class="min-w-0">
                        <div class="text-sm sm:text-base font-semibold text-slate-100 truncate">${esc(r.athlete)}</div>
                        <div class="text-xs text-slate-500 truncate hidden sm:block">${esc(r.club ?? '')}</div>
                        ${r.status === 'not_performed' ? '<div class="text-xs text-amber-300">Не выступила</div>' : ''}
                    </div>
                    <div class="hidden sm:flex gap-3 text-xs tabular-nums text-slate-400">
                        <span>Вид ${esc(r.apparatus ?? '—')}: ${fmt3(r.apparatus_score)}</span>
                        ${(r.vidi && r.vidi.length > 1)
                            ? '<span>Виды: ' + r.vidi.map(fmt3).join(' + ') + '</span>'
                            : '<span>D ' + fmt3(r.d) + '</span><span>A ' + fmt3(r.a) + '</span><span>E ' + fmt3(r.e) + '</span>'}
                    </div>
                    <div class="sb-total">${totalStr}</div>
                </div>
            `;
        }).join('');

        if (changed.size) {
            document.querySelectorAll('[data-row-key]').forEach(el => {
                if (changed.has(el.dataset.rowKey)) {
                    el.classList.add('flash-update');
                    setTimeout(() => el.classList.remove('flash-update'), 1600);
                }
            });
        }
        prevTotals.clear();
        rows.forEach(r => prevTotals.set(String(r.id), fmt3(r.total)));
    }

    let lastUpdated = null;
    let lastRev = null;
    async function tick() {
        try {
            const res = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            lastUpdated = new Date(data.updated_at);
            status.textContent = 'Live · ' + data.rows.length + ' участниц';
            if (data.rev && data.rev === lastRev) return; // без изменений — не перерисовываем
            lastRev = data.rev;
            const nextAccepted = new Set(data.rows.filter(r => r.accepted_at).map(r => String(r.id)));
            if (hasLiveSnapshot) {
                data.rows.filter(r => r.accepted_at && !acceptedIds.has(String(r.id))).forEach(announce);
            }
            acceptedIds = nextAccepted;
            hasLiveSnapshot = true;
            render(data.rows);
        } catch (e) {
            status.textContent = 'Ошибка обновления';
        }
    }
    setInterval(() => { if (lastUpdated) liveUpdatedAt.textContent = lastUpdated.toLocaleTimeString(); }, 1000);
    setInterval(tick, document.hidden ? 5000 : 2000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
    tick();
})();
</script>
