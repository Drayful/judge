<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-slate-100 leading-tight">
                Судейство · Турниры
            </h2>
            <x-badge tone="violet">{{ $tournaments->count() }} турниров</x-badge>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="w-full px-0 space-y-4">
            <x-flash />

            <x-card>
                <p class="text-sm text-slate-400 mb-4">
                    Выберите турнир — откроется планшет для <strong class="text-slate-200">текущего потока</strong>, который ведёт секретарь в Live (поток и гимнастку выбирает только секретарь).
                </p>

                <div class="-mx-2 px-2 space-y-3">
                    @forelse($tournaments as $t)
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border border-slate-800 rounded-xl p-4 bg-slate-950/40">
                            <div class="min-w-0">
                                <div class="font-medium text-slate-100 truncate">{{ $t->name }}</div>
                                <div class="text-sm text-slate-500 mt-1">
                                    Потоков: {{ $t->categories_count }}
                                    @if($t->activeCategory)
                                        <span class="text-slate-400">· поток: {{ \Illuminate\Support\Str::limit($t->activeCategory->name, 48) }}</span>
                                    @elseif($t->active_category_id)
                                        <span class="text-slate-400">· поток #{{ $t->active_category_id }}</span>
                                    @endif
                                </div>
                            </div>
                            <a href="{{ route('judge.tournament.tablet', $t) }}"
                                class="shrink-0 inline-flex justify-center rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-emerald-950/40 hover:bg-emerald-500">
                                Планшет
                            </a>
                        </div>
                    @empty
                        <div class="text-sm text-slate-500">Нет турниров.</div>
                    @endforelse
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
