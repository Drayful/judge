{{-- DA-бригада · групповые упражнения: DC + акробатика, баллы 0.2–1.0 --}}

@php
    $dcTypes = [
        ['k' => 'CC',     'label' => 'CC',   'sub' => 'без бросков', 'bg' => '#4a3d8a', 'display' => 'CC'],
        ['k' => 'CR',     'label' => 'CR',   'sub' => 'с бросками',  'bg' => '#3b3070', 'display' => 'CR'],
        ['k' => 'C_UP',   'label' => 'C↗↗', 'sub' => 'броски',      'bg' => '#1e6a85', 'display' => 'C_arrows_up'],
        ['k' => 'C_DOWN', 'label' => 'C↓↓', 'sub' => 'ловли',       'bg' => '#0f5f6f', 'display' => 'C_arrows_down'],
    ];
    $allScores = [0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0];
@endphp

<div class="col-span-3 flex flex-col gap-2 h-full min-h-0">
    @foreach ($dcTypes as $p)
        <button type="button" @click="selectDcType(@js($p['k']), @js($p['label']))"
            style="background-color: {{ $p['bg'] }}"
            :class="pendingDc && pendingDc.symbol === @js($p['k'])
                ? 'ring-2 ring-amber-400 brightness-125 scale-[0.99]'
                : 'hover:brightness-110'"
            class="flex-1 min-h-0 rounded-2xl border border-indigo-900/40 text-white active:scale-[0.98] shadow-md flex flex-col items-center justify-center transition">
            @if ($p['display'] === 'C_arrows_up')
                <div class="text-3xl xl:text-4xl font-black leading-none flex items-start justify-center gap-0">
                    <span>C</span><span class="text-lg xl:text-xl leading-none mt-0.5 tracking-tighter">↗↗</span>
                </div>
            @elseif ($p['display'] === 'C_arrows_down')
                <div class="text-3xl xl:text-4xl font-black leading-none flex items-start justify-center gap-0">
                    <span>C</span><span class="text-lg xl:text-xl leading-none mt-1 tracking-tighter">↓↓</span>
                </div>
            @else
                <div class="text-3xl xl:text-4xl font-black leading-none">{{ $p['display'] }}</div>
            @endif
            <div class="mt-1 text-[10px] uppercase tracking-wider text-indigo-100/70">{{ $p['sub'] }}</div>
        </button>
    @endforeach

    <button type="button" @click="toggleAcro()"
        :class="acroPending ? 'ring-2 ring-amber-400 brightness-125 bg-[#6b4cc0]' : 'bg-[#5547a5] hover:bg-[#6657c2]'"
        class="flex-1 min-h-0 rounded-2xl border border-indigo-700/60 text-white shadow-md active:scale-[0.98] flex flex-col items-center justify-center transition">
        <div class="text-3xl xl:text-4xl font-black leading-none">A</div>
        <div class="mt-1 text-[10px] uppercase tracking-wider text-indigo-100/80">Акробатика</div>
        <div class="mt-0.5 text-xs font-mono tabular-nums"
             :class="groupDaComputed().acro >= groupDaLim().acro ? 'text-rose-300' : 'text-amber-200'"
             x-text="groupDaComputed().acro + '/' + groupDaLim().acro"></div>
    </button>
</div>

