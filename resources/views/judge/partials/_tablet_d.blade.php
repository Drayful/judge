{{-- D-бригада: DB1/DB2 — сложность тела, DA1/DA2 — сложность предмета.
     Новая логика: судья сначала выбирает СИМВОЛ элемента (значения у символов нет),
     затем присваивает ему балл (значение) или жмёт «Х» — элемент не выполнен (0 баллов).
     Слева — символы + две колонки значений. Справа — ОТМЕНА, «Х» и быстрые баллы. --}}

@php
    // Символы элементов без значений: прыжок, равновесие, поворот (круг с палочкой), риск.
    $symbols = [
        ['k' => '^',  'label' => 'Прыжок',     'bg' => '#0e3d4a'],
        ['k' => 'T',  'label' => 'Равновесие', 'bg' => '#0f5f6f'],
        ['k' => '⚲',  'label' => 'Поворот',    'bg' => '#0e3d4a'],
        ['k' => 'R',  'label' => 'Риск',       'bg' => '#0f5f6f'],
    ];
@endphp

{{-- ====== ЛЕВАЯ ЗОНА ====== --}}
<div class="col-span-5 grid grid-cols-3 gap-2 h-full min-h-0">

    {{-- Символы элементов (без значений) --}}
    <div class="flex flex-col gap-2 h-full min-h-0">
        @foreach ($symbols as $p)
            <button type="button" @click="selectSymbol(@js($p['k']), @js($p['label']))"
                style="background-color: {{ $p['bg'] }}"
                :class="pendingSymbol && pendingSymbol.symbol === @js($p['k'])
                    ? 'ring-2 ring-amber-400 brightness-125 scale-[0.99]'
                    : 'hover:brightness-110'"
                class="flex-1 min-h-0 rounded-2xl border border-cyan-900/30 text-cyan-50 active:scale-[0.98] shadow-md flex flex-col items-center justify-center transition">
                <div class="text-3xl xl:text-4xl font-black leading-none">{{ $p['k'] }}</div>
                <div class="mt-1 text-[10px] uppercase tracking-wider text-cyan-200/70">{{ $p['label'] }}</div>
            </button>
        @endforeach
    </div>

    {{-- Значения 1.0..1.7 --}}
    <div class="flex flex-col gap-2 h-full min-h-0" :class="pendingSymbol ? '' : 'opacity-50'">
        @foreach ([1.0, 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7] as $v)
            <button type="button" @click="assignValue({{ $v }})"
                class="flex-1 min-h-0 rounded-xl bg-[#13294b] hover:bg-[#1a3865] border border-slate-700 text-white text-2xl xl:text-3xl font-bold shadow-md tabular-nums active:scale-[0.98] flex items-center justify-center">
                {{ number_format($v, 1) }}
            </button>
        @endforeach
    </div>
    {{-- Значения 1.8..2.5 --}}
    <div class="flex flex-col gap-2 h-full min-h-0" :class="pendingSymbol ? '' : 'opacity-50'">
        @foreach ([1.8, 1.9, 2.0, 2.1, 2.2, 2.3, 2.4, 2.5] as $v)
            <button type="button" @click="assignValue({{ $v }})"
                class="flex-1 min-h-0 rounded-xl bg-[#163057] hover:bg-[#1f3f73] border border-slate-700 text-white text-2xl xl:text-3xl font-bold shadow-md tabular-nums active:scale-[0.98] flex items-center justify-center">
                {{ number_format($v, 1) }}
            </button>
        @endforeach
    </div>
</div>

