{{-- DA-бригада: сложность предмета. Отдельный планшет.
     Логика: значения (0.2/0.3/0.4/0.5) напрямую, либо «Акробатика» + значение.
     Засчитываются только первые ТРИ акробатики — 4-я и далее уходят в историю,
     но в итог не попадают («не учтено»). «Х» — несделанная акробатика (0 баллов). --}}

{{-- ====== ЛЕВАЯ ЗОНА: Х (несделанная акробатика) + 0.2 + Акробатика ====== --}}
<div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
    {{-- «Х» — несделанная акробатика: занимает слот, в итог 0 (размер как у цифр) --}}
    <button type="button" @click="markAcroNotDone()"
        class="flex-1 min-h-0 rounded-2xl bg-[#5a1d28] hover:bg-[#74232f] border border-rose-800/60 text-white font-bold active:scale-[0.98] flex flex-col items-center justify-center shadow-md transition">
        <div class="text-4xl xl:text-5xl leading-none font-black">Х</div>
        <div class="mt-1 text-[10px] text-rose-200/80">не сделана · 0</div>
    </button>

    <button type="button" @click="assignValue(0.2)"
        :class="acroPending ? 'ring-2 ring-amber-400 brightness-110' : ''"
        class="flex-1 min-h-0 rounded-2xl bg-[#1e6a85] hover:bg-[#247c9b] border border-cyan-800/40 text-4xl xl:text-5xl font-bold text-white tabular-nums shadow-md active:scale-[0.98] flex items-center justify-center transition">
        0.2
    </button>

    {{-- Акробатика — переключатель режима «следующий балл = акробатика» --}}
    <button type="button" @click="toggleAcro()"
        :class="acroPending ? 'ring-2 ring-amber-400 brightness-125 bg-[#6b4cc0]' : 'bg-[#4a3d8a] hover:bg-[#5a4ca6]'"
        class="flex-1 min-h-0 rounded-2xl border border-indigo-700/60 text-white shadow-md active:scale-[0.98] flex flex-col items-center justify-center transition">
        <div class="text-4xl xl:text-5xl font-black leading-none">A</div>
        <div class="mt-1 text-[11px] uppercase tracking-wider text-indigo-100/80">Акробатика</div>
        <div class="mt-0.5 text-sm font-mono tabular-nums"
             :class="daComputed().acro >= daLim().acro ? 'text-rose-300' : 'text-amber-200'"
             x-text="daComputed().acro + '/' + daLim().acro"></div>
    </button>
</div>

