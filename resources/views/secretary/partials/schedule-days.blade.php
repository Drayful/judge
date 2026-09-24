@php
    $daySessions = \App\Models\StreamSession::with('category')
        ->whereHas('category', fn ($q) => $q->where('tournament_id', $tournament->id))
        ->orderBy('scheduled_on')->orderBy('starts_at')->orderBy('id')->get()
        ->groupBy(fn ($session) => $session->scheduled_on->format('Y-m-d'));
@endphp
<section class="mx-auto my-6 max-w-[1600px] space-y-3 text-slate-100" x-data="{date: '', time: ''}" x-init="try { const saved = JSON.parse(localStorage.getItem('schedule-inputs-{{ $tournament->id }}') || '{}'); date = saved.scheduled_on || ''; time = saved.start_time || saved.starts_at || ''; } catch (_) {}">
    <h2 class="text-xl font-bold">Сформированные потоки по дням</h2>
    <label>Дата <input type="date" x-model="date" class="rounded bg-slate-900"></label>
    <label>Начиная с <input type="time" x-model="time" class="rounded bg-slate-900"></label>
    <a href="{{ route('secretary.tournament.programme', $tournament) }}" class="inline-block rounded bg-sky-700 px-3 py-2">Программа по дням Excel</a>
    @foreach($daySessions as $date => $sessions)
        <details class="rounded-xl border border-slate-700 p-4" x-show="!date || date === '{{ $date }}'">
            <summary class="cursor-pointer font-bold">День {{ $loop->iteration }} · {{ \Carbon\Carbon::parse($date)->format('d.m.Y') }}</summary>
            <a href="{{ route('secretary.tournament.start-sheet', ['tournament' => $tournament, 'date' => $date]) }}" class="my-3 inline-block text-sky-300">Скачать стартовый лист дня</a>
            @foreach($sessions as $session)
                <div class="border-t border-slate-700 py-3" x-show="!time || '{{ substr($session->starts_at ?? '', 0, 5) }}' >= time">
                    <a class="text-sky-200" href="{{ route('secretary.queue.review', ['category' => $session->category_id, 'session' => $session->id]) }}">{{ substr($session->starts_at ?? '', 0, 5) }}–{{ substr($session->ends_at ?? '', 0, 5) }} · {{ $session->category->name }}</a>
                    @unless(auth()->user()->isChiefJudge())
                    <form method="POST" action="{{ route('workflow.award', $session) }}" class="mt-2 flex items-center gap-2">
                        @csrf
                        <label>Награждение после потока, минут <input name="minutes" type="number" min="0" max="240" required value="{{ $session->award_minutes }}" class="w-24 rounded bg-slate-900"></label>
                        <button class="rounded bg-emerald-700 px-3 py-2">Сохранить</button>
                    </form>
                    @endunless
                </div>
            @endforeach
        </details>
    @endforeach
</section>
<script>
(() => {
    const key = 'schedule-inputs-{{ $tournament->id }}';
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(key) || '{}'); } catch (_) {}
    document.querySelectorAll('form input[name="scheduled_on"], form input[name="starts_at"], form input[name="start_time"]').forEach(input => {
        if (input.form.querySelector('input[name="_method"]')) return;
        if (saved[input.name]) input.value = saved[input.name];
        input.addEventListener('change', () => {
            saved[input.name] = input.value;
            try { localStorage.setItem(key, JSON.stringify(saved)); } catch (_) {}
        });
    });
})();
</script>