{{-- ====== ЦЕНТР ====== --}}
<div class="col-span-3 flex flex-col gap-2 h-full min-h-0">
    {{-- Переключатель возрастной группы: лимиты зачёта элементов --}}
    <div class="shrink-0 grid grid-cols-2 gap-2">
        <button type="button" @click="setAgeGroup('junior')"
            :class="ageGroup === 'junior' ? 'bg-[#4a3d8a] border-indigo-500 ring-2 ring-indigo-400/60 text-white' : 'bg-[#101a36] border-slate-700 text-slate-400 hover:text-slate-200'"
            class="rounded-xl border py-1.5 px-2 transition active:scale-[0.98]">
            <div class="text-xs font-bold uppercase tracking-wider">Юниоры</div>
            <div class="text-[10px] opacity-80">6 эл · 3 риска</div>
        </button>
        <button type="button" @click="setAgeGroup('senior')"
            :class="ageGroup === 'senior' ? 'bg-[#4a3d8a] border-indigo-500 ring-2 ring-indigo-400/60 text-white' : 'bg-[#101a36] border-slate-700 text-slate-400 hover:text-slate-200'"
            class="rounded-xl border py-1.5 px-2 transition active:scale-[0.98]">
            <div class="text-xs font-bold uppercase tracking-wider">Сеньоры</div>
            <div class="text-[10px] opacity-80">8 эл · 4 риска</div>
        </button>
    </div>

    <div class="flex-1 min-h-0 rounded-2xl border border-slate-700 bg-[#0f1830] p-3 flex flex-col items-center justify-center text-center">
        <div class="text-[10px] uppercase tracking-widest text-slate-400">Итоговая оценка</div>
        <div class="my-1 text-5xl xl:text-6xl font-extrabold tabular-nums text-white leading-none" x-text="finalScore().toFixed(2)"></div>

        {{-- Минимум по одному элементу без риска: −0.3 за отсутствующий тип --}}
        <div class="mt-1 w-full">
            <div class="text-[9px] uppercase tracking-wider text-slate-500 mb-0.5">Мин. по 1 элементу</div>
            <div class="flex flex-wrap justify-center gap-1 text-[10px]">
                <template x-for="s in dbMinElementsStatus().items" :key="s.k">
                    <span class="rounded px-1.5 py-0.5 border font-medium"
                          :class="s.ok ? 'border-emerald-700/40 text-emerald-300/90 bg-emerald-950/25' : 'border-rose-700/40 text-rose-300 bg-rose-950/30'"
                          x-text="s.label + (s.ok ? '' : ' −0.3')"></span>
                </template>
            </div>
            <div x-show="dbMinElementsStatus().penalty > 0"
                 class="mt-0.5 text-[10px] text-rose-300/90 font-mono tabular-nums"
                 x-text="'Сбавка за отсутствие: −' + dbMinElementsStatus().penalty.toFixed(1)"></div>
        </div>

        {{-- Зачёт: элементы с наивысшей стоимостью + лимит рисков --}}
        <div class="flex items-center gap-2 text-[10px] font-mono tabular-nums">
            <span class="rounded bg-slate-800 border border-slate-700 px-1.5 py-0.5"
                  :class="dbComputed().used >= dbLim().elements ? 'text-amber-300' : 'text-slate-300'"
                  x-text="'Элементов: ' + dbComputed().used + '/' + dbLim().elements"></span>
            <span class="rounded bg-slate-800 border border-slate-700 px-1.5 py-0.5"
                  :class="dbComputed().risks >= dbLim().risks ? 'text-amber-300' : 'text-slate-300'"
                  x-text="'Рисков: ' + dbComputed().risks + '/' + dbLim().risks"></span>
        </div>

        {{-- Подсказка по шагу: выбран символ или нет --}}
        <div class="min-h-[20px]">
            <template x-if="pendingSymbol">
                <div class="text-[11px] text-amber-200">
                    Выбран: <span class="font-black" x-text="pendingSymbol.symbol"></span>
                    <span x-text="pendingSymbol.label"></span> — нажмите балл или «Х»
                </div>
            </template>
            <template x-if="!pendingSymbol">
                <div class="text-[11px] text-slate-500">Выберите символ элемента</div>
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

        {{-- Лента шагов: символ + балл; тусклые — не попали в зачёт (лимит элементов/рисков) --}}
        <div class="mt-2 w-full grid grid-cols-4 gap-1">
            <template x-for="(a, i) in actions.slice(0, 12)" :key="i">
                <div class="rounded-md border text-[11px] text-center py-0.5 px-1"
                     :class="a.notDone
                        ? 'bg-rose-900/40 border-rose-800/50 text-rose-100'
                        : (isCounted(i) ? 'bg-cyan-900/40 border-cyan-800/40 text-cyan-50' : 'bg-slate-900/60 border-slate-700/60 text-slate-500 line-through')">
                    <span class="font-black" x-text="a.symbol"></span>
                    <span class="font-mono tabular-nums" x-text="a.notDone ? ' Х·0' : ' ' + Number(a.v).toFixed(1)"></span>
                </div>
            </template>
            <div x-show="actions.length === 0" class="col-span-4 text-center text-[10px] text-slate-600">История пуста</div>
        </div>
    </div>

    <button type="button" @click="submit()" :disabled="busy"
        class="shrink-0 rounded-2xl bg-[#3b3070] hover:bg-[#4a3d8a] disabled:opacity-50 disabled:cursor-wait border border-indigo-700/60 py-3 text-lg font-bold text-white shadow-lg shadow-indigo-950/40 active:scale-[0.99]">
        ОТПРАВИТЬ
    </button>
