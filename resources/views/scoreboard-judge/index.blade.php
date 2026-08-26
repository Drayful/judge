<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-100 leading-tight">Оператор табло</h2>
    </x-slot>

    <div class="py-8 max-w-6xl mx-auto space-y-5">
        <x-flash />

        <x-card>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="font-semibold text-slate-100">Очередь одобренных оценок</div>
                    <p class="mt-1 text-sm text-slate-400">Сверху — результат, который одобрили раньше. Кнопка выводит выбранную гимнастку на публичное табло на {{ \App\Support\ScoreboardUi::RESULT_HOLD_SECONDS }} секунд.</p>
                </div>
                <a href="{{ route('scoreboard.index') }}" target="_blank" rel="noopener"
                   class="rounded-lg border border-cyan-700 bg-cyan-950/40 px-4 py-2 text-sm font-semibold text-cyan-100 hover:bg-cyan-900/50">
                    Открыть табло ↗
                </a>
            </div>

            <div class="mt-4 divide-y divide-slate-800">
                @forelse($pendingPerformances as $performance)
                    @php($isTeam = $performance->category?->program === 'group' || $performance->athlete?->is_team)
                    <div class="py-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-xs text-slate-500">
                                Одобрено {{ $performance->approved_at?->format('H:i:s') }} · {{ $performance->category?->tournament?->name }} · {{ $performance->category?->name }}
                            </div>
                            <div class="mt-1 text-lg font-semibold text-slate-100">
                                {{ $performance->athlete?->last_name }}@if(! $isTeam) {{ $performance->athlete?->first_name }}@endif
                            </div>
                            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm">
                                <span class="text-slate-300">{{ $performance->apparatus ?? '—' }}</span>
                                <span class="font-mono text-cyan-200">DB {{ $performance->db_average !== null ? number_format((float) $performance->db_average, 3) : '—' }}</span>
                                <span class="font-mono text-cyan-200">DA {{ $performance->da_average !== null ? number_format((float) $performance->da_average, 3) : '—' }}</span>
                                <span class="font-mono font-bold text-emerald-300">Итог {{ number_format((float) $performance->total, 3) }}</span>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('scoreboard-judge.accept', $performance) }}">
                            @csrf
                            <button class="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-emerald-950/30 hover:bg-emerald-500">
                                ▶ Показать на табло
                            </button>
                        </form>
                    </div>
                @empty
                    <div class="py-8 text-sm text-slate-400">Нет новых одобренных оценок.</div>
                @endforelse
            </div>
        </x-card>

        @if($shownPerformances->isNotEmpty())
            <x-card>
                <div class="font-semibold text-slate-100">Недавно показанные</div>
                <p class="mt-1 text-sm text-slate-400">Результат можно повторно вывести на табло.</p>
                <div class="mt-4 divide-y divide-slate-800">
                    @foreach($shownPerformances as $performance)
                        <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div>
                                <div class="text-xs text-slate-500">Показано {{ $performance->scoreboard_accepted_at?->format('H:i:s') }} · {{ $performance->category?->name }}</div>
                                <div class="mt-1 font-medium text-slate-100">
                                    {{ $performance->athlete?->last_name }} {{ $performance->athlete?->first_name }}
                                    <span class="ml-2 font-mono text-emerald-300">{{ number_format((float) $performance->total, 3) }}</span>
                                </div>
                            </div>
                            <form method="POST" action="{{ route('scoreboard-judge.accept', $performance) }}">
                                @csrf
                                <button class="rounded-lg border border-slate-600 bg-slate-900 px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-800">↻ Показать ещё раз</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </x-card>
        @endif
    </div>
</x-app-layout>
