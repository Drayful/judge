<x-app-layout>
    <x-slot name="header">
        @php($tr = request()->route('tournament'))
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="font-semibold text-xl text-slate-100 leading-tight">
                    Турнир: {{ $tr->name }}
                </h2>
                <div class="text-sm text-slate-400">
                    {{ $tr->starts_on?->format('Y-m-d') ?? '—' }} → {{ $tr->ends_on?->format('Y-m-d') ?? '—' }} · {{ $tr->timezone }}
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 justify-end">
                @if($tr->categories->isNotEmpty())
                    <a class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow-lg shadow-emerald-950/40 hover:bg-emerald-500"
                        href="{{ route('secretary.tournament.live', $tr) }}?category={{ $tr->categories->sortBy('id')->first()->id }}">
                        Live — Секретарь
                    </a>
                @endif
                <a class="text-emerald-400 hover:text-emerald-300 hover:underline text-sm font-medium" href="{{ route('secretary.tournaments') }}">← Все турниры</a>
                <a class="text-emerald-400 hover:text-emerald-300 hover:underline text-sm font-medium" href="{{ route('secretary.athletes') }}">Атлеты →</a>
            </div>
        </div>
    </x-slot>

    @php($tr = request()->route('tournament'))

    <div class="py-10">
        <div class="w-full px-0 space-y-4">
            <x-flash />

            <x-card>
                <div class="mb-4">
                    <div class="font-semibold text-slate-100">Импорт стартового протокола (Excel)</div>
                    <div class="mt-2 rounded-lg border border-emerald-800/50 bg-emerald-950/35 px-3 py-2 text-sm text-emerald-100/95">
                        Все группы и потоки из файла создаются <strong>в этом турнире</strong>:
                        «{{ $tr->name }}» <span class="font-mono text-emerald-300/90">#{{ $tr->id }}</span>.
                        Название чемпионата в шапке Excel не задаёт привязку — важно только то, что вы на странице этого турнира.
                    </div>
                    <p class="text-sm text-slate-400 mt-3">
                        Структура файла: блоки «Группа: …», строки «Поток N», список участниц (стартовый №, ФИО, год рождения, клуб).
                        Колонки от H: непустые ячейки — подпись вида. Число кругов в потоке — не меньше, чем в строке «Группа: …» (например «2 вида») и не меньше, чем по фактическим колонкам; у каждой участницы столько выступлений в очереди, сколько кругов. Порядок: сначала все по H, затем по I и далее.
                        Каждый поток в файле становится отдельной категорией с очередью.
                    </p>
                </div>

                <form method="POST" action="{{ route('secretary.tournament.importStartProtocol', $tr) }}" enctype="multipart/form-data" class="flex flex-col sm:flex-row sm:items-end gap-3">
                    @csrf
                    <div class="flex-1 min-w-0">
                        <x-input-label for="protocol" value="Файл .xls / .xlsx" />
                        <input id="protocol" name="protocol" type="file" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            class="mt-1 block w-full text-sm text-slate-200 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-800 file:text-emerald-300 hover:file:bg-slate-700 border border-slate-700 rounded-lg bg-slate-950/50" />
                    </div>
                    <x-primary-button class="shrink-0 justify-center">Импортировать потоки</x-primary-button>
                </form>
            </x-card>

            <x-card>
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div class="text-sm text-slate-400">
                        Добавьте категорию вручную или используйте импорт Excel выше.
                    </div>
                    <div class="flex items-center gap-2">
                        <x-badge tone="violet">{{ $tr->categories->count() }} категорий</x-badge>
                        <x-badge tone="gray">{{ $athletes->count() }} атлетов</x-badge>
                    </div>
                </div>

                <form method="POST" action="{{ route('secretary.tournament.categories.store', $tr) }}" class="grid grid-cols-1 md:grid-cols-6 gap-3 mb-6">
                    @csrf
                    <div class="md:col-span-2">
                        <x-input-label value="Название категории" />
                        <x-text-input name="name" class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100 placeholder:text-slate-500" required placeholder="Напр. Juniors – Hoop" />
                    </div>
                    <div>
                        <x-input-label value="Программа" />
                        <select name="program" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 focus:ring-emerald-500 focus:border-emerald-500" required>
                            <option value="individual">individual</option>
                            <option value="group">group</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Снаряд/предмет" />
                        <x-text-input name="apparatus" class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" placeholder="hoop/ball/..." />
                    </div>
                    <div>
                        <x-input-label value="Возраст от" />
                        <x-text-input name="age_min" type="number" class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                    </div>
                    <div>
                        <x-input-label value="Возраст до" />
                        <x-text-input name="age_max" type="number" class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                        <label class="mt-2 flex items-center gap-2 text-sm text-slate-300">
                            <input type="checkbox" name="is_published" value="1" class="rounded border-slate-600 bg-slate-950 text-emerald-500 focus:ring-emerald-500">
                            Опубликована
                        </label>
                    </div>
                    <div class="md:col-span-6 flex justify-end">
                        <x-primary-button>Создать категорию</x-primary-button>
                    </div>
                </form>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                    @forelse($tr->categories as $c)
                        <div class="border border-slate-800 rounded-xl p-4 bg-slate-950/40 hover:bg-slate-900/50 transition">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="font-medium text-slate-100 truncate">{{ $c->name }}</div>
                                    <div class="text-sm text-slate-400 mt-1 flex flex-wrap gap-2 items-center">
                                        <x-badge tone="gray">{{ $c->program }}</x-badge>
                                        @if($c->apparatus)
                                            <x-badge tone="violet">{{ $c->apparatus }}</x-badge>
                                        @else
                                            <x-badge tone="gray">—</x-badge>
                                        @endif
                                        <span class="text-slate-400">
                                            {{ $c->age_min ?? '—' }}–{{ $c->age_max ?? '—' }}
                                        </span>
                                    </div>
                                </div>
                                <div class="shrink-0">
                                    <x-badge :tone="$c->is_published ? 'green' : 'gray'">
                                        {{ $c->is_published ? 'published' : 'draft' }}
                                    </x-badge>
                                </div>
                            </div>

                            <div class="mt-3 flex justify-end">
                                <a class="text-emerald-400 hover:text-emerald-300 hover:underline font-medium" href="{{ route('secretary.tournament.live', $tr) }}?category={{ $c->id }}">
                                    Открыть очередь →
                                </a>
                            </div>
                        </div>
                    @empty
                        <div class="text-sm text-slate-400">
                            Пока нет категорий. Импортируйте протокол или создайте категорию вручную.
                        </div>
                    @endforelse
                </div>
            </x-card>

            <x-card>
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div class="text-sm text-slate-400">
                        Атлеты, которые уже добавлены в старт-листы этого турнира (через очередь выступлений).
                    </div>
                    <a class="text-emerald-400 hover:text-emerald-300 hover:underline text-sm font-medium" href="{{ route('secretary.athletes') }}">Все атлеты →</a>
                </div>

                @if($athletes->isEmpty())
                    <div class="text-sm text-slate-400">
                        В этом турнире пока нет атлетов. Импортируйте протокол или добавьте их в очередь категории.
                    </div>
                @else
                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                        @foreach($athletes as $a)
                            <div class="border border-slate-800 rounded-xl p-4 bg-slate-950/40">
                                <div class="font-medium text-slate-100 truncate">
                                    {{ $a->last_name }} {{ $a->first_name }}
                                </div>
                                <div class="text-sm text-slate-400 mt-1 truncate">
                                    {{ $a->club ?? '—' }}
                                </div>
                                <div class="text-xs text-slate-500 mt-2">
                                    {{ $a->birthdate?->format('Y-m-d') ?? '—' }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>
    </div>
</x-app-layout>

