@php
    $draggable = ! $c['locked'] && ! ($started ?? false);
@endphp
<div data-entry="{{ $c['id'] }}" class="{{ $c['locked'] ? 'locked' : '' }} sx-entry {{ $draggable ? 'is-draggable' : '' }} {{ $c['withdrawn'] ? 'is-out' : '' }} {{ ! $c['music'] && ! $c['withdrawn'] ? 'is-nomusic' : '' }}">
    <span class="sx-grip" @if(! $draggable) style="visibility:hidden" @endif>@include('secretary.partials.sx-icon', ['name' => 'grip', 'size' => 16])</span>
    <span class="sx-startno" title="Стартовый номер">
        <span class="sx-label" style="font-size:9px;line-height:11px">старт</span>
        <b class="sx-mono">{{ $inStream ? '#'.($c['number'] ?? '—') : '—' }}</b>
    </span>
    <span class="sx-grow">
        <span class="sx-truncate sx-body {{ $c['withdrawn'] ? 'sx-strike sx-dim' : 'sx-text' }}" style="display:block;font-weight:600">{{ $c['name'] }}</span>
        <span class="sx-truncate sx-small sx-muted" style="display:block">{{ $c['year'] ? $c['year'].' · ' : '' }}{{ $c['club'] ?? '—' }}</span>
        @if($c['team'] && $c['members'])
            <details class="sx-small sx-muted" style="margin-top:.2rem">
                <summary style="cursor:pointer" class="sx-dim">состав: {{ count($c['members']) }}</summary>
                {!! collect($c['members'])->map(fn ($m) => e($m))->implode('<br>') !!}
            </details>
        @endif
    </span>
    @if($inStream && ! $c['withdrawn'] && $c['times'])
        <span class="sx-mono sx-small sx-muted" style="display:flex;flex-direction:column;align-items:flex-end;gap:.1rem;flex-shrink:0">
            @foreach($c['times'] as $t)<span><span class="sx-dim">{{ $t['apparatus'] }}</span> <span class="sx-text">{{ $t['time'] }}</span></span>@endforeach
        </span>
    @endif
    <span class="sx-row-wrap" style="flex-shrink:0;justify-content:flex-end">
        @if($c['withdrawn'])
            <span class="sx-chip sx-chip-bad"><span class="sx-dot"></span>снята</span>
        @elseif($c['locked'])
            <span class="sx-chip sx-chip-mute" title="Уже выступила — перемещать нельзя">@include('secretary.partials.sx-icon', ['name' => 'lock', 'size' => 12])выступила</span>
        @endif
        @unless($c['withdrawn'])
            @if($c['music'])
                <span class="sx-chip sx-chip-info sx-chip-plain" title="Музыка загружена">@include('secretary.partials.sx-icon', ['name' => 'music', 'size' => 12])готово</span>
            @else
                <span class="sx-chip sx-chip-warn sx-chip-plain" title="Нет музыки">@include('secretary.partials.sx-icon', ['name' => 'music-off', 'size' => 12])нет музыки</span>
            @endif
        @endunless
    </span>
</div>
