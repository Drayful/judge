{{-- Запасные стили страниц «Потоки» и «Стартовый лист»: работают, даже если CSS на сервере ещё не пересобран (npm run build). --}}
<style>
    .align-top{vertical-align:top}.items-stretch{align-items:stretch}.gap-8{gap:2rem}.mr-1{margin-right:.25rem}.pl-9{padding-left:2.25rem}.w-14{width:3.5rem}
    .px-1\.5{padding-left:.375rem;padding-right:.375rem}.py-0\.5{padding-top:.125rem;padding-bottom:.125rem}.py-1\.5{padding-top:.375rem;padding-bottom:.375rem}.py-2\.5{padding-top:.625rem;padding-bottom:.625rem}
    .space-y-1\.5>:not([hidden])~:not([hidden]){margin-top:.375rem}.space-y-8>:not([hidden])~:not([hidden]){margin-top:2rem}
    .text-\[10px\]{font-size:10px}.text-\[11px\]{font-size:11px}
    .min-h-\[60px\]{min-height:60px}.min-h-\[70px\]{min-height:70px}.min-h-\[90px\]{min-height:90px}.min-w-\[16rem\]{min-width:16rem}.max-h-\[70vh\]{max-height:70vh}
    .select-none{user-select:none;-webkit-user-select:none}.cursor-grab{cursor:grab}.line-through{text-decoration-line:line-through}
    .first-letter\:uppercase::first-letter{text-transform:uppercase}
    .bg-slate-900\/50{background-color:rgb(15 23 42/.5)}.bg-slate-900\/60{background-color:rgb(15 23 42/.6)}.bg-slate-900\/70{background-color:rgb(15 23 42/.7)}
    .bg-slate-950\/30{background-color:rgb(2 6 23/.3)}.bg-slate-950\/40{background-color:rgb(2 6 23/.4)}.bg-slate-950\/50{background-color:rgb(2 6 23/.5)}.bg-slate-950\/60{background-color:rgb(2 6 23/.6)}.bg-slate-950\/70{background-color:rgb(2 6 23/.7)}
    .bg-amber-950\/20{background-color:rgb(69 26 3/.2)}.bg-amber-950\/25{background-color:rgb(69 26 3/.25)}.bg-amber-950\/30{background-color:rgb(69 26 3/.3)}
    .bg-emerald-900\/50{background-color:rgb(6 78 59/.5)}.bg-emerald-950\/40{background-color:rgb(2 44 34/.4)}
    .bg-rose-900\/60{background-color:rgb(136 19 55/.6)}.bg-rose-950\/30{background-color:rgb(76 5 25/.3)}.bg-rose-950\/40{background-color:rgb(76 5 25/.4)}
    .bg-sky-950\/10{background-color:rgb(8 47 73/.1)}.bg-sky-950\/30{background-color:rgb(8 47 73/.3)}.bg-violet-950\/50{background-color:rgb(46 16 101/.5)}
    .border-amber-700\/60{border-color:rgb(180 83 9/.6)}.border-amber-800\/60{border-color:rgb(146 64 14/.6)}.border-emerald-800\/70{border-color:rgb(6 95 70/.7)}
    .border-rose-800\/70{border-color:rgb(159 18 57/.7)}.border-sky-800\/70{border-color:rgb(7 89 133/.7)}.border-slate-800\/80{border-color:rgb(30 41 59/.8)}
    .text-amber-200\/70{color:rgb(253 230 138/.7)}.text-amber-300\/70{color:rgb(252 211 77/.7)}
    .placeholder\:text-slate-500::placeholder{color:#64748b}
    .hover\:bg-slate-800:hover{background-color:#1e293b}.hover\:bg-amber-500:hover{background-color:#f59e0b}.hover\:bg-emerald-500:hover{background-color:#10b981}
    .hover\:bg-emerald-900\/40:hover{background-color:rgb(6 78 59/.4)}.hover\:bg-emerald-900\/50:hover{background-color:rgb(6 78 59/.5)}.hover\:bg-rose-950\/60:hover{background-color:rgb(76 5 25/.6)}
    .hover\:bg-violet-900\/60:hover{background-color:rgb(76 29 149/.6)}.hover\:text-rose-300:hover{color:#fda4af}.hover\:text-white:hover{color:#fff}.hover\:underline:hover{text-decoration-line:underline}
    .focus\:border-emerald-500:focus{border-color:#10b981}.focus\:ring-emerald-500:focus{--tw-ring-color:#10b981}
    .disabled\:opacity-40:disabled{opacity:.4}
    .has-\[\:checked\]\:border-emerald-500:has(:checked){border-color:#10b981}.has-\[\:checked\]\:bg-emerald-950\/40:has(:checked){background-color:rgb(2 44 34/.4)}
    @media (min-width:1024px){.lg\:flex-row{flex-direction:row}.lg\:w-72{width:18rem}.lg\:sticky{position:sticky}.lg\:top-24{top:6rem}}
</style>
