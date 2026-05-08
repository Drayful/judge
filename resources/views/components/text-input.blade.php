@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-slate-700 bg-slate-950/50 text-slate-100 placeholder:text-slate-500 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm']) }}>
