<x-scoreboard-layout>
    @php
        $payload = $initialPayload;
        $perf = $payload['performance'] ?? null;
        $tournament = $category->tournament;
        $pollCategory = $pollCategory ?? $category;
    @endphp

    <div class="sb-screen" id="performanceRoot">
        <header class="sb-header scoreboard-chrome">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.2em] text-cyan-300">
                        <span class="h-2 w-2 rounded-full bg-cyan-400 live-pulse"></span>
                        Табло выступления
                    </div>
                    @if($tournament)
                        <p class="mt-1 truncate text-sm text-slate-400">{{ $tournament->name }}</p>
                    @endif
                    <h1 id="categoryName" class="mt-0.5 truncate text-xl font-bold text-white sm:text-2xl">{{ $perf['category_name'] ?? $category->name }}</h1>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <a href="{{ route('scoreboard.table', $pollCategory) }}" class="sb-btn sb-btn-ghost">Результаты</a>
                    <a href="{{ route('scoreboard.index', ['category' => $pollCategory->id]) }}" class="sb-btn sb-btn-ghost">Все потоки</a>
                    <button type="button" id="tvModeBtn" class="sb-btn sb-btn-cyan">На весь экран</button>
                </div>
            </div>
        </header>

        <main class="sb-live-stage scoreboard-tv-stage">
            <div class="w-full max-w-7xl" id="liveBoard">
                <div id="emptyState" class="{{ $perf ? 'hidden' : '' }} py-16 text-center">
                    <div class="mb-6 text-6xl opacity-25">◎</div>
                    <h2 class="text-2xl font-semibold text-white sm:text-3xl">Ожидание участницы</h2>
                    <p class="mt-2 text-slate-500">Гимнастка появится после выбора оператора табло</p>
                </div>

                <div id="liveContent" class="{{ $perf ? '' : 'hidden' }} flex flex-col items-center gap-6 sm:gap-8">
                    <div class="w-full space-y-3 text-center">
                        <div id="phaseBadge" class="inline-flex items-center gap-2 rounded-full border border-cyan-500/40 bg-cyan-950/50 px-5 py-2 text-sm font-bold uppercase tracking-wider text-cyan-100">
                            <span id="phaseDot" class="h-2.5 w-2.5 rounded-full bg-cyan-400 live-pulse"></span>
                            <span id="phaseLabel">{{ $payload['phase_label'] ?? '—' }}</span>
                        </div>

                        <div id="inquiryBanner" class="{{ ($perf['inquiry_active'] ?? false) ? '' : 'hidden' }} mx-auto max-w-4xl rounded-2xl border-2 border-amber-400 bg-amber-950/80 px-6 py-3 text-lg font-black uppercase tracking-wide text-amber-100">
                            Запрос по <span id="inquiryPanel">{{ $perf['inquiry_panel'] ?? 'оценке' }}</span> — результат предварительный
                        </div>

                        <div class="text-sm font-semibold uppercase tracking-[0.14em] text-cyan-300" id="classificationLabel">{{ $perf['classification_label'] ?? '' }}</div>
                        <div class="text-base font-semibold text-slate-400 sm:text-lg" id="startNumberWrap">
                            @if($perf && $perf['start_number']) № <span class="font-semibold tabular-nums text-slate-300">{{ $perf['start_number'] }}</span> @endif
                        </div>
                        <h2 class="sb-athlete-name px-4" id="athleteName">{{ $perf['athlete'] ?? '—' }}</h2>
                        <div class="mx-auto max-w-4xl truncate px-4 text-xl text-slate-300 sm:text-2xl" id="athleteClub">{{ $perf['club'] ?? '—' }}</div>
                        <span id="apparatusBadge" class="inline-block rounded-xl border border-slate-500 bg-slate-900/80 px-6 py-2 text-lg font-black text-white sm:text-2xl">
                            {{ $perf['apparatus_label'] ?? '—' }}
                        </span>

                        <div id="groupWrap" class="{{ ($perf['is_group'] ?? false) ? '' : 'hidden' }} mt-3">
                            <span class="inline-block rounded-lg border border-amber-500/50 bg-amber-950/40 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-amber-200">Групповое выступление</span>
                            <div id="groupMembers" class="mt-2 text-base text-slate-300">{{ isset($perf['members']) ? implode(' · ', $perf['members']) : '' }}</div>
                        </div>
                    </div>

                    <div id="calculatingState" class="{{ ($perf && ! ($perf['score_visible'] ?? false)) ? '' : 'hidden' }} rounded-3xl border border-cyan-700/50 bg-cyan-950/25 px-8 py-7 text-center">
                        <div class="text-2xl font-black text-white sm:text-4xl">Оценка подсчитывается</div>
                        <div class="mt-3 text-base text-cyan-200">Результат появится после одобрения и вывода оператором</div>
                    </div>

                    <div id="resultState" class="{{ ($perf['score_visible'] ?? false) ? '' : 'hidden' }} w-full space-y-5">
                        <div id="normalDComponents" class="{{ ($perf['is_body_only'] ?? false) ? 'hidden' : '' }} sb-scores mx-auto max-w-5xl">
                            @foreach(['d' => 'D', 'db' => 'DB', 'da' => 'DA', 'a' => 'A', 'e' => 'E', 'penalty' => 'Сбавка'] as $key => $label)
                                <div class="sb-score-card {{ in_array($key, ['d', 'a', 'e'], true) ? 'sb-score-card--primary' : '' }}">
                                    <div class="sb-score-label">{{ $label }}</div>
                                    <div id="score{{ ucfirst($key) }}" class="sb-score-value {{ $key === 'penalty' ? 'sb-score-value--penalty' : '' }}">{{ isset($perf[$key]) && $perf[$key] !== null ? number_format((float) $perf[$key], 3) : '—' }}</div>
                                </div>
                            @endforeach
                        </div>

                        <div id="bodyOnlyComponents" class="{{ ($perf['is_body_only'] ?? false) ? '' : 'hidden' }} sb-scores sb-scores--body mx-auto max-w-4xl">
                            @foreach(['d' => 'D', 'a' => 'A', 'e' => 'E', 'penalty' => 'Сбавка'] as $key => $label)
                                <div class="sb-score-card {{ in_array($key, ['d', 'a', 'e'], true) ? 'sb-score-card--primary' : '' }}">
                                    <div class="sb-score-label">{{ $label }}</div>
                                    <div id="bodyScore{{ ucfirst($key) }}" class="sb-score-value {{ $key === 'penalty' ? 'sb-score-value--penalty' : '' }}">{{ isset($perf[$key]) && $perf[$key] !== null ? number_format((float) $perf[$key], 3) : '—' }}</div>
                                </div>
                            @endforeach
                        </div>

                        <div class="sb-result-grid">
                            <div class="sb-result-card border-2 border-cyan-400/70 bg-gradient-to-br from-cyan-950 to-slate-950 shadow-2xl shadow-cyan-950/50">
                                <div class="text-sm font-bold uppercase tracking-[0.18em] text-cyan-200">За упражнение</div>
                                <div id="scoreApparatus_score" class="sb-final-score">{{ isset($perf['apparatus_score']) && $perf['apparatus_score'] !== null ? number_format((float) $perf['apparatus_score'], 3) : '—' }}</div>
                            </div>
                            <div class="sb-result-card border border-violet-500/60 bg-violet-950/35">
                                <div class="text-sm font-bold uppercase tracking-wider text-violet-200">Сумма многоборья</div>
                                <div id="scoreTotal" class="sb-overall-score">{{ isset($perf['total']) && $perf['total'] !== null ? number_format((float) $perf['total'], 3) : '—' }}</div>
                            </div>
                            <div id="placeBlock" class="sb-result-card border border-amber-500/60 bg-amber-950/35 {{ ($perf['place'] ?? null) ? '' : 'opacity-40' }}">
                                <div class="text-sm font-bold uppercase tracking-wider text-amber-200">Место в многоборье</div>
                                <div class="mt-1 flex items-baseline justify-center gap-2">
                                    <span id="placeValue" class="sb-rank-value">{{ $perf['place'] ?? '—' }}</span>
                                    <span id="placeOf" class="text-xl font-bold text-amber-200">из {{ $perf['place_of'] ?? '—' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <footer class="sb-footer scoreboard-chrome mx-auto w-full max-w-7xl">
            <span>Место: Excel-пул · год рождения · категория</span>
            <span id="liveStatus">Live</span>
        </footer>
    </div>

    <script>
    (function () {
        const url = @json(route('scoreboard.performance.live', $pollCategory));
        const emptyState = document.getElementById('emptyState');
        const liveContent = document.getElementById('liveContent');
        const liveStatus = document.getElementById('liveStatus');
        const tvBtn = document.getElementById('tvModeBtn');
        const prev = { db: null, da: null, d: null, a: null, e: null, penalty: null, apparatus_score: null, total: null, place: null };

        const fmt3 = (value) => value === null || value === undefined || Number.isNaN(Number(value)) ? '—' : Number(value).toFixed(3);
        const esc = (value) => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
        const flashEl = (id) => {
            const element = document.getElementById(id);
            if (! element) return;
            element.classList.add('flash-update');
            setTimeout(() => element.classList.remove('flash-update'), 1600);
        };
        const setText = (id, value) => {
            const element = document.getElementById(id);
            if (element) element.textContent = value;
        };

        function setTvMode(on) {
            document.body.classList.toggle('scoreboard-tv-mode', on);
            if (tvBtn) tvBtn.textContent = on ? 'Выйти из экрана' : 'На весь экран';
        }

        if (tvBtn) {
            tvBtn.addEventListener('click', async () => {
                const on = ! document.body.classList.contains('scoreboard-tv-mode');
                setTvMode(on);
                try {
                    if (on) await document.documentElement.requestFullscreen();
                    else if (document.fullscreenElement) await document.exitFullscreen();
                } catch (error) {}
            });
            document.addEventListener('fullscreenchange', () => {
                if (! document.fullscreenElement) setTvMode(false);
            });
        }

        function updateScore(key, value, id = null) {
            const elementId = id || ('score' + key.charAt(0).toUpperCase() + key.slice(1));
            const formatted = fmt3(value);
            if (prev[key] !== null && prev[key] !== formatted && formatted !== '—') flashEl(elementId);
            setText(elementId, formatted);
            prev[key] = formatted;
        }

        function render(data) {
            const perf = data.performance;
            if (! perf) {
                emptyState.classList.remove('hidden');
                liveContent.classList.add('hidden');
                return;
            }

            emptyState.classList.add('hidden');
            liveContent.classList.remove('hidden');
            setText('phaseLabel', perf.score_visible ? 'Результат' : (data.phase === 'performing' ? 'На ковре' : 'Оценка подсчитывается'));
            document.getElementById('phaseDot').className = 'h-2.5 w-2.5 rounded-full ' + (data.phase === 'performing' ? 'bg-cyan-400 live-pulse' : 'bg-emerald-400');
            setText('categoryName', perf.category_name || 'Поток');
            setText('classificationLabel', perf.classification_label || '');
            setText('athleteName', perf.athlete || '—');
            setText('athleteClub', perf.club || '—');
            setText('apparatusBadge', perf.apparatus_label || '—');

            document.getElementById('startNumberWrap').innerHTML = perf.start_number
                ? '№ <span class="font-semibold tabular-nums text-slate-300">' + esc(perf.start_number) + '</span>'
                : '';
            document.getElementById('groupWrap').classList.toggle('hidden', ! perf.is_group);
            setText('groupMembers', (perf.members || []).join(' · '));

            document.getElementById('inquiryBanner').classList.toggle('hidden', ! perf.inquiry_active);
            setText('inquiryPanel', perf.inquiry_panel || 'оценке');
            document.getElementById('calculatingState').classList.toggle('hidden', !! perf.score_visible);
            document.getElementById('resultState').classList.toggle('hidden', ! perf.score_visible);

            if (! perf.score_visible) return;

            document.getElementById('normalDComponents').classList.toggle('hidden', !! perf.is_body_only);
            document.getElementById('bodyOnlyComponents').classList.toggle('hidden', ! perf.is_body_only);
            if (perf.is_body_only) {
                updateScore('d', perf.d, 'bodyScoreD');
                updateScore('a', perf.a, 'bodyScoreA');
                updateScore('e', perf.e, 'bodyScoreE');
                updateScore('penalty', perf.penalty, 'bodyScorePenalty');
            } else {
                ['d', 'db', 'da', 'a', 'e', 'penalty'].forEach(key => updateScore(key, perf[key]));
            }
            updateScore('apparatus_score', perf.apparatus_score, 'scoreApparatus_score');
            updateScore('total', perf.total);

            const placeBlock = document.getElementById('placeBlock');
            const place = perf.place;
            placeBlock.classList.toggle('opacity-40', place === null || place === undefined);
            setText('placeValue', place ?? '—');
            setText('placeOf', 'из ' + (perf.place_of ?? '—'));
            if (prev.place !== null && prev.place !== place) flashEl('placeValue');
            prev.place = place;
        }

        // Не перерисовываем уже показанный сервером результат при первом poll:
        // это исключает лишнее переключение блоков и визуальный рывок экрана.
        let lastRev = @json($payload['rev'] ?? null);
        async function tick() {
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (! response.ok) throw new Error('HTTP ' + response.status);
                const data = await response.json();
                liveStatus.textContent = 'Обновлено';
                if (data.rev && data.rev === lastRev) return;
                lastRev = data.rev;
                render(data);
            } catch (error) {
                liveStatus.textContent = 'Ошибка связи';
            }
        }

        setInterval(tick, document.hidden ? 3000 : 1000);
        document.addEventListener('visibilitychange', () => { if (! document.hidden) tick(); });
        render(@json($payload));
        tick();
    })();
    </script>
</x-scoreboard-layout>
