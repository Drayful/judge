{{-- D-бригада · групповые упражнения: DB/DE по порядку ввода --}}

@php
    $leftSymbols = [
        ['k' => '^',  'label' => 'Прыжок',     'bg' => '#0e3d4a'],
        ['k' => 'T',  'label' => 'Равновесие', 'bg' => '#0f5f6f'],
        ['k' => '⚲',  'label' => 'Поворот',    'bg' => '#0e3d4a'],
        ['k' => 'DE', 'label' => 'DE',         'bg' => '#1a3560'],
        ['k' => 'R',  'label' => 'Риск',       'bg' => '#0f5f6f'],
    ];
@endphp

<div class="col-span-5 grid grid-cols-3 gap-2 h-full min-h-0">
    <div class="flex flex-col gap-2 h-full min-h-0">
        @foreach ($leftSymbols as $p)
            <button type="button" @click="selectSymbol(@js($p['k']), @js($p['label']))"
                style="background-color: {{ $p['bg'] }}"
                :class="pendingSymbol && pendingSymbol.symbol === @js($p['k'])
                    ? 'ring-2 ring-amber-400 brightness-125 scale-[0.99]'
                    : 'hover:brightness-110'"
                class="flex-1 min-h-0 rounded-2xl border border-cyan-900/30 text-cyan-50 active:scale-[0.98] shadow-md flex flex-col items-center justify-center transition">
                <div class="text-3xl xl:text-4xl font-black leading-none">{{ $p['k'] }}</div>
                @if($p['k'] !== 'DE')
                    <div class="mt-1 text-[10px] uppercase tracking-wider text-cyan-200/70">{{ $p['label'] }}</div>
                @else
                    <div class="mt-1 text-[10px] uppercase tracking-wider text-cyan-200/70">С обменом</div>
                @endif
            </button>
        @endforeach
    </div>

    <div class="flex flex-col gap-2 h-full min-h-0" :class="pendingSymbol ? '' : 'opacity-50'">
        @foreach ([1.0, 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7] as $v)
            <button type="button" @click="assignValue({{ $v }})"
                class="flex-1 min-h-0 rounded-xl bg-[#13294b] hover:bg-[#1a3865] border border-slate-700 text-white text-2xl xl:text-3xl font-bold shadow-md tabular-nums active:scale-[0.98] flex items-center justify-center">
                {{ number_format($v, 1) }}
            </button>
        @endforeach
    </div>
    <div class="flex flex-col gap-2 h-full min-h-0" :class="pendingSymbol ? '' : 'opacity-50'">
        @foreach ([1.8, 1.9, 2.0, 2.1, 2.2, 2.3, 2.4, 2.5] as $v)
            <button type="button" @click="assignValue({{ $v }})"
                class="flex-1 min-h-0 rounded-xl bg-[#163057] hover:bg-[#1f3f73] border border-slate-700 text-white text-2xl xl:text-3xl font-bold shadow-md tabular-nums active:scale-[0.98] flex items-center justify-center">
                {{ number_format($v, 1) }}
            </button>
        @endforeach
    </div>
</div>

