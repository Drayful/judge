{{-- A-бригада по FIG RG Code of Points 2025-2028.
     Индивидуальная и групповая программы имеют разные наборы штрафов. --}}

@php
    $eventPenalties = $groupProgram
        ? [
            ['v' => 0.3, 'cat' => 'formationDesign',     'label' => 'Рисунки построений'],
            ['v' => 0.3, 'cat' => 'formationAmplitude',  'label' => 'Амплитуда построений'],
            ['v' => 0.6, 'cat' => 'interrupt',           'label' => 'Нет прерывания непрерывности 4+ сек.'],
            ['v' => 0.3, 'cat' => 'groupContactDuration','label' => 'Нет гимнастки без предмета 5+ сек.'],
            ['v' => 0.6, 'cat' => 'groupContactPose',    'label' => 'Есть контакт с предметом в начале/конце'],
            ['v' => 0.3, 'cat' => 'musicIntro',          'label' => 'Музыкальное вступление'],
            ['v' => 0.3, 'cat' => 'musicNorms',          'label' => 'Музыка'],
            ['v' => 0.3, 'cat' => 'musicEnd',            'label' => 'Конец'],
        ]
        : [
            ['v' => 0.3, 'cat' => 'floorArea',  'label' => 'Площадка'],
            ['v' => 0.6, 'cat' => 'interrupt',  'label' => 'Прерывание непрерывности 4+ сек.'],
            ['v' => 0.3, 'cat' => 'musicIntro', 'label' => 'Музыкальное вступление'],
            ['v' => 0.3, 'cat' => 'musicNorms', 'label' => 'Музыка'],
            ['v' => 0.3, 'cat' => 'musicEnd',   'label' => 'Конец'],
        ];

    $collectivePenalties = [
        ['cat' => 'collectiveSync',     'label' => 'Синхронизация'],
        ['cat' => 'collectiveContrast', 'label' => 'Контраст'],
        ['cat' => 'collectiveCanon',    'label' => 'Быстрая последовательность / канон'],
        ['cat' => 'collectiveChoral',   'label' => 'Хоровая работа'],
    ];
@endphp