<div class="col-span-5 flex flex-col gap-2 h-full min-h-0">
    <div class="shrink-0 grid grid-cols-2 gap-2">
        <button type="button" @click="setAgeGroup('junior')"
            :class="ageGroup === 'junior' ? 'bg-[#4a3d8a] border-indigo-500 ring-2 ring-indigo-400/60 text-white' : 'bg-[#101a36] border-slate-700 text-slate-400 hover:text-slate-200'"
            class="rounded-xl border py-1.5 px-2 transition active:scale-[0.98]">
            <div class="text-xs font-bold uppercase tracking-wider">Юниоры</div>
            <div class="text-[10px] opacity-80">DC 6–10 · CC/CR/бр≥2</div>
        </button>
        <button type="button" @click="setAgeGroup('senior')"
            :class="ageGroup === 'senior' ? 'bg-[#4a3d8a] border-indigo-500 ring-2 ring-indigo-400/60 text-white' : 'bg-[#101a36] border-slate-700 text-slate-400 hover:text-slate-200'"
            class="rounded-xl border py-1.5 px-2 transition active:scale-[0.98]">
            <div class="text-xs font-bold uppercase tracking-wider">Сеньоры</div>
            <div class="text-[10px] opacity-80">DC 9–14 · CC/CR/бр≥3</div>
        </button>
    </div>

    <div class="flex-1 min-h-0 rounded-2xl border border-slate-700 bg-[#0f1830] p-3 flex flex-col items-center justify-center text-center">
        <div class="text-[10px] uppercase tracking-widest text-slate-400">Итоговая оценка</div>
        <div class="my-1 text-5xl xl:text-6xl font-extrabold tabular-nums text-white leading-none" x-text="finalScore().toFixed(2)"></div>

        <div class="flex flex-wrap items-center justify-center gap-1.5 text-[10px] font-mono tabular-nums">
            <span class="rounded bg-slate-800 border px-1.5 py-0.5"
                  :class="groupDaComputed().used > groupDaLim().elementsMax ? 'border-rose-600 bg-rose-950/40 text-rose-200' : (groupDaComputed().used < groupDaLim().elementsMin ? 'border-amber-600/60 text-amber-200' : 'border-slate-700 text-slate-300')"
                  x-text="'DC: ' + groupDaComputed().used + '/' + groupDaLim().elementsMax"></span>
            <span class="rounded bg-slate-800 border px-1.5 py-0.5"
                  :class="groupDaComputed().cc < groupDaLim().ccMin ? 'border-amber-600/60 text-amber-200' : 'border-slate-700 text-slate-300'"
                  x-text="'CC: ' + groupDaComputed().cc + '/' + groupDaLim().ccMin"></span>
            <span class="rounded bg-slate-800 border px-1.5 py-0.5"
                  :class="groupDaComputed().cr < groupDaLim().crMin ? 'border-amber-600/60 text-amber-200' : 'border-slate-700 text-slate-300'"
                  x-text="'CR: ' + groupDaComputed().cr + '/' + groupDaLim().crMin"></span>
            <span class="rounded bg-slate-800 border px-1.5 py-0.5"
                  :class="groupDaComputed().multi < groupDaLim().multiMin ? 'border-amber-600/60 text-amber-200' : 'border-slate-700 text-slate-300'"
                  x-text="'Бр/Лв: ' + groupDaComputed().multi + '/' + groupDaLim().multiMin"></span>
            <span class="rounded bg-slate-800 border px-1.5 py-0.5"
                  :class="groupDaComputed().acro >= groupDaLim().acro ? 'text-amber-300 border-amber-600/40' : 'border-slate-700 text-slate-300'"
                  x-text="'Акр: ' + groupDaComputed().acro + '/' + groupDaLim().acro"></span>
        </div>

        <div class="min-h-[20px] mt-1">
            <template x-if="pendingDc">
                <div class="text-[11px] text-amber-200">
                    <span x-text="pendingDc.label"></span> — нажмите балл или «Х»
                </div>
            </template>
            <template x-if="!pendingDc && acroPending">
                <div class="text-[11px] text-amber-200">Акробатика — нажмите балл или «Х»</div>
            </template>
            <template x-if="!pendingDc && !acroPending && groupDaComputed().used >= groupDaLim().elementsMax">
                <div class="text-[11px] text-rose-300">Лимит DC достигнут — новые баллы не зачтутся</div>
            </template>
            <template x-if="!pendingDc && !acroPending && groupDaComputed().used < groupDaLim().elementsMax">
                <div class="text-[11px] text-slate-500">Сотрудничество / акробатика + балл, либо просто балл</div>
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

        <div class="mt-2 w-full grid grid-cols-3 gap-1 max-h-24 overflow-y-auto">
            <template x-for="(a, i) in actions.slice(0, 15)" :key="i">
                <div class="rounded-md border text-[10px] text-center py-0.5 px-1 truncate"
                     :class="a.notDone
                        ? 'bg-rose-900/40 border-rose-800/50 text-rose-100'
                        : (isCounted(i) ? 'bg-indigo-900/40 border-indigo-800/40 text-indigo-50' : 'bg-slate-900/60 border-slate-700/60 text-slate-500 line-through')"
                     x-text="daHistoryLabel(a)"></div>
            </template>
            <div x-show="actions.length === 0" class="col-span-3 text-center text-[10px] text-slate-600">История пуста</div>
        </div>
    </div>

    <button type="button" @click="submit()" :disabled="busy"
        class="shrink-0 rounded-2xl bg-[#3b3070] hover:bg-[#4a3d8a] disabled:opacity-50 disabled:cursor-wait border border-indigo-700/60 py-3 text-lg font-bold text-white shadow-lg shadow-indigo-950/40 active:scale-[0.99]">
        ОТПРАВИТЬ
    </button>
</div>

<div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
    <div class="shrink-0 grid grid-cols-2 gap-2">
        <button type="button" @click="cancel()"
            class="rounded-2xl bg-[#6f1d2e] hover:bg-[#8a2638] border border-rose-800/60 py-3 text-base font-bold text-white shadow-md active:scale-[0.98]">
            ОТМЕНА
        </button>
        <button type="button" @click="pendingDc ? markDcNotDone() : markAcroNotDone()"
            :class="(pendingDc || acroPending) ? 'border-rose-600 ring-1 ring-rose-500/50' : 'opacity-60 border-slate-700'"
            class="rounded-2xl bg-[#5a1d28] hover:bg-[#74232f] border text-white font-semibold active:scale-[0.98] flex flex-col items-center justify-center py-2 shadow-md transition">
            <div class="text-2xl leading-none font-black">Х</div>
            <div class="text-[9px] text-rose-200/80" x-text="pendingDc ? 'не выполнен' : 'акроб. 0'"></div>
        </button>
    </div>

    <div class="flex-1 min-h-0 grid grid-cols-3 grid-rows-3 gap-2">
        @foreach ($allScores as $v)
            <button type="button" @click="assignValue({{ $v }})"
                :class="(pendingDc || acroPending) ? 'ring-2 ring-amber-400/80 brightness-110' : ''"
                class="min-h-0 rounded-xl bg-[#13294b] hover:bg-[#1a3865] border border-slate-700 text-white text-xl xl:text-2xl font-bold shadow-md tabular-nums active:scale-[0.98] flex items-center justify-center transition">
                {{ number_format($v, 1) }}
            </button>
        @endforeach
    </div>
</div>
