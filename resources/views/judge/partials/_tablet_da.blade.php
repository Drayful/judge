{{-- DA-бригада: сложность предмета. Отдельный планшет.
     Логика: можно ставить просто значения, либо «Акробатика» + значение.
     Засчитываются только первые ТРИ акробатики — 4-я и далее уходят в историю,
     но в итог не попадают (помечаются «не учтено»). --}}

{{-- ====== ЛЕВАЯ ЗОНА: сброс + значения + акробатика ====== --}}
<div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
    {{-- «Х (0.0)» — полный сброс --}}
    <button type="button" @click="clearAll()"
        class="shrink-0 h-14 rounded-2xl bg-[#5a1d28] hover:bg-[#74232f] border border-rose-800/60 text-white font-bold active:scale-[0.98] flex flex-col items-center justify-center shadow-md">
        <div class="text-xl leading-none font-black">Х</div>
        <div class="text-[9px] text-rose-200/80">сброс (0.0)</div>
    </button>

    @foreach ([0.1, 0.2, 0.3, 0.4] as $v)
        <button type="button" @click="assignValue({{ $v }})"
            :class="acroPending ? 'ring-2 ring-amber-400 brightness-110' : ''"
            class="flex-1 min-h-0 rounded-2xl bg-[#1e6a85] hover:bg-[#247c9b] border border-cyan-800/40 text-3xl xl:text-4xl font-bold text-white tabular-nums shadow-md active:scale-[0.98] flex items-center justify-center transition">
            {{ number_format($v, 1) }}
        </button>
    @endforeach

    {{-- Акробатика — переключатель режима «следующий балл = акробатика» --}}
    <button type="button" @click="toggleAcro()"
        :class="acroPending ? 'ring-2 ring-amber-400 brightness-125 bg-[#6b4cc0]' : 'bg-[#4a3d8a] hover:bg-[#5a4ca6]'"
        class="flex-1 min-h-0 rounded-2xl border border-indigo-700/60 text-white shadow-md active:scale-[0.98] flex flex-col items-center justify-center transition">
        <div class="text-2xl xl:text-3xl font-black leading-none">A</div>
        <div class="mt-1 text-[10px] uppercase tracking-wider text-indigo-100/80">Акробатика</div>
        <div class="mt-0.5 text-xs font-mono tabular-nums"
             :class="acroCount >= acroMax ? 'text-rose-300' : 'text-amber-200'"
             x-text="acroCount + '/' + acroMax"></div>
    </button>
</div>

{{-- ====== ЦЕНТР ====== --}}
<div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
    <div class="flex-1 min-h-0 rounded-2xl border border-slate-700 bg-[#0f1830] p-3 flex flex-col items-center justify-center text-center">
        <div class="text-[10px] uppercase tracking-widest text-slate-400">Итоговая оценка</div>
        <div class="my-1 text-5xl xl:text-6xl font-extrabold tabular-nums text-white leading-none" x-text="finalScore().toFixed(2)"></div>

        {{-- Подсказка по режиму --}}
        <div class="min-h-[20px]">
            <template x-if="acroPending && acroCount < acroMax">
                <div class="text-[11px] text-amber-200">Акробатика — выберите балл</div>
            </template>
            <template x-if="acroPending && acroCount >= acroMax">
                <div class="text-[11px] text-rose-300">Лимит акробатик (3) — балл не зачтётся</div>
            </template>
            <template x-if="!acroPending">
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

        {{-- Лента шагов: акробатика / простое значение --}}
        <div class="mt-2 w-full grid grid-cols-3 gap-1">
            <template x-for="(a, i) in actions.slice(0, 12)" :key="i">
                <div class="rounded-md border text-[11px] text-center py-0.5 px-1"
                     :class="a.acro
                        ? (a.counted ? 'bg-indigo-900/50 border-indigo-700/50 text-indigo-100' : 'bg-rose-900/40 border-rose-800/50 text-rose-200')
                        : 'bg-cyan-900/40 border-cyan-800/40 text-cyan-50'">
                    <span x-show="a.acro" class="font-black">A</span>
                    <span class="font-mono tabular-nums" x-text="' ' + Number(a.v).toFixed(1)"></span>
                    <span x-show="a.acro && !a.counted" class="text-[9px] text-rose-300"> ⃠</span>
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

{{-- ====== ПРАВАЯ ЗОНА: отмена + значения ====== --}}
<div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
    <button type="button" @click="cancel()"
        class="shrink-0 h-14 rounded-2xl bg-[#6f1d2e] hover:bg-[#8a2638] border border-rose-800/60 text-base font-bold text-white shadow-md active:scale-[0.98]">
        ОТМЕНА
    </button>

    @foreach ([0.5, 0.6, 0.7, 0.8, 0.9] as $v)
        <button type="button" @click="assignValue({{ $v }})"
            :class="acroPending ? 'ring-2 ring-amber-400 brightness-110' : ''"
            class="flex-1 min-h-0 rounded-2xl bg-[#163057] hover:bg-[#1f3f73] border border-slate-700 text-3xl xl:text-4xl font-bold text-white tabular-nums shadow-md active:scale-[0.98] flex items-center justify-center transition">
            {{ number_format($v, 1) }}
        </button>
    @endforeach
</div>
