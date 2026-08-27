<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-100 leading-tight">Оператор табло</h2>
    </x-slot>

    <div class="mx-auto max-w-6xl space-y-5 py-8">
        <x-flash />

        <x-card>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <form method="GET" action="{{ route('scoreboard-judge.index') }}" class="w-full sm:w-96">
                    <label for="scoreboard-tournament" class="block text-xs font-semibold uppercase tracking-wider text-slate-400">Турнир для табло</label>
                    <select id="scoreboard-tournament" name="tournament" onchange="this.form.submit()"
                            class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-base font-semibold text-white focus:border-cyan-500 focus:ring-cyan-500">
                        @forelse($tournaments as $tournament)
                            <option value="{{ $tournament->id }}" @selected($selectedTournament?->id === $tournament->id)>{{ $tournament->name }}</option>
                        @empty
                            <option value="">Нет турниров</option>
                        @endforelse
                    </select>
                </form>

                <div class="flex flex-wrap items-center gap-3">
                    <span id="scoreboard-operator-status" class="text-xs text-slate-500">Live · обновление автоматически</span>
                    @if($selectedTournament)
                        <a href="{{ route('scoreboard.index', ['tournament' => $selectedTournament->id]) }}" target="_blank" rel="noopener"
                           class="rounded-lg border border-cyan-700 bg-cyan-950/40 px-4 py-2 text-sm font-semibold text-cyan-100 hover:bg-cyan-900/50">
                            Открыть табло ↗
                        </a>
                    @endif
                </div>
            </div>
        </x-card>

        @if($selectedTournament)
            <div id="scoreboard-operator-queues" class="space-y-5">
                @include('scoreboard-judge.partials.queues', [
                    'tournament' => $selectedTournament,
                    'pendingPerformances' => $pendingPerformances,
                    'shownPerformances' => $shownPerformances,
                ])
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
            const root = document.getElementById('scoreboard-operator-queues');
            const status = document.getElementById('scoreboard-operator-status');
            const liveUrl = @json(route('scoreboard-judge.live', ['tournament' => $selectedTournament->id]));
            let revision = @json($queueRevision);
            let requestInFlight = false;
            let pointerDown = false;
            let interactionLockedUntil = 0;

            if (! root) return;

            const lockInteraction = (milliseconds = 800) => {
                interactionLockedUntil = Math.max(interactionLockedUntil, Date.now() + milliseconds);
            };
            const interactionIsBusy = () => pointerDown || Date.now() < interactionLockedUntil;
            root.addEventListener('pointerdown', () => {
                pointerDown = true;
                lockInteraction();
            });
            const finishPointer = () => {
                if (! pointerDown) return;
                pointerDown = false;
                lockInteraction(900);
            };
            document.addEventListener('pointerup', finishPointer);
            document.addEventListener('pointercancel', finishPointer);
            window.addEventListener('blur', finishPointer);

            async function refreshQueues(force = false) {
                if (requestInFlight || (! force && interactionIsBusy()) || document.hidden) return;
                requestInFlight = true;
                try {
                    const response = await fetch(liveUrl, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                    });
                    if (! response.ok) throw new Error('HTTP ' + response.status);
                    const data = await response.json();
                    if (! force && data.rev === revision) {
                        status.textContent = 'Live · обновлено';
                        return;
                    }
                    if (interactionIsBusy()) return;

                    const scrollX = window.scrollX;
                    const scrollY = window.scrollY;
                    root.innerHTML = data.html;
                    revision = data.rev;
                    requestAnimationFrame(() => window.scrollTo({ left: scrollX, top: scrollY, behavior: 'auto' }));
                    status.textContent = 'Live · очередь обновлена';
                } catch (error) {
                    status.textContent = 'Live · ошибка связи';
                } finally {
                    requestInFlight = false;
                }
            }

            root.addEventListener('submit', async (event) => {
                const form = event.target.closest('form[data-scoreboard-accept]');
                if (! form) return;
                event.preventDefault();
                const button = event.submitter || form.querySelector('button[type="submit"], button:not([type])');
                if (button) button.disabled = true;
                status.textContent = 'Показываю результат…';
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    const data = await response.json().catch(() => ({}));
                    if (! response.ok || data.ok === false) throw new Error(data.message || 'Не удалось показать результат.');
                    interactionLockedUntil = 0;
                    pointerDown = false;
                    await refreshQueues(true);
                    status.textContent = data.message || 'Результат показан';
                } catch (error) {
                    status.textContent = error?.message || 'Ошибка показа результата';
                    if (button) button.disabled = false;
                }
            });

            setInterval(refreshQueues, 1000);
            document.addEventListener('visibilitychange', () => { if (! document.hidden) refreshQueues(); });
        })();
        </script>
    @endif
</x-app-layout>
