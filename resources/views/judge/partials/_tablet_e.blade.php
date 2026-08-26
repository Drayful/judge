{{-- E-бригада: Execution. Зелёная лента истории сбавок.
     «Вставить» → numpad итоговой оценки. «ОТМЕНА» → удалить последнее. --}}

<div data-e-large-controls class="col-span-4 flex flex-col gap-2 h-full min-h-0">
    <button type="button" @click="cancel()"
        class="shrink-0 h-14 rounded-2xl bg-[#6f1d2e] hover:bg-[#8a2638] border border-rose-800/60 text-xl font-bold text-white shadow-md active:scale-[0.98]">
        ОТМЕНА
    </button>
    <button type="button" @click="add(0.1)"
        class="flex-1 min-h-0 rounded-2xl bg-[#0f4f5e] hover:bg-[#10657a] border border-cyan-900/40 text-7xl md:text-8xl xl:text-9xl leading-none font-extrabold text-white shadow-md active:scale-[0.98] tabular-nums">
        0.1
    </button>
    <button type="button" @click="add(0.3)"
        class="flex-1 min-h-0 rounded-2xl bg-[#0e6a7a] hover:bg-[#0f8294] border border-cyan-700/40 text-7xl md:text-8xl xl:text-9xl leading-none font-extrabold text-white shadow-md active:scale-[0.98] tabular-nums">
        0.3
    </button>
</div>

<div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
    <div class="judge-score-stage flex-1 min-h-0 rounded-3xl border p-3 flex flex-col items-center justify-center text-center">
        <div class="text-[10px] uppercase tracking-widest text-slate-400">Итоговая сбавка</div>
        <div class="my-1 text-7xl md:text-8xl xl:text-9xl font-extrabold tabular-nums text-white leading-none" x-text="workingTotal().toFixed(2)"></div>

        <div class="flex items-center gap-2 w-full justify-center">
            <div class="rounded-lg bg-slate-800 border border-slate-700 px-2 py-1 text-sm font-mono tabular-nums text-emerald-200 min-w-[100px] text-center">
                {{ $slot }} (оценка)
            </div>
            <button type="button" @click="openFinalScoreNumpad()"
                class="rounded-lg bg-[#5547a5] hover:bg-[#6657c2] border border-indigo-700/60 px-3 py-1.5 text-xs font-semibold text-white shadow">
                Вставить
            </button>
        </div>

        <div class="mt-1 text-xs text-slate-500">{{ $slot }}: финал E = {{ number_format((float) $base, 2, '.', '') }} − сбавка</div>
        <div class="text-3xl md:text-4xl font-extrabold leading-none text-emerald-300 font-mono tabular-nums" x-text="finalScore().toFixed(2)"></div>

        <div class="mt-2 w-full grid grid-cols-6 gap-1">
            <template x-for="(a, i) in actions.slice(0, 12)" :key="i">
                <div class="rounded-md bg-rose-900/40 border border-rose-800/40 text-rose-50 text-[11px] font-mono tabular-nums text-center py-0.5"
                     x-text="'-' + Number(a.v).toFixed(2)"></div>
            </template>
            <div x-show="actions.length === 0" class="col-span-6 text-center text-[10px] text-slate-600">История пуста</div>
        </div>
    </div>

    @include('judge.partials._tablet_center_logo')

    <button type="button" @click="submit()" :disabled="busy"
        class="judge-submit-button shrink-0 rounded-2xl disabled:opacity-50 disabled:cursor-wait border py-3 text-lg font-bold text-white active:scale-[0.99]">
        ОТПРАВИТЬ
    </button>
</div>

<div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
    <button type="button" @click="add(0.5)"
        class="flex-1 min-h-0 rounded-2xl bg-[#1f78c4] hover:bg-[#2a8bd9] border border-blue-700/40 text-7xl md:text-8xl xl:text-9xl leading-none font-extrabold text-white shadow-md active:scale-[0.98] tabular-nums">
        0.5
    </button>
    <button type="button" @click="add(0.7)"
        class="flex-1 min-h-0 rounded-2xl bg-[#9a6c1a] hover:bg-[#b78224] border border-amber-800/40 text-7xl md:text-8xl xl:text-9xl leading-none font-extrabold text-white shadow-md active:scale-[0.98] tabular-nums">
        0.7
    </button>
    <button type="button" @click="add(1.0)"
        class="flex-1 min-h-0 rounded-2xl bg-[#962638] hover:bg-[#b62b41] border border-rose-800/40 text-7xl md:text-8xl xl:text-9xl leading-none font-extrabold text-white shadow-md active:scale-[0.98] tabular-nums">
        1.0
    </button>
</div>
