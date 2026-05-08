@props(['class' => ''])

<div {{ $attributes->merge(['class' => 'bg-slate-900/70 border border-slate-800 shadow-lg shadow-slate-950/30 rounded-xl '.$class]) }}>
    <div class="p-6">
        {{ $slot }}
    </div>
</div>