{{-- ====== Страница 1: считаемые штрафы и обязательные элементы ====== --}}
<template x-if="page === 1">
    <div class="col-span-12 h-full min-h-0 grid grid-cols-12 gap-2">
        <div class="col-span-4 min-h-0 flex flex-col gap-2">
            @foreach ([['connections', 'Соединения'], ['rhythm', 'Ритм']] as [$cat, $label])
                <button type="button" @click="incrementPenalty(0.1, '{{ $cat }}', 2.0)"
                    :disabled="categoryPenalty('{{ $cat }}') >= 2"
                    class="judge-score-stage {{ $groupProgram ? 'shrink-0 min-h-28' : 'flex-1 min-h-0' }} w-full rounded-2xl border p-3 text-left active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-50">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <div class="text-xs font-bold uppercase tracking-wide text-slate-200">{{ $label }}</div>
                            <div class="mt-1 text-[10px] text-slate-500">FIG: 0.00–2.00 · шаг 0.10</div>
                        </div>
                        <div class="font-mono text-2xl font-extrabold text-white tabular-nums" x-text="categoryPenalty('{{ $cat }}').toFixed(2)"></div>
                    </div>
                    <div class="mt-2 flex min-h-12 items-center justify-center rounded-xl bg-[#0e6a7a] px-4 py-2 text-2xl font-extrabold text-white shadow-md">−0.10</div>
                </button>
            @endforeach

            @if($groupProgram)
                <div class="flex-1 min-h-0 flex flex-col rounded-2xl border border-slate-800 bg-[#0c1429] p-2">
                    <div class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Типы коллективной работы · не отмечено = −0.30</div>
                    <div class="flex-1 min-h-0 grid grid-cols-2 gap-2">
                        @foreach($collectivePenalties as $item)
                            <button type="button" @click="add(0.3, '{{ $item['cat'] }}')" :disabled="!can('{{ $item['cat'] }}')"
                                :class="cat.{{ $item['cat'] }} > 0 ? 'border-emerald-400 bg-emerald-800/90 text-white' : 'border-rose-700/70 bg-rose-950/50 text-rose-100'"
                                class="min-h-0 rounded-xl border p-2 text-center text-sm font-bold leading-tight active:scale-[0.98] disabled:cursor-default disabled:opacity-100">
                                <span x-show="cat.{{ $item['cat'] }} > 0" class="mb-1 block text-3xl leading-none text-emerald-200">✓</span>
                                <span x-show="cat.{{ $item['cat'] }} === 0" class="mb-1 block font-mono text-lg text-rose-300">−0.30</span>
                                {{ $item['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="col-span-4 min-h-0 flex flex-col gap-2">
            <div class="shrink-0 flex gap-2">
                <button type="button" @click="cancel()" class="rounded-lg border border-rose-800/60 bg-[#6f1d2e] px-3 py-2 text-xs font-semibold text-white active:scale-[0.98]">ОТМЕНА</button>
                <button type="button" @click="page = 2" class="flex-1 rounded-lg border border-emerald-700/40 bg-[#0e5a3f] px-4 py-2 text-sm font-bold text-emerald-50 active:scale-[0.98]">Общие и событийные штрафы →</button>
            </div>

            <div class="judge-score-stage flex-1 min-h-0 rounded-3xl border p-3 flex flex-col items-center justify-center text-center">
                <div class="text-[10px] uppercase tracking-widest text-slate-400">Итоговая сбавка</div>
                <div class="my-1 text-6xl font-extrabold tabular-nums text-white" x-text="workingTotal().toFixed(2)"></div>
                <div class="flex items-center justify-center gap-2">
                    <div class="text-sm font-mono text-emerald-200">{{ $slot }}: <span x-text="finalScore().toFixed(2)"></span></div>
                    <button type="button" @click="openFinalScoreNumpad()" class="rounded-lg border border-indigo-700/60 bg-[#5547a5] px-3 py-1.5 text-xs font-semibold text-white active:scale-[0.98]">Вставить</button>
                </div>
                <div class="mt-1 text-[10px] text-slate-500">{{ number_format((float) $base, 2, '.', '') }} − сбавка · максимум 10.00</div>
            </div>

            <div class="shrink-0 rounded-xl border border-slate-800 bg-[#0c1429] p-2">
                <div class="text-[9px] uppercase tracking-wider text-slate-400">Автосбавка за отсутствующие требования</div>
                <div class="mt-1 grid grid-cols-3 gap-1 text-center font-mono text-xs tabular-nums">
                    <div class="rounded-md bg-cyan-950/60 px-1 py-1"><div class="text-[9px] text-cyan-200">S · 2</div><div x-text="'-' + blockPenalty('dance').toFixed(2)"></div></div>
                    <div class="rounded-md bg-amber-950/60 px-1 py-1"><div class="text-[9px] text-amber-200">Дин./эфф. · {{ $groupProgram ? '4' : '2' }}</div><div x-text="'-' + blockPenalty('dynamic').toFixed(2)"></div></div>
                    <div class="rounded-md bg-indigo-950/60 px-1 py-1"><div class="text-[9px] text-indigo-200">Итого</div><div x-text="'-' + comboPenalty().toFixed(2)"></div></div>
                </div>
            </div>

            <button type="button" @click="submit()" :disabled="busy" class="judge-submit-button shrink-0 rounded-2xl border py-3 text-lg font-bold text-white disabled:cursor-wait disabled:opacity-50 active:scale-[0.99]">ОТПРАВИТЬ</button>
        </div>

        <div class="col-span-4 min-h-0 flex flex-col gap-2">
            <div class="flex-1 min-h-0 flex flex-col rounded-2xl border border-cyan-700/40 bg-[#0e3d4a] p-2 text-white">
                <button type="button" @click="add(0.3, 'dance')" :disabled="!can('dance')" :class="can('dance') ? 'hover:brightness-110' : 'cursor-not-allowed opacity-50'" class="flex-1 min-h-0 p-1 text-left active:scale-[0.98]">
                    <div class="flex items-center justify-between"><span class="text-3xl font-extrabold">S выполнена</span><span class="rounded bg-black/30 px-2 py-1 font-mono" x-text="cat.dance + '/' + catMax.dance"></span></div>
                    <div class="mt-2 text-sm text-cyan-100/80">За каждую выполненную комбинацию танцевальных шагов</div>
                    <div class="mt-2 text-xl font-bold" x-text="'Остаток сбавки: −' + blockPenalty('dance').toFixed(2)"></div>
                </button>
            </div>
            <div class="flex-1 min-h-0 flex flex-col rounded-2xl border border-amber-700/40 bg-[#7a4a1f] p-2 text-white">
                <button type="button" @click="add(0.3, 'dynamic')" :disabled="!can('dynamic')" :class="can('dynamic') ? 'hover:brightness-110' : 'cursor-not-allowed opacity-50'" class="flex-1 min-h-0 p-1 text-left active:scale-[0.98]">
                    <div class="flex items-center justify-between"><span class="text-3xl font-extrabold">Дин./эффект выполнен</span><span class="rounded bg-black/30 px-2 py-1 font-mono" x-text="cat.dynamic + '/' + catMax.dynamic"></span></div>
                    <div class="mt-2 text-sm text-amber-100/80">За каждое выполненное динамическое изменение или эффект</div>
                    <div class="mt-2 text-xl font-bold" x-text="'Остаток сбавки: −' + blockPenalty('dynamic').toFixed(2)"></div>
                </button>
            </div>
        </div>
    </div>
</template>

{{-- ====== Страница 2: общая оценка и событийные штрафы ====== --}}
<template x-if="page === 2">
    <div class="col-span-12 h-full min-h-0 flex flex-col gap-2">
        <div class="flex-1 min-h-0 grid grid-cols-12 gap-2">
            <div class="col-span-3 min-h-0 flex flex-col gap-1.5">
                <div class="flex-1 rounded-2xl border border-slate-800 bg-[#0c1429] p-2 flex flex-col justify-center">
                    <div class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Идея / характер · выбрать одно</div>
                    <div class="flex-1 min-h-0 grid grid-cols-4 gap-1">
                        @foreach ([0, 0.3, 0.6, 1.0] as $value)
                            <button type="button" @click="selectPenalty('character', {{ $value }})"
                                :class="categoryPenalty('character') === {{ $value }} ? 'border-emerald-500 bg-emerald-800 text-white' : 'border-slate-700 bg-slate-800 text-slate-200'"
                                class="h-full min-h-14 rounded-lg border py-2 font-mono text-base font-bold active:scale-[0.98]">{{ number_format($value, 1) }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="flex-1 rounded-2xl border border-slate-800 bg-[#0c1429] p-2 flex flex-col justify-center">
                    <div class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Экспрессия тела · выбрать одно</div>
                    <div class="flex-1 min-h-0 grid grid-cols-3 gap-1">
                        @foreach ([0, 0.3, 0.6] as $value)
                            <button type="button" @click="selectPenalty('bodyExpr', {{ $value }})"
                                :class="categoryPenalty('bodyExpr') === {{ $value }} ? 'border-emerald-500 bg-emerald-800 text-white' : 'border-slate-700 bg-slate-800 text-slate-200'"
                                class="h-full min-h-14 rounded-lg border py-2 font-mono text-base font-bold active:scale-[0.98]">{{ number_format($value, 1) }}</button>
                        @endforeach
                    </div>
                </div>
                @if($groupProgram)
                    <button type="button" @click="add(0.3, 'faceExpr')" :disabled="!can('faceExpr')"
                        :class="cat.faceExpr > 0 ? 'border-emerald-400 bg-emerald-800/90 text-white' : 'border-rose-700/70 bg-rose-950/50 text-rose-100'"
                        class="flex-1 rounded-xl border px-3 py-2 text-center text-xs font-semibold leading-tight active:scale-[0.98] disabled:cursor-default disabled:opacity-100">
                        <span x-show="cat.faceExpr > 0" class="block text-2xl leading-none text-emerald-200">✓</span>
                        <span x-show="cat.faceExpr === 0" class="block font-mono text-base font-bold text-rose-300">−0.30</span>
                        Экспрессия лица
                    </button>
                @else
                    <button type="button" @click="togglePenalty(0.3, 'faceExpr')"
                        :class="hasPenalty('faceExpr') ? 'border-rose-500 bg-rose-900/70 text-white' : 'border-slate-700 bg-slate-800 text-slate-300'"
                        class="flex-1 rounded-xl border px-3 py-2 text-center text-sm font-semibold leading-tight active:scale-[0.98]"><span class="block font-mono text-lg font-bold">−0.30</span>Экспрессия лица</button>
                @endif
            </div>

            <div class="col-span-4 min-h-0 flex flex-col gap-2">
                <div class="shrink-0 flex gap-2">
                    <button type="button" @click="page = 1" class="flex-1 rounded-lg border border-slate-700 bg-slate-800 px-4 py-2 text-sm font-bold text-white active:scale-[0.98]">← Считаемые штрафы</button>
                    <button type="button" @click="cancel()" class="rounded-lg border border-rose-800/60 bg-[#6f1d2e] px-3 py-2 text-xs font-semibold text-white active:scale-[0.98]">ОТМЕНА</button>
                </div>
                <div class="judge-score-stage flex-1 min-h-0 rounded-3xl border p-3 flex flex-col items-center justify-center text-center">
                    <div class="text-[10px] uppercase tracking-widest text-slate-400">Итоговая сбавка</div>
                    <div class="my-1 text-6xl font-extrabold tabular-nums text-white" x-text="workingTotal().toFixed(2)"></div>
                    <div class="flex items-center justify-center gap-2">
                        <div class="text-sm font-mono text-emerald-200">{{ $slot }}: <span x-text="finalScore().toFixed(2)"></span></div>
                        <button type="button" @click="openFinalScoreNumpad()" class="rounded-lg border border-indigo-700/60 bg-[#5547a5] px-3 py-1.5 text-xs font-semibold text-white active:scale-[0.98]">Вставить</button>
                    </div>
                    <div class="mt-1 text-[10px] text-slate-500">{{ number_format((float) $base, 2, '.', '') }} − сбавка · максимум 10.00</div>
                </div>
                <button type="button" @click="submit()" :disabled="busy" class="judge-submit-button shrink-0 rounded-2xl border py-3 text-lg font-bold text-white disabled:cursor-wait disabled:opacity-50 active:scale-[0.99]">ОТПРАВИТЬ</button>
            </div>

            <div class="col-span-5 min-h-0 grid grid-cols-3 gap-1 content-stretch">
                @foreach($eventPenalties as $item)
                    @if($groupProgram)
                        <button type="button" @click="add({{ $item['v'] }}, '{{ $item['cat'] }}')" :disabled="!can('{{ $item['cat'] }}')"
                            :class="cat.{{ $item['cat'] }} > 0 ? 'border-emerald-400 bg-emerald-800/90 text-white' : 'border-rose-700/70 bg-rose-950/50 text-rose-100'"
                            class="min-h-0 rounded-xl border px-2 py-1.5 text-center text-[10px] font-semibold leading-tight active:scale-[0.98] disabled:cursor-default disabled:opacity-100">
                            <span x-show="cat.{{ $item['cat'] }} > 0" class="block text-2xl leading-none text-emerald-200">✓</span>
                            <span x-show="cat.{{ $item['cat'] }} === 0" class="block font-mono text-base font-extrabold text-rose-300">−{{ number_format($item['v'], 2) }}</span>{{ $item['label'] }}
                        </button>
                    @else
                        <button type="button" @click="togglePenalty({{ $item['v'] }}, '{{ $item['cat'] }}')"
                            :class="hasPenalty('{{ $item['cat'] }}') ? 'border-rose-500 bg-rose-900/70 text-white' : 'border-slate-700 bg-slate-800 text-slate-300'"
                            class="min-h-0 rounded-xl border px-2 py-1.5 text-center text-[10px] font-semibold leading-tight active:scale-[0.98]">
                            <span class="block font-mono text-base font-extrabold">−{{ number_format($item['v'], 2) }}</span>{{ $item['label'] }}
                        </button>
                    @endif
                @endforeach
                @if($groupProgram)
                    <div class="min-h-0 flex flex-col rounded-xl border border-amber-700/60 bg-amber-900/60 p-1.5 text-white">
                        <button type="button" @click="add(0.6, 'bodyConstruction')" class="flex-1 text-left text-[10px] font-semibold leading-tight active:scale-[0.98]">
                            <span class="block font-mono text-base font-extrabold">−0.60</span>Конструкция / поднятое положение · за каждый элемент
                            <span class="mt-1 block text-[9px] text-amber-200" x-text="'Сумма: −' + categoryPenalty('bodyConstruction').toFixed(2)"></span>
                        </button>
                    </div>
                @endif
            </div>
        </div>

        <div class="shrink-0 rounded-xl border border-slate-800 bg-[#0c1429] p-1.5">
            <div class="mb-1 flex items-center justify-between text-[9px] uppercase tracking-wider text-slate-400"><span>Полная история сбавок</span><span x-text="actions.length + ' действий'"></span></div>
            <div class="flex gap-1 overflow-x-auto pb-1">
                <template x-for="(a, i) in actions.slice().reverse()" :key="i">
                    <div class="shrink-0 max-w-44 rounded-md border border-slate-700 bg-slate-800/70 px-2 py-1 text-[10px] text-slate-100">
                        <span class="font-mono font-bold" x-text="(a.combo ? '+' : '−') + Number(a.v).toFixed(2)"></span><span class="ml-1 text-slate-400" x-text="a.label || 'Без категории'"></span>
                    </div>
                </template>
                <div x-show="actions.length === 0" class="text-[10px] text-slate-600">Сбавок пока нет</div>
            </div>
        </div>
    </div>
</template>
