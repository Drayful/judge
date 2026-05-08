@props(['tone' => 'gray'])

@php
    $tones = [
        'gray' => 'bg-slate-800/90 text-slate-200 border-slate-600',
        'blue' => 'bg-sky-950/80 text-sky-200 border-sky-700',
        'amber' => 'bg-amber-950/60 text-amber-100 border-amber-700',
        'green' => 'bg-emerald-950/70 text-emerald-100 border-emerald-700',
        'red' => 'bg-rose-950/70 text-rose-100 border-rose-700',
        'violet' => 'bg-violet-950/70 text-violet-100 border-violet-700',
    ];
    $cls = $tones[$tone] ?? $tones['gray'];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-medium ".$cls]) }}>
    {{ $slot }}
</span>

