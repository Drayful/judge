<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-100 leading-tight">Судья на табло</h2>
    </x-slot>

    <div class="py-8 max-w-5xl mx-auto space-y-4">
        <x-flash />
        <x-card>
            <div class="font-semibold text-slate-100">Подтверждённые результаты</div>
            <p class="mt-1 text-sm text-slate-400">«Принять» публикует результат на табло и сохраняет точное время принятия.</p>

            <div class="mt-4 divide-y divide-slate-800">
                @forelse($performances as $performance)
                    @php($isTeam = $performance->category?->program === 'group' || $performance->athlete?->is_team)
                    <div class="py-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-xs text-slate-500">{{ $performance->category?->tournament?->name }} · {{ $performance->category?->name }}</div>
                            <div class="mt-1 font-medium text-slate-100">
                                {{ $performance->athlete?->last_name }}@if(! $isTeam) {{ $performance->athlete?->first_name }}@endif
                                <span class="ml-1 text-sm text-emerald-300">{{ $performance->apparatus ?? '—' }} · {{ number_format((float) $performance->total, 3) }}</span>
                            </div>
                            @if($performance->isNotPerformed())
                                <div class="mt-1 text-xs text-amber-300">Не выступила</div>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('scoreboard-judge.accept', $performance) }}">
                            @csrf
                            <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Принять</button>
                        </form>
                    </div>
                @empty
                    <div class="py-8 text-sm text-slate-400">Нет результатов, ожидающих принятия.</div>
                @endforelse
            </div>
        </x-card>
    </div>
</x-app-layout>
