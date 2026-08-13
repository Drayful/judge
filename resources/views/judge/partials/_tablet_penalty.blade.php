{{-- Штрафной судья: LINE / TIME / RESP. Landscape, без прокрутки.
     «Вставить» → numpad (ввод любого значения). «ОТМЕНА» → undo последнего шортката (если был).
     Кнопки шорткатов сразу выставляют значение (set), а numpad даёт ввод произвольных цифр. --}}

@php
    $penaltyType = $panel['penalty_type'] ?? null;
    $titleByType = match ($penaltyType) {
        'time' => 'Хронометр — сбавка',
        'music' => 'Музыка / RESP — сбавка',
        'line' => 'Линия / выход за ковёр',
        default => 'Штраф',
    };
@endphp

@if ($penaltyType === 'time')
    <div class="col-span-12 grid h-full min-h-0 grid-cols-1 gap-3 md:grid-cols-3">
        <button type="button" @click="recordOfficialTimer('start')"
            :disabled="timerBusy || officialTimerRunning() || timerEndedAt"
            class="rounded-2xl border border-emerald-700/60 bg-emerald-700 px-5 py-8 text-3xl font-bold text-white shadow-lg transition hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-40">
            ▶ Старт таймера
        </button>

        <div class="rounded-2xl border border-sky-700/60 bg-slate-950/70 p-6 text-center shadow-lg">
            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-sky-200">Официальное время</div>
            <div class="mt-4 font-mono text-7xl font-extrabold tabular-nums text-white" x-text="officialTimerValue()">—</div>
            <div class="mt-4 text-sm text-slate-400">Норматив считает система после остановки таймера.</div>
            <template x-if="timerEndedAt">
                <div class="mt-2 text-sm text-rose-200">Сбавка по времени: −<span x-text="timePenalty.toFixed(2)"></span></div>
            </template>
        </div>

        <button type="button" @click="recordOfficialTimer('stop')"
            :disabled="timerBusy || ! officialTimerRunning()"
            class="rounded-2xl border border-rose-700/60 bg-rose-700 px-5 py-8 text-3xl font-bold text-white shadow-lg transition hover:bg-rose-600 disabled:cursor-not-allowed disabled:opacity-40">
            ■ Стоп и сохранить
        </button>
    </div>
@elseif ($penaltyType === 'line')
    <div class="col-span-12 grid h-full min-h-0 grid-rows-[1fr_auto] gap-3">
        <div class="grid min-h-0 grid-cols-1 gap-3 md:grid-cols-2">
            <button type="button" @click="setLinePenalty('line_gymnast')" :disabled="busy"
                class="rounded-2xl border border-cyan-700/50 bg-[#0f5f6f] px-7 py-10 text-left text-white shadow-lg transition hover:bg-[#117383] active:scale-[0.98] disabled:cursor-wait disabled:opacity-50">
                <span class="block text-6xl font-extrabold tabular-nums">0.30</span>
                <span class="mt-3 block text-xl font-bold uppercase tracking-wide">Гимнастка за линию</span>
            </button>

            <button type="button" @click="setLinePenalty('line_ball')" :disabled="busy"
                class="rounded-2xl border border-cyan-700/50 bg-[#1e6a85] px-7 py-10 text-left text-white shadow-lg transition hover:bg-[#247c9b] active:scale-[0.98] disabled:cursor-wait disabled:opacity-50">
                <span class="block text-6xl font-extrabold tabular-nums">0.30</span>
                <span class="mt-3 block text-xl font-bold uppercase tracking-wide">Мяч за линию</span>
            </button>
        </div>

        <div class="grid grid-cols-1 gap-3 rounded-2xl border border-slate-700 bg-slate-950/80 p-4 md:grid-cols-[1fr_auto] md:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Сумма сбавки</div>
                <div class="mt-1 font-mono text-6xl font-extrabold tabular-nums text-rose-200" x-text="draft.toFixed(2)"></div>
                <div class="mt-1 text-xs text-slate-500">Нажатий: <span x-text="actions.length"></span></div>
            </div>
            <button type="button" @click="submit()" :disabled="busy"
                class="rounded-2xl border border-emerald-700/70 bg-emerald-700 px-8 py-5 text-xl font-bold uppercase tracking-wide text-white shadow-lg transition hover:bg-emerald-600 active:scale-[0.98] disabled:cursor-wait disabled:opacity-50">
                Отправить
            </button>
        </div>
    </div>
@else
<div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
    <button type="button" @click="cancel()"
        class="shrink-0 h-14 rounded-2xl bg-[#6f1d2e] hover:bg-[#8a2638] border border-rose-800/60 text-xl font-bold text-white shadow-md active:scale-[0.98]">
        ОТМЕНА
    </button>
    @foreach ([0.05, 0.10, 0.30] as $v)
        <button type="button" @click="set({{ $v }})"
            class="flex-1 min-h-0 rounded-2xl bg-[#0f5f6f] hover:bg-[#117383] border border-cyan-700/40 text-4xl xl:text-5xl font-bold text-white tabular-nums shadow-md active:scale-[0.98]">
            {{ number_format($v, 2) }}
        </button>
    @endforeach
</div>

<div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
    <div class="flex-1 min-h-0 rounded-2xl border border-slate-700 bg-[#0f1830] p-4 flex flex-col items-center justify-center text-center">
        <div class="text-[10px] uppercase tracking-widest text-slate-400">{{ $titleByType }}</div>
        <div class="my-2 text-6xl xl:text-7xl font-extrabold tabular-nums text-white leading-none" x-text="draft.toFixed(2)"></div>

        <div class="flex items-center gap-2 w-full justify-center">
            <div class="rounded-lg bg-slate-800 border border-slate-700 px-2 py-1 text-sm font-mono tabular-nums text-emerald-200 min-w-[100px] text-center">
                {{ $slot }} (штраф)
            </div>
            <button type="button" @click="openNumpad()"
                class="rounded-lg bg-[#5547a5] hover:bg-[#6657c2] border border-indigo-700/60 px-3 py-1.5 text-xs font-semibold text-white">
                Вставить
            </button>
        </div>

        <div class="mt-2 text-[10px] text-slate-500">{{ $slot }} · значение без знака, сервер вычтет из итога</div>
    </div>

    <button type="button" @click="submit()" :disabled="busy"
        class="shrink-0 rounded-2xl bg-[#3b3070] hover:bg-[#4a3d8a] disabled:opacity-50 disabled:cursor-wait border border-indigo-700/60 py-3 text-lg font-bold text-white shadow-lg shadow-indigo-950/40 active:scale-[0.99]">
        ОТПРАВИТЬ
    </button>
</div>

<div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
    @foreach ([0.50, 1.00, 2.00] as $v)
        <button type="button" @click="set({{ $v }})"
            class="flex-1 min-h-0 rounded-2xl bg-[#7a1f2e] hover:bg-[#962638] border border-rose-800/40 text-4xl xl:text-5xl font-bold text-white tabular-nums shadow-md active:scale-[0.98]">
            {{ number_format($v, 2) }}
        </button>
    @endforeach
    <button type="button" @click="clearAll()"
        class="shrink-0 h-14 rounded-2xl bg-[#2a2e44] hover:bg-[#363b58] border border-slate-700 text-white text-xl font-bold active:scale-[0.98]">
        ✕ 0.00
    </button>
</div>
@endif
