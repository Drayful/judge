{{-- A-бригада: Artistry. Landscape, без прокрутки.
     Лимит 2/2 на «Танц. шаги» и «Дин. изменения»: бейдж → красный, тайл → серый/disabled.
     «Вставить» → numpad, «ОТМЕНА» → удалить последнее действие. --}}

{{-- ====== Страница 1 ====== --}}
<template x-if="page === 1">
    <div class="col-span-12 h-full min-h-0 grid grid-cols-12 gap-2">

        <div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
            @php
                $catLeft1 = [
                    ['v' => 0.1, 'label' => 'Соединение',  'cat' => 'union',     'color' => '#1f78c4'],
                    ['v' => 0.1, 'label' => 'Ритм',         'cat' => 'rhythm',    'color' => '#9a6c1a'],
                    ['v' => 0.6, 'label' => 'Прерывание',  'cat' => 'interrupt', 'color' => '#5547a5'],
                ];
            @endphp
            @foreach ($catLeft1 as $c)
                <button type="button" @click="add({{ $c['v'] }}, '{{ $c['cat'] }}')"
                    style="background-color: {{ $c['color'] }}"
                    class="flex-1 min-h-0 rounded-2xl border border-slate-700/40 hover:brightness-110 px-3 py-2 text-left text-white shadow-md active:scale-[0.98] flex flex-col justify-center">
                    <div class="text-3xl xl:text-4xl font-extrabold tabular-nums leading-none">−{{ number_format($c['v'], 1) }}</div>
                    <div class="mt-1 text-xs xl:text-sm uppercase tracking-wide opacity-90">{{ $c['label'] }}</div>
                </button>
            @endforeach
        </div>

        <div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
            <div class="shrink-0 flex justify-between gap-2">
                <button type="button" @click="cancel()"
                    class="rounded-lg bg-[#6f1d2e] hover:bg-[#8a2638] border border-rose-800/60 px-3 py-1.5 text-xs font-semibold text-white">
                    ОТМЕНА
                </button>
                <button type="button" @click="page = 2"
                    class="rounded-lg bg-[#0e5a3f] hover:bg-[#117a52] border border-emerald-700/40 px-3 py-1.5 text-xs font-semibold text-emerald-50">
                    Следующая стр. →
                </button>
            </div>

            <div class="flex-1 min-h-0 rounded-2xl border border-slate-700 bg-[#0f1830] p-3 flex flex-col items-center justify-center text-center">
                <div class="text-[10px] uppercase tracking-widest text-slate-400">Итоговая сбавка</div>
                <div class="my-1 text-5xl xl:text-6xl font-extrabold tabular-nums text-white leading-none" x-text="workingTotal().toFixed(2)"></div>

                <div class="flex items-center gap-2 w-full justify-center">
                    <div class="rounded-lg bg-slate-800 border border-slate-700 px-2 py-1 text-sm font-mono tabular-nums text-emerald-200 min-w-[90px] text-center">
                        {{ $slot }} (оценка)
                    </div>
                    <button type="button" @click="openNumpad()"
                        class="rounded-lg bg-[#5547a5] hover:bg-[#6657c2] border border-indigo-700/60 px-3 py-1.5 text-xs font-semibold text-white">
                        Вставить
                    </button>
                </div>

                <div class="mt-1 text-[10px] text-slate-500">{{ $slot }}: финал A = 10.00 − сбавка → <span class="text-emerald-300 font-mono" x-text="finalScore().toFixed(2)"></span></div>
            </div>

            <div class="shrink-0 rounded-xl border border-slate-800 bg-[#0c1429] p-2">
                <div class="text-[9px] uppercase tracking-wider text-slate-400 mb-1">Сбавка за танц. шаги и дин./эффекты</div>
                <div class="grid grid-cols-3 gap-1 text-center font-mono tabular-nums text-xs">
                    <div class="rounded-md bg-[#0e3d4a]/70 border border-cyan-900/40 px-1 py-1">
                        <div class="text-[9px] uppercase text-cyan-200/70">Танц. шаги</div>
                        <div class="text-sm text-white" x-text="'-' + sumOf('dance').toFixed(2)"></div>
                        <div class="text-[9px]" :class="cat.dance >= catMax.dance ? 'text-rose-400 font-bold' : 'text-slate-400'" x-text="cat.dance + '/' + catMax.dance"></div>
                    </div>
                    <div class="rounded-md bg-[#7a4a1f]/70 border border-amber-900/40 px-1 py-1">
                        <div class="text-[9px] uppercase text-amber-200/80">Дин./эфф.</div>
                        <div class="text-sm text-white" x-text="'-' + sumOf('dynamic').toFixed(2)"></div>
                        <div class="text-[9px]" :class="cat.dynamic >= catMax.dynamic ? 'text-rose-400 font-bold' : 'text-slate-400'" x-text="cat.dynamic + '/' + catMax.dynamic"></div>
                    </div>
                    <div class="rounded-md bg-[#1c2547] border border-indigo-900/40 px-1 py-1">
                        <div class="text-[9px] uppercase text-indigo-200/80">Итого</div>
                        <div class="text-sm text-white" x-text="'-' + (sumOf('dance') + sumOf('dynamic')).toFixed(2)"></div>
                        <div class="text-[9px] text-slate-400">&nbsp;</div>
                    </div>
                </div>
            </div>

            <button type="button" @click="submit()" :disabled="busy"
                class="shrink-0 rounded-2xl bg-[#3b3070] hover:bg-[#4a3d8a] disabled:opacity-50 disabled:cursor-wait border border-indigo-700/60 py-3 text-lg font-bold text-white shadow-lg shadow-indigo-950/40 active:scale-[0.99]">
                ОТПРАВИТЬ
            </button>
        </div>

        <div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
            <button type="button"
                @click="add(0.3, 'dance')"
                :disabled="!can('dance')"
                :class="can('dance')
                    ? 'border-cyan-700/40 hover:brightness-110 active:scale-[0.98]'
                    : 'opacity-40 cursor-not-allowed border-slate-700 grayscale'"
                style="background-color: #0e3d4a"
                class="flex-1 min-h-0 rounded-2xl border px-3 py-2 text-left text-white shadow-md relative transition flex flex-col justify-center">
                <div class="absolute top-2 right-2 text-[11px] font-bold rounded px-2 py-0.5 tabular-nums"
                     :class="cat.dance >= catMax.dance ? 'bg-rose-700 text-white' : 'bg-black/40 text-slate-200'"
                     x-text="cat.dance + '/' + catMax.dance"></div>
                <div class="text-3xl xl:text-4xl font-extrabold tabular-nums leading-none">−0.3</div>
                <div class="mt-1 text-xs xl:text-sm uppercase tracking-wide opacity-90">Танцевальные шаги</div>
                <div class="mt-0.5 text-[10px] opacity-70" x-text="can('dance') ? ('Доступно: ' + left('dance')) : 'Лимит (2/2)'"></div>
            </button>

            <button type="button"
                @click="add(0.3, 'dynamic')"
                :disabled="!can('dynamic')"
                :class="can('dynamic')
                    ? 'border-amber-700/40 hover:brightness-110 active:scale-[0.98]'
                    : 'opacity-40 cursor-not-allowed border-slate-700 grayscale'"
                style="background-color: #7a4a1f"
                class="flex-1 min-h-0 rounded-2xl border px-3 py-2 text-left text-white shadow-md relative transition flex flex-col justify-center">
                <div class="absolute top-2 right-2 text-[11px] font-bold rounded px-2 py-0.5 tabular-nums"
                     :class="cat.dynamic >= catMax.dynamic ? 'bg-rose-700 text-white' : 'bg-black/40 text-slate-200'"
                     x-text="cat.dynamic + '/' + catMax.dynamic"></div>
                <div class="text-3xl xl:text-4xl font-extrabold tabular-nums leading-none">−0.3</div>
                <div class="mt-1 text-xs xl:text-sm uppercase tracking-wide opacity-90">Динамические изменения и эффекты</div>
                <div class="mt-0.5 text-[10px] opacity-70" x-text="can('dynamic') ? ('Доступно: ' + left('dynamic')) : 'Лимит (2/2)'"></div>
            </button>
        </div>
    </div>
