<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="font-semibold text-xl text-slate-100 leading-tight">
                    Группы и потоки: {{ $tournament->name }}
                </h2>
                <div class="text-sm text-slate-400">
                    Пул участниц → группы (предметы) → потоки (время, стартовые номера, очередь).
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a class="text-emerald-400 hover:text-emerald-300 hover:underline text-sm font-medium"
                   href="{{ route('secretary.tournament', $tournament) }}">← Турнир</a>
                @if($tournament->categories->isNotEmpty())
                    <a class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-500"
                       href="{{ route('secretary.tournament.live', $tournament) }}?category={{ $tournament->categories->sortBy('id')->first()->id }}">
                        Live — Секретарь
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="w-full px-0 space-y-4">
            <x-flash />

            {{-- СБОРКА ТУРНИРА В ОДИН КЛИК --}}
            @if($pool->isNotEmpty())
                <x-card>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-semibold text-emerald-200">⚡ Собрать турнир из файла в один клик</div>
                            <p class="mt-1 text-xs text-slate-400 max-w-3xl">
                                По каждому пулу ({{ $pool->count() }} шт.) создаётся группа с выбранными предметами и сразу
                                нарезаются потоки. Время идёт каскадом: следующая группа стартует после предыдущей.
                                Предметы и время по любой группе можно поправить ниже.
                            </p>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('secretary.tournament.assemble', $tournament) }}"
                          class="mt-4 space-y-3"
                          onsubmit="return confirm('Создать группы по всем {{ $pool->count() }} пулам и нарезать потоки?');">
                        @csrf
                        @php($hasGroupPool = $pool->contains(fn ($p) => $p['program'] === 'group'))
                        <div>
                            <x-input-label :value="$hasGroupPool ? 'Предметы — индивидуальные' : 'Предметы по умолчанию'" />
                            <div class="mt-1 flex flex-wrap gap-2">
                                @foreach($apparatusOptions as $ap)
                                    <label class="inline-flex items-center gap-1.5 rounded-md border border-slate-700 bg-slate-950/50 px-2.5 py-1.5 text-sm text-slate-200 cursor-pointer hover:border-emerald-600">
                                        <input type="checkbox" name="apparatus[]" value="{{ $ap }}" @checked($ap === 'Б.П.')
                                               class="rounded border-slate-600 bg-slate-950 text-emerald-500 focus:ring-emerald-500">
                                        {{ $ap }}
                                    </label>
                                @endforeach
                            </div>
                            @error('apparatus') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                            @error('assemble') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                        </div>

                        @if($hasGroupPool)
                            <div class="rounded-lg border border-amber-800/40 bg-amber-950/15 p-3">
                                <x-input-label value="Предметы — групповые команды (отдельно)" />
                                <div class="mt-1 flex flex-wrap gap-2">
                                    @foreach($apparatusOptions as $ap)
                                        <label class="inline-flex items-center gap-1.5 rounded-md border border-slate-700 bg-slate-950/50 px-2.5 py-1.5 text-sm text-slate-200 cursor-pointer hover:border-amber-600">
                                            <input type="checkbox" name="group_apparatus[]" value="{{ $ap }}"
                                                   class="rounded border-slate-600 bg-slate-950 text-amber-500 focus:ring-amber-500">
                                            {{ $ap }}
                                        </label>
                                    @endforeach
                                </div>
                                <p class="mt-1 text-[11px] text-slate-500">
                                    Групповые команды идут отдельной секцией после индивидуальных. Если ничего не выбрать — возьмутся индивидуальные предметы.
                                </p>
                            </div>
                        @endif

                        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 items-end">
                            <div>
                                <x-input-label value="Размер потока" />
                                <x-text-input name="stream_size" type="number" min="1" max="200" value="12"
                                              class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                            </div>
                            <div>
                                <x-input-label value="Начало дня (ЧЧ:ММ)" />
                                <x-text-input name="start_time" type="time" value="08:00"
                                              class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                            </div>
                            <div>
                                <x-input-label value="Блок, мин" />
                                <x-text-input name="block_minutes" type="number" min="1" max="600" value="25"
                                              class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                            </div>
                            <div>
                                <x-input-label value="Нумерация" />
                                <select name="number_mode" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                    <option value="continuous">сквозная</option>
                                    <option value="per_stream">с начала в потоке</option>
                                </select>
                            </div>
                            <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow-lg shadow-emerald-950/40 hover:bg-emerald-500">
                                ⚡ Собрать турнир
                            </button>
                        </div>
                        <p class="text-[11px] text-slate-500">
                            Предметы применяются ко всем группам одинаково — для групп с другим набором поправьте предметы и пересоберите потоки ниже.
                        </p>
                    </form>
                </x-card>
            @endif

            {{-- ПУЛ: непривязанные участницы по (программа/год/категория) --}}
            <x-card>
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div class="font-semibold text-slate-100">Пул участниц (не привязаны к группе)</div>
                    <x-badge tone="violet">{{ $pool->sum('count') }} в пуле</x-badge>
                </div>

                {{-- Ручная вставка в пул (если импорт кого-то не добавил) --}}
                <details class="mb-4 rounded-xl border border-slate-800 bg-slate-950/40 p-3">
                    <summary class="cursor-pointer text-sm font-medium text-emerald-300 hover:text-emerald-200">
                        ＋ Добавить участницу вручную
                    </summary>
                    <form method="POST" action="{{ route('secretary.tournament.entries.store', $tournament) }}"
                          class="mt-3 grid grid-cols-1 sm:grid-cols-6 gap-2 items-end">
                        @csrf
                        <div class="sm:col-span-2">
                            <x-input-label value="ФИО / название команды" />
                            <x-text-input name="full_name" required placeholder="Иванова Мария"
                                          class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                        </div>
                        <div>
                            <x-input-label value="Программа" />
                            <select name="program" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="individual">индивид.</option>
                                <option value="group">групповые</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label value="Год" />
                            <x-text-input name="birth_year" type="number" min="1990" max="2035" placeholder="2018"
                                          class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                        </div>
                        <div>
                            <x-input-label value="Категория" />
                            <select name="division" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">—</option>
                                @foreach(['A', 'B', 'C', 'D'] as $div)
                                    <option value="{{ $div }}">{{ $div }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-primary-button class="justify-center">В пул</x-primary-button>
                        <div class="sm:col-span-4">
                            <x-text-input name="club" placeholder="Клуб (необязательно)"
                                          class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-text-input name="iin" placeholder="ИИН (12 цифр)" inputmode="numeric" pattern="\d{12}" maxlength="12"
                                          class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100 font-mono" />
                        </div>
                    </form>
                    @error('full_name') <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror
                    @error('iin') <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror
                </details>

                @if($pool->isEmpty())
                    <div class="text-sm text-slate-400">
                        Пул пуст. Импортируйте список участвующих на странице турнира.
                    </div>
                @else
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        @foreach($pool as $p)
                            <div class="border border-slate-800 rounded-xl p-4 bg-slate-950/40">
                                <div class="flex items-center justify-between gap-3 mb-3">
                                    <div class="font-medium text-slate-100">
                                        {{ $p['label'] ?? (($p['birth_year'] ? $p['birth_year'].' г.р.' : 'Без года')) }}
                                        @if($p['division']), кат. {{ $p['division'] }}@endif
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <x-badge tone="gray">{{ $p['program'] === 'group' ? 'групповые' : 'индивид.' }}</x-badge>
                                        <x-badge tone="violet">{{ $p['count'] }} уч.</x-badge>
                                    </div>
                                </div>

                                {{-- Состав пула ДО создания группы --}}
                                <details class="mb-3">
                                    <summary class="cursor-pointer text-xs text-emerald-300 hover:text-emerald-200">Показать состав ({{ $p['count'] }})</summary>
                                    <ol class="mt-2 max-h-52 overflow-y-auto space-y-0.5 rounded-lg border border-slate-800 bg-slate-950/50 p-2 text-xs text-slate-300 list-decimal list-inside">
                                        @foreach($p['participants'] as $pt)
                                            <li class="truncate">
                                                {{ $pt['name'] }}
                                                @if($pt['year'])<span class="text-slate-500">{{ $pt['year'] }}</span>@endif
                                                @if($pt['club'])<span class="text-slate-500">· {{ $pt['club'] }}</span>@endif
                                                @if($pt['iin'])<span class="text-slate-600 font-mono">· {{ $pt['iin'] }}</span>@endif
                                            </li>
                                        @endforeach
                                    </ol>
                                </details>

                                <form method="POST" action="{{ route('secretary.tournament.groups.store', $tournament) }}" class="space-y-3">
                                    @csrf
                                    <input type="hidden" name="program" value="{{ $p['program'] }}">
                                    <input type="hidden" name="birth_year" value="{{ $p['birth_year'] }}">
                                    <input type="hidden" name="division" value="{{ $p['division'] }}">

                                    <div>
                                        <x-input-label value="Предметы (круги, по порядку)" />
                                        <div class="mt-1 flex flex-wrap gap-2">
                                            @foreach($apparatusOptions as $ap)
                                                <label class="inline-flex items-center gap-1.5 rounded-md border border-slate-700 bg-slate-950/50 px-2.5 py-1.5 text-sm text-slate-200 cursor-pointer hover:border-emerald-600">
                                                    <input type="checkbox" name="apparatus[]" value="{{ $ap }}"
                                                           class="rounded border-slate-600 bg-slate-950 text-emerald-500 focus:ring-emerald-500">
                                                    {{ $ap }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="flex items-end justify-between gap-3">
                                        <div>
                                            <x-input-label value="Нумерация" />
                                            <select name="number_mode" class="mt-1 rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                                <option value="continuous">сквозная по группе</option>
                                                <option value="per_stream">с начала в каждом потоке</option>
                                            </select>
                                        </div>
                                        <x-primary-button>Создать группу</x-primary-button>
                                    </div>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            {{-- ГРУППЫ и их ПОТОКИ --}}
            <x-card>
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div class="font-semibold text-slate-100">Группы</div>
                    <x-badge tone="gray">{{ $tournament->groups->count() }} групп</x-badge>
                </div>

                {{-- Массовое формирование потоков по всем группам --}}
                @if($tournament->groups->isNotEmpty())
                    <div class="mb-4 rounded-xl border border-sky-800/50 bg-sky-950/20 p-4">
                        <div class="text-sm font-semibold text-sky-100">Массовое формирование потоков (все группы)</div>
                        <p class="mt-1 text-xs text-slate-400">
                            Нарезать потоки сразу во всех {{ $tournament->groups->count() }} группах единым размером и каскадным
                            расписанием дня. Предметы каждой группы сохраняются. Уже начатые/завершённые выступления не трогаются.
                        </p>
                        <form method="POST" action="{{ route('secretary.tournament.streams.all', $tournament) }}"
                              class="mt-3 grid grid-cols-2 md:grid-cols-5 gap-3 items-end"
                              onsubmit="return confirm('Пересобрать потоки во всех группах?');">
                            @csrf
                            <div>
                                <x-input-label value="Размер потока" />
                                <x-text-input name="stream_size" type="number" min="1" max="200" value="12"
                                              class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                            </div>
                            <div>
                                <x-input-label value="Начало дня (ЧЧ:ММ)" />
                                <x-text-input name="start_time" type="time" value="08:00"
                                              class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                            </div>
                            <div>
                                <x-input-label value="Блок, мин" />
                                <x-text-input name="block_minutes" type="number" min="1" max="600" value="25"
                                              class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                            </div>
                            <div>
                                <x-input-label value="Нумерация" />
                                <select name="number_mode" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                    <option value="">как у группы</option>
                                    <option value="continuous">сквозная</option>
                                    <option value="per_stream">с начала в потоке</option>
                                </select>
                            </div>
                            <button type="submit" class="rounded-lg border border-sky-600/70 bg-sky-800/40 px-3 py-2 text-sm font-semibold text-sky-50 hover:bg-sky-700/50">
                                Сформировать во всех
                            </button>
                        </form>
                        @error('streams') <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror
                    </div>
                @endif

                @forelse($tournament->groups as $group)
                    <div class="border border-slate-800 rounded-xl p-4 bg-slate-950/40 mb-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-medium text-slate-100">{{ $group->name }}</div>
                                <div class="text-sm text-slate-400 mt-1 flex flex-wrap items-center gap-2">
                                    <x-badge tone="gray">{{ $group->program === 'group' ? 'групповые' : 'индивид.' }}</x-badge>
                                    @foreach($group->apparatusLabels() as $ap)
                                        <x-badge tone="violet">{{ $ap }}</x-badge>
                                    @endforeach
                                    <span class="text-slate-400">· {{ $groupEntryCounts[$group->id] ?? 0 }} уч.</span>
                                    <span class="text-slate-500">· нумерация: {{ $group->number_mode === 'per_stream' ? 'с начала в потоке' : 'сквозная' }}</span>
                                </div>
                            </div>

                            <form method="POST" action="{{ route('secretary.tournament.groups.destroy', [$tournament, $group]) }}"
                                  onsubmit="return confirm('Удалить группу «{{ $group->name }}»? Её потоки, выступления и оценки будут удалены; участницы вернутся в пул.');">
                                @csrf @method('DELETE')
                                <button type="submit" class="rounded-md border border-rose-800/60 bg-rose-950/40 px-2.5 py-1.5 text-xs font-medium text-rose-200 hover:bg-rose-900/60 hover:border-rose-600 transition">
                                    Удалить группу
                                </button>
                            </form>
                        </div>

                        {{-- Форма формирования потоков --}}
                        <form method="POST" action="{{ route('secretary.tournament.groups.streams', [$tournament, $group]) }}"
                              class="mt-4 grid grid-cols-2 md:grid-cols-5 gap-3 items-end border-t border-slate-800 pt-4">
                            @csrf
                            <div>
                                <x-input-label value="Размер потока" />
                                <x-text-input name="stream_size" type="number" min="1" max="200" value="12"
                                              class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                            </div>
                            <div>
                                <x-input-label value="Начало (ЧЧ:ММ)" />
                                <x-text-input name="start_time" type="time" value="08:00"
                                              class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                            </div>
                            <div>
                                <x-input-label value="Блок, мин" />
                                <x-text-input name="block_minutes" type="number" min="1" max="600" value="25"
                                              class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100" />
                            </div>
                            <div>
                                <x-input-label value="Нумерация" />
                                <select name="number_mode" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                    <option value="continuous" @selected($group->number_mode !== 'per_stream')>сквозная</option>
                                    <option value="per_stream" @selected($group->number_mode === 'per_stream')>с начала в потоке</option>
                                </select>
                            </div>
                            <x-primary-button class="justify-center">Сформировать потоки</x-primary-button>
                        </form>

                        {{-- Список потоков группы --}}
                        @if($group->categories->isNotEmpty())
                            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                @foreach($group->categories->sortBy('stream_no') as $cat)
                                    <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-800 bg-slate-900/40 px-3 py-2">
                                        <div class="text-sm text-slate-200">
                                            Поток {{ $cat->stream_no }}
                                            @if($cat->starts_at_label)
                                                <span class="text-slate-400">· {{ $cat->starts_at_label }}@if($cat->ends_at_label)–{{ $cat->ends_at_label }}@endif</span>
                                            @endif
                                        </div>
                                        <a class="text-emerald-400 hover:text-emerald-300 hover:underline text-sm font-medium"
                                           href="{{ route('secretary.tournament.live', $tournament) }}?category={{ $cat->id }}">
                                            Очередь →
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-3 flex flex-wrap items-center gap-4">
                                <form method="POST" action="{{ route('secretary.tournament.groups.shuffle', [$tournament, $group]) }}" class="inline-block"
                                      onsubmit="return confirm('Перемешать порядок участниц в потоках (жеребьёвка)? Состав потоков сохранится, изменится порядок и номера внутри.');">
                                    @csrf
                                    <button type="submit" class="text-xs text-sky-300 hover:text-sky-200 hover:underline">🎲 Перемешать (жеребьёвка)</button>
                                </form>
                                <form method="POST" action="{{ route('secretary.tournament.groups.renumber', [$tournament, $group]) }}" class="inline-block">
                                    @csrf
                                    <button type="submit" class="text-xs text-slate-400 hover:text-slate-200 hover:underline">Пересчитать номера и очереди</button>
                                </form>
                            </div>

                            {{-- Состав по потокам + ручной перенос --}}
                            @php($streamNos = $group->categories->pluck('stream_no')->filter()->unique()->sort()->values())
                            <details class="mt-3 rounded-lg border border-slate-800 bg-slate-900/30 p-3">
                                <summary class="cursor-pointer text-xs font-medium text-sky-300 hover:text-sky-200">Состав по потокам / перенос</summary>
                                <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    @foreach($streamNos as $sn)
                                        <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-2">
                                            <div class="text-[11px] uppercase tracking-wider text-slate-500 mb-1">Поток {{ $sn }}</div>
                                            <ul class="space-y-1">
                                                @foreach($group->entries->where('stream_no', $sn)->sortBy('start_number') as $entry)
                                                    <li class="flex items-center gap-2 text-xs">
                                                        <span class="w-7 shrink-0 text-right font-mono text-slate-500">{{ $entry->start_number }}</span>
                                                        <span class="flex-1 min-w-0 truncate text-slate-200">{{ $entry->athlete?->last_name }} {{ $entry->athlete?->first_name }}</span>
                                                        <form method="POST" action="{{ route('secretary.entries.move', $entry) }}" class="flex items-center gap-1 shrink-0">
                                                            @csrf
                                                            <select name="stream_no" class="rounded-md border-slate-700 bg-slate-950 text-slate-200 text-[11px] py-0.5">
                                                                @foreach($streamNos as $opt)
                                                                    <option value="{{ $opt }}" @selected($opt === $sn)>П{{ $opt }}</option>
                                                                @endforeach
                                                            </select>
                                                            <button type="submit" class="rounded border border-slate-700 px-1.5 py-0.5 text-[10px] text-slate-300 hover:bg-slate-800" title="Перенести">→</button>
                                                        </form>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @else
                            <div class="mt-3 text-sm text-slate-500">Потоки ещё не сформированы.</div>
                        @endif

                        {{-- Быстрое добавление участницы в эту группу --}}
                        <details class="mt-3 rounded-lg border border-slate-800 bg-slate-900/30 p-3">
                            <summary class="cursor-pointer text-xs font-medium text-emerald-300 hover:text-emerald-200">
                                ＋ Добавить участницу в группу
                            </summary>
                            <form method="POST" action="{{ route('secretary.tournament.entries.store', $tournament) }}"
                                  class="mt-2 flex flex-wrap items-end gap-2">
                                @csrf
                                <input type="hidden" name="group_id" value="{{ $group->id }}">
                                <input type="hidden" name="program" value="{{ $group->program }}">
                                <div class="flex-1 min-w-[180px]">
                                    <label class="block text-[10px] uppercase tracking-wider text-slate-500">ФИО / команда</label>
                                    <x-text-input name="full_name" required placeholder="Иванова Мария"
                                                  class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100 text-sm" />
                                </div>
                                <div class="min-w-[160px]">
                                    <label class="block text-[10px] uppercase tracking-wider text-slate-500">Клуб</label>
                                    <x-text-input name="club" placeholder="Клуб (необязательно)"
                                                  class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100 text-sm" />
                                </div>
                                <div class="min-w-[140px]">
                                    <label class="block text-[10px] uppercase tracking-wider text-slate-500">ИИН</label>
                                    <x-text-input name="iin" placeholder="12 цифр" inputmode="numeric" pattern="\d{12}" maxlength="12"
                                                  class="mt-1 block w-full border-slate-700 bg-slate-950/50 text-slate-100 text-sm font-mono" />
                                </div>
                                <button type="submit" class="rounded-md border border-emerald-700/70 bg-emerald-900/40 px-3 py-2 text-xs font-semibold text-emerald-100 hover:bg-emerald-800/50">
                                    Добавить
                                </button>
                            </form>
                            <p class="mt-2 text-[11px] text-slate-500">
                                Год и категория берутся из группы. Если потоки уже сформированы — попадёт в последний поток с пересчётом номеров (при необходимости перенесите).
                            </p>
                        </details>
                    </div>
                @empty
                    <div class="text-sm text-slate-400">Групп пока нет. Создайте группу из пула выше.</div>
                @endforelse
            </x-card>
        </div>
    </div>
</x-app-layout>
