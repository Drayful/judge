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