</template>

{{-- ====== Страница 2 ====== --}}
<template x-if="page === 2">
    <div class="col-span-12 h-full min-h-0 flex flex-col gap-2">
        <div class="flex-1 min-h-0 grid grid-cols-12 gap-2">

            <div class="col-span-4 grid grid-cols-2 gap-2 h-full min-h-0">
                <div class="flex flex-col gap-2 h-full min-h-0">
                    <button type="button" @click="add(0.3, 'character')" class="flex-1 min-h-0 rounded-xl bg-[#1f78c4] hover:brightness-110 text-3xl xl:text-4xl font-extrabold text-white tabular-nums shadow-md active:scale-[0.98]">−0.3</button>
                    <button type="button" @click="add(0.6, 'character')" class="flex-1 min-h-0 rounded-xl bg-[#0e6a7a] hover:brightness-110 text-3xl xl:text-4xl font-extrabold text-white tabular-nums shadow-md active:scale-[0.98]">−0.6</button>
                    <button type="button" @click="add(1.0, 'character')" class="flex-1 min-h-0 rounded-xl bg-[#962638] hover:brightness-110 text-3xl xl:text-4xl font-extrabold text-white tabular-nums shadow-md active:scale-[0.98]">−1.0</button>
                    <div class="shrink-0 text-[10px] uppercase tracking-wider text-slate-400 text-center">Характер</div>
                </div>
                <div class="flex flex-col gap-2 h-full min-h-0">
                    <button type="button" @click="add(0.3, 'bodyExpr')" class="flex-1 min-h-0 rounded-xl bg-[#1f78c4] hover:brightness-110 text-3xl xl:text-4xl font-extrabold text-white tabular-nums shadow-md active:scale-[0.98]">−0.3</button>
                    <button type="button" @click="add(0.6, 'bodyExpr')" class="flex-1 min-h-0 rounded-xl bg-[#0e6a7a] hover:brightness-110 text-3xl xl:text-4xl font-extrabold text-white tabular-nums shadow-md active:scale-[0.98]">−0.6</button>
                    <button type="button" @click="add(1.0, 'bodyExpr')" class="flex-1 min-h-0 rounded-xl bg-[#962638] hover:brightness-110 text-3xl xl:text-4xl font-extrabold text-white tabular-nums shadow-md active:scale-[0.98]">−1.0</button>
                    <div class="shrink-0 text-[10px] uppercase tracking-wider text-slate-400 text-center">Экспрессия тела</div>
                </div>
            </div>

            <div class="col-span-4 flex flex-col gap-2 h-full min-h-0">
                <div class="shrink-0 flex justify-between gap-2">
                    <button type="button" @click="page = 1"
                        class="rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 px-3 py-1.5 text-xs font-semibold text-white">
                        ← Предыдущая стр.
                    </button>
                    <button type="button" @click="cancel()"
                        class="rounded-lg bg-[#6f1d2e] hover:bg-[#8a2638] border border-rose-800/60 px-3 py-1.5 text-xs font-semibold text-white">
                        ОТМЕНА
                    </button>
                </div>

                <div class="flex-1 min-h-0 rounded-2xl border border-slate-700 bg-[#0f1830] p-3 flex flex-col items-center justify-center text-center">
                    <div class="text-[10px] uppercase tracking-widest text-slate-400">Итоговая сбавка</div>
                    <div class="my-1 text-5xl xl:text-6xl font-extrabold tabular-nums text-white leading-none" x-text="workingTotal().toFixed(2)"></div>

                    <div class="flex items-center gap-2 w-full justify-center">
                        <div class="rounded-lg bg-slate-800 border border-slate-700 px-2 py-1 text-sm font-mono tabular-nums text-emerald-200 min-w-[90px] text-center">
                            {{ $slot }} (оценка)
                        </div>
                        <button type="button" @click="openNumpad()"
                            class="rounded-lg bg-[#5547a5] hover:bg-[#6657c2] border border-indigo-700/60 px-3 py-1.5 text-xs font-semibold text-white">
                            Вставить
                        </button>
                    </div>
                    <div class="mt-1 text-[10px] text-slate-500">{{ $slot }}: финал A = 10.00 − сбавка → <span class="text-emerald-300 font-mono" x-text="finalScore().toFixed(2)"></span></div>
                </div>

                <button type="button" @click="submit()" :disabled="busy"
                    class="shrink-0 rounded-2xl bg-[#3b3070] hover:bg-[#4a3d8a] disabled:opacity-50 disabled:cursor-wait border border-indigo-700/60 py-3 text-lg font-bold text-white shadow-lg shadow-indigo-950/40 active:scale-[0.99]">
                    ОТПРАВИТЬ
                </button>
            </div>

            <div class="col-span-4 grid grid-cols-2 gap-2 h-full min-h-0">
                @php
                    $catRight2 = [
                        ['v' => 0.3, 'label' => 'Экспрессия лица',           'cat' => 'faceExpr',   'color' => '#0e3d4a'],
                        ['v' => 0.3, 'label' => 'Использование площадки',    'cat' => 'space',      'color' => '#1f78c4'],
                        ['v' => 0.3, 'label' => 'Соответствие муз.характеру','cat' => 'musicChar',  'color' => '#0e6a7a'],
                        ['v' => 0.3, 'label' => 'Музыкальное вступление',    'cat' => 'musicIntro', 'color' => '#7a4a1f'],
                        ['v' => 0.3, 'label' => 'Музыкальная динамика',      'cat' => 'musicDyn',   'color' => '#5547a5'],
                        ['v' => 0.3, 'label' => 'Связь упражнения',          'cat' => 'link',       'color' => '#1e6a85'],
                    ];
                @endphp
                @foreach ($catRight2 as $c)
                    <button type="button" @click="add({{ $c['v'] }}, '{{ $c['cat'] }}')"
                        style="background-color: {{ $c['color'] }}"
                        class="min-h-0 rounded-xl border border-slate-700/40 hover:brightness-110 px-2 py-2 text-left text-white shadow-md active:scale-[0.98] flex flex-col justify-center">
                        <div class="text-xl xl:text-2xl font-bold tabular-nums leading-none">−{{ number_format($c['v'], 1) }}</div>
                        <div class="mt-1 text-[10px] uppercase tracking-wide opacity-90 leading-tight">{{ $c['label'] }}</div>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Лента истории сбавок: цветной фон по категории, значение и подпись --}}
        <div class="shrink-0 rounded-xl border border-slate-800 bg-[#0c1429] p-1.5">
            <div class="text-[9px] uppercase tracking-wider text-slate-400 mb-1">История сбавок</div>
            <div class="grid grid-cols-12 gap-1">
                <template x-for="(a, i) in actions.slice(0, 12).slice().reverse()" :key="i">
                    <div class="rounded-md border text-[10px] py-0.5 px-1 text-center"
                         :class="a.cat === 'dance' ? 'bg-cyan-900/40 border-cyan-800/40 text-cyan-50'
                                : a.cat === 'dynamic' ? 'bg-amber-900/40 border-amber-800/40 text-amber-50'
                                : 'bg-slate-800/60 border-slate-700 text-slate-100'">
                        <div class="font-mono tabular-nums" x-text="'-' + Number(a.v).toFixed(2)"></div>
                        <div class="text-[9px] opacity-75 truncate" x-text="a.label || '—'"></div>
                    </div>
                </template>
                <div x-show="actions.length === 0" class="col-span-12 text-center text-[10px] text-slate-600 py-1">Сбавок пока нет</div>
            </div>
        </div>
    </div>
</template>
