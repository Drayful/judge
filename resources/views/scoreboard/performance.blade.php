<x-scoreboard-layout>
    @php
        $payload = $initialPayload;
        $perf = $payload['performance'] ?? null;
        $tournament = $category->tournament;
        $tableUrl = route('scoreboard.table', $category);
    @endphp

    <div class="sb-screen" id="performanceRoot">
        <header class="sb-header scoreboard-chrome">
            <div class="max-w-6xl mx-auto flex flex-wrap items-center justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 text-xs uppercase tracking-[0.2em] text-cyan-400/90 font-medium">
                        <span class="h-2 w-2 rounded-full bg-cyan-400 live-pulse"></span>
                        На ковре
                    </div>
                    @if($tournament)
                        <p class="mt-1 text-sm text-slate-400 truncate">{{ $tournament->name }}</p>
                    @endif
                    <h1 class="mt-0.5 text-xl sm:text-2xl font-bold text-white truncate">{{ $category->name }}</h1>
                </div>
                <div class="flex flex-wrap items-center gap-2 shrink-0">
                    <a href="{{ $tableUrl }}" class="sb-btn sb-btn-ghost">Результаты</a>
                    <a href="{{ route('scoreboard.index', ['category' => $category->id]) }}" class="sb-btn sb-btn-ghost">Все потоки</a>
                    <button type="button" id="tvModeBtn" class="sb-btn sb-btn-cyan">На весь экран</button>
                </div>
            </div>
        </header>

        <main class="sb-live-stage scoreboard-tv-stage">
            <div class="w-full max-w-5xl" id="liveBoard">
                <div id="emptyState" class="{{ $perf ? 'hidden' : '' }} text-center py-16">
                    <div class="text-6xl mb-6 opacity-25">◎</div>
                    <h2 class="text-2xl sm:text-3xl font-semibold text-white">Ожидание участницы</h2>
                    <p class="mt-2 text-slate-500">Выступление появится автоматически</p>
                </div>

                <div id="liveContent" class="{{ $perf ? '' : 'hidden' }} flex flex-col items-center gap-8 sm:gap-10">
                    <div class="text-center w-full space-y-4">
                        <div id="phaseBadge" class="inline-flex items-center gap-2 rounded-full border border-cyan-500/40 bg-cyan-950/50 px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-cyan-200">
                            <span class="h-2 w-2 rounded-full bg-cyan-400 live-pulse" id="phaseDot"></span>
                            <span id="phaseLabel">{{ $payload['phase_label'] ?? '—' }}</span>
                        </div>

                        <div id="placeBlock" class="{{ ($perf['place'] ?? null) ? '' : 'opacity-40' }}">
                            <div class="text-xs uppercase tracking-[0.2em] text-slate-500 mb-1">Текущее место</div>
                            <div class="flex items-baseline justify-center gap-2">
                                <span id="placeValue" class="sb-place-hero">{{ $perf['place'] ?? '?' }}</span>
                                <span id="placeOf" class="text-2xl sm:text-3xl text-slate-500 font-medium">/ {{ $perf['place_of'] ?? '—' }}</span>
                            </div>
                        </div>

                        <div class="text-sm text-slate-500" id="startNumberWrap">
                            @if($perf && $perf['start_number'])
                                № <span id="startNumber" class="text-slate-300 font-semibold tabular-nums">{{ $perf['start_number'] }}</span>
                            @endif
                        </div>

                        <h2 class="sb-athlete-name px-4" id="athleteName">{{ $perf['athlete'] ?? '—' }}</h2>
                        <div class="text-lg sm:text-xl text-slate-400 truncate px-4 max-w-3xl mx-auto" id="athleteClub">{{ $perf['club'] ?? '—' }}</div>

                        <span id="apparatusBadge" class="inline-block rounded-xl border border-slate-600 bg-slate-900/70 px-4 py-1.5 text-sm text-slate-200">
                            {{ $perf['apparatus_label'] ?? '—' }}
                        </span>

                        <div id="groupWrap" class="{{ ($perf['is_group'] ?? false) ? '' : 'hidden' }} mt-3">
                            <span class="inline-block rounded-lg border border-amber-500/50 bg-amber-950/40 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-amber-200">
                                Групповое выступление
                            </span>
                            <div id="groupMembers" class="mt-2 text-base text-slate-300">{{ isset($perf['members']) ? implode(' · ', $perf['members']) : '' }}</div>
                        </div>
                    </div>

                    <div class="sb-scores">
                        @foreach(['d' => 'D', 'a' => 'A', 'e' => 'E', 'penalty' => 'Штр', 'total' => 'Итог'] as $key => $label)
                            @php
                                $cardClass = 'sb-score-card' . ($key === 'total' ? ' sb-score-card--total' : '');
                                $valueClass = 'sb-score-value'
                                    . ($key === 'total' ? ' sb-score-value--total' : '')
                                    . ($key === 'penalty' ? ' sb-score-value--penalty' : '');
                            @endphp
                            <div class="{{ $cardClass }}">
                                <div class="sb-score-label">{{ $label }}</div>
                                <div class="{{ $valueClass }}" id="score{{ ucfirst($key) }}">
                                    @if($perf && $perf[$key] !== null)
                                        {{ number_format((float) $perf[$key], 3) }}
                                    @else
                                        —
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="scoreboard-chrome w-full max-w-2xl rounded-2xl border border-slate-700/50 bg-slate-900/50 px-5 py-4">
                        <div class="flex justify-between text-xs text-slate-400 mb-2">
                            <span>Судьи</span>
                            <span id="judgeProgress" class="tabular-nums font-medium text-slate-300">
                                {{ ($payload['judges']['submitted'] ?? 0) }}/{{ ($payload['judges']['required'] ?? 0) }}
                            </span>
                        </div>
                        <div class="h-2 rounded-full bg-slate-800 overflow-hidden">
                            <div id="judgeProgressBar" class="h-full bg-gradient-to-r from-emerald-600 to-cyan-500 transition-all duration-500"
                                style="width: {{ ($payload['judges']['required'] ?? 0) > 0 ? round(100 * ($payload['judges']['submitted'] ?? 0) / ($payload['judges']['required'] ?? 1)) : 0 }}%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <footer class="sb-footer scoreboard-chrome max-w-6xl mx-auto w-full">
            <span>Индивидуальное табло</span>
            <span id="liveStatus">Live</span>
        </footer>
    </div>

    <script>
    (function () {
        const url = @json(route('scoreboard.performance.live', $category));
        const emptyState = document.getElementById('emptyState');
        const liveContent = document.getElementById('liveContent');
        const liveStatus = document.getElementById('liveStatus');
        const tvBtn = document.getElementById('tvModeBtn');
        const prev = { d: null, a: null, e: null, penalty: null, total: null, place: null };

        function fmt3(v) {
            if (v === null || v === undefined) return '—';
            const n = Number(v);
            return Number.isNaN(n) ? '—' : n.toFixed(3);
        }
        function esc(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        function flashEl(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.classList.add('flash-update');
            setTimeout(() => el.classList.remove('flash-update'), 1600);
        }

        function setTvMode(on) {
            document.body.classList.toggle('scoreboard-tv-mode', on);
            if (tvBtn) tvBtn.textContent = on ? 'Выйти из экрана' : 'На весь экран';
        }

        if (tvBtn) {
            tvBtn.addEventListener('click', async () => {
                const on = !document.body.classList.contains('scoreboard-tv-mode');
                setTvMode(on);
                if (on) {
                    try { await document.documentElement.requestFullscreen(); } catch (e) {}
                } else {
                    try { if (document.fullscreenElement) await document.exitFullscreen(); } catch (e) {}
                }
            });
            document.addEventListener('fullscreenchange', () => {
                if (!document.fullscreenElement && document.body.classList.contains('scoreboard-tv-mode')) {
                    setTvMode(false);
                }
            });
        }

        function render(data) {
            const perf = data.performance;
            if (!perf) {
                emptyState.classList.remove('hidden');
                liveContent.classList.add('hidden');
                return;
            }
            emptyState.classList.add('hidden');
            liveContent.classList.remove('hidden');

            document.getElementById('phaseLabel').textContent = data.phase_label || '—';
            const performing = data.phase === 'performing';
            document.getElementById('phaseDot').className = 'h-2 w-2 rounded-full ' + (performing ? 'bg-cyan-400 live-pulse' : 'bg-slate-500');

            const placeBlock = document.getElementById('placeBlock');
            const placeVal = perf.place;
            if (placeVal !== null && placeVal !== undefined) {
                placeBlock.classList.remove('opacity-40');
                document.getElementById('placeValue').textContent = placeVal;
                if (prev.place !== null && prev.place !== placeVal) flashEl('placeValue');
                prev.place = placeVal;
            } else {
                placeBlock.classList.add('opacity-40');
                document.getElementById('placeValue').textContent = '?';
                prev.place = null;
            }
            document.getElementById('placeOf').textContent = '/ ' + (perf.place_of ?? '—');

            document.getElementById('athleteName').textContent = perf.athlete || '—';
            document.getElementById('athleteClub').textContent = perf.club || '—';
            document.getElementById('apparatusBadge').textContent = perf.apparatus_label || '—';

            const groupWrap = document.getElementById('groupWrap');
            if (groupWrap) {
                groupWrap.classList.toggle('hidden', !perf.is_group);
                document.getElementById('groupMembers').textContent = (perf.members || []).join(' · ');
            }

            const startWrap = document.getElementById('startNumberWrap');
            startWrap.innerHTML = perf.start_number
                ? '№ <span class="text-slate-300 font-semibold tabular-nums">' + esc(perf.start_number) + '</span>'
                : '';

            ['d', 'a', 'e', 'penalty', 'total'].forEach(key => {
                const id = 'score' + key.charAt(0).toUpperCase() + key.slice(1);
                const val = fmt3(perf[key]);
                const el = document.getElementById(id);
                if (el && prev[key] !== null && prev[key] !== val && val !== '—') flashEl(id);
                if (el) el.textContent = val;
                prev[key] = val;
            });

            const judges = data.judges || { submitted: 0, required: 0 };
            document.getElementById('judgeProgress').textContent = judges.submitted + '/' + judges.required;
            document.getElementById('judgeProgressBar').style.width =
                (judges.required > 0 ? Math.round(100 * judges.submitted / judges.required) : 0) + '%';
        }

        let lastRev = null;
        async function tick() {
            try {
                const res = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                liveStatus.textContent = 'Обновлено';
                if (data.rev && data.rev === lastRev) return; // без изменений — пропускаем перерисовку
                lastRev = data.rev;
                render(data);
            } catch (e) {
                liveStatus.textContent = 'Ошибка';
            }
        }
        setInterval(tick, document.hidden ? 3000 : 1000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
        tick();
    })();
    </script>
</x-scoreboard-layout>
