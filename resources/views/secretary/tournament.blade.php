<x-app-layout>
    <x-slot name="header">
        @php
            $tr = request()->route('tournament');
        @endphp
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
                <a class="rounded-lg border border-emerald-700/60 bg-emerald-950/40 px-3 py-2 text-sm font-semibold text-emerald-100 hover:bg-emerald-900/60 transition"
                   href="#protocols">
                    Итоговые протоколы
                </a>
                <a class="rounded-lg border border-sky-700/60 bg-sky-950/40 px-3 py-2 text-sm font-semibold text-sky-100 hover:bg-sky-900/60 transition"
                   href="{{ route('secretary.tournament.groups', $tr) }}">
                    Группы и потоки
                </a>
                @if($tr->categories->isNotEmpty())
                    <a class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow-lg shadow-emerald-950/40 hover:bg-emerald-500"
                        href="{{ route('secretary.tournament.live', $tr) }}?category={{ $tr->categories->sortBy('id')->first()->id }}">
                        Live — Секретарь
                    </a>
                    <form method="POST"
                          action="{{ route('secretary.tournament.categories.clear', $tr) }}"
                          onsubmit="return confirm('Полностью очистить турнир?\n\nБудут удалены: потоки, группы, весь пул участниц, выступления, оценки судей, запросы, музыка, а также атлеты, привязанные к этому турниру (если они не участвуют в других турнирах).\n\nДействие необратимо. Продолжить?');"
                          class="inline-block">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="rounded-lg border border-rose-700/60 bg-rose-950/40 px-3 py-2 text-sm font-semibold text-rose-100 hover:bg-rose-900/60 hover:border-rose-600 transition">
                            Полностью очистить турнир
                        </button>
                    </form>
                @endif
                <a class="text-emerald-400 hover:text-emerald-300 hover:underline text-sm font-medium" href="{{ route('secretary.tournaments') }}">← Все турниры</a>
                <a class="text-emerald-400 hover:text-emerald-300 hover:underline text-sm font-medium" href="{{ route('secretary.athletes') }}">Атлеты →</a>
            </div>
        </div>
    </x-slot>

    @php
        $tr = request()->route('tournament');
    @endphp

    <div class="py-10">
        <div class="w-full px-0 space-y-4">
            <x-flash />

            <x-card>
                @include('secretary.partials.protocol-downloads', ['tournament' => $tr, 'protocolGroups' => $protocolGroups ?? collect()])
            </x-card>

            <x-card>
                <div class="mb-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="font-semibold text-slate-100">Импорт списка участвующих (Excel)</div>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-badge tone="green">{{ $poolEntriesCount }} записей в пуле</x-badge>
                            @if($poolEntriesCount > 0)
                                <x-badge tone="gray">{{ $poolIndividualCount }} личных</x-badge>
                                <x-badge tone="violet">{{ $poolTeamCount }} команд</x-badge>
                            @endif
                        </div>
                    </div>
                    <div class="mt-2 rounded-lg border border-emerald-800/50 bg-emerald-950/35 px-3 py-2 text-sm text-emerald-100/95">
                        Участницы загружаются в <strong>пул этого турнира</strong>:
                        «{{ $tr->name }}» <span class="font-mono text-emerald-300/90">#{{ $tr->id }}</span>.
                        Группы (набор предметов) и потоки (время, стартовые номера) формируются потом на странице
                        <a class="underline hover:text-white" href="{{ route('secretary.tournament.groups', $tr) }}">«Группы и потоки»</a>.
                    </div>
                    <p class="text-sm text-slate-400 mt-3">
                        Структура файла: по одному листу на (год + категория) — «2019 А», «2018С», «2020 и мл»;
                        колонки ФИО, год рождения, клуб. Листы «груп…» — групповые команды, «Лист судей» пропускается.
                    </p>
                </div>

                <form method="POST" action="{{ route('secretary.tournament.importStartProtocol', $tr) }}" enctype="multipart/form-data" class="flex flex-col sm:flex-row sm:items-end gap-3">
                    @csrf
                    <div class="flex-1 min-w-0">
                        <x-input-label for="protocol" value="Файл .xls / .xlsx" />
                        <input id="protocol" name="protocol" type="file" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            class="mt-1 block w-full text-sm text-slate-200 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-800 file:text-emerald-300 hover:file:bg-slate-700 border border-slate-700 rounded-lg bg-slate-950/50" />
                    </div>
                    <x-primary-button class="shrink-0 justify-center">Импортировать в пул</x-primary-button>
                </form>
            </x-card>

            <x-card>
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div class="text-sm text-slate-400">
                        Добавьте категорию вручную или используйте импорт Excel выше.
                    </div>
                    <div class="flex items-center gap-2">
                        <x-badge tone="green">{{ $poolEntriesCount }} в пуле</x-badge>
                        <x-badge tone="violet">{{ $tr->categories->count() }} потоков</x-badge>
                        <x-badge tone="gray">{{ $athletes->count() }} в старт-листах</x-badge>
                    </div>
                </div>

                <form method="POST" action="{{ route('secretary.tournament.categories.store', $tr) }}" class="grid grid-cols-1 md:grid-cols-6 gap-3 mb-6">
                    @csrf
                    <div class="md:col-span-2">
                        <x-input-label value="Название потока" />
                        <x-text-input name="name" class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100 placeholder:text-slate-500" required placeholder="2015 г.р., B · Мяч — Поток 1" />
                    </div>
                    <div>
                        <x-input-label value="Программа" />
                        <select name="program" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 focus:ring-emerald-500 focus:border-emerald-500" required>
                            <option value="individual">individual</option>
                            <option value="group">group</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Вид / предмет" />
                        <select name="apparatus" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="">— выберите вид —</option>
                            @foreach(\App\Support\PerformanceApparatus::RG_APPARATUS as $apparatus)
                                <option value="{{ $apparatus }}">{{ $apparatus }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Год рождения" />
                        <x-text-input name="birth_year" type="number" min="1990" max="2035" class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" required placeholder="2015" />
                        <p class="mt-1 text-xs text-slate-500">Группа для итога и табло</p>
                    </div>
                    <div>
                        <x-input-label value="Категория" />
                        <select name="division" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="">— без буквы —</option>
                            @foreach(['A', 'B', 'C', 'D'] as $div)
                                <option value="{{ $div }}">{{ $div }}</option>
                            @endforeach
                        </select>
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
                                            {{ $c->birth_year ?? $c->resolvedBirthYear() ?? '—' }} г.р.@if($c->division ?? $c->resolvedDivision()), кат. {{ $c->division ?? $c->resolvedDivision() }}@endif
                                        </span>
                                    </div>
                                </div>
                                <div class="shrink-0">
                                    <x-badge :tone="$c->is_published ? 'green' : 'gray'">
                                        {{ $c->is_published ? 'published' : 'draft' }}
                                    </x-badge>
                                </div>
                            </div>

                            <div class="mt-3 flex items-center justify-between gap-3">
                                <form method="POST"
                                      action="{{ route('secretary.tournament.categories.destroy', [$tr, $c]) }}"
                                      onsubmit="return confirm('Удалить поток «{{ $c->name }}»?\n\nВместе с потоком будут удалены все его выступления, оценки и музыка.\n\nДействие необратимо.');"
                                      class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="inline-flex items-center gap-1.5 rounded-md border border-rose-800/60 bg-rose-950/40 px-2.5 py-1.5 text-xs font-medium text-rose-200 hover:bg-rose-900/60 hover:border-rose-600 transition"
                                            title="Удалить поток">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M9 2a1 1 0 0 0-.894.553L7.382 4H4a1 1 0 0 0 0 2v10a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V6a1 1 0 1 0 0-2h-3.382l-.724-1.447A1 1 0 0 0 11 2H9zM7 8a1 1 0 0 1 2 0v6a1 1 0 1 1-2 0V8zm4-1a1 1 0 0 0-1 1v6a1 1 0 1 0 2 0V8a1 1 0 0 0-1-1z" clip-rule="evenodd" />
                                        </svg>
                                        Удалить
                                    </button>
                                </form>
                                <a class="text-sky-300 hover:text-sky-200 hover:underline font-medium text-sm" href="{{ route('secretary.queue.review', $c) }}">
                                    Просмотр потока →
                                </a>
                            </div>
                        </div>
                    @empty
                        @if($poolEntriesCount > 0)
                            <div class="rounded-lg border border-emerald-800/50 bg-emerald-950/30 px-4 py-3 text-sm text-emerald-100">
                                В пуле загружено <strong>{{ $poolEntriesCount }}</strong> записей.
                                Теперь откройте
                                <a class="font-semibold underline hover:text-white" href="{{ route('secretary.tournament.groups', $tr) }}">«Группы и потоки»</a>,
                                чтобы сформировать группы, потоки и старт-листы.
                            </div>
                        @else
                            <div class="text-sm text-slate-400">
                                Пока нет потоков и участников в пуле. Импортируйте список Excel или создайте поток вручную.
                            </div>
                        @endif
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
                    @if($poolEntriesCount > 0)
                        <div class="text-sm text-slate-400">
                            В пуле уже {{ $poolEntriesCount }} записей, но старт-листы ещё не сформированы.
                            Перейдите в <a class="text-emerald-400 underline hover:text-emerald-300" href="{{ route('secretary.tournament.groups', $tr) }}">«Группы и потоки»</a>.
                        </div>
                    @else
                        <div class="text-sm text-slate-400">
                            В этом турнире пока нет атлетов. Импортируйте список или добавьте их в очередь потока.
                        </div>
                    @endif
                @else
                    <div x-data="{ search: '' }">
                        <input x-model="search" type="search" placeholder="Поиск по ФИО или ИИН"
                               class="mb-4 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 placeholder:text-slate-500 focus:border-emerald-500 focus:ring-emerald-500">
                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                        @foreach($athletes as $a)
                            <div class="border border-slate-800 rounded-xl p-4 bg-slate-950/40"
                                 data-search="{{ mb_strtolower($a->last_name.' '.$a->first_name.' '.$a->iin) }}"
                                 x-show="!search || $el.dataset.search.includes(search.toLowerCase())">
                                <div class="font-medium text-slate-100 truncate">
                                    {{ $a->last_name }} {{ $a->first_name }}
                                </div>
                                <div class="text-sm text-slate-400 mt-1 truncate">
                                    {{ $a->club ?? '—' }}
                                </div>
                                <div class="text-xs text-slate-500 mt-2">
                                    {{ $a->birthdate?->format('Y-m-d') ?? '—' }}
                                </div>
                                <div class="text-xs text-slate-500 mt-1 font-mono">
                                    ИИН: {{ $a->iin ?? '—' }}
                                </div>

                                <details class="mt-3 border-t border-slate-800 pt-3">
                                    <summary class="cursor-pointer text-sm font-medium text-emerald-300 hover:text-emerald-200">Редактировать</summary>
                                    <form method="POST" action="{{ route('secretary.tournament.athletes.update', [$tournament, $a]) }}" class="mt-3 space-y-3">
                                        @csrf
                                        <div>
                                            <x-input-label value="Фамилия" />
                                            <x-text-input name="last_name" value="{{ $a->last_name }}" required class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                                        </div>
                                        <div>
                                            <x-input-label value="Имя" />
                                            <x-text-input name="first_name" value="{{ $a->first_name }}" required class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                                        </div>
                                        <div>
                                            <x-input-label value="ИИН" />
                                            <x-text-input name="iin" value="{{ $a->iin }}" inputmode="numeric" pattern="\d{12}" maxlength="12" class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100 font-mono" />
                                        </div>
                                        @php
                                            $currentStream = $a->performances->first(fn ($performance) => $performance->category?->tournament_id === $tournament->id);
                                            $availableStreams = $currentStream?->category?->group_id
                                                ? $tournament->categories->where('group_id', $currentStream->category->group_id)
                                                : collect();
                                        @endphp
                                        @if($availableStreams->isNotEmpty())
                                            <div>
                                                <x-input-label value="Поток" />
                                                <select name="stream_category_id" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 focus:ring-emerald-500 focus:border-emerald-500">
                                                    <option value="">Не менять поток</option>
                                                    @foreach($availableStreams as $stream)
                                                        <option value="{{ $stream->id }}" @selected($currentStream?->category_id === $stream->id)>
                                                            Поток {{ $stream->stream_no }}@if($stream->starts_at_label) · {{ $stream->starts_at_label }}@endif
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <p class="mt-1 text-xs text-slate-500">Поток изменится вместе с ФИО; после начала выступлений он заблокирован.</p>
                                            </div>
                                        @endif
                                        <x-primary-button class="w-full justify-center">Сохранить</x-primary-button>
                                    </form>
                                </details>
                            </div>
                        @endforeach
                    </div>
                    </div>
                @endif
            </x-card>
        </div>
    </div>
</x-app-layout>
