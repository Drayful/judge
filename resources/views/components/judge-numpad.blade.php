{{-- Numpad-модалка для ввода произвольного значения или итоговой оценки A/E.
     Открывается по нажатию «Вставить».
     Завязана на Alpine-стейт x-data="judgeTablet({...})" в родителе. --}}
<div
    x-cloak
    x-show="numpadOpen"
    @keydown.escape.window="closeNumpad()"
    x-transition.opacity
    class="absolute inset-0 z-40 grid place-items-center bg-black/80 backdrop-blur-md"
>
    <div @click.outside="closeNumpad()"
         class="judge-numpad w-[380px] rounded-3xl p-4">
        <div class="flex items-center justify-between mb-2">
            <div class="text-[11px] uppercase tracking-widest text-slate-400"
                 x-text="numpadPurpose === 'finalScore' ? 'Итоговая оценка · от 0.00 до 10.00' : 'Ввести своё значение'"></div>
            <button type="button" @click="closeNumpad()"
                class="rounded-md text-slate-400 hover:text-white text-lg leading-none px-2 py-0.5">✕</button>
        </div>
        <input type="text" inputmode="decimal" maxlength="6" x-model="numpadValue"
               @keydown.enter.prevent="applyNumpad()"
               class="h-14 w-full rounded-lg border border-slate-700 bg-slate-950/70 px-3 py-3 text-right font-mono text-3xl tabular-nums text-white shadow-inner focus:border-cyan-500 focus:ring-cyan-500"
               placeholder="0.00" aria-label="Ввод оценки">
        <div x-cloak x-show="error" class="mt-2 text-center text-xs font-semibold text-rose-300" x-text="error"></div>

        <div class="grid grid-cols-3 gap-2 mt-3">
            @foreach ([7,8,9,4,5,6,1,2,3] as $n)
                <button type="button" @click="numpadAppend('{{ $n }}')"
                    class="judge-numpad-key rounded-2xl py-3 text-2xl font-bold text-white active:scale-[0.98]">{{ $n }}</button>
            @endforeach
            <button type="button" @click="numpadAppend('.')"
                class="judge-numpad-key rounded-2xl py-3 text-2xl font-bold text-cyan-200 active:scale-[0.98]">.</button>
            <button type="button" @click="numpadAppend('0')"
                class="judge-numpad-key rounded-2xl py-3 text-2xl font-bold text-white active:scale-[0.98]">0</button>
            <button type="button" @click="numpadBackspace()"
                class="rounded-2xl bg-[#3a1f24] hover:bg-[#4a262b] border border-rose-800 py-3 text-2xl font-bold text-rose-100 active:scale-[0.98]">←</button>
        </div>

        <div class="grid grid-cols-2 gap-2 mt-3">
            <button type="button" @click="closeNumpad()"
                class="rounded-xl border border-slate-700 bg-slate-800 hover:bg-slate-700 py-3 font-semibold text-slate-200">
                Отмена
            </button>
            <button type="button" @click="applyNumpad()"
                class="judge-numpad-apply rounded-2xl py-3 font-bold text-white">
                Вставить
            </button>
        </div>
    </div>
</div>