{{-- ====== ЦЕНТР ====== --}}
<div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
    {{-- Переключатель возрастной группы: лимит засчитываемых элементов --}}
    <div class="shrink-0 grid grid-cols-2 gap-2">
        <button type="button" @click="setAgeGroup('junior')"
            :class="ageGroup === 'junior' ? 'bg-[#4a3d8a] border-indigo-500 ring-2 ring-indigo-400/60 text-white' : 'bg-[#101a36] border-slate-700 text-slate-400 hover:text-slate-200'"
            class="rounded-xl border py-1.5 px-2 transition active:scale-[0.98]">
            <div class="text-xs font-bold uppercase tracking-wider">Юниоры</div>
            <div class="text-[10px] opacity-80">макс. 12 элементов</div>
        </button>
        <button type="button" @click="setAgeGroup('senior')"
            :class="ageGroup === 'senior' ? 'bg-[#4a3d8a] border-indigo-500 ring-2 ring-indigo-400/60 text-white' : 'bg-[#101a36] border-slate-700 text-slate-400 hover:text-slate-200'"
            class="rounded-xl border py-1.5 px-2 transition active:scale-[0.98]">
            <div class="text-xs font-bold uppercase tracking-wider">Сеньоры</div>
            <div class="text-[10px] opacity-80">макс. 15 элементов</div>
        </button>
    </div>

    <div class="flex-1 min-h-0 rounded-2xl border border-slate-700 bg-[#0f1830] p-3 flex flex-col items-center justify-center text-center">
        <div class="text-[10px] uppercase tracking-widest text-slate-400">Итоговая оценка</div>
        <div class="my-1 text-5xl xl:text-6xl font-extrabold tabular-nums text-white leading-none" x-text="finalScore().toFixed(2)"></div>

        {{-- Зачёт элементов --}}
        <div class="flex items-center gap-2 text-[10px] font-mono tabular-nums">
            <span class="rounded bg-slate-800 border border-slate-700 px-1.5 py-0.5"
                  :class="daComputed().used >= daLim().elements ? 'text-amber-300' : 'text-slate-300'"
                  x-text="'Элементов: ' + daComputed().used + '/' + daLim().elements"></span>
            <span class="rounded bg-slate-800 border border-slate-700 px-1.5 py-0.5"
                  :class="daComputed().acro >= daLim().acro ? 'text-amber-300' : 'text-slate-300'"
                  x-text="'Акробатик: ' + daComputed().acro + '/' + daLim().acro"></span>
        </div>

        {{-- Подсказка по режиму --}}
        <div class="min-h-[20px]">
            <template x-if="acroPending && daComputed().acro < daLim().acro">
                <div class="text-[11px] text-amber-200">Акробатика — выберите балл (или «Х», если не сделана)</div>
            </template>
            <template x-if="acroPending && daComputed().acro >= daLim().acro">
                <div class="text-[11px] text-rose-300">Лимит акробатик — балл не зачтётся</div>
            </template>
            <template x-if="!acroPending && daComputed().used >= daLim().elements">
                <div class="text-[11px] text-rose-300">Лимит элементов достигнут — новые баллы не зачтутся</div>
            </template>
            <template x-if="!acroPending && daComputed().used < daLim().elements">
                <div class="text-[11px] text-slate-500">Балл — это значение, либо «Акробатика» + значение</div>
            </template>
        </div>

        <div class="mt-1 flex items-center gap-2 w-full justify-center">
            <div class="rounded-lg bg-slate-800 border border-slate-700 px-2 py-1 text-sm font-mono tabular-nums text-cyan-100 min-w-[100px] text-center">
                {{ $slot }} (оценка)
            </div>
            <button type="button" @click="openNumpad()"
                class="rounded-lg bg-[#5547a5] hover:bg-[#6657c2] border border-indigo-700/60 px-3 py-1.5 text-xs font-semibold text-white shadow">
                Вставить
            </button>
        </div>

        {{-- Лента шагов: акробатика / простое значение; тусклые — не в зачёте --}}
        <div class="mt-2 w-full grid grid-cols-3 gap-1">
            <template x-for="(a, i) in actions.slice(0, 12)" :key="i">
                <div class="rounded-md border text-[11px] text-center py-0.5 px-1"
                     :class="a.notDone
                        ? 'bg-rose-900/50 border-rose-800/60 text-rose-100'
                        : (! isCounted(i)
                            ? 'bg-slate-900/60 border-slate-700/60 text-slate-500 line-through'
                            : (a.acro ? 'bg-indigo-900/50 border-indigo-700/50 text-indigo-100' : 'bg-cyan-900/40 border-cyan-800/40 text-cyan-50'))">
                    <span x-show="a.acro" class="font-black">A</span>
                    <span class="font-mono tabular-nums" x-text="a.notDone ? ' Х·0' : ' ' + Number(a.v).toFixed(1)"></span>
                    <span x-show="!a.notDone && !isCounted(i)" class="text-[9px] text-rose-300"> ⃠</span>
                </div>
            </template>
            <div x-show="actions.length === 0" class="col-span-3 text-center text-[10px] text-slate-600">История пуста</div>
        </div>
    </div>

    <button type="button" @click="submit()" :disabled="busy"
        class="shrink-0 rounded-2xl bg-[#3b3070] hover:bg-[#4a3d8a] disabled:opacity-50 disabled:cursor-wait border border-indigo-700/60 py-3 text-lg font-bold text-white shadow-lg shadow-indigo-950/40 active:scale-[0.99]">
        ОТПРАВИТЬ
    </button>
</div>

{{-- ====== ПРАВАЯ ЗОНА: ОТМЕНА + 0.3 + 0.4 ====== --}}
<div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
    <button type="button" @click="cancel()"
        class="flex-1 min-h-0 rounded-2xl bg-[#6f1d2e] hover:bg-[#8a2638] border border-rose-800/60 text-lg xl:text-xl font-bold text-white shadow-md active:scale-[0.98] flex items-center justify-center">
        ОТМЕНА
    </button>

    <button type="button" @click="assignValue(0.3)"
        :class="acroPending ? 'ring-2 ring-amber-400 brightness-110' : ''"
        class="flex-1 min-h-0 rounded-2xl bg-[#1e6a85] hover:bg-[#247c9b] border border-cyan-800/40 text-4xl xl:text-5xl font-bold text-white tabular-nums shadow-md active:scale-[0.98] flex items-center justify-center transition">
        0.3
    </button>

    <button type="button" @click="assignValue(0.4)"
        :class="acroPending ? 'ring-2 ring-amber-400 brightness-110' : ''"
        class="flex-1 min-h-0 rounded-2xl bg-[#9a7b1e] hover:bg-[#b69226] border border-amber-700/40 text-4xl xl:text-5xl font-bold text-white tabular-nums shadow-md active:scale-[0.98] flex items-center justify-center transition">
        0.4
    </button>
</div>
