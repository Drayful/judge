@extends('layouts.tablet')

@section('title', 'Ожидание потока')

@section('content')
    <div class="max-w-lg mx-auto px-4 py-10 space-y-6 text-center">
        <a href="{{ route('judge.tournaments') }}" class="text-sm text-emerald-400 hover:text-emerald-300">← Турниры</a>

        <div class="rounded-2xl border border-amber-800/50 bg-amber-950/30 p-6">
            <h1 class="text-lg font-semibold text-amber-100">Поток не выбран</h1>
            <p class="mt-3 text-sm text-amber-100/80">
                Секретарь должен открыть Live турнира <span class="font-medium text-white">«{{ $tournament->name }}»</span> и выбрать поток в списке — тогда здесь появится текущая гимнастка.
            </p>
        </div>

        <a href="{{ route('judge.tournament.tablet', $tournament) }}"
            class="inline-block text-sm text-emerald-400 hover:text-emerald-300">
            Обновить
        </a>
    </div>
@endsection

@push('body-scripts')
    <script>
        (function () {
            const pingUrl = @json(route('judge.tournament.tablet.ping', $tournament));
            setInterval(async function () {
                try {
                    const r = await fetch(pingUrl, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                    });
                    if (!r.ok) return;
                    const j = await r.json();
                    if (j.resolved) {
                        window.location.reload();
                    }
                } catch (e) {}
            }, 3000);
        })();
    </script>
@endpush