<div class="col-span-3 flex flex-col gap-2 h-full min-h-0">
    <div class="shrink-0 grid grid-cols-2 gap-2">
        <button type="button" @click="setAgeGroup('junior')"
            :class="ageGroup === 'junior' ? 'bg-[#4a3d8a] border-indigo-500 ring-2 ring-indigo-400/60 text-white' : 'bg-[#101a36] border-slate-700 text-slate-400 hover:text-slate-200'"
            class="rounded-xl border py-1.5 px-2 transition active:scale-[0.98]">
            <div class="text-xs font-bold uppercase tracking-wider">Юниоры</div>
            <div class="text-[10px] opacity-80">Макс 6 DB/DE · Риски всегда в зачёте</div>
        </button>
        <button type="button" @click="setAgeGroup('senior')"
            :class="ageGroup === 'senior' ? 'bg-[#4a3d8a] border-indigo-500 ring-2 ring-indigo-400/60 text-white' : 'bg-[#101a36] border-slate-700 text-slate-400 hover:text-slate-200'"
            class="rounded-xl border py-1.5 px-2 transition active:scale-[0.98]">
            <div class="text-xs font-bold uppercase tracking-wider">Сеньоры</div>
            <div class="text-[10px] opacity-80">Макс 9 DB/DE · Риски всегда в зачёте</div>
        </button>
    </div>

    <div class="judge-score-stage flex-1 min-h-0 rounded-3xl border p-3 flex flex-col items-center justify-center text-center">
        <div class="text-[10px] uppercase tracking-widest text-slate-400">Итоговая оценка</div>
        <div class="my-1 text-5xl xl:text-6xl font-extrabold tabular-nums text-white leading-none" x-text="finalScore().toFixed(2)"></div>

        <div class="mt-1 w-full">
            <div class="text-[9px] uppercase tracking-wider text-slate-500 mb-0.5">Обязательные элементы и DE</div>
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

        <div class="mt-2 flex flex-wrap items-center justify-center gap-1.5 text-[10px] font-mono tabular-nums">
            <span class="rounded bg-slate-800 border px-1.5 py-0.5"
                  :class="groupDbComputed().totalOver ? 'border-rose-600 bg-rose-950/40 text-rose-200' : 'border-slate-700 text-slate-300'"
                  x-text="'Элементов: ' + groupDbComputed().used + '/' + groupDbLim().elements"></span>
            <span class="rounded bg-slate-800 border px-1.5 py-0.5"
                  :class="groupDbComputed().dbOver ? 'border-rose-600 bg-rose-950/40 text-rose-200' : 'border-slate-700 text-slate-300'"
                  x-text="'DB: ' + groupDbComputed().dbUsed + '/' + groupDbLim().dbMax"></span>
            <span class="rounded bg-slate-800 border px-1.5 py-0.5"
                  :class="groupDbComputed().deOver ? 'border-rose-600 bg-rose-950/40 text-rose-200' : 'border-slate-700 text-slate-300'"
                  x-text="'DE: ' + groupDbComputed().deUsed + '/' + groupDbLim().deMax"></span>
            <span class="rounded bg-slate-800 border px-1.5 py-0.5"
                  class="border-emerald-700/50 bg-emerald-950/25 text-emerald-200"
                  x-text="'Рисков (всегда в зачёте): ' + groupDbComputed().risks"></span>
        </div>
        <div x-show="ageGroup === 'senior' && (groupDbComputed().dbUsed < groupDbLim().dbMin || groupDbComputed().deUsed < groupDbLim().deMin)"
             class="mt-1 text-[10px] text-amber-300/90">
            Сеньоры: минимум <span x-text="groupDbLim().dbMin"></span> DB и <span x-text="groupDbLim().deMin"></span> DE
        </div>

        <div class="min-h-[20px] mt-1">
            <template x-if="pendingSymbol">
                <div class="text-[11px] text-amber-200">
                    <span x-text="pendingSymbol.label"></span>
                    <span x-show="pendingSymbol.exchange === 'db'"> (DB)</span>
                    <span x-show="pendingSymbol.exchange === 'de' && pendingSymbol.symbol !== 'DE'"> (DE)</span>
                    <span> — нажмите балл или «Х»</span>
                </div>
            </template>
            <template x-if="!pendingSymbol">
                <div class="text-[11px] text-slate-500">Выберите тип элемента</div>
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

        <div class="mt-2 w-full grid grid-cols-2 gap-1 max-h-24 overflow-y-auto">
            <template x-for="(a, i) in actions.slice(0, 16)" :key="i">
                <div class="rounded-md border text-[10px] text-center py-0.5 px-1 truncate"
                     :class="a.notDone
                        ? 'bg-rose-900/40 border-rose-800/50 text-rose-100'
                        : (isCounted(i) ? 'bg-cyan-900/40 border-cyan-800/40 text-cyan-50' : 'bg-slate-900/60 border-slate-700/60 text-slate-500 line-through')"
                     x-text="historyLabel(a)"></div>
            </template>
            <div x-show="actions.length === 0" class="col-span-2 text-center text-[10px] text-slate-600">История пуста</div>
        </div>
    </div>

    <button type="button" @click="submit()" :disabled="busy"
        class="judge-submit-button shrink-0 rounded-2xl disabled:opacity-50 disabled:cursor-wait border py-3 text-lg font-bold text-white active:scale-[0.99]">
        ОТПРАВИТЬ
    </button>
</div>

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
