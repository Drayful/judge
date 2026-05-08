@extends('layouts.tablet')

@section('title', 'Бригада '.$panel['panel'].($panel['subpanel'] ? ' '.$panel['subpanel'] : ''))

@php
    $athlete = $current?->athlete;
    $cityLine = $athlete?->club ?? '—';
    $statusTone = match ($streamStatus) {
        'waiting_scores' => 'amber',
        'finalized' => 'green',
        'on_deck' => 'amber',
        'scheduled' => 'gray',
        'done' => 'green',
        'empty' => 'red',
        default => 'gray',
    };
    $pKey = $panel['panel'];
    $mode = $pKey === 'd' ? 'add' : ($pKey === 'a' || $pKey === 'e' ? 'subtract' : 'penalty');
    $startVal = $mode === 'add' ? 0.0 : ($pKey === 'e' ? $eBase : $aBase);
    $saved = $myScore?->score !== null ? (float) $myScore->score : null;
    $alreadySubmitted = $myScore !== null && $myScore->submitted_at !== null;
    $submittedDisplay = $alreadySubmitted && $myScore->score !== null
        ? number_format((float) $myScore->score, 3, '.', '')
        : null;
@endphp

@section('content')
    <div class="max-w-lg mx-auto px-4 py-6 space-y-5">
        <div class="flex items-center justify-between gap-3">
            <a href="{{ route('judge.tournaments') }}" class="text-sm text-emerald-400 hover:text-emerald-300">← Турниры</a>
            <a href="{{ route('judge.category', $category) }}" class="text-sm text-slate-400 hover:text-slate-200">Таблица потока</a>
        </div>

        <x-flash />

        @if ($errors->any())
            <div class="rounded-xl border border-rose-800/60 bg-rose-950/40 px-4 py-3 text-sm text-rose-100">
                {{ $errors->first() }}
            </div>
        @endif

        {{-- Шапка ТЗ: ФИО, категория, город, статус --}}
        <div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5 shadow-lg shadow-black/30">
            @if($current && $athlete)
                <div class="text-lg font-semibold text-white leading-snug">
                    {{ $athlete->last_name }} {{ $athlete->first_name }}
                </div>
                <div class="mt-2 text-sm text-slate-400 space-y-1">
                    <div><span class="text-slate-500">Турнир:</span> {{ $tournament->name }}</div>
                    <div><span class="text-slate-500">Поток:</span> {{ $category->name }}</div>
                    <div><span class="text-slate-500">Клуб / город:</span> {{ $cityLine }}</div>
                    <div><span class="text-slate-500">Предмет:</span> {{ $current->apparatus ?? '—' }}</div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-badge :tone="$statusTone">Статус: {{ $streamStatus }}</x-badge>
                    <x-badge tone="violet">№ {{ $current->start_number ?? '—' }}</x-badge>
                </div>
            @else
                <div class="text-slate-300 text-sm">Нет активного выступления для оценки.</div>
                <p class="mt-2 text-xs text-slate-500">Секретарь должен вызвать гимнастку (статус on_deck / performing) или начать поток.</p>
            @endif
        </div>

        @if($current && $athlete && $panel['panel'] !== 'penalty' && $alreadySubmitted)
            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5 space-y-4">
                <div class="text-center">
                    <div class="text-xs uppercase tracking-wider text-slate-500">Оценка отправлена</div>
                    <div class="mt-2 text-5xl font-bold tabular-nums tracking-tight text-emerald-200">{{ $submittedDisplay }}</div>
                    <p class="mt-3 text-sm text-slate-400">Повторная отправка для этого выступления не требуется. Дождитесь следующей гимнастки.</p>
                </div>
                <button type="button" disabled
                    class="w-full rounded-2xl bg-slate-700/80 py-4 text-lg font-semibold text-slate-400 cursor-not-allowed border border-slate-700">
                    Отправить оценку (уже отправлено: {{ $submittedDisplay }})
                </button>
            </div>
        @elseif($current && $athlete && $panel['panel'] !== 'penalty')
            @php
                $initial = $saved !== null ? $saved : $startVal;
            @endphp
            <div
                class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5 space-y-5"
                x-data="{
                    working: {{ json_encode($initial) }},
                    actions: [],
                    add(v) {
                        this.working = Math.round((this.working + v) * 1000) / 1000;
                        if (this.working < 0) this.working = 0;
                        if (this.working > 99.999) this.working = 99.999;
                        this.actions.unshift(v);
                        if (this.actions.length > 24) this.actions.pop();
                    },
                    reset() {
                        this.working = {{ json_encode($startVal) }};
                        this.actions = [];
                    }
                }"
            >
                <div class="text-center">
                    <div class="text-xs uppercase tracking-wider text-slate-500">Текущая оценка (черновик)</div>
                    <div class="mt-2 text-5xl font-bold tabular-nums tracking-tight text-white" x-text="working.toFixed(3)"></div>
                    <div class="mt-1 text-xs text-slate-500">
                        Панель: <span class="text-emerald-300/90 font-mono">{{ strtoupper($panel['panel']) }}{{ $panel['subpanel'] ? ' / '.$panel['subpanel'] : '' }}</span>
                    </div>
                </div>

                @if($mode === 'add')
                    <div class="grid grid-cols-3 gap-2">
                        @foreach ([0.1, 0.2, 0.3, 0.5, 1.0, 2.0] as $step)
                            <button type="button" @click="add({{ $step }})"
                                class="rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 py-4 text-lg font-semibold text-white active:scale-[0.98] transition">
                                +{{ $step === (float) (int) $step ? (int) $step : $step }}
                            </button>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" @click="add(-0.1)" class="rounded-xl border border-slate-700 py-3 text-slate-300 hover:bg-slate-800">−0.1</button>
                        <button type="button" @click="reset()" class="rounded-xl border border-amber-900/50 bg-amber-950/30 py-3 text-amber-100 hover:bg-amber-950/50">Сброс</button>
                    </div>
                @else
                    <div class="grid grid-cols-3 gap-2">
                        @foreach ([-0.1, -0.2, -0.3, -0.5, -1.0, -2.0] as $step)
                            <button type="button" @click="add({{ $step }})"
                                class="rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 py-4 text-lg font-semibold text-white active:scale-[0.98] transition">
                                {{ $step }}
                            </button>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" @click="add(0.1)" class="rounded-xl border border-slate-700 py-3 text-slate-300 hover:bg-slate-800">+0.1</button>
                        <button type="button" @click="reset()" class="rounded-xl border border-amber-900/50 bg-amber-950/30 py-3 text-amber-100 hover:bg-amber-950/50">Сброс к базе</button>
                    </div>
                @endif

                <form method="POST" action="{{ route('judge.tournament.tablet.score', $tournament) }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="score" :value="working.toFixed(3)">
                    @if(auth()->user()->isAdmin())
                        <input type="hidden" name="panel" value="{{ $panel['panel'] }}">
                        @if(($panel['subpanel'] ?? null) !== null)
                            <input type="hidden" name="subpanel" value="{{ $panel['subpanel'] }}">
                        @endif
                    @endif
                    <div class="rounded-xl border border-slate-800/80 bg-slate-950/60 p-3 max-h-36 overflow-y-auto">
                        <div class="text-xs font-medium text-slate-500 mb-2">История шагов (Вставить)</div>
                        <template x-for="(a, i) in actions" :key="i">
                            <div class="text-sm font-mono text-slate-300 border-b border-slate-800/80 py-1" x-text="(a > 0 ? '+' : '') + a"></div>
                        </template>
                        <div x-show="actions.length === 0" class="text-xs text-slate-600">Пока пусто</div>
                    </div>

                    <button type="submit"
                        class="w-full rounded-2xl bg-emerald-600 hover:bg-emerald-500 py-4 text-lg font-bold text-white shadow-lg shadow-emerald-950/40 active:scale-[0.99] transition">
                        Отправить оценку
                    </button>
                </form>
            </div>
        @elseif($current && $athlete && $panel['panel'] === 'penalty' && $alreadySubmitted)
            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5 space-y-4">
                <div class="text-xs uppercase tracking-wider text-slate-500">Штраф отправлен</div>
                <div class="text-4xl font-bold tabular-nums text-emerald-200">{{ $submittedDisplay }}</div>
                <p class="text-sm text-slate-400">Повторная отправка для этого выступления не требуется.</p>
                <button type="button" disabled
                    class="w-full rounded-2xl bg-slate-700/80 py-4 text-lg font-semibold text-slate-400 cursor-not-allowed border border-slate-700">
                    Отправить (уже отправлено: {{ $submittedDisplay }})
                </button>
            </div>
        @elseif($current && $athlete && $panel['panel'] === 'penalty')
            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                <form method="POST" action="{{ route('judge.tournament.tablet.score', $tournament) }}" class="space-y-3">
                    @csrf
                    <label class="block text-sm text-slate-400">Штраф (число)</label>
                    <input name="score" type="number" step="0.001" min="0" max="99.999"
                        value="{{ $myScore?->score ?? '' }}"
                        class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-lg text-white">
                    @if(auth()->user()->isAdmin())
                        <input type="hidden" name="panel" value="penalty">
                        <input type="hidden" name="penalty_type" value="{{ $panel['penalty_type'] ?? 'line' }}">
                    @endif
                    <button type="submit" class="w-full rounded-2xl bg-emerald-600 hover:bg-emerald-500 py-4 text-lg font-bold text-white">Отправить</button>
                </form>
            </div>
        @endif

        <p class="text-center text-xs text-slate-600 px-2">
            Итог D+A+E−штраф считается на сервере (A/E: при 4 судьях отбрасываются min/max). После отправки диспатчится событие <code class="text-slate-500">ScoreUpdated</code>.
        </p>
    </div>

@endsection

@push('body-scripts')
    <script>
        (function () {
            const pingUrl = @json(route('judge.tournament.tablet.ping', $tournament));
            let lastPid = @json($current?->id);
            let lastCid = @json($category->id);
            const intervalMs = 3500;
            setInterval(async function () {
                try {
                    const r = await fetch(pingUrl, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                    });
                    if (!r.ok) return;
                    const j = await r.json();
                    if (!j.resolved) return;
                    const pid = j.performance_id ?? null;
                    const cid = j.category_id ?? null;
                    if (pid !== lastPid || cid !== lastCid) {
                        lastPid = pid;
                        lastCid = cid;
                        window.location.reload();
                    }
                } catch (e) {}
            }, intervalMs);
        })();
    </script>
@endpush
