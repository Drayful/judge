@php
    $apparatusLabel = $currentPerformance?->apparatus ?? $category->apparatus ?? '—';
    $statusLabel = match ($streamStatus) {
        'waiting_scores' => 'ожидание оценок',
        'finalized' => 'итог зафиксирован',
        'on_deck' => 'вызвана',
        'scheduled' => 'ожидание',
        'done' => 'завершено',
        'empty' => 'поток пуст',
        default => $streamStatus,
    };
    $d = $currentPerformance?->d_score;
    $a = $currentPerformance?->a_score;
    $e = $currentPerformance?->e_score;
    $pen = $currentPerformance?->penalty;
    $sumDisplay = null;
    if ($d !== null && $a !== null && $e !== null) {
        $sumDisplay = (float) $d + (float) $a + (float) $e - (float) ($pen ?? 0);
    }
    $durationBounds = $currentPerformance?->durationBounds();
    $durationNorm = $durationBounds ? sprintf('%d:%02d–%d:%02d', intdiv($durationBounds['min'], 60), $durationBounds['min'] % 60, intdiv($durationBounds['max'], 60), $durationBounds['max'] % 60) : null;
    $canApproveFinal = in_array(auth()->user()?->role, ['secretary', 'organising_committee', 'chief_judge', 'admin', 'super_admin'], true);
    $manualAveragesReady = $currentPerformance
        && \App\Support\SecretaryLiveUi::requiredManualAveragesSubmitted($currentPerformance, $category);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3 w-full">
            <a class="text-sm text-emerald-400 hover:text-emerald-300" href="{{ route('secretary.tournament', $category->tournament_id) }}">← {{ $category->tournament?->name ?? 'Турнир' }}</a>
        </div>
    </x-slot>

    <div class="py-6 space-y-6 max-w-[1600px] mx-auto">
        <x-flash />

        @if($category->tournament)
            <x-card>
                <div class="font-semibold text-slate-100">Импорт стартового протокола (Excel)</div>
                <p class="text-sm text-slate-400 mt-1">
                    Тот же импорт, что на странице турнира. Данные попадают в турнир
                    <span class="text-slate-200">«{{ $category->tournament->name }}»</span>
                    <span class="font-mono text-slate-500">#{{ $category->tournament->id }}</span>
                    — название в файле Excel не выбирает турнир.
                </p>
                <form method="POST" action="{{ route('secretary.tournament.importStartProtocol', $category->tournament) }}" enctype="multipart/form-data" class="mt-4 flex flex-col sm:flex-row sm:items-end gap-3">
                    @csrf
                    <div class="flex-1 min-w-0">
                        <x-input-label for="protocol_queue" value="Файл .xls / .xlsx" />
                        <input id="protocol_queue" name="protocol" type="file" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            class="mt-1 block w-full text-sm text-slate-200 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-800 file:text-emerald-300 hover:file:bg-slate-700 border border-slate-700 rounded-lg bg-slate-950/50" />
                    </div>
                    <x-primary-button class="shrink-0 justify-center">Импортировать потоки</x-primary-button>
                </form>
            </x-card>
        @endif

        {{-- Заголовок Live --}}
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-white">Live — Секретарь</h1>
                <p class="mt-1 text-sm text-slate-400">
                    {{ $category->tournament?->name ?? 'Турнир' }}
                    @if($category->tournament)
                        <span class="text-slate-500">• ID: {{ $category->tournament->id }}</span>
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <span class="inline-flex items-center gap-2 rounded-full border border-slate-700 bg-slate-900/80 px-3 py-1.5 text-slate-200">
                    <span class="h-2 w-2 rounded-full {{ $streamStatus === 'waiting_scores' ? 'bg-amber-400 shadow-[0_0_10px_rgba(251,191,36,0.6)]' : 'bg-emerald-400' }}" title="{{ $statusLabel }}"></span>
                    Статус: <span class="font-mono text-xs text-amber-200/90">{{ $streamStatus }}</span>
                </span>
                <span class="rounded-full border border-slate-700 bg-slate-900/80 px-3 py-1.5 text-slate-300">
                    Поток <span class="font-mono text-emerald-400">#{{ $category->id }}</span>
                </span>
                <span class="rounded-full border border-slate-700 bg-slate-900/80 px-3 py-1.5 text-slate-300">
                    Предмет: <span class="text-white">{{ $apparatusLabel }}</span>
                </span>
            </div>
        </div>

        {{-- Сетка карточек --}}
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            {{-- Управление потоком --}}
            <div class="live-panel p-5 xl:col-span-1">
                <h2 class="text-base font-semibold text-white">Управление потоком</h2>
                <p class="mt-1 text-xs text-slate-500">
                    @if(isset($tournamentCategories) && $tournamentCategories->isNotEmpty() && $category->tournament)
                        Один экран на весь турнир: переключайте поток (из импорта Excel: группа → поток). Выбор предмета сохраняется в карточке выступления.
                    @else
                        Поток «{{ $category->name }}». Выбор предмета сохраняется в карточке выступления.
                    @endif
                </p>

                @if(isset($tournamentCategories) && $tournamentCategories->isNotEmpty() && $category->tournament)
                    <div class="mt-4 flex flex-wrap items-end gap-3">
                        <div class="min-w-[min(100%,280px)] flex-1">
                            <label for="stream_select" class="block text-xs font-medium text-slate-400 mb-1">Поток</label>
                            <select id="stream_select" name="stream"
                                class="block w-full rounded-xl border border-slate-700 bg-slate-950/80 px-3 py-2.5 text-sm text-slate-100 focus:ring-emerald-500 focus:border-emerald-500"
                                onchange="if (this.value) window.location.href = this.value;">
                                @foreach($tournamentCategories as $tc)
                                    <option value="{{ route('secretary.tournament.live', $category->tournament) }}?category={{ $tc->id }}"
                                        @selected($tc->id === $category->id)>
                                        Поток #{{ $tc->id }} · {{ $tc->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        @if(isset($categorySessions) && $categorySessions->isNotEmpty())
                            <div class="min-w-[min(100%,280px)] flex-1">
                                <label for="session_select" class="block text-xs font-medium text-slate-400 mb-1">День / сессия</label>
                                <select id="session_select" class="block w-full rounded-xl border border-sky-800/70 bg-slate-950/80 px-3 py-2.5 text-sm text-slate-100 focus:ring-emerald-500 focus:border-emerald-500" onchange="if (this.value) window.location.href = this.value;">
                                    @foreach($categorySessions as $session)
                                        <option value="{{ route('secretary.tournament.live', $category->tournament) }}?category={{ $category->id }}&session={{ $session->id }}" @selected($streamSession?->id === $session->id)>
                                            {{ $session->scheduled_on?->format('d.m.Y') }}@if($session->starts_at) · {{ substr($session->starts_at, 0, 5) }}@endif · {{ implode(', ', $session->apparatus ?? []) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                @endif

                @if($currentPerformance)
                    <?php $isGroupProgram = $category->program === 'group' || $currentPerformance->athlete?->is_team; ?>
                    <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div class="rounded-xl border border-sky-800/60 bg-sky-950/20 p-3" data-performance-timer
                             data-running="{{ $currentPerformance->timer_started_at && ! $currentPerformance->timer_ended_at ? '1' : '0' }}"
                             data-started-at="{{ $currentPerformance->timer_started_at?->toIso8601String() }}"
                             data-duration="{{ $currentPerformance->actual_duration_seconds }}">
                            <div class="text-[10px] uppercase tracking-wider text-sky-200/70">Фактическое время выступления · хронометрист</div>
                            <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-sky-100" data-performance-timer-value>—</div>
                            <div class="mt-1 text-xs text-slate-400">Норматив: {{ $durationNorm ?? '—' }} · вне норматива: −0,05 за секунду</div>
                            @if((float) ($currentPerformance->time_penalty ?? 0) > 0)
                                <div class="mt-1 text-xs text-rose-300">Сбавка времени: −{{ number_format((float) $currentPerformance->time_penalty, 2, ',', ' ') }}</div>
                            @endif
                        </div>
                        @if($currentPerformance->track)
                            <div class="rounded-xl border border-violet-800/60 bg-violet-950/20 p-3">
                                <div class="text-[10px] uppercase tracking-wider text-violet-200/70">Музыка выхода</div>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <audio id="live-performance-audio" preload="none" src="{{ route('tracks.play', $currentPerformance->track) }}"></audio>
                                    <button type="button" id="live-performance-audio-toggle" class="rounded-lg bg-violet-700 px-3 py-2 text-xs font-semibold text-white hover:bg-violet-600">▶ Запустить музыку</button>
                                    <a href="{{ route('tracks.download', $currentPerformance->track) }}" class="text-xs text-violet-200 hover:underline">Скачать файл</a>
                                </div>
                                <p class="mt-1 text-xs text-slate-400">Музыка запускается отдельно и не влияет на таймер.</p>
                            </div>
                        @endif
                    </div>
                    <div class="mt-4 rounded-xl border border-orange-500/80 bg-orange-950/45 p-4 ring-1 ring-orange-500/50 shadow-lg shadow-orange-950/30">
                        <div class="flex items-center justify-between gap-2">
                            <div class="text-xs uppercase tracking-wider text-slate-500">{{ $isGroupProgram ? 'Текущая команда' : 'Текущая гимнастка' }}</div>
                            @if($isGroupProgram)
                                <span class="rounded-md border border-amber-600/60 bg-amber-900/30 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-100">Групповое выступление</span>
                            @endif
                        </div>
                        <div class="mt-2 text-lg font-medium text-white">
                            {{ $currentPerformance->athlete->last_name }}@if(! $isGroupProgram) {{ $currentPerformance->athlete->first_name }}@endif
                        </div>
                        <div class="mt-1 text-sm text-slate-400">
                            @if(! $isGroupProgram)Год: {{ $currentPerformance->athlete->birthdate?->format('Y') ?? '—' }} • Кат.: — • @endif
                            {{ Str::limit($currentPerformance->athlete->club ?? '—', 48) }}
                        </div>
                        @if($isGroupProgram && $currentPerformance->athlete && $currentPerformance->athlete->members->isNotEmpty())
                            <div class="mt-3">
                                <div class="text-[10px] uppercase tracking-wider text-amber-200/80 mb-1">Состав команды ({{ $currentPerformance->athlete->members->count() }})</div>
                                <ol class="text-sm text-slate-200 list-decimal list-inside space-y-0.5">
                                    @foreach($currentPerformance->athlete->members as $m)
                                        <li>{{ $m->last_name }} {{ $m->first_name }}@if($m->birthdate) <span class="text-slate-500">{{ $m->birthdate->format('Y') }}</span>@endif</li>
                                    @endforeach
                                </ol>
                            </div>
                        @endif
                        <div class="mt-4">
                            <label class="block text-xs font-medium text-slate-400 mb-1">Предмет для этой гимнастки</label>
                            <div class="flex flex-wrap gap-2 items-center">
                                <span class="rounded-lg border border-emerald-700/50 bg-emerald-950/40 px-3 py-2 text-sm text-emerald-100">{{ $apparatusLabel }}</span>
                                <span class="text-xs text-slate-500">Изменить можно в расширенной таблице ниже (поле «Снаряд»).</span>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="mt-4 rounded-xl border border-dashed border-slate-700 p-6 text-center text-slate-500 text-sm">
                        Нет активной гимнастки. Нажмите «Начать поток» или добавьте участниц в очередь.
                    </div>
                @endif

                <div class="mt-5 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('secretary.callNext', $category) }}">
                        @csrf
                        @if($streamSession)<input type="hidden" name="stream_session_id" value="{{ $streamSession->id }}">@endif
                        <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-950/40 hover:bg-emerald-500">
                            {{ $currentPerformance ? 'Следующая гимнастка' : 'Начать поток' }}
                        </button>
                    </form>
                    @if($currentPerformance)
                        <form method="POST" action="{{ route('secretary.finish', $currentPerformance) }}" onsubmit="return confirm('Завершить выступление без следующей?');">
                            @csrf
                            <button type="submit" class="rounded-xl border border-rose-800/80 bg-rose-950/50 px-4 py-2.5 text-sm font-medium text-rose-100 hover:bg-rose-900/60">
                                Завершить
                            </button>
                        </form>
                        <form method="POST" action="{{ route('secretary.performance.withdraw', $currentPerformance) }}"
                              onsubmit="return confirm('Снять {{ $currentPerformance->athlete->last_name }} {{ $currentPerformance->athlete->first_name }} со старта? Стартовый № сохранится, очередь перейдёт к следующей.');">
                            @csrf
                            <button type="submit" class="rounded-xl border border-amber-700/70 bg-amber-950/40 px-4 py-2.5 text-sm font-medium text-amber-100 hover:bg-amber-900/60">
                                Снять со старта
                            </button>
                        </form>
                    @endif
                </div>
                @if($nextPerformance)
                    <p class="mt-3 text-xs text-slate-500">Следом: <span class="text-slate-300">{{ $nextPerformance->athlete->last_name }} {{ $nextPerformance->athlete->first_name }}</span></p>
                @endif
            </div>

            {{-- Порядок выступления --}}
            <div class="live-panel p-5">
                <h2 class="text-base font-semibold text-white">Порядок выступления потока</h2>
                <p class="mt-1 text-xs text-slate-500">Список в порядке выхода. Текущая — подсвечена.</p>
                <ul class="mt-4 max-h-72 space-y-1 overflow-y-auto pr-1 text-sm">
                    <?php $queuePosition = 0; foreach ($orderedPerformances as $p): $queuePosition++; ?>
                        <?php
                            $isCurrent = $currentPerformance && $currentPerformance->id === $p->id;
                            $isWithdrawn = $p->isWithdrawn();
                            $tag = $p->apparatus ?? $category->apparatus ?? '—';
                        ?>
                        <li class="flex items-center gap-3 rounded-lg px-3 py-2.5 {{ $isWithdrawn ? 'bg-slate-950/30 opacity-60' : ($isCurrent ? 'bg-orange-900/60 ring-2 ring-orange-400/80 shadow-md shadow-orange-950/30' : 'bg-slate-950/40 hover:bg-slate-900/60') }}">
                            <span class="text-slate-500 w-6 text-right font-mono">{{ $p->start_number ?? $queuePosition }}</span>
                            <span class="flex-1 min-w-0 truncate {{ $isWithdrawn ? 'text-slate-500 line-through' : 'text-slate-100' }}">{{ $p->athlete->last_name }} {{ $p->athlete->first_name }}</span>
                            <?php if ($isWithdrawn): ?>
                                <span class="shrink-0 rounded-md border border-amber-700/60 bg-amber-950/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-200">снята</span>
                                <form method="POST" action="{{ route('secretary.performance.restore', $p) }}" class="shrink-0">
                                    @csrf
                                    <button type="submit" class="text-xs text-slate-400 hover:text-slate-200 hover:underline" title="Вернуть в очередь">↩</button>
                                </form>
                            <?php else: ?>
                                <span class="shrink-0 rounded-md border border-slate-600 bg-slate-900 px-2 py-0.5 text-xs text-slate-300">{{ $tag }}</span>
                                <?php if ($isCurrent): ?>
                                    <span class="h-2 w-2 shrink-0 rounded-full bg-orange-400 shadow-[0_0_8px_rgba(251,146,60,0.8)]"></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            {{-- Активные судьи --}}
            <div class="live-panel p-5" id="judge-slots-panel">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-white">Состав бригады</h2>
                        <p class="mt-1 text-xs text-slate-500">
                            Ожидание: <span id="judge-slots-waiting">{{ $waitingJudges }}</span>/<span id="judge-slots-active">{{ $activeJudgeSlots }}</span> активных (всего слотов {{ $totalJudgeSlots }}).
                            Нажмите на слот, чтобы выключить его, если судьи в этой позиции нет.
                        </p>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap gap-2" id="judge-slots-grid">
                    @foreach($judgeSlots as $slot)
                        @php
                            $inactive = (bool) ($slot['inactive'] ?? false);
                            $ok = (bool) $slot['ok'];
                        @endphp
                        <button
                            type="button"
                            data-slot="{{ $slot['label'] }}"
                            data-inactive="{{ $inactive ? '1' : '0' }}"
                            data-ok="{{ $ok ? '1' : '0' }}"
                            class="judge-slot-toggle inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-mono transition focus:outline-none focus:ring-2 focus:ring-emerald-500/50 {{ $inactive ? 'border-slate-800 bg-slate-950/40 text-slate-500 line-through opacity-70 hover:opacity-100' : 'border-slate-700 bg-slate-950/50 text-slate-200 hover:border-slate-500' }}"
                            title="{{ $inactive ? 'Слот отключён — клик, чтобы включить' : 'Активный слот — клик, чтобы отключить (нет судьи)' }}"
                        >
                            <span class="h-2 w-2 rounded-full {{ $inactive ? 'bg-slate-600' : ($ok ? 'bg-emerald-400' : 'bg-amber-400') }}"></span>
                            <span class="slot-label">{{ $slot['label'] }}</span>
                            <span class="slot-suffix text-[10px] {{ $inactive ? 'text-slate-500' : 'text-slate-500' }}">{{ $inactive ? 'off' : '' }}</span>
                        </button>
                    @endforeach
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-400"></span>оценка пришла</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-amber-400"></span>ждём</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-slate-600"></span>отключён (не нужен для автоперехода)</span>
                </div>
            </div>
        </div>

        {{-- Оценки --}}
        <div class="live-panel p-5">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-white">Оценки (текущая гимнастка)</h2>
                    <p class="mt-1 text-xs text-slate-500">Судьи вводят оценки в своей панели. Автопереход срабатывает после всех активных слотов, ручных средних DB1/DA1 и завершения официального таймера. Ненужные слоты LINE/TIME/RESP следует отключить в составе бригады. Разброс внутри панели A/E/DB/DA не должен превышать <span class="text-slate-300">{{ number_format($panelSpread['max_spread'] ?? 1.0, 1) }}</span>.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="inline-flex items-center gap-2 rounded-lg border border-emerald-600/80 bg-emerald-950/50 px-2.5 py-1.5 font-medium text-emerald-100">
                        <span class="h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.6)]"></span>
                        Автопереход: всегда включён
                    </span>
                    <span class="rounded-lg border px-2.5 py-1 {{ ($panelSpread['has_violation'] ?? false) ? 'border-rose-700/60 bg-rose-950/40 text-rose-100' : 'border-emerald-800/60 bg-emerald-950/40 text-emerald-100' }}">
                        Расхождение ≤ {{ number_format($panelSpread['max_spread'] ?? 1.0, 1) }}:
                        {{ ($panelSpread['has_violation'] ?? false) ? 'нарушено' : 'ок' }}
                    </span>
                    <span class="rounded-lg border border-slate-700 bg-slate-900 px-2.5 py-1 text-slate-300">Ожидание: <span class="text-amber-200 font-mono">{{ $waitingJudges }}/{{ $activeJudgeSlots }}</span></span>
                    <button type="button" id="total-score-badge"
                        class="rounded-lg border border-teal-700/60 bg-teal-950/40 px-2.5 py-1 text-teal-100 hover:bg-teal-900/50 transition"
                        title="Нажмите — вся история выставления оценок">
                        Итого: <span class="text-white font-mono">{{ \App\Support\SecretaryLiveUi::formatScore($sumDisplay !== null ? (float) $sumDisplay : null) }}</span>
                    </button>
                </div>
            </div>

            @if(($panelSpread['has_violation'] ?? false) && !empty($panelSpread['violations']) && $currentPerformance)
                <div class="mt-4 rounded-xl border border-rose-700/60 bg-rose-950/30 px-4 py-3 text-sm text-rose-100">
                    <div class="font-semibold">Расхождение оценок — нужно решение секретаря / главного судьи</div>
                    <p class="mt-1 text-rose-100/90 text-xs">
                        Разброс превышает {{ number_format($panelSpread['max_spread'], 1) }}. Оценки приняты, автопереход приостановлен.
                        Исправьте или верните на доработку в блоке ниже, либо подтвердите итог как есть.
                    </p>
                    <ul class="mt-2 space-y-1 text-xs font-mono">
                        @foreach($panelSpread['violations'] as $v)
                            <li>
                                {{ $v['label'] }}:
                                min {{ number_format($v['min'], 3) }} · max {{ number_format($v['max'], 3) }}
                                · разброс <span class="text-rose-200 font-bold">{{ number_format($v['spread'], 3) }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if(! $manualAveragesReady)
                        <div class="mt-3 text-xs text-amber-200">Ожидаются отдельные ручные средние от DB1 и DA1.</div>
                    @elseif($canApproveFinal)
                        <form method="POST" action="{{ route('secretary.performance.confirmScore', $currentPerformance) }}" class="mt-3"
                              onsubmit="return confirm('Подтвердить итог несмотря на расхождение оценок?');">
                            @csrf
                            <button type="submit" class="rounded-lg bg-emerald-700 hover:bg-emerald-600 px-4 py-2 text-xs font-semibold text-white shadow">
                                ✓ Подтвердить итог
                            </button>
                        </form>
                    @else
                        <div class="mt-3 text-xs text-amber-200">Итог ожидает подтверждения секретарём или главным судьёй.</div>
                    @endif
                </div>
            @endif

            @php
                $manualAverageRows = $currentPerformance
                    ? \App\Support\SecretaryLiveUi::scoreRowsBySlot($currentPerformance, $category)
                    : [];
                $db1ManualAverage = $manualAverageRows['DB1'] ?? null;
                $da1ManualAverage = $manualAverageRows['DA1'] ?? null;
            @endphp
            <div class="mt-4 grid grid-cols-2 sm:grid-cols-5 gap-3">
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4 text-center">
                    <div class="text-xs text-slate-500 uppercase">D</div>
                    <div class="mt-1 text-2xl font-semibold text-white font-mono">{{ \App\Support\SecretaryLiveUi::formatScore($d !== null ? (float) $d : null) }}</div>
                    <div class="mt-1 text-[10px] text-slate-500">Авто: DB {{ \App\Support\SecretaryLiveUi::formatScore($currentPerformance?->db_average !== null ? (float) $currentPerformance->db_average : null) }} · DA {{ \App\Support\SecretaryLiveUi::formatScore($currentPerformance?->da_average !== null ? (float) $currentPerformance->da_average : null) }}</div>
                    <div class="mt-1 text-[10px] font-semibold text-cyan-300">Ручные: DB1 {{ \App\Support\SecretaryLiveUi::formatScore($db1ManualAverage?->average_submitted_at !== null ? (float) $db1ManualAverage->average_score : null) }} · DA1 {{ \App\Support\SecretaryLiveUi::formatScore($da1ManualAverage?->average_submitted_at !== null ? (float) $da1ManualAverage->average_score : null) }}</div>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4 text-center">
                    <div class="text-xs text-slate-500 uppercase">A</div>
                    <div class="mt-1 text-2xl font-semibold text-white font-mono">{{ \App\Support\SecretaryLiveUi::formatScore($a !== null ? (float) $a : null) }}</div>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4 text-center">
                    <div class="text-xs text-slate-500 uppercase">E</div>
                    <div class="mt-1 text-2xl font-semibold text-white font-mono">{{ \App\Support\SecretaryLiveUi::formatScore($e !== null ? (float) $e : null) }}</div>
                </div>
                <div class="rounded-xl border border-rose-900/40 bg-rose-950/30 p-4 text-center">
                    <div class="text-xs text-rose-200/80 uppercase">Penalty</div>
                    <div class="mt-1 text-2xl font-semibold text-rose-100 font-mono">{{ \App\Support\SecretaryLiveUi::formatScore($pen !== null ? (float) $pen : null) }}</div>
                </div>
                <div class="rounded-xl border border-teal-800/50 bg-teal-950/40 p-4 text-center col-span-2 sm:col-span-1">
                    <div class="text-xs text-teal-200/80 uppercase">D + A + E − Pen</div>
                    <div class="mt-1 text-2xl font-semibold text-teal-50 font-mono">{{ \App\Support\SecretaryLiveUi::formatScore($sumDisplay !== null ? (float) $sumDisplay : null) }}</div>
                </div>
            </div>

            @if($lastCompletedPerformance)
                @php
                    $lastRows = \App\Support\SecretaryLiveUi::scoreRowsBySlot($lastCompletedPerformance, $category);
                    $lastDb1 = $lastRows['DB1'] ?? null;
                    $lastDa1 = $lastRows['DA1'] ?? null;
                @endphp
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-cyan-900/50 bg-cyan-950/20 px-4 py-3 text-xs">
                    <div>
                        <span class="font-semibold text-cyan-100">Последний завершённый результат:</span>
                        <span class="ml-1 text-slate-200">{{ $lastCompletedPerformance->athlete?->last_name }} {{ $lastCompletedPerformance->athlete?->first_name }}</span>
                    </div>
                    <div class="flex flex-wrap gap-x-4 gap-y-1 font-mono text-slate-300">
                        <span>D {{ \App\Support\SecretaryLiveUi::formatScore($lastCompletedPerformance->d_score !== null ? (float) $lastCompletedPerformance->d_score : null) }}</span>
                        <span>A {{ \App\Support\SecretaryLiveUi::formatScore($lastCompletedPerformance->a_score !== null ? (float) $lastCompletedPerformance->a_score : null) }}</span>
                        <span>E {{ \App\Support\SecretaryLiveUi::formatScore($lastCompletedPerformance->e_score !== null ? (float) $lastCompletedPerformance->e_score : null) }}</span>
                        <span class="text-cyan-200">DB1 ср. {{ \App\Support\SecretaryLiveUi::formatScore($lastDb1?->average_submitted_at !== null ? (float) $lastDb1->average_score : null) }}</span>
                        <span class="text-cyan-200">DA1 ср. {{ \App\Support\SecretaryLiveUi::formatScore($lastDa1?->average_submitted_at !== null ? (float) $lastDa1->average_score : null) }}</span>
                        <span class="font-semibold text-white">Итого {{ \App\Support\SecretaryLiveUi::formatScore($lastCompletedPerformance->total !== null ? (float) $lastCompletedPerformance->total : null) }}</span>
                    </div>
                </div>
            @endif

            <div class="mt-5 overflow-x-auto rounded-xl border border-slate-800">
                <table class="w-full min-w-[720px] text-center text-xs">
                    <thead>
                        <tr>
                            @foreach($scoreMatrix['columns'] as $col)
                                @php
                                    $isPen = $scoreMatrix['penalty'][$col] ?? false;
                                    $isOff = $scoreMatrix['inactive'][$col] ?? false;
                                    $isSpread = in_array($col, $panelSpread['violating_slots'] ?? [], true);
                                @endphp
                                <th class="px-2 py-3 font-semibold border-b border-slate-900 {{ $isOff ? 'bg-slate-900/80 text-slate-500 line-through' : ($isSpread ? 'bg-rose-950/90 text-rose-100 ring-1 ring-inset ring-rose-500/50' : ($isPen ? 'bg-rose-950/80 text-rose-100' : 'bg-slate-800 text-slate-200')) }}">{{ $col }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="bg-slate-950/80">
                            @foreach($scoreMatrix['columns'] as $col)
                                @php
                                    $isPen = $scoreMatrix['penalty'][$col] ?? false;
                                    $isOff = $scoreMatrix['inactive'][$col] ?? false;
                                    $isSpread = in_array($col, $panelSpread['violating_slots'] ?? [], true);
                                @endphp
                                <td data-history-slot="{{ $col }}"
                                    class="px-2 py-3 font-mono text-sm border-t border-slate-800 {{ isset($scoreHistory[$col]) ? 'cursor-pointer hover:bg-slate-800/60' : '' }} {{ $isOff ? 'text-slate-500 italic' : ($isSpread ? 'bg-rose-950/40 text-rose-100 font-bold ring-1 ring-inset ring-rose-500/40' : ($isPen ? 'text-rose-100' : 'text-slate-100')) }}"
                                    title="{{ isset($scoreHistory[$col]) ? 'Нажмите — история выставления оценки '.$col : '' }}">{{ $scoreMatrix['values'][$col] }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-5 rounded-xl border border-cyan-900/60 bg-slate-950/45 overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 px-4 py-3">
                    <div>
                        <h3 class="text-sm font-semibold text-cyan-50">Live-действия судей</h3>
                        <p class="mt-0.5 text-xs text-slate-500">Каждое нажатие на планшете: выбор элемента, добавление, отмена и черновой результат. Обновляется автоматически.</p>
                    </div>
                    <span class="rounded-full border border-cyan-800/70 bg-cyan-950/40 px-2.5 py-1 text-xs font-mono text-cyan-100">{{ count($liveJudgeActions ?? []) }} действий</span>
                </div>
                <div class="max-h-72 overflow-y-auto divide-y divide-slate-800/80">
                    @forelse($liveJudgeActions ?? [] as $action)
                        <div class="flex items-start gap-3 px-4 py-3">
                            <div class="shrink-0 rounded-md border border-emerald-800/70 bg-emerald-950/40 px-2 py-1 font-mono text-xs font-bold text-emerald-200">{{ $action['slot'] }}</div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm text-slate-100">{{ $action['action'] }}</div>
                                <div class="mt-0.5 flex flex-wrap gap-x-2 text-[11px] text-slate-500">
                                    <span>{{ $action['judge'] }}</span>
                                    @if($action['entry_label'] !== '')<span>· {{ $action['entry_label'] }}</span>@endif
                                    @if($action['entries_count'] > 0)<span>· элементов: {{ $action['entries_count'] }}</span>@endif
                                    <span>· {{ $action['created_at'] }}</span>
                                </div>
                            </div>
                            @if($action['draft_score'] !== null)
                                <div class="shrink-0 text-right">
                                    <div class="text-[10px] uppercase text-slate-500">Черновик</div>
                                    <div class="font-mono text-sm font-semibold text-cyan-100">{{ $action['draft_score'] }}</div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="px-4 py-7 text-center text-sm text-slate-500">Судьи ещё не сделали действий на планшетах для этой гимнастки.</div>
                    @endforelse
                </div>
            </div>

            {{-- Управление оценками: редактирование и возврат на доработку (любой слот) --}}
            @if($currentPerformance)
                @php
                    $editableSlots = collect($scoreMatrix['columns'])->filter(function ($col) use ($scoreMatrix) {
                        if ($scoreMatrix['inactive'][$col] ?? false) return false;
                        $v = $scoreMatrix['values'][$col] ?? '—';
                        return $v !== '—' && $v !== 'off';
                    })->values();
                @endphp
                <div class="mt-4 rounded-xl border border-slate-700 bg-slate-950/50 p-4">
                    <h3 class="text-sm font-semibold text-white">Управление оценками</h3>
                    <p class="mt-1 text-xs text-slate-500">
                        Исправить оценку секретарём / главным судьёй или вернуть судье на доработку — для любого слота, не только при расхождении.
                        Клик по оценке в таблице выше — история выставления.
                    </p>

                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        @foreach([
                            ['slot' => 'DB1', 'label' => 'Ручная средняя DB', 'row' => $db1ManualAverage],
                            ['slot' => 'DA1', 'label' => 'Ручная средняя DA', 'row' => $da1ManualAverage],
                        ] as $averageItem)
                            @php
                                $averageRow = $averageItem['row'];
                                $averageReady = $averageRow?->average_submitted_at !== null && $averageRow?->average_score !== null;
                            @endphp
                            <div class="rounded-lg border {{ $averageReady ? 'border-cyan-700/60 bg-cyan-950/25' : 'border-amber-800/50 bg-amber-950/20' }} px-3 py-2.5">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <div class="text-[10px] font-semibold uppercase tracking-wider {{ $averageReady ? 'text-cyan-300' : 'text-amber-300' }}">{{ $averageItem['label'] }} · {{ $averageItem['slot'] }}</div>
                                        <div class="mt-0.5 text-xs text-slate-500">Второй ручной ввод после основной оценки</div>
                                    </div>
                                    <div class="font-mono text-2xl font-bold tabular-nums {{ $averageReady ? 'text-cyan-100' : 'text-amber-200' }}">
                                        {{ $averageReady ? number_format((float) $averageRow->average_score, 3, '.', '') : 'ожидание' }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if($editableSlots->isEmpty())
                        <p class="mt-3 text-sm text-slate-500">Пока нет выставленных оценок для редактирования.</p>
                    @else
                        <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            @foreach($editableSlots as $col)
                                @php
                                    $isPen = $scoreMatrix['penalty'][$col] ?? false;
                                    $isSpread = in_array($col, $panelSpread['violating_slots'] ?? [], true);
                                    $cur = $scoreMatrix['values'][$col];
                                @endphp
                                <div class="rounded-lg border px-3 py-2.5 {{ $isSpread ? 'border-rose-700/60 bg-rose-950/20' : ($isPen ? 'border-rose-900/40 bg-rose-950/10' : 'border-slate-800 bg-slate-900/40') }}">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-mono text-xs font-bold {{ $isSpread ? 'text-rose-200' : 'text-emerald-300' }}">{{ $col }}</span>
                                        <span class="font-mono text-sm text-white tabular-nums">{{ $cur }}</span>
                                    </div>
                                    @if(isset($scoreHistory[$col]['judge']))
                                        <div class="mt-0.5 text-[10px] text-slate-500 truncate">{{ $scoreHistory[$col]['judge'] }}</div>
                                    @endif
                                    <form method="POST" action="{{ route('secretary.performance.updateJudgeScore', $currentPerformance) }}" class="mt-2 flex items-center gap-1.5">
                                        @csrf
                                        <input type="hidden" name="slot" value="{{ $col }}">
                                        <input type="number" name="score" step="0.001" min="0" max="99.999" value="{{ $cur }}" required
                                               class="flex-1 min-w-0 rounded-md border border-slate-700 bg-slate-950 text-slate-100 text-xs py-1 px-2 font-mono tabular-nums">
                                        <button type="submit" class="shrink-0 rounded-md border border-amber-700/60 bg-amber-900/30 px-2 py-1 text-[10px] text-amber-100 hover:bg-amber-800/40" title="Сохранить исправление">
                                            ✓
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('secretary.performance.returnScores', $currentPerformance) }}" class="mt-1.5"
                                          @if(! $isPen) onsubmit="return confirm('Вернуть оценку {{ $col }} судье на доработку?');" @endif>
                                        @csrf
                                        <input type="hidden" name="slot" value="{{ $col }}">
                                        <button type="submit" class="w-full rounded-md border border-slate-700 bg-slate-900/60 px-2 py-1 text-[10px] text-slate-300 hover:bg-slate-800 hover:text-slate-100">
                                            ↩ На доработку судье
                                        </button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-800 pt-3">
                        <span class="w-full text-[10px] uppercase tracking-wider text-slate-500 mb-1">Вернуть панель целиком</span>
                        @foreach(['db' => 'DB', 'da' => 'DA', 'a' => 'A', 'e' => 'E', 'penalty' => 'Штрафы'] as $pKey => $pLabel)
                            <form method="POST" action="{{ route('secretary.performance.returnScores', $currentPerformance) }}" class="inline"
                                  @if($pKey !== 'penalty') onsubmit="return confirm('Вернуть все оценки панели {{ $pLabel }} судьям?');" @endif>
                                @csrf
                                <input type="hidden" name="panel" value="{{ $pKey }}">
                                <button type="submit" class="rounded-md border border-slate-700 bg-slate-900 px-2.5 py-1.5 text-xs text-slate-300 hover:bg-slate-800 hover:text-white">
                                    ↩ {{ $pLabel }}
                                </button>
                            </form>
                        @endforeach
                        <form method="POST" action="{{ route('secretary.performance.returnScores', $currentPerformance) }}" class="inline"
                              onsubmit="return confirm('Вернуть ВСЕ оценки всем судьям? Итог будет сброшен.');">
                            @csrf
                            <input type="hidden" name="panel" value="all">
                            <button type="submit" class="rounded-md border border-rose-800/70 bg-rose-950/50 px-2.5 py-1.5 text-xs text-rose-200 hover:bg-rose-900/60">
                                ↩ Все оценки
                            </button>
                        </form>
                        @if($currentPerformance->approved_at === null && \App\Support\SecretaryLiveUi::requiredScoresSubmitted($currentPerformance, $category))
                            @if(! $manualAveragesReady)
                                <span class="ml-auto text-xs text-amber-200">Ожидаются ручные средние DB1 и DA1</span>
                            @elseif($canApproveFinal)
                                <form method="POST" action="{{ route('secretary.performance.confirmScore', $currentPerformance) }}" class="inline ml-auto"
                                      onsubmit="return confirm('Подтвердить и зафиксировать итог?');">
                                    @csrf
                                    <button type="submit" class="rounded-md border border-emerald-700/70 bg-emerald-900/40 px-3 py-1.5 text-xs font-semibold text-emerald-100 hover:bg-emerald-800/50">
                                        ✓ Подтвердить итог
                                    </button>
                                </form>
                            @else
                                <span class="ml-auto text-xs text-amber-200">Ожидается подтверждение секретаря или главного судьи</span>
                            @endif
                        @endif
                    </div>

                    {{-- Ручное выставление финальной оценки (секретарь / главный судья) --}}
                    <div class="mt-4 rounded-xl border {{ $currentPerformance->scores_overridden ? 'border-amber-600/60 bg-amber-950/20' : 'border-slate-700 bg-slate-950/40' }} p-4">
                        <div class="flex items-center justify-between gap-2">
                            <h4 class="text-sm font-semibold text-white">Финальная оценка вручную</h4>
                            @if($currentPerformance->scores_overridden)
                                <span class="rounded-md border border-amber-600/60 bg-amber-900/30 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-100">Ручной режим</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            Проставьте итоговые D / A / E и штраф напрямую — без ожидания судей. Пока включён ручной режим,
                            оценки судей не пересчитывают итог. Итог фиксируется сразу.
                        </p>

                        <form method="POST" action="{{ route('secretary.performance.setFinalScore', $currentPerformance) }}"
                              class="mt-3 grid grid-cols-2 sm:grid-cols-5 gap-2 items-end"
                              onsubmit="return confirm('Выставить финальную оценку вручную и зафиксировать итог?');">
                            @csrf
                            <div>
                                <label class="block text-[10px] uppercase tracking-wider text-slate-500">D</label>
                                <input type="number" name="d_score" step="0.001" min="0" max="99.999" required
                                       value="{{ old('d_score', $d !== null ? number_format((float) $d, 3, '.', '') : '') }}"
                                       class="mt-1 w-full rounded-md border border-slate-700 bg-slate-950 text-slate-100 text-sm py-1.5 px-2 font-mono tabular-nums">
                            </div>
                            <div>
                                <label class="block text-[10px] uppercase tracking-wider text-slate-500">A</label>
                                <input type="number" name="a_score" step="0.001" min="0" max="99.999" required
                                       value="{{ old('a_score', $a !== null ? number_format((float) $a, 3, '.', '') : '') }}"
                                       class="mt-1 w-full rounded-md border border-slate-700 bg-slate-950 text-slate-100 text-sm py-1.5 px-2 font-mono tabular-nums">
                            </div>
                            <div>
                                <label class="block text-[10px] uppercase tracking-wider text-slate-500">E</label>
                                <input type="number" name="e_score" step="0.001" min="0" max="99.999" required
                                       value="{{ old('e_score', $e !== null ? number_format((float) $e, 3, '.', '') : '') }}"
                                       class="mt-1 w-full rounded-md border border-slate-700 bg-slate-950 text-slate-100 text-sm py-1.5 px-2 font-mono tabular-nums">
                            </div>
                            <div>
                                <label class="block text-[10px] uppercase tracking-wider text-rose-300/80">Штраф</label>
                                <input type="number" name="penalty" step="0.001" min="0" max="99.999"
                                       value="{{ old('penalty', $pen !== null ? number_format((float) $pen, 3, '.', '') : '') }}"
                                       class="mt-1 w-full rounded-md border border-rose-900/50 bg-slate-950 text-rose-100 text-sm py-1.5 px-2 font-mono tabular-nums">
                            </div>
                            <button type="submit" class="rounded-md border border-amber-600/70 bg-amber-800/40 px-3 py-2 text-xs font-semibold text-amber-50 hover:bg-amber-700/50">
                                Выставить итог
                            </button>
                        </form>

                        @error('d_score') <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror
                        @error('a_score') <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror
                        @error('e_score') <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror
                        @error('penalty') <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror

                        @if($currentPerformance->scores_overridden)
                            <form method="POST" action="{{ route('secretary.performance.clearFinalOverride', $currentPerformance) }}" class="mt-3"
                                  onsubmit="return confirm('Снять ручной режим? Итог снова будет считаться по оценкам судей.');">
                                @csrf
                                <button type="submit" class="text-xs text-slate-400 hover:text-slate-200 hover:underline">
                                    ↩ Снять ручной режим (считать по судьям)
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- История потока --}}
        <div class="live-panel p-5">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <div>
                    <h2 class="text-base font-semibold text-white">История гимнасток потока</h2>
                    <p class="mt-1 text-xs text-slate-500">Итоги по выступлениям в этом потоке (после расчёта).</p>
                </div>
                <span class="text-xs rounded-lg border px-3 py-1 {{ ($panelSpread['has_violation'] ?? false) ? 'border-rose-700/60 bg-rose-950/40 text-rose-100' : 'border-emerald-800/60 bg-emerald-950/40 text-emerald-100' }}">
                    Расхождение ≤ {{ number_format($panelSpread['max_spread'] ?? 1.0, 1) }}: {{ ($panelSpread['has_violation'] ?? false) ? 'нарушено' : 'ок' }}
                </span>
            </div>
            <div class="overflow-x-auto rounded-xl border border-slate-800">
                <table class="w-full min-w-[2260px] text-sm">
                    <thead>
                        <tr class="border-b border-slate-800 bg-slate-900/90 text-left text-xs uppercase tracking-wide text-slate-400">
                            <th class="px-3 py-3">#</th>
                            <th class="px-3 py-3">Гимнастка</th>
                            <th class="px-3 py-3">Предмет</th>
                            @foreach($historyJudgeColumns as $judgeColumn)
                                <th class="px-2 py-3 text-right font-mono text-[11px]">{{ $judgeColumn }}</th>
                            @endforeach
                            <th class="px-3 py-3 text-right">D</th>
                            <th class="px-3 py-3 text-right">A</th>
                            <th class="px-3 py-3 text-right">E</th>
                            <th class="px-3 py-3 text-right text-rose-200">Pen.</th>
                            <th class="px-3 py-3 text-right text-teal-200">Итого</th>
                            <th class="px-3 py-3 text-right">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @foreach($orderedPerformances as $p)
                            <tr class="hover:bg-slate-900/50 {{ $currentPerformance && $currentPerformance->id === $p->id ? 'bg-orange-950/40' : '' }}">
                                <td class="px-3 py-2.5 font-mono text-slate-500">{{ $loop->iteration }}</td>
                                <td class="px-3 py-2.5 text-slate-100">{{ $p->athlete->last_name }} {{ $p->athlete->first_name }}</td>
                                <td class="px-3 py-2.5 text-slate-400">{{ $p->apparatus ?? $category->apparatus ?? '—' }}</td>
                                @foreach($historyJudgeColumns as $judgeColumn)
                                    @php($judgeHistory = $scoreHistoryByPerformance[$p->id]['slots'][$judgeColumn] ?? null)
                                    <td class="px-2 py-2.5 text-right font-mono">
                                        @if($judgeHistory)
                                            <button type="button"
                                                data-stream-history-score
                                                data-performance-id="{{ $p->id }}"
                                                data-slot="{{ $judgeColumn }}"
                                                class="rounded px-1.5 py-1 text-emerald-200 underline decoration-emerald-700/60 underline-offset-2 hover:bg-emerald-950/60 hover:text-white"
                                                title="Нажмите, чтобы посмотреть и исправить оценку {{ $judgeColumn }}">
                                                {{ $judgeHistory['score'] }}
                                            </button>
                                        @else
                                            <span class="text-slate-600">—</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-3 py-2.5 text-right font-mono text-slate-200">{{ \App\Support\SecretaryLiveUi::formatScore($p->d_score !== null ? (float) $p->d_score : null) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-slate-200">{{ \App\Support\SecretaryLiveUi::formatScore($p->a_score !== null ? (float) $p->a_score : null) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-slate-200">{{ \App\Support\SecretaryLiveUi::formatScore($p->e_score !== null ? (float) $p->e_score : null) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-rose-200/90">{{ \App\Support\SecretaryLiveUi::formatScore($p->penalty !== null ? (float) $p->penalty : null) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-teal-200">{{ \App\Support\SecretaryLiveUi::formatScore($p->total !== null ? (float) $p->total : null) }}</td>
                                <td class="px-3 py-2.5 text-right">
                                    @if(! $p->isWithdrawn())
                                        <button
                                            type="button"
                                            data-manual-score
                                            data-action="{{ route('secretary.performance.setFinalScore', $p) }}"
                                            data-athlete="{{ trim($p->athlete->last_name.' '.$p->athlete->first_name) }}"
                                            data-apparatus="{{ $p->apparatus ?? $category->apparatus ?? '—' }}"
                                            data-d-score="{{ $p->d_score }}"
                                            data-a-score="{{ $p->a_score }}"
                                            data-e-score="{{ $p->e_score }}"
                                            data-penalty="{{ $p->penalty }}"
                                            data-will-unpublish="{{ $p->approved_at !== null || $p->published_at !== null || $p->scoreboard_accepted_at !== null ? '1' : '0' }}"
                                            class="whitespace-nowrap rounded-md border border-orange-600/70 bg-orange-900/35 px-2.5 py-1.5 text-xs font-semibold text-orange-100 hover:bg-orange-800/50"
                                        >
                                            {{ $p->scores_overridden ? 'Изменить вручную' : 'Выставить вручную' }}
                                        </button>
                                    @else
                                        <span class="text-xs text-slate-600">Недоступно</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Расширенное управление (очередь, музыка, drag) --}}
        <details class="live-panel p-5 group" open>
            <summary class="cursor-pointer list-none text-base font-semibold text-white flex items-center gap-2">
                <span class="text-slate-500 group-open:rotate-90 transition inline-block">▸</span>
                Расширенное управление очередью
            </summary>
            <div class="mt-5 space-y-5">
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <div class="font-medium text-slate-200">Записать атлета в категорию</div>
                        <a class="text-emerald-400 hover:text-emerald-300 text-sm font-medium" href="{{ route('secretary.athletes') }}">Атлеты →</a>
                    </div>
                    <form method="POST" action="{{ route('secretary.queue.add', $category) }}" class="grid grid-cols-1 md:grid-cols-6 gap-3">
                        @csrf
                        @if($streamSession)
                            <input type="hidden" name="stream_session_id" value="{{ $streamSession->id }}">
                        @endif
                        <div class="md:col-span-2">
                            <x-input-label value="Атлет" />
                            <select name="athlete_id" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 focus:ring-emerald-500 focus:border-emerald-500" required>
                                @foreach($athletes as $a)
                                    <option value="{{ $a->id }}">{{ $a->last_name }} {{ $a->first_name }}{{ $a->club ? ' · '.$a->club : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label value="Вид / предмет" />
                            <select name="apparatus" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">{{ $category->apparatus ?? '— выберите —' }}</option>
                                @foreach(\App\Support\PerformanceApparatus::RG_APPARATUS as $apparatus)
                                    <option value="{{ $apparatus }}">{{ $apparatus }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label value="Старт № (опц.)" />
                            <x-text-input name="start_number" type="number" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label value="Позиция (опц.)" />
                            <x-text-input name="position" type="number" class="mt-1 block w-full" placeholder="в конец" />
                        </div>
                        <div class="flex items-end justify-end">
                            <x-primary-button>Добавить</x-primary-button>
                        </div>
                    </form>
                </div>

                <div id="secretary-music-upload" class="rounded-xl border border-violet-900/40 bg-slate-950/50 p-4 scroll-mt-24">
                    <div class="font-medium text-slate-200">Музыка для выхода</div>
                    <p class="text-xs text-slate-500 mt-1 mb-3">
                        Гимнастке не нужен свой аккаунт: файл привязывается к строке выхода в этом потоке (то же хранилище, что и при загрузке спортсменкой). После дедлайна обмена музыкой загружает только секретариат / администратор.
                    </p>
                    @if($performances->isEmpty())
                        <p class="text-sm text-slate-500">В этом потоке пока нет выходов — сначала импортируйте протокол или добавьте гимнасток в очередь.</p>
                    @else
                    <form method="POST" action="{{ route('secretary.category.performance.music', $category) }}" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-12 gap-3 items-end">
                        @csrf
                        <div class="lg:col-span-5">
                            <x-input-label for="sec_perf_music" value="Выход" />
                            <select id="sec_perf_music" name="performance_id" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 focus:ring-emerald-500 focus:border-emerald-500" required>
                                <?php $defPerfId = old('performance_id', optional($performances->first())->id); ?>
                                @foreach($performances as $p)
                                    <?php $lab = ($p->start_number ?? '—').' · '.($p->apparatus ?? $category->apparatus ?? '—').' · '.$p->athlete->last_name.' '.$p->athlete->first_name; ?>
                                    <option value="{{ $p->id }}" @selected((string) $defPerfId === (string) $p->id)>{{ $lab }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('performance_id')" class="mt-2" />
                        </div>
                        <div class="lg:col-span-2">
                            <x-input-label for="sec_music_type" value="Тип" />
                            <select id="sec_music_type" name="type" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="primary">Основной</option>
                                <option value="backup">Резерв</option>
                            </select>
                        </div>
                        <div class="lg:col-span-4">
                            <x-input-label for="sec_music_file" value="Файл (mp3 / m4a / wav, до 30 МБ)" />
                            <input id="sec_music_file" name="music" type="file" required class="mt-1 block w-full text-sm text-slate-200 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-800 file:text-emerald-300" />
                            <x-input-error :messages="$errors->get('music')" class="mt-2" />
                        </div>
                        <div class="lg:col-span-1 flex justify-end">
                            <x-primary-button class="w-full justify-center">ОК</x-primary-button>
                        </div>
                    </form>
                    @endif
                </div>

                <div class="flex items-center justify-between gap-3">
                    <x-badge tone="violet">{{ $performances->count() }} выходов</x-badge>
                    <span class="text-xs text-slate-500">Перетаскивание строк сохраняет порядок. На мобильных — кнопки ↑ / ↓.</span>
                </div>

                <div class="-mx-2 px-2">
                    {{-- Десктоп / планшет: таблица с drag-and-drop --}}
                    <div class="hidden sm:block overflow-x-auto">
                        <table class="w-full text-sm table-fixed min-w-[900px]">
                            <thead class="text-left text-slate-400">
                                <tr class="border-b border-slate-800">
                                    <th class="py-3 pr-2 font-medium w-8"></th>
                                    <th class="py-3 pr-4 font-medium w-16">№</th>
                                    <th class="py-3 pr-4 font-medium">Спортсменка</th>
                                    <th class="py-3 pr-4 font-medium w-28">Предмет</th>
                                    <th class="py-3 pr-4 font-medium w-48">Клуб</th>
                                    <th class="py-3 pr-4 font-medium w-28">Статус</th>
                                    <th class="py-3 pr-4 font-medium w-28">План / факт</th>
                                    <th class="py-3 pr-4 font-medium w-36">Музыка</th>
                                    <th class="py-3 text-right font-medium w-56">Действия</th>
                                </tr>
                            </thead>
                            <tbody id="queue-body" class="text-slate-100 divide-y divide-slate-800">
                                @foreach($performances as $p)
                                    <?php $t = $p->track; ?>
                                    <?php $inq = $p->inquiries->first(); ?>
                                    <?php $tone =
                                        $p->status === 'on_deck' ? 'amber' :
                                        ($p->status === 'performing' ? 'blue' :
                                        ($p->status === 'done' ? 'green' : 'gray'))
                                    ; ?>
                                    <tr class="hover:bg-slate-800/40" data-performance-id="{{ $p->id }}" data-queue-locked="{{ $p->status === 'scheduled' ? '0' : '1' }}">
                                        <td class="py-3 pr-2 text-slate-500">
                                            <button type="button" class="drag-handle cursor-grab active:cursor-grabbing select-none px-1" title="Перетащить">⋮⋮</button>
                                        </td>
                                        <td class="py-3 pr-4 font-medium">{{ $p->start_number ?? '—' }}</td>
                                        <td class="py-3 pr-4 font-medium">{{ $p->athlete->last_name }} {{ $p->athlete->first_name }}</td>
                                        <td class="py-3 pr-4"><x-badge :tone="$tone === 'gray' ? 'violet' : $tone">{{ $p->apparatus ?? $category->apparatus ?? '—' }}</x-badge></td>
                                        <td class="py-3 pr-4 text-slate-400 truncate">{{ $p->athlete->club ?? '—' }}</td>
                                        <td class="py-3 pr-4"><x-badge :tone="$tone">{{ $p->status }}</x-badge></td>
                                        <td class="py-3 pr-4 text-xs text-slate-300">
                                            <div class="font-mono text-emerald-200">{{ $p->scheduled_at_label ?? '—' }}</div>
                                            @if($p->actual_duration_seconds !== null)
                                                <div class="mt-0.5 font-mono text-sky-200" title="Фактическое время выступления">
                                                    факт {{ intdiv($p->actual_duration_seconds, 60) }}:{{ str_pad((string) ($p->actual_duration_seconds % 60), 2, '0', STR_PAD_LEFT) }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="py-3 pr-4 text-xs">
                                            @if($t)
                                                <a class="text-emerald-400 hover:underline" href="{{ route('tracks.download', $t) }}">Файл</a>
                                            @else
                                                <span class="text-slate-500">нет</span>
                                            @endif
                                            <a href="#secretary-music-upload" class="block mt-1 text-violet-400/90 hover:underline">загрузить</a>
                                        </td>
                                        <td class="py-3 text-right">
                                            <div class="inline-flex items-center gap-2">
                                                <form method="POST" action="{{ route('secretary.queue.move', $p) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="dir" value="up">
                                                    <button type="submit" class="text-xs rounded-md border border-slate-700 px-2 py-1 text-slate-300 hover:bg-slate-800" title="Выше">↑</button>
                                                </form>
                                                <form method="POST" action="{{ route('secretary.queue.move', $p) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="dir" value="down">
                                                    <button type="submit" class="text-xs rounded-md border border-slate-700 px-2 py-1 text-slate-300 hover:bg-slate-800" title="Ниже">↓</button>
                                                </form>
                                                <form method="POST" action="{{ route('secretary.queue.remove', $p) }}" class="inline" onsubmit="return confirm('Удалить «{{ $p->athlete->last_name }} {{ $p->athlete->first_name }}» из очереди?');">
                                                    @csrf
                                                    <button type="submit" class="text-xs rounded-md border border-rose-800/80 bg-rose-950/40 px-2 py-1 text-rose-200 hover:bg-rose-900/60">Удалить</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Мобильный список — карточки с кнопками ↑ / ↓ / удалить (drag тяжело на touch) --}}
                    <ul id="queue-body-mobile" class="sm:hidden mt-2 space-y-2">
                        @foreach($performances as $p)
                            <?php $t = $p->track; ?>
                            <?php $tone =
                                $p->status === 'on_deck' ? 'amber' :
                                ($p->status === 'performing' ? 'blue' :
                                ($p->status === 'done' ? 'green' : 'gray'))
                            ; ?>
                            <li class="rounded-xl border border-slate-800 bg-slate-950/40 p-3" data-performance-id="{{ $p->id }}" data-queue-locked="{{ $p->status === 'scheduled' ? '0' : '1' }}">
                                <div class="flex items-start gap-2">
                                    <button type="button" class="drag-handle shrink-0 cursor-grab select-none rounded-md border border-slate-700 px-2 py-1 text-slate-500 hover:bg-slate-800" title="Перетащить">⋮⋮</button>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium text-slate-100 truncate">
                                            № {{ $p->start_number ?? '—' }} · {{ $p->athlete->last_name }} {{ $p->athlete->first_name }}
                                        </div>
                                        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                                            <x-badge :tone="$tone === 'gray' ? 'violet' : $tone">{{ $p->apparatus ?? $category->apparatus ?? '—' }}</x-badge>
                                            <x-badge :tone="$tone">{{ $p->status }}</x-badge>
                                            <span class="text-slate-500 truncate">{{ $p->athlete->club ?? '—' }}</span>
                                            @if($p->scheduled_at_label)
                                                <span class="font-mono text-emerald-200">план {{ $p->scheduled_at_label }}</span>
                                            @endif
                                            @if($p->actual_duration_seconds !== null)
                                                <span class="font-mono text-sky-200">факт {{ intdiv($p->actual_duration_seconds, 60) }}:{{ str_pad((string) ($p->actual_duration_seconds % 60), 2, '0', STR_PAD_LEFT) }}</span>
                                            @endif
                                        </div>
                                        <div class="mt-1 text-xs">
                                            @if($t)
                                                <a class="text-emerald-400 hover:underline" href="{{ route('tracks.download', $t) }}">Музыка: файл</a>
                                            @else
                                                <a href="#secretary-music-upload" class="text-violet-400/90 hover:underline">Загрузить музыку</a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3 flex items-center justify-end gap-2">
                                    <form method="POST" action="{{ route('secretary.queue.move', $p) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="dir" value="up">
                                        <button type="submit" class="text-xs rounded-md border border-slate-700 px-3 py-1.5 text-slate-200 hover:bg-slate-800" title="Выше">↑</button>
                                    </form>
                                    <form method="POST" action="{{ route('secretary.queue.move', $p) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="dir" value="down">
                                        <button type="submit" class="text-xs rounded-md border border-slate-700 px-3 py-1.5 text-slate-200 hover:bg-slate-800" title="Ниже">↓</button>
                                    </form>
                                    <form method="POST" action="{{ route('secretary.queue.remove', $p) }}" class="inline" onsubmit="return confirm('Удалить «{{ $p->athlete->last_name }} {{ $p->athlete->first_name }}» из очереди?');">
                                        @csrf
                                        <button type="submit" class="text-xs rounded-md border border-rose-800/80 bg-rose-950/40 px-3 py-1.5 text-rose-200 hover:bg-rose-900/60">Удалить</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </details>
    </div>
{{-- ===== Модалка: ручная оценка выбранной гимнастки ===== --}}
<div id="manual-score-modal" data-pause-live-refresh="1" class="hidden fixed inset-0 z-[60]">
    <div class="absolute inset-0 bg-black/70" data-manual-score-close></div>
    <div class="relative mx-auto mt-12 w-[min(94vw,720px)] max-h-[82vh] overflow-y-auto rounded-2xl border border-orange-600/70 bg-slate-950 p-5 shadow-2xl shadow-orange-950/40">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="text-[10px] font-semibold uppercase tracking-[0.18em] text-orange-300">Ручная финальная оценка</div>
                <h3 id="manual-score-athlete" class="mt-1 text-lg font-semibold text-white">Гимнастка</h3>
                <p id="manual-score-apparatus" class="mt-0.5 text-sm text-slate-400"></p>
            </div>
            <button type="button" data-manual-score-close class="rounded-lg border border-slate-700 px-2.5 py-1 text-sm text-slate-300 hover:bg-slate-800">✕</button>
        </div>

        <div id="manual-score-unpublish-warning" class="mt-4 hidden rounded-lg border border-amber-700/60 bg-amber-950/40 px-3 py-2 text-xs text-amber-100">
            Результат уже был утверждён или опубликован. Сохранение ручной оценки снимет утверждение и публикацию — результат потребуется проверить повторно.
        </div>

        <form id="manual-score-form" method="POST" class="mt-5" onsubmit="return confirm('Сохранить ручной итог для выбранной гимнастки?');">
            @csrf
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div>
                    <label for="manual-score-d" class="block text-[10px] uppercase tracking-wider text-slate-400">D</label>
                    <input id="manual-score-d" name="d_score" type="number" step="0.001" min="0" max="99.999" required
                           class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2.5 text-center font-mono text-xl text-white focus:border-orange-500 focus:ring-orange-500">
                </div>
                <div>
                    <label for="manual-score-a" class="block text-[10px] uppercase tracking-wider text-slate-400">A</label>
                    <input id="manual-score-a" name="a_score" type="number" step="0.001" min="0" max="99.999" required
                           class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2.5 text-center font-mono text-xl text-white focus:border-orange-500 focus:ring-orange-500">
                </div>
                <div>
                    <label for="manual-score-e" class="block text-[10px] uppercase tracking-wider text-slate-400">E</label>
                    <input id="manual-score-e" name="e_score" type="number" step="0.001" min="0" max="99.999" required
                           class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2.5 text-center font-mono text-xl text-white focus:border-orange-500 focus:ring-orange-500">
                </div>
                <div>
                    <label for="manual-score-penalty" class="block text-[10px] uppercase tracking-wider text-rose-300">Штраф</label>
                    <input id="manual-score-penalty" name="penalty" type="number" step="0.001" min="0" max="99.999"
                           class="mt-1 w-full rounded-lg border border-rose-900/70 bg-slate-900 px-3 py-2.5 text-center font-mono text-xl text-rose-100 focus:border-orange-500 focus:ring-orange-500">
                </div>
            </div>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-slate-800 pt-4">
                <div>
                    <div class="text-[10px] uppercase tracking-wider text-slate-500">Предварительный итог</div>
                    <div id="manual-score-total" class="font-mono text-3xl font-bold tabular-nums text-orange-200">—</div>
                </div>
                <div class="flex gap-2">
                    <button type="button" data-manual-score-close class="rounded-lg border border-slate-700 px-4 py-2 text-sm text-slate-300 hover:bg-slate-800">Отмена</button>
                    <button type="submit" class="rounded-lg bg-orange-600 px-4 py-2 text-sm font-bold text-white shadow-lg shadow-orange-950/40 hover:bg-orange-500">Сохранить итог</button>
                </div>
            </div>
        </form>
    </div>
</div>
{{-- ===== Модалка: история выставления оценки ===== --}}
<div id="score-history-modal" class="hidden fixed inset-0 z-50">
    <div class="absolute inset-0 bg-black/60" data-history-close></div>
    <div class="relative mx-auto mt-16 w-[min(92vw,760px)] max-h-[75vh] overflow-y-auto rounded-2xl border border-slate-700 bg-slate-950 p-5 shadow-2xl">
        <div class="flex items-start justify-between gap-3">
            <h3 id="score-history-title" class="text-base font-semibold text-white">История выставления оценки</h3>
            <button type="button" data-history-close class="rounded-lg border border-slate-700 px-2.5 py-1 text-sm text-slate-300 hover:bg-slate-800">✕</button>
        </div>
        <div id="score-history-body" class="mt-4 space-y-4 text-sm text-slate-200"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(() => {
    const modal = document.getElementById('manual-score-modal');
    const form = document.getElementById('manual-score-form');
    if (!modal || !form) return;

    const athlete = document.getElementById('manual-score-athlete');
    const apparatus = document.getElementById('manual-score-apparatus');
    const warning = document.getElementById('manual-score-unpublish-warning');
    const total = document.getElementById('manual-score-total');
    const fields = {
        d: document.getElementById('manual-score-d'),
        a: document.getElementById('manual-score-a'),
        e: document.getElementById('manual-score-e'),
        penalty: document.getElementById('manual-score-penalty'),
    };

    const formatInput = (value, fallback = '') => {
        if (value === undefined || value === null || value === '') return fallback;
        const number = Number(value);
        return Number.isFinite(number) ? number.toFixed(3) : fallback;
    };
    const updateTotal = () => {
        const d = Number(fields.d.value);
        const a = Number(fields.a.value);
        const e = Number(fields.e.value);
        const penalty = fields.penalty.value === '' ? 0 : Number(fields.penalty.value);
        total.textContent = [d, a, e, penalty].every(Number.isFinite)
            ? (d + a + e - penalty).toFixed(3)
            : '—';
    };
    const close = () => modal.classList.add('hidden');

    document.querySelectorAll('[data-manual-score]').forEach((button) => {
        button.addEventListener('click', () => {
            form.action = button.dataset.action || '';
            athlete.textContent = button.dataset.athlete || 'Гимнастка';
            apparatus.textContent = 'Предмет: ' + (button.dataset.apparatus || '—');
            fields.d.value = formatInput(button.dataset.dScore);
            fields.a.value = formatInput(button.dataset.aScore);
            fields.e.value = formatInput(button.dataset.eScore);
            fields.penalty.value = formatInput(button.dataset.penalty, '0.000');
            warning.classList.toggle('hidden', button.dataset.willUnpublish !== '1');
            updateTotal();
            modal.classList.remove('hidden');
            fields.d.focus();
        });
    });
    Object.values(fields).forEach((field) => field.addEventListener('input', updateTotal));
    modal.querySelectorAll('[data-manual-score-close]').forEach((element) => element.addEventListener('click', close));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) close();
    });
})();
</script>
<script>
(() => {
    const histories = @json($scoreHistoryByPerformance ?? []);
    const currentPerformanceId = @json($currentPerformance?->id);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const modal = document.getElementById('score-history-modal');
    const title = document.getElementById('score-history-title');
    const body = document.getElementById('score-history-body');
    if (! modal) return;

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const slotActions = (performanceHistory, slot, score) => {
        const updateUrl = performanceHistory?.update_url;
        const returnUrl = performanceHistory?.return_url;
        if (! updateUrl || ! returnUrl) return '';
        const returnConfirm = /^(LINE|TIME|RESP)/.test(slot)
            ? ''
            : ` onsubmit="return confirm('Вернуть оценку ${esc(slot)} судье на доработку?');"`;
        return `
            <div class="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-800 pt-3">
                <form method="POST" action="${esc(updateUrl)}" class="flex items-center gap-2 flex-1 min-w-[200px]">
                    <input type="hidden" name="_token" value="${esc(csrf)}">
                    <input type="hidden" name="slot" value="${esc(slot)}">
                    <label class="text-[10px] text-slate-500 shrink-0">Исправить</label>
                    <input type="number" name="score" step="0.001" min="0" max="99.999" value="${esc(score)}" required
                           class="flex-1 rounded-md border border-slate-700 bg-slate-950 text-slate-100 text-xs py-1.5 px-2 font-mono">
                    <button type="submit" class="rounded-md border border-amber-700/60 bg-amber-900/30 px-3 py-1.5 text-xs text-amber-100 hover:bg-amber-800/40">Сохранить</button>
                </form>
                <form method="POST" action="${esc(returnUrl)}"${returnConfirm}>
                    <input type="hidden" name="_token" value="${esc(csrf)}">
                    <input type="hidden" name="slot" value="${esc(slot)}">
                    <button type="submit" class="rounded-md border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-300 hover:bg-slate-800">↩ На доработку</button>
                </form>
            </div>`;
    };

    const dcDisplay = (sym) => {
        if (sym === 'C_UP') return 'C↗↗';
        if (sym === 'C_DOWN') return 'C↓↓';
        return sym || '';
    };

    const entryLine = (e) => {
        const showEx = e.exchange && e.symbol !== 'DE';
        const exTag = showEx ? ` <span class="text-indigo-300/90">(${esc(String(e.exchange).toUpperCase())})</span>` : '';
        const dcSym = e.symbol && ['CC', 'CR', 'C_UP', 'C_DOWN'].includes(e.symbol)
            ? `<span class="font-black text-indigo-300">${esc(dcDisplay(e.symbol))}</span> `
            : '';
        const sym = dcSym || (e.symbol ? `<span class="font-black">${esc(e.symbol)}</span> ` : (e.acro ? '<span class="font-black text-indigo-300">A</span> ' : ''));
        const label = e.label ? `<span class="text-slate-400">${esc(e.label)}</span>${exTag} ` : '';
        const val = e.combo
            ? '<span class="text-emerald-300">выполнено</span>'
            : (e.notDone ? '<span class="text-rose-300">Х · 0 (не выполнен)</span>' : `<span class="font-mono tabular-nums">${Number(e.v).toFixed(1)}</span>`);
        const counted = (e.notDone || e.combo) ? '' : (e.counted === false ? ' <span class="text-[10px] text-rose-300">не в зачёте</span>' : '');
        return `<li class="flex items-center gap-2 rounded-md border border-slate-800 bg-slate-900/60 px-2 py-1 ${e.counted === false && !e.notDone && !e.combo ? 'opacity-60' : ''}">${sym}${label}${val}${counted}</li>`;
    };

    const slotBlock = (performanceHistory, slot, withActions = false) => {
        const h = performanceHistory?.slots?.[slot];
        if (! h) return '';
        const ag = h.age_group === 'junior' ? 'Юниоры' : (h.age_group === 'senior' ? 'Сеньоры' : null);
        const meta = [h.judge, ag, h.submitted_at ? 'отправлено ' + h.submitted_at : null].filter(Boolean).map(esc).join(' · ');
        const entries = Array.isArray(h.entries) && h.entries.length
            ? `<ul class="mt-2 grid grid-cols-2 sm:grid-cols-3 gap-1 text-xs">${h.entries.map(entryLine).join('')}</ul>`
            : '<div class="mt-2 text-xs text-slate-500">История нажатий не передана (оценка введена без планшета или старой версией).</div>';
        const actions = withActions ? slotActions(performanceHistory, slot, h.score) : '';
        return `
            <div class="rounded-xl border border-slate-800 bg-slate-900/40 p-3">
                <div class="flex items-center justify-between gap-2">
                    <div class="font-mono font-bold text-emerald-300">${esc(slot)} <span class="text-white">${esc(h.score)}</span></div>
                    <div class="text-[11px] text-slate-500">${meta}</div>
                </div>
                ${entries}
                ${actions}
            </div>`;
    };

    const open = (performanceHistory, slots, heading, withActions = false) => {
        const blocks = slots.map((s) => slotBlock(performanceHistory, s, withActions && slots.length === 1)).filter(Boolean);
        if (! blocks.length) return;
        title.textContent = heading;
        body.innerHTML = blocks.join('');
        modal.classList.remove('hidden');
    };

    document.querySelectorAll('[data-history-slot]').forEach((td) => {
        td.addEventListener('click', () => {
            const slot = td.dataset.historySlot;
            const performanceHistory = histories[String(currentPerformanceId)];
            if (performanceHistory?.slots?.[slot]) open(performanceHistory, [slot], 'История выставления — ' + slot, true);
        });
    });

    document.querySelectorAll('[data-stream-history-score]').forEach((button) => {
        button.addEventListener('click', () => {
            const performanceHistory = histories[String(button.dataset.performanceId)];
            const slot = button.dataset.slot;
            if (! performanceHistory?.slots?.[slot]) return;
            open(
                performanceHistory,
                [slot],
                `${performanceHistory.athlete || 'Гимнастка'} — ${slot}`,
                true,
            );
        });
    });

    const totalBadge = document.getElementById('total-score-badge');
    if (totalBadge) {
        totalBadge.addEventListener('click', () => {
            const performanceHistory = histories[String(currentPerformanceId)];
            if (performanceHistory) {
                open(performanceHistory, Object.keys(performanceHistory.slots || {}), 'История выставления оценок — все судьи');
            }
        });
    }

    modal.querySelectorAll('[data-history-close]').forEach((el) => {
        el.addEventListener('click', () => modal.classList.add('hidden'));
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') modal.classList.add('hidden');
    });
})();
</script>
<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    const toast = (tone, text) => {
        let el = document.getElementById('queue-save-status');
        if (!el) {
            el = document.createElement('div');
            el.id = 'queue-save-status';
            document.body.appendChild(el);
        }
        el.className = 'fixed bottom-4 right-4 z-50 px-4 py-2 rounded-xl border text-sm shadow-lg ' + (
            tone === 'ok' ? 'bg-emerald-950/90 border-emerald-700 text-emerald-50' :
            tone === 'saving' ? 'bg-slate-900 border-amber-700 text-amber-100' :
            'bg-rose-950/90 border-rose-700 text-rose-50'
        );
        el.textContent = text;
        el.classList.remove('hidden');
        if (tone !== 'saving') {
            clearTimeout(el._t);
            el._t = setTimeout(() => { el.classList.add('hidden'); }, 1800);
        }
    };

    // --- Drag-and-drop for athletes (desktop table + mobile list) ---
    if (typeof Sortable !== 'undefined') {
        const saveUrl = @json(route('secretary.queue.reorder', $category));
        const containers = [document.getElementById('queue-body'), document.getElementById('queue-body-mobile')].filter(Boolean);

        const idsNow = (root) => Array.from(root.querySelectorAll('[data-performance-id]')).map((el) => Number(el.dataset.performanceId)).filter(Number.isFinite);
        const restoreOrder = (root, ids) => {
            const map = new Map();
            root.querySelectorAll('[data-performance-id]').forEach((el) => map.set(Number(el.dataset.performanceId), el));
            ids.forEach((id) => { const el = map.get(id); if (el) root.appendChild(el); });
        };

        const state = new WeakMap();
        let saving = false;

        const persist = async (root) => {
            if (saving) return;
            saving = true;
            toast('saving', 'Сохраняю порядок…');
            const ids = idsNow(root);
            try {
                const res = await fetch(saveUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
                    body: JSON.stringify({ ids, stream_session_id: @json($streamSession?->id) }),
                });
                if (!res.ok) {
                    restoreOrder(root, state.get(root) || []);
                    toast('err', 'Ошибка сохранения порядка.');
                    return;
                }
                state.set(root, idsNow(root));
                // Sync the other container (table ↔ mobile list).
                containers.filter((c) => c !== root).forEach((c) => restoreOrder(c, idsNow(root)));
                toast('ok', 'Порядок сохранён');
            } catch (e) {
                restoreOrder(root, state.get(root) || []);
                toast('err', 'Нет связи.');
            } finally {
                saving = false;
            }
        };

        containers.forEach((root) => {
            state.set(root, idsNow(root));
            new Sortable(root, {
                animation: 150,
                handle: '.drag-handle',
                filter: '[data-queue-locked="1"]',
                preventOnFilter: false,
                ghostClass: 'bg-emerald-950/40',
                onMove: (event) => event.related?.dataset.queueLocked !== '1',
                onStart: () => { state.set(root, idsNow(root)); },
                onEnd: () => persist(root),
            });
        });
    }

    // --- Toggle judge slots (на случай неполного состава бригады) ---
    const toggleUrl = @json(route('secretary.category.judgeSlot.toggle', $category));
    const grid = document.getElementById('judge-slots-grid');
    const waitingEl = document.getElementById('judge-slots-waiting');
    const activeCountEl = document.getElementById('judge-slots-active');

    const styleButton = (btn) => {
        const inactive = btn.dataset.inactive === '1';
        const ok = btn.dataset.ok === '1';
        btn.classList.remove(
            'border-slate-800', 'bg-slate-950/40', 'text-slate-500', 'line-through', 'opacity-70',
            'border-slate-700', 'bg-slate-950/50', 'text-slate-200',
        );
        if (inactive) {
            btn.classList.add('border-slate-800', 'bg-slate-950/40', 'text-slate-500', 'line-through', 'opacity-70');
            btn.title = 'Слот отключён — клик, чтобы включить';
        } else {
            btn.classList.add('border-slate-700', 'bg-slate-950/50', 'text-slate-200');
            btn.title = 'Активный слот — клик, чтобы отключить (нет судьи)';
        }
        const dot = btn.querySelector('span.h-2.w-2');
        if (dot) {
            dot.classList.remove('bg-emerald-400', 'bg-amber-400', 'bg-slate-600');
            dot.classList.add(inactive ? 'bg-slate-600' : (ok ? 'bg-emerald-400' : 'bg-amber-400'));
        }
        const suffix = btn.querySelector('.slot-suffix');
        if (suffix) suffix.textContent = inactive ? 'off' : '';
    };

    const updateCounters = () => {
        if (!grid || !waitingEl || !activeCountEl) return;
        const buttons = Array.from(grid.querySelectorAll('.judge-slot-toggle'));
        const active = buttons.filter((b) => b.dataset.inactive !== '1');
        const waiting = active.filter((b) => b.dataset.ok !== '1');
        waitingEl.textContent = waiting.length;
        activeCountEl.textContent = active.length;
    };

    if (grid) {
        grid.addEventListener('click', async (ev) => {
            const btn = ev.target.closest('.judge-slot-toggle');
            if (!btn) return;
            ev.preventDefault();
            const slot = btn.dataset.slot;
            const willBeActive = btn.dataset.inactive === '1' ? 1 : 0;
            btn.disabled = true;
            toast('saving', `Сохраняю слот ${slot}…`);
            try {
                const res = await fetch(toggleUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                    },
                    body: JSON.stringify({ slot, active: willBeActive }),
                });
                if (!res.ok) {
                    toast('err', 'Не удалось сохранить.');
                    return;
                }
                const data = await res.json();
                btn.dataset.inactive = data.active ? '0' : '1';
                styleButton(btn);
                updateCounters();
                toast('ok', data.message || 'Сохранено');
                // Refresh score matrix and waiting counters on the page by triggering reload via ping rev change.
                // No full reload here — keep secretary's UX snappy; the periodic ping below will pick up the change.
            } catch (e) {
                toast('err', 'Нет связи.');
            } finally {
                btn.disabled = false;
            }
        });
    }
})();
</script>
<script>
(function () {
    const pageRoot = document.querySelector('[data-async-page]');
    const formatDuration = (seconds) => {
        const value = Math.max(0, Math.floor(Number(seconds) || 0));
        return Math.floor(value / 60) + ':' + String(value % 60).padStart(2, '0');
    };

    const timer = document.querySelector('[data-performance-timer]');
    if (timer) {
        const value = timer.querySelector('[data-performance-timer-value]');
        const startedAt = Date.parse(timer.dataset.startedAt || '');
        const savedDuration = timer.dataset.duration === '' ? null : Number(timer.dataset.duration);
        const render = () => {
            const seconds = timer.dataset.running === '1' && Number.isFinite(startedAt)
                ? Math.max(0, (Date.now() - startedAt) / 1000)
                : savedDuration;
            if (value) value.textContent = seconds === null || !Number.isFinite(seconds) ? '—' : formatDuration(seconds);
        };
        render();
        if (timer.dataset.running === '1') {
            const timerInterval = setInterval(() => {
                if (pageRoot && ! pageRoot.isConnected) {
                    clearInterval(timerInterval);
                    return;
                }
                render();
            }, 250);
        }
    }

    const audio = document.getElementById('live-performance-audio');
    const audioButton = document.getElementById('live-performance-audio-toggle');
    if (audio && audioButton) {
        audioButton.addEventListener('click', async () => {
            if (audio.paused) {
                try {
                    await audio.play();
                    audioButton.textContent = '❚❚ Остановить музыку';
                } catch (e) {}
            } else {
                audio.pause();
                audioButton.textContent = '▶ Запустить музыку';
            }
        });
        audio.addEventListener('ended', () => { audioButton.textContent = '▶ Запустить музыку'; });
    }
})();
</script>
<script>
(function () {
    const pageRoot = document.querySelector('[data-async-page]');
    const pingUrl = @json(route('secretary.queue.ping', $category).($streamSession ? '?session='.$streamSession->id : ''));
    let lastRev = @json($queueRev);
    let requestInFlight = false;
    let failedRefreshes = 0;
    const intervalMs = 1000;
    const requestTimeoutMs = 5000;
    const checkForUpdates = async function () {
        if (pageRoot && ! pageRoot.isConnected) {
            stopPolling();
            return;
        }
        if (document.querySelector('[data-pause-live-refresh="1"]:not(.hidden)')) return;
        if (requestInFlight) return;

        requestInFlight = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), requestTimeoutMs);
        try {
            const r = await fetch(pingUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
            });
            if (!r.ok) return;
            const j = await r.json();
            if (!j.rev) return;
            if (lastRev === null) {
                lastRev = j.rev;
                return;
            }
            if (j.rev !== lastRev) {
                let refreshed = false;
                if (window.JudgeAsync) {
                    refreshed = await window.JudgeAsync.refresh(window.location.href, { silent: true });
                }

                if (refreshed) return;

                // JudgeAsync may be temporarily busy with a secretary action. Retry once;
                // if background replacement is still unavailable, reload automatically.
                failedRefreshes += 1;
                if (!window.JudgeAsync || failedRefreshes >= 2) {
                    window.location.reload();
                }
            }
        } catch (e) {
            // A short network interruption must not stop the next polling attempt.
        } finally {
            clearTimeout(timeout);
            requestInFlight = false;
        }
    };
    const pingInterval = setInterval(checkForUpdates, intervalMs);
    const checkWhenVisible = () => {
        if (!document.hidden) checkForUpdates();
    };
    const stopPolling = () => {
        clearInterval(pingInterval);
        document.removeEventListener('visibilitychange', checkWhenVisible);
        window.removeEventListener('focus', checkWhenVisible);
    };
    document.addEventListener('visibilitychange', checkWhenVisible);
    window.addEventListener('focus', checkWhenVisible);
    window.addEventListener('judge:before-page-update', stopPolling, { once: true });
    checkForUpdates();
})();
</script>
</x-app-layout>
