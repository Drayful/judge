{{-- D-бригада: DB1/DB2 — сложность тела, DA1/DA2 — сложность предмета.
     Слева — пиктограммы типов элемента (^, T, ⊺, R) + две колонки крупных значений 1.0..1.7 / 1.8..2.5.
     Справа — ОТМЕНА, X(0.0) и быстрые добавки 0.1..0.9 в двух колонках. По центру — итог. --}}

@php
    $picks = [
        ['k' => '^', 'v' => 0.1, 'bg' => '#0e3d4a'],
        ['k' => 'T', 'v' => 0.2, 'bg' => '#0f5f6f'],
        ['k' => '⊺', 'v' => 0.3, 'bg' => '#0e3d4a'],
        ['k' => 'R', 'v' => 0.4, 'bg' => '#0f5f6f'],
    ];
@endphp

{{-- ====== ЛЕВАЯ ЗОНА ====== --}}
<div class="col-span-5 grid grid-cols-3 gap-2 h-full min-h-0">

    <div class="flex flex-col gap-2 h-full min-h-0">
        @foreach ($picks as $p)
            <button type="button" @click="add({{ $p['v'] }})"
                style="background-color: {{ $p['bg'] }}"
                class="flex-1 min-h-0 rounded-2xl border border-cyan-900/30 text-cyan-50 hover:brightness-110 active:scale-[0.98] shadow-md flex flex-col items-center justify-center">
                <div class="text-3xl xl:text-4xl font-black leading-none">{{ $p['k'] }}</div>
                <div class="mt-1 text-[10px] uppercase tracking-wider text-cyan-200/70">+{{ number_format($p['v'], 1) }}</div>
            </button>
        @endforeach
    </div>

    <div class="flex flex-col gap-2 h-full min-h-0">
        @foreach ([1.0, 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7] as $v)
            <button type="button" @click="add({{ $v }})"
                class="flex-1 min-h-0 rounded-xl bg-[#13294b] hover:bg-[#1a3865] border border-slate-700 text-white text-2xl xl:text-3xl font-bold shadow-md tabular-nums active:scale-[0.98] flex items-center justify-center">
                {{ number_format($v, 1) }}
            </button>
        @endforeach
    </div>
    <div class="flex flex-col gap-2 h-full min-h-0">
        @foreach ([1.8, 1.9, 2.0, 2.1, 2.2, 2.3, 2.4, 2.5] as $v)
            <button type="button" @click="add({{ $v }})"
                class="flex-1 min-h-0 rounded-xl bg-[#163057] hover:bg-[#1f3f73] border border-slate-700 text-white text-2xl xl:text-3xl font-bold shadow-md tabular-nums active:scale-[0.98] flex items-center justify-center">
                {{ number_format($v, 1) }}
            </button>
        @endforeach
    </div>
</div>

{{-- ====== ЦЕНТР ====== --}}
<div class="col-span-3 flex flex-col gap-2 h-full min-h-0">
    <div class="flex-1 min-h-0 rounded-2xl border border-slate-700 bg-[#0f1830] p-3 flex flex-col items-center justify-center text-center">
        <div class="text-[10px] uppercase tracking-widest text-slate-400">Итоговая оценка</div>
        <div class="my-1 text-5xl xl:text-6xl font-extrabold tabular-nums text-white leading-none" x-text="finalScore().toFixed(2)"></div>

        <div class="flex items-center gap-2 w-full justify-center">
            <div class="rounded-lg bg-slate-800 border border-slate-700 px-2 py-1 text-sm font-mono tabular-nums text-cyan-100 min-w-[100px] text-center">
                {{ $slot }} (оценка)
            </div>
            <button type="button" @click="openNumpad()"
                class="rounded-lg bg-[#5547a5] hover:bg-[#6657c2] border border-indigo-700/60 px-3 py-1.5 text-xs font-semibold text-white shadow">
                Вставить
            </button>
        </div>

        {{-- Лента шагов снизу --}}
        <div class="mt-2 w-full grid grid-cols-6 gap-1">
            <template x-for="(a, i) in actions.slice(0, 12)" :key="i">
                <div class="rounded-md bg-cyan-900/40 border border-cyan-800/40 text-cyan-50 text-[11px] font-mono tabular-nums text-center py-0.5"
                     x-text="'+' + Number(a.v).toFixed(1)"></div>
            </template>
            <div x-show="actions.length === 0" class="col-span-6 text-center text-[10px] text-slate-600">История пуста</div>
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
            class="shrink-0 h-12 rounded-2xl bg-[#6f1d2e] hover:bg-[#8a2638] border border-rose-800/60 text-base font-bold text-white shadow-md active:scale-[0.98]">
            ОТМЕНА
        </button>
        @foreach ([0.5, 0.6, 0.7, 0.8, 0.9] as $v)
            <button type="button" @click="add({{ $v }})"
                class="flex-1 min-h-0 rounded-2xl bg-[#1e6a85] hover:bg-[#247c9b] border border-cyan-800/40 text-2xl xl:text-3xl font-bold text-white tabular-nums shadow-md active:scale-[0.98] flex items-center justify-center">
                {{ number_format($v, 1) }}
            </button>
        @endforeach
    </div>
    <div class="flex flex-col gap-2 h-full min-h-0">
        <button type="button" @click="clearAll()"
            class="shrink-0 h-12 rounded-2xl bg-[#2a2e44] hover:bg-[#363b58] border border-slate-700 text-white font-semibold active:scale-[0.98] flex flex-col items-center justify-center">
            <div class="text-xl leading-none">✕</div>
            <div class="text-[9px] text-slate-400">0.0</div>
        </button>
        @foreach ([0.1, 0.2, 0.3, 0.4] as $v)
            <button type="button" @click="add({{ $v }})"
                class="flex-1 min-h-0 rounded-2xl bg-[#0f5f6f] hover:bg-[#117383] border border-cyan-700/40 text-2xl xl:text-3xl font-bold text-white tabular-nums shadow-md active:scale-[0.98] flex items-center justify-center">
                {{ number_format($v, 1) }}
            </button>
        @endforeach
        <button type="button" @click="add(0.5)"
            class="flex-1 min-h-0 rounded-2xl bg-[#0e6a7a] hover:bg-[#0e8294] border border-cyan-700/40 text-white font-semibold shadow-md active:scale-[0.98] flex flex-col items-center justify-center">
            <div class="text-2xl xl:text-3xl leading-none font-black">A</div>
            <div class="mt-0.5 text-[10px] tracking-wider opacity-80">АКРОБАТИКА</div>
        </button>
    </div>
</div>