</div>

{{-- ====== ПРАВАЯ ЗОНА ====== --}}
<div class="col-span-4 grid grid-cols-2 gap-2 h-full min-h-0">
    <div class="flex flex-col gap-2 h-full min-h-0">
        <button type="button" @click="cancel()"
            class="flex-1 min-h-0 rounded-2xl bg-[#6f1d2e] hover:bg-[#8a2638] border border-rose-800/60 text-lg xl:text-xl font-bold text-white shadow-md active:scale-[0.98] flex items-center justify-center">
            ОТМЕНА
        </button>
        @foreach ([0.5, 0.6, 0.7, 0.8, 0.9] as $v)
            <button type="button" @click="assignValue({{ $v }})"
                :class="pendingSymbol ? '' : 'opacity-50'"
                class="flex-1 min-h-0 rounded-2xl bg-[#1e6a85] hover:bg-[#247c9b] border border-cyan-800/40 text-2xl xl:text-3xl font-bold text-white tabular-nums shadow-md active:scale-[0.98] flex items-center justify-center">
                {{ number_format($v, 1) }}
            </button>
        @endforeach
    </div>
    <div class="flex flex-col gap-2 h-full min-h-0">
        {{-- «Х» — элемент НЕ выполнен: символ уходит в историю с 0 баллов --}}
        <button type="button" @click="markNotDone()"
            :class="pendingSymbol ? 'border-rose-600 ring-1 ring-rose-500/50' : 'opacity-60 border-slate-700'"
            class="flex-1 min-h-0 rounded-2xl bg-[#5a1d28] hover:bg-[#74232f] border text-white font-semibold active:scale-[0.98] flex flex-col items-center justify-center">
            <div class="text-3xl xl:text-4xl leading-none font-black">Х</div>
            <div class="mt-1 text-[10px] text-rose-200/80">не выполнен · 0</div>
        </button>
        @foreach ([0.1, 0.2, 0.3, 0.4] as $v)
            <button type="button" @click="assignValue({{ $v }})"
                :class="pendingSymbol ? '' : 'opacity-50'"
                class="flex-1 min-h-0 rounded-2xl bg-[#0f5f6f] hover:bg-[#117383] border border-cyan-700/40 text-2xl xl:text-3xl font-bold text-white tabular-nums shadow-md active:scale-[0.98] flex items-center justify-center">
                {{ number_format($v, 1) }}
            </button>
        @endforeach
    </div>
</div>
