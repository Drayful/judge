<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3 w-full">
            <div>
                <h2 class="font-semibold text-xl text-slate-100 leading-tight">
                    Судейство: {{ $category->name }}
                </h2>
                @if($panel)
                    <div class="text-sm text-slate-400 mt-1">
                        Панель: <span class="font-medium text-slate-200">{{ $panel['panel'] }}{{ $panel['subpanel'] ? ' / '.$panel['subpanel'] : '' }}{{ $panel['penalty_type'] ? ' / '.$panel['penalty_type'] : '' }}</span>
                    </div>
                @endif
            </div>
            @if($category->tournament)
                <a href="{{ route('judge.tournament.tablet', $category->tournament) }}" class="text-sm font-semibold text-emerald-400 hover:text-emerald-300 shrink-0">
                    Планшет →
                </a>
            @endif
        </div>
    </x-slot>

    <div class="py-10">
        <div class="w-full px-0 space-y-4">
            <x-flash />

            @php
                $allowedPanels = $panel ? [$panel['panel']] : ['d','a','e','penalty'];
                $isSupervisor = in_array(auth()->user()->role, ['superior_jury','head_judge','admin','super_admin'], true);
            @endphp

            <x-card>
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div class="text-sm text-slate-400">
                        Вводите оценки только своей панели. Итог публикуется после утверждения.
                    </div>
                    <x-badge tone="violet">{{ $performances->count() }} выходов</x-badge>
                </div>

                <div class="-mx-2 px-2">
                    <div class="hidden sm:block">
                        <table class="w-full text-sm table-fixed">
                            <thead class="text-left text-slate-400">
                            <tr class="border-b border-slate-800">
                                <th class="py-3 pr-4 font-medium w-16">№</th>
                                <th class="py-3 pr-4 font-medium">Спортсменка</th>
                                <th class="py-3 pr-4 font-medium w-64">Статус</th>
                                @foreach($allowedPanels as $pnl)
                                    <th class="py-3 pr-4 font-medium w-40">
                                        {{ $pnl === 'penalty' ? 'Штраф' : strtoupper($pnl) }}
                                    </th>
                                @endforeach
                                <th class="py-3 pr-4 font-medium w-24">Итог</th>
                                <th class="py-3 text-right font-medium w-56">Действия</th>
                            </tr>
                            </thead>
                            <tbody class="text-slate-100 divide-y divide-slate-800">
                            @foreach($performances as $p)
                                @php($scores = $myScores[$p->id] ?? collect())
                                @php($inq = $p->inquiries->first())
                                @php($tone =
                                    $p->status === 'on_deck' ? 'amber' :
                                    ($p->status === 'performing' ? 'blue' :
                                    ($p->status === 'done' ? 'green' : 'gray'))
                                )
                                <tr class="hover:bg-slate-800/40">
                                    <td class="py-3 pr-4 font-medium">{{ $p->start_number ?? '—' }}</td>
                                    <td class="py-3 pr-4">
                                        <div class="font-medium">{{ $p->athlete->last_name }} {{ $p->athlete->first_name }}</div>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <x-badge :tone="$tone">{{ $p->status }}</x-badge>
                                        @if($p->approved_at)
                                            <x-badge tone="violet">approved</x-badge>
                                        @endif
                                        @if($p->published_at)
                                            <x-badge tone="green">published</x-badge>
                                        @endif
                                        @if($inq && $inq->status !== 'decided')
                                            <x-badge tone="amber">inquiry: {{ $inq->status }}</x-badge>
                                        @elseif($inq && $inq->status === 'decided')
                                            <x-badge tone="green">inquiry: decided</x-badge>
                                        @endif
                                    </td>

                                    @foreach($allowedPanels as $panelKey)
                                        <td class="py-3 pr-4">
                                            <form method="POST" action="{{ route('judge.score', $p) }}" class="flex items-center gap-2">
                                                @csrf
                                                <input type="hidden" name="panel" value="{{ $panelKey }}">
                                                <input
                                                    class="w-24 rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 text-sm focus:ring-emerald-500 focus:border-emerald-500"
                                                    name="score"
                                                    inputmode="decimal"
                                                    value="{{ ($scores->where('panel', $panelKey)->first()?->score) ?? '' }}"
                                                    placeholder="{{ $panelKey === 'penalty' ? 'Penalty' : strtoupper($panelKey) }}"
                                                >
                                                <button class="text-emerald-400 hover:text-emerald-300 text-sm font-medium" type="submit">OK</button>
                                            </form>
                                        </td>
                                    @endforeach

                                    <td class="py-3 pr-4 tabular-nums text-slate-200">
                                        {{ $p->total !== null ? number_format($p->total, 3) : '—' }}
                                    </td>
                                    <td class="py-3 text-right whitespace-nowrap">
                                        <div class="inline-flex items-center gap-2">
                                            <form method="POST" action="{{ route('judge.finalize', $p) }}">
                                                @csrf
                                                <x-secondary-button>Итог</x-secondary-button>
                                            </form>

                                            @if($isSupervisor)
                                                @if($inq && $inq->status !== 'decided')
                                                    <form method="POST" action="{{ route('inquiries.underReview', $inq) }}">
                                                        @csrf
                                                        <x-secondary-button>Under review</x-secondary-button>
                                                    </form>
                                                    <form method="POST" action="{{ route('inquiries.decide', $inq) }}" class="inline-flex items-center gap-2">
                                                        @csrf
                                                        <select name="decision" class="rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                                            <option value="accepted">accepted</option>
                                                            <option value="rejected">rejected</option>
                                                            <option value="partially_accepted">partial</option>
                                                        </select>
                                                        <input name="decision_notes" class="w-40 rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 text-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="notes (опц.)">
                                                        <button class="text-emerald-400 hover:text-emerald-300 text-sm font-medium" type="submit">Decide</button>
                                                    </form>
                                                @endif
                                                <form method="POST" action="{{ route('supervisor.approve', $p) }}">
                                                    @csrf
                                                    <x-secondary-button>Утвердить</x-secondary-button>
                                                </form>
                                                <form method="POST" action="{{ route('supervisor.publish', $p) }}">
                                                    @csrf
                                                    <x-secondary-button>Публикация</x-secondary-button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="sm:hidden space-y-3">
                    @foreach($performances as $p)
                        @php($scores = $myScores[$p->id] ?? collect())
                        @php($inq = $p->inquiries->first())
                        @php($tone =
                            $p->status === 'on_deck' ? 'amber' :
                            ($p->status === 'performing' ? 'blue' :
                            ($p->status === 'done' ? 'green' : 'gray'))
                        )

                        <div class="border border-slate-800 rounded-xl p-4 bg-slate-950/40">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="font-medium text-slate-100 truncate">
                                        № {{ $p->start_number ?? '—' }} · {{ $p->athlete->last_name }} {{ $p->athlete->first_name }}
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-2 items-center">
                                        <x-badge :tone="$tone">{{ $p->status }}</x-badge>
                                        @if($p->approved_at)
                                            <x-badge tone="violet">approved</x-badge>
                                        @endif
                                        @if($p->published_at)
                                            <x-badge tone="green">published</x-badge>
                                        @endif
                                        @if($inq && $inq->status !== 'decided')
                                            <x-badge tone="amber">inquiry: {{ $inq->status }}</x-badge>
                                        @elseif($inq && $inq->status === 'decided')
                                            <x-badge tone="green">inquiry: decided</x-badge>
                                        @endif
                                    </div>
                                </div>
                                <div class="text-sm font-semibold tabular-nums text-slate-100">
                                    {{ $p->total !== null ? number_format($p->total, 3) : '—' }}
                                </div>
                            </div>

                            <div class="mt-3 space-y-2">
                                @foreach($allowedPanels as $panelKey)
                                    <form method="POST" action="{{ route('judge.score', $p) }}" class="flex items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="panel" value="{{ $panelKey }}">
                                        <div class="w-20 text-sm text-slate-400">
                                            {{ $panelKey === 'penalty' ? 'Penalty' : strtoupper($panelKey) }}
                                        </div>
                                        <input
                                            class="flex-1 min-w-0 rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 text-sm focus:ring-emerald-500 focus:border-emerald-500"
                                            name="score"
                                            inputmode="decimal"
                                            value="{{ ($scores->where('panel', $panelKey)->first()?->score) ?? '' }}"
                                            placeholder="0.000"
                                        >
                                        <button class="text-emerald-400 hover:text-emerald-300 text-sm font-medium" type="submit">OK</button>
                                    </form>
                                @endforeach
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2 justify-end">
                                <form method="POST" action="{{ route('judge.finalize', $p) }}">
                                    @csrf
                                    <x-secondary-button>Итог</x-secondary-button>
                                </form>

                                @if($isSupervisor)
                                    @if($inq && $inq->status !== 'decided')
                                        <form method="POST" action="{{ route('inquiries.underReview', $inq) }}">
                                            @csrf
                                            <x-secondary-button>Under review</x-secondary-button>
                                        </form>
                                        <form method="POST" action="{{ route('inquiries.decide', $inq) }}" class="flex items-center gap-2">
                                            @csrf
                                            <select name="decision" class="rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                                <option value="accepted">accepted</option>
                                                <option value="rejected">rejected</option>
                                                <option value="partially_accepted">partial</option>
                                            </select>
                                            <input name="decision_notes" class="w-40 rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 text-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="notes (опц.)">
                                            <button class="text-emerald-400 hover:text-emerald-300 text-sm font-medium" type="submit">Decide</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('supervisor.approve', $p) }}">
                                        @csrf
                                        <x-secondary-button>Утвердить</x-secondary-button>
                                    </form>
                                    <form method="POST" action="{{ route('supervisor.publish', $p) }}">
                                        @csrf
                                        <x-secondary-button>Публикация</x-secondary-button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>

