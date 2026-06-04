@php
    $tournament = $tournament ?? null;
    $protocolGroups = $protocolGroups ?? collect();
@endphp

@if($tournament)
    <div id="protocols" class="scroll-mt-6">
        <div class="flex items-center justify-between gap-3 mb-4">
            <div>
                <div class="font-semibold text-slate-100">Итоговые протоколы</div>
                <div class="text-sm text-slate-400 mt-1">
                    Скачать Excel по году рождения и категории (A, B, C…). Доступно секретарю после судейства.
                </div>
            </div>
            <x-badge tone="violet">{{ $protocolGroups->count() }} групп</x-badge>
        </div>

        @if($protocolGroups->isEmpty())
            <div class="rounded-lg border border-slate-800 bg-slate-950/50 px-4 py-3 text-sm text-slate-400">
                Пока нет завершённых результатов. Проведите выступления и дождитесь итоговых оценок —
                здесь появятся кнопки «Скачать Excel» (например «2015 г.р. — категория A»).
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach($protocolGroups as $g)
                    <div class="border border-slate-800 rounded-xl p-4 bg-slate-950/40 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-medium text-slate-100 truncate">{{ $g['label'] }}</div>
                            <div class="text-xs text-slate-500 mt-1">
                                {{ $g['athletes'] }} {{ $g['athletes'] === 1 ? 'гимнастка' : 'гимнасток' }} с результатом
                            </div>
                        </div>
                        @if($g['athletes'] > 0)
                            <a class="shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white shadow-lg shadow-emerald-950/30 hover:bg-emerald-500 transition"
                               href="{{ route('secretary.tournament.protocol', $tournament) }}?birth_year={{ $g['birth_year'] }}&division={{ urlencode((string) ($g['division'] ?? '')) }}"
                               title="Скачать итоговый протокол">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path d="M10 2a1 1 0 0 1 1 1v8.586l2.293-2.293a1 1 0 1 1 1.414 1.414l-4 4a1 1 0 0 1-1.414 0l-4-4a1 1 0 1 1 1.414-1.414L9 11.586V3a1 1 0 0 1 1-1z" />
                                    <path d="M4 16a1 1 0 0 0-1 1 1 1 0 0 0 1 1h12a1 1 0 0 0 1-1 1 1 0 0 0-1-1H4z" />
                                </svg>
                                Скачать
                            </a>
                        @else
                            <span class="shrink-0 text-xs text-slate-500">нет итогов</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif
