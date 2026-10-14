<div data-entry="{{ $c['id'] }}" class="{{ $c['locked'] ? 'locked' : '' }} select-none rounded-lg border px-3 py-2 {{ $c['locked'] ? 'border-slate-800 bg-slate-950/40 opacity-70' : ($c['withdrawn'] ? 'border-slate-800 bg-slate-950/30 cursor-grab' : 'border-slate-700/80 bg-slate-950/70 cursor-grab hover:border-slate-500') }}">
    <div class="flex items-center gap-3">
        <span class="w-6 font-mono text-xs {{ $inStream ? 'text-emerald-300/80' : 'text-slate-600' }}" title="Стартовый номер">{{ $inStream ? ($c['number'] ?? '—') : '—' }}</span>
        <span class="min-w-0 {{ $c['withdrawn'] ? 'text-slate-500' : '' }}">
            <span class="block truncate text-sm {{ $c['withdrawn'] ? 'line-through' : 'text-slate-100' }}">{{ $c['name'] }}</span>
            <span class="block truncate text-[11px] text-slate-500">{{ $c['year'] ? $c['year'].' · ' : '' }}{{ $c['club'] ?? '—' }}</span>
        </span>
        <span class="ml-auto flex shrink-0 items-center gap-2 text-xs">
            @if($c['withdrawn'])<span class="rounded bg-slate-800 px-1.5 text-[10px] text-slate-400">снята</span>@endif
            @unless($c['music'])<span class="text-amber-300" title="Нет музыки">♪✕</span>@endunless
            @if($c['locked'])<span title="Уже выступила">🔒</span>@endif
        </span>
    </div>
    @if($c['team'] && $c['members'])
        <details class="mt-1 pl-9 text-[11px] text-slate-400"><summary class="cursor-pointer text-slate-500">состав: {{ count($c['members']) }}</summary>{!! collect($c['members'])->map(fn ($m) => e($m))->implode('<br>') !!}</details>
    @endif
    @if($inStream && ! $c['withdrawn'] && $c['times'])
        <div class="mt-1 flex flex-wrap gap-x-3 pl-9 font-mono text-[11px] text-slate-400">
            @foreach($c['times'] as $t)<span>{{ $t['apparatus'] }} {{ $t['time'] }}</span>@endforeach
        </div>
    @endif
</div>
