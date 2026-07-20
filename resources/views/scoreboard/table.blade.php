<x-scoreboard-layout>
    @php
        $tournament = $category->tournament;
        $shareUrl = route('scoreboard.table', $category);
    @endphp

    <div class="sb-screen">
        <header class="sb-header">
            <div class="max-w-6xl mx-auto flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 text-xs uppercase tracking-[0.2em] text-emerald-400/90 font-medium">
                        <span class="h-2 w-2 rounded-full bg-emerald-400 live-pulse"></span>
                        Результаты потока
                        @if($category->program === 'group')
                            <span class="rounded border border-amber-500/50 bg-amber-950/40 px-2 py-0.5 text-[10px] text-amber-200 tracking-normal">Групповые выступления</span>
                        @endif
                    </div>
                    @if($tournament)
                        <p class="mt-1 text-sm text-slate-400 truncate">{{ $tournament->name }}</p>
                    @endif
                    <h1 class="mt-0.5 text-xl sm:text-2xl lg:text-3xl font-bold text-white leading-tight truncate">
                        {{ $category->name }}
                    </h1>
                </div>
                <button type="button" id="copyShareLink" class="sb-btn sb-btn-primary shrink-0">
                    Скопировать ссылку
                </button>
            </div>
        </header>

        @include('scoreboard.partials.results-board', [
            'rows' => $rows,
            'liveUrl' => route('scoreboard.category.live', $category),
        ])
    </div>

    <script>
        document.getElementById('copyShareLink')?.addEventListener('click', async () => {
            const url = @json($shareUrl);
            const btn = document.getElementById('copyShareLink');
            try {
                await navigator.clipboard.writeText(url);
                const prev = btn.textContent;
                btn.textContent = 'Скопировано!';
                setTimeout(() => { btn.textContent = prev; }, 2000);
            } catch (e) {
                prompt('Ссылка для родителей:', url);
            }
        });
    </script>
</x-scoreboard-layout>
