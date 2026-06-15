<x-scoreboard-layout>
    <div class="min-h-dvh flex flex-col">
        <div class="border-b border-white/5 bg-slate-950/80 backdrop-blur-md">
            <div class="max-w-3xl mx-auto w-full px-4 sm:px-6 py-8 lg:py-12">
                <div class="flex items-center gap-2 text-xs uppercase tracking-[0.2em] text-emerald-400/80 mb-4">
                    <span class="inline-flex h-2 w-2 rounded-full bg-emerald-400 live-pulse"></span>
                    <span>Табло</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-semibold text-white">Выберите поток</h1>
                <p class="mt-2 text-sm text-slate-400">
                    Два отдельных экрана: <strong class="text-slate-300">результаты</strong> (ссылка для чата родителей) и <strong class="text-slate-300">на ковре</strong> (телевизор в зале).
                </p>

                @if($tournaments->isEmpty())
                    <div class="mt-8 live-panel p-8 text-center">
                        <p class="text-slate-400">Нет опубликованных турниров.</p>
                    </div>
                @else
                    <form method="GET" action="{{ route('scoreboard.index') }}" class="mt-8 space-y-4">
                        <div>
                            <label for="hubTournament" class="block text-[10px] uppercase tracking-wider text-slate-500 mb-1">Турнир</label>
                            <select id="hubTournament" class="w-full rounded-xl border border-slate-700 bg-slate-900 text-slate-100 px-3 py-3 text-sm">
                                @foreach($tournaments as $t)
                                    <option value="{{ $t->id }}" @selected($selected && $selected->tournament_id === $t->id)>{{ $t->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="hubCategory" class="block text-[10px] uppercase tracking-wider text-slate-500 mb-1">Поток</label>
                            <select id="hubCategory" name="category" class="w-full rounded-xl border border-slate-700 bg-slate-900 text-slate-100 px-3 py-3 text-sm">
                                @foreach($tournaments as $t)
                                    @foreach($t->categories as $c)
                                        <option value="{{ $c->id }}" data-tournament="{{ $t->id }}" @selected($selected && $selected->id === $c->id)>{{ $c->name }}</option>
                                    @endforeach
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="w-full sb-btn sb-btn-primary py-3">
                            Выбрать поток
                        </button>
                    </form>

                    @if($selected)
                        <div class="mt-8 space-y-3">
                            <a href="{{ route('scoreboard.table', $selected) }}"
                                class="block live-panel p-5 hover:border-emerald-500/40 transition">
                                <div class="text-[10px] uppercase tracking-wider text-emerald-400/80">Для родителей · чат</div>
                                <div class="mt-1 text-lg font-semibold text-white">Общая таблица результатов</div>
                                <p class="mt-1 text-xs text-slate-500 break-all">{{ route('scoreboard.table', $selected) }}</p>
                            </a>
                            <a href="{{ route('scoreboard.performance', $selected) }}"
                                class="block live-panel p-5 hover:border-cyan-500/40 transition ring-1 ring-cyan-900/20">
                                <div class="text-[10px] uppercase tracking-wider text-cyan-400/80">Для зала · ТВ</div>
                                <div class="mt-1 text-lg font-semibold text-white">Сейчас на ковре</div>
                                <p class="mt-1 text-xs text-slate-500 break-all">{{ route('scoreboard.performance', $selected) }}</p>
                            </a>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>

    @if($tournaments->isNotEmpty())
        <script>
            (function () {
                const tournamentSelect = document.getElementById('hubTournament');
                const categorySelect = document.getElementById('hubCategory');
                if (!tournamentSelect || !categorySelect) return;
                const allOptions = Array.from(categorySelect.options).map(o => ({
                    value: o.value, label: o.textContent, tournament: o.dataset.tournament,
                }));
                function filterCategories() {
                    const tid = tournamentSelect.value;
                    const current = categorySelect.value;
                    categorySelect.innerHTML = '';
                    allOptions.filter(o => o.tournament === tid).forEach(o => {
                        const opt = document.createElement('option');
                        opt.value = o.value;
                        opt.textContent = o.label;
                        opt.dataset.tournament = o.tournament;
                        categorySelect.appendChild(opt);
                    });
                    if (Array.from(categorySelect.options).some(o => o.value === current)) {
                        categorySelect.value = current;
                    }
                }
                tournamentSelect.addEventListener('change', filterCategories);
                filterCategories();
            })();
        </script>
    @endif
</x-scoreboard-layout>
