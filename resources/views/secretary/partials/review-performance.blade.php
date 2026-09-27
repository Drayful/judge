@php
    $bounds = $performance->durationBounds();
    $seconds = $performance->actual_duration_seconds;
    if ($seconds === null && $performance->timer_started_at) {
        $seconds = max(0, (int) floor($performance->timer_started_at->diffInSeconds($performance->timer_ended_at ?? now())));
    }
@endphp
<div class="mt-3 grid gap-3 rounded-lg border border-slate-800 bg-slate-900/50 p-3 text-sm md:grid-cols-2">
    <div>
        <div class="text-xs text-slate-400">Официальный таймер · норматив {{ $bounds['min'] }}–{{ $bounds['max'] }} сек.</div>
        <div class="mt-1 font-mono text-lg text-sky-200" data-review-timer data-elapsed="{{ $seconds ?? '' }}" data-running="{{ $performance->timer_started_at && !$performance->timer_ended_at ? '1' : '0' }}">{{ $seconds === null ? 'Не запущен' : sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60) }}</div>
        <div class="text-xs text-slate-400">Сбавка времени: {{ number_format((float) $performance->time_penalty, 2, ',', '') }}</div>
    </div>
    <div class="text-xs text-slate-300">
        <div>{{ $performance->scores_overridden ? 'Ручной итог' : 'Расчёт по оценкам судей' }}</div>
        <div>{{ $performance->approved_at ? 'Итог подтверждён' : 'Ожидает подтверждения' }} · {{ $performance->scoreboard_accepted_at ? 'Показан на табло' : 'Не показан на табло' }}</div>
        <div class="mt-1">Расхождение крайних ≤ {{ $history['spread']['max_spread'] ?? '—' }}; от средней ≤ {{ $category->tournament?->max_average_deviation ?? 'не задано' }}</div>
        @foreach($history['spread']['violations'] ?? [] as $violation)
            <div class="mt-1 text-rose-300">{{ $violation['label'] }}: min {{ number_format($violation['min'], 3) }} · max {{ number_format($violation['max'], 3) }} · разброс {{ number_format($violation['spread'], 3) }}</div>
        @endforeach
    </div>
    @if($performance->athlete?->members->isNotEmpty())
        <div class="md:col-span-2">
            <div class="text-xs font-semibold text-amber-200">Состав команды</div>
            <div class="mt-1 text-slate-300">{{ $performance->athlete->members->map(fn ($member) => trim($member->last_name.' '.$member->first_name).($member->birthdate ? ' ('.$member->birthdate->year.')' : ''))->implode(', ') }}</div>
        </div>
    @endif
    @foreach(['track' => 'Музыка выхода', 'trackBackup' => 'Резервная музыка'] as $relation => $label)
        @if($performance->$relation)
            <div>
                <div class="mb-1 text-xs text-violet-200">{{ $label }} · {{ $performance->$relation->original_name }}</div>
                <audio controls preload="none" class="w-full" src="{{ route('tracks.play', $performance->$relation) }}"></audio>
            </div>
        @endif
    @endforeach
    @if($performance->inquiries->isNotEmpty())
        <details class="md:col-span-2"><summary class="cursor-pointer text-amber-200">Протесты (просмотр)</summary>
            @foreach($performance->inquiries as $inquiry)
                <p class="mt-2 text-xs text-slate-300">{{ $inquiry->reason }} · {{ $inquiry->status }}@if($inquiry->decision_notes) · {{ $inquiry->decision_notes }}@endif</p>
            @endforeach
        </details>
    @endif
</div>
