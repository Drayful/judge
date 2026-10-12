<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-100 leading-tight">Музыка выступлений</h2>
    </x-slot>

    <div class="mx-auto max-w-5xl space-y-5 py-8">
        <x-flash />

        <x-card>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <form method="GET" action="{{ route('music-operator.index') }}" class="w-full sm:w-96">
                    <label for="music-tournament" class="block text-xs font-semibold uppercase tracking-wider text-slate-400">Турнир</label>
                    <select id="music-tournament" name="tournament" onchange="this.form.submit()"
                            class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-base font-semibold text-white focus:border-violet-500 focus:ring-violet-500">
                        @forelse($tournaments as $tournament)
                            <option value="{{ $tournament->id }}" @selected($selectedTournament?->id === $tournament->id)>{{ $tournament->name }}</option>
                        @empty
                            <option value="">Нет турниров</option>
                        @endforelse
                    </select>
                </form>
                <span id="music-status" class="text-xs text-slate-500">Live · обновление автоматически</span>
            </div>
        </x-card>

        @if($selectedTournament)
            <div class="sticky top-0 z-10 rounded-2xl border border-violet-700/70 bg-slate-950/95 p-4 shadow-lg shadow-violet-950/40 backdrop-blur">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-[10px] uppercase tracking-wider text-violet-300/80">Сейчас играет</div>
                        <div id="music-now-playing" class="truncate text-sm font-semibold text-white">—</div>
                    </div>
                    <button type="button" id="music-stop" class="rounded-lg border border-rose-700 bg-rose-950/50 px-4 py-2 text-sm font-semibold text-rose-100 hover:bg-rose-900/60">■ Стоп</button>
                </div>
                <audio id="music-player" controls preload="none" class="mt-3 w-full"></audio>
            </div>

            <div>
                <div class="mb-2 flex items-baseline justify-between gap-3">
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-orange-300">На ковре</h3>
                    <span id="music-stream" class="truncate text-xs text-slate-500"></span>
                </div>
                <div id="music-current"></div>
            </div>

            <div>
                <h3 class="mb-2 text-sm font-semibold uppercase tracking-wider text-slate-400">Далее в очереди</h3>
                <div id="music-upcoming" class="space-y-2"></div>
            </div>
        @else
            <x-card>
                <div class="py-10 text-center text-slate-400">Сначала создайте турнир и его потоки.</div>
            </x-card>
        @endif
    </div>

    @if($selectedTournament)
        <script>
        (() => {
            const liveUrl = @json(route('music-operator.live', ['tournament' => $selectedTournament->id]));
            let state = @json($state);
            const player = document.getElementById('music-player');
            const nowPlaying = document.getElementById('music-now-playing');
            const statusEl = document.getElementById('music-status');
            const currentEl = document.getElementById('music-current');
            const upcomingEl = document.getElementById('music-upcoming');
            const streamEl = document.getElementById('music-stream');
            let playingTrackId = null;

            const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

            const trackButton = (performance, track, label, primary) => {
                if (!track) {
                    return primary
                        ? '<span class="rounded-lg border border-amber-800 bg-amber-950/40 px-3 py-2 text-xs font-semibold text-amber-200">Музыка не загружена</span>'
                        : '';
                }
                const isPlaying = playingTrackId === track.id;
                const cls = isPlaying
                    ? 'border-emerald-500 bg-emerald-700 text-white'
                    : (primary ? 'border-violet-600 bg-violet-700 text-white hover:bg-violet-600' : 'border-slate-600 bg-slate-800 text-slate-100 hover:bg-slate-700');
                return `<button type="button" data-play="${track.id}" data-url="${esc(track.play_url)}" data-title="${esc(performance.name)} · ${esc(label)}"
                            class="rounded-lg border px-4 py-2 text-sm font-semibold ${cls}">${isPlaying ? '♪ Играет' : '▶ ' + esc(label)}</button>
                        <a href="${esc(track.download_url)}" class="text-xs text-slate-400 hover:underline" title="${esc(track.name)}">файл</a>`;
            };

            const card = (performance, isCurrent) => `
                <div class="rounded-xl border p-4 ${isCurrent ? 'border-orange-500/80 bg-orange-950/40 ring-1 ring-orange-500/50' : 'border-slate-800 bg-slate-900/50'}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="${isCurrent ? 'text-xl' : 'text-base'} font-semibold text-white">
                                <span class="mr-2 font-mono text-slate-400">#${esc(performance.number)}</span>${esc(performance.name)}
                            </div>
                            <div class="mt-0.5 text-xs text-slate-400">${esc(performance.club || '—')}${performance.apparatus ? ' · ' + esc(performance.apparatus) : ''}</div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            ${trackButton(performance, performance.track, 'Основная', true)}
                            ${trackButton(performance, performance.backup, 'Резерв', false)}
                        </div>
                    </div>
                </div>`;

            const render = () => {
                streamEl.textContent = state.stream ? `Поток: ${state.stream}` : '';
                currentEl.innerHTML = state.current
                    ? card(state.current, true)
                    : '<div class="rounded-xl border border-slate-800 bg-slate-900/40 p-6 text-center text-sm text-slate-400">Сейчас никто не выступает. Секретарь ещё не вызвал следующую гимнастку.</div>';
                upcomingEl.innerHTML = state.upcoming.length
                    ? state.upcoming.map((p) => card(p, false)).join('')
                    : '<div class="text-sm text-slate-500">Очередь пуста.</div>';
            };

            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-play]');
                if (!button) return;
                const trackId = Number(button.dataset.play);
                if (playingTrackId === trackId && !player.paused) {
                    player.pause();
                    return;
                }
                playingTrackId = trackId;
                player.src = button.dataset.url;
                nowPlaying.textContent = button.dataset.title;
                player.play().catch(() => { statusEl.textContent = 'Не удалось запустить музыку. Нажмите ▶ в плеере.'; });
                render();
            });

            document.getElementById('music-stop').addEventListener('click', () => {
                player.pause();
                player.currentTime = 0;
                playingTrackId = null;
                nowPlaying.textContent = '—';
                render();
            });

            player.addEventListener('ended', () => {
                playingTrackId = null;
                render();
            });

            const poll = async () => {
                try {
                    const response = await fetch(liveUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!response.ok) throw new Error(String(response.status));
                    const next = await response.json();
                    statusEl.textContent = 'Live · обновлено ' + new Date().toLocaleTimeString('ru-RU');
                    if (next.rev !== state.rev) {
                        state = next;
                        render();
                    }
                } catch (e) {
                    statusEl.textContent = 'Нет связи с сервером, повтор…';
                }
            };

            render();
            setInterval(poll, 3000);
        })();
        </script>
    @endif
</x-app-layout>
