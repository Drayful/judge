@extends('layouts.judging')

@section('title', 'Ожидание потока')

@section('content')
    <div class="judge-console h-screen overflow-hidden flex flex-col items-center justify-center px-4 text-center gap-5" data-panel="d">
        <a href="{{ route('judge.tournaments') }}" class="judge-back-button rounded-xl px-4 py-2 text-sm text-slate-300 hover:text-white">← Турниры</a>

        <div class="judge-state-card rounded-3xl p-10 max-w-lg">
            <div class="mx-auto mb-5 h-3 w-3 rounded-full bg-amber-300 shadow-[0_0_24px_rgba(252,211,77,0.8)]"></div>
            <div class="text-[10px] font-semibold uppercase tracking-[0.25em] text-amber-300">Stand by</div>
            <h1 class="mt-2 text-2xl font-bold text-white">Поток не выбран</h1>
            <p class="mt-3 text-sm text-slate-400">
                Секретарь должен открыть Live турнира <span class="font-medium text-white">«{{ $tournament->name }}»</span> и выбрать поток в списке — тогда здесь появится текущая гимнастка.
            </p>
        </div>

        <a href="{{ route('judge.tournament.tablet', $tournament) }}"
            class="text-sm text-emerald-300 hover:text-emerald-200">Проверить подключение</a>
    </div>
@endsection

@push('body-scripts')
    <script>
        (function () {
            const pageRoot = document.querySelector('[data-async-page]');
            const pingUrl = @json(route('judge.tournament.tablet.ping', $tournament));
            const pingInterval = setInterval(async function () {
                if (pageRoot && ! pageRoot.isConnected) {
                    clearInterval(pingInterval);
                    return;
                }
                try {
                    const r = await fetch(pingUrl, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                    });
                    if (!r.ok) return;
                    const j = await r.json();
                    if (j.resolved) {
                        if (window.JudgeAsync) {
                            await window.JudgeAsync.refresh(window.location.href, { silent: true });
                        } else {
                            window.location.reload();
                        }
                    }
                } catch (e) {}
            }, 3000);
        })();
    </script>
@endpush
