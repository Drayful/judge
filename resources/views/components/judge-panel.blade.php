@props([
    'type',          // 'd' | 'a' | 'e' | 'penalty'
    'subpanel' => null,   // 'db' | 'da' (для type=d)
    'penaltyType' => null, // 'line' | 'time' | 'music' (для type=penalty)
    'slot',
    'base' => 10.0,
    'saved' => null,
    'entries' => [],
    'ageGroup' => 'junior',
    'tournament',
])

@php
    $mode = match ($type) {
        'd' => 'add',
        'a', 'e' => 'subtract',
        'penalty' => 'penalty',
        default => 'add',
    };
@endphp

<div
    x-data="judgeTablet({
        mode: @js($mode),
        base: {{ json_encode((float) $base) }},
        initial: {{ json_encode($saved !== null ? (float) $saved : 0.0) }},
        initialEntries: @js($entries),
        initialAgeGroup: @js($ageGroup),
        submitUrl: @js(route('judge.submit-score')),
        tabletUrl: @js(route('judge.tournament.tablet', $tournament)),
        tournamentId: {{ (int) $tournament->id }},
        panel: @js($type),
        subpanel: @js($subpanel),
        penaltyType: @js($penaltyType),
    })"
    class="flex-1 min-h-0 grid grid-cols-12 gap-2 relative"
>
    @if ($type === 'd' && $subpanel === 'da')
        @include('judge.partials._tablet_da', ['slot' => $slot, 'subpanel' => $subpanel])
    @elseif ($type === 'd')
        @include('judge.partials._tablet_d', ['slot' => $slot, 'subpanel' => $subpanel])
    @elseif ($type === 'e')
        @include('judge.partials._tablet_e', ['slot' => $slot, 'base' => $base])
    @elseif ($type === 'a')
        @include('judge.partials._tablet_a', ['slot' => $slot, 'base' => $base])
    @elseif ($type === 'penalty')
        @include('judge.partials._tablet_penalty', ['slot' => $slot, 'panel' => ['penalty_type' => $penaltyType]])
    @endif

    {{-- Плашка "идёт отправка" / ошибки сети --}}
    <div x-cloak x-show="busy" class="absolute inset-0 z-30 bg-black/40 grid place-items-center">
        <div class="rounded-xl bg-slate-900 border border-slate-700 px-5 py-3 text-sm text-slate-100">Отправка…</div>
    </div>
    <div x-cloak x-show="error" class="absolute top-2 left-1/2 -translate-x-1/2 z-30 rounded-xl bg-rose-900/90 border border-rose-700 px-4 py-2 text-sm text-white shadow-lg"
         x-text="error"></div>

    {{-- Numpad-модалка для «Вставить» --}}
    <x-judge-numpad />
</div>
