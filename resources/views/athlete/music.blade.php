<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-slate-100 leading-tight">
                Музыка
            </h2>
            <x-badge tone="violet">{{ $performances->count() }} выходов</x-badge>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="w-full px-0 space-y-4">
            <x-flash />

            <x-card>
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div class="text-sm text-slate-400">
                        Загружайте музыку отдельно для каждого выхода (снаряда). Если у гимнастки нет аккаунта, файл может загрузить секретариат в окне Live потока («Музыка для выхода»).
                    </div>
                </div>

                    <form method="POST" action="{{ route('athlete.music.store') }}" enctype="multipart/form-data" class="space-y-4">
                        @csrf

                        <div>
                            <x-input-label for="performance_id" value="Выход (снаряд / категория)"/>
                            <select id="performance_id" name="performance_id" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 focus:ring-emerald-500 focus:border-emerald-500">
                                @foreach($performances as $p)
                                    @php($app = $p->apparatus ?? $p->category->apparatus ?? '—')
                                    <option value="{{ $p->id }}">
                                        #{{ $p->start_number ?? '—' }} · {{ $app }} · {{ $p->category->name }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('performance_id')" class="mt-2"/>
                            @php($deadline = $performances->firstWhere('id', old('performance_id'))?->category?->music_deadline_at ?? null)
                            @if($deadline)
                                <div class="mt-2 text-xs text-slate-500">
                                    Дедлайн замены музыки: <span class="font-medium text-slate-300">{{ $deadline->format('d.m.Y H:i') }}</span>
                                </div>
                            @endif
                        </div>

                        <div>
                            <x-input-label for="type" value="Тип файла"/>
                            <select id="type" name="type" class="mt-1 block w-full rounded-lg border-slate-700 bg-slate-950/50 text-slate-100 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="primary">Основной</option>
                                <option value="backup">Резервный (backup)</option>
                            </select>
                            <div class="mt-1 text-xs text-slate-500">
                                Можно держать основной и резервный файл. История замен сохраняется.
                            </div>
                            <x-input-error :messages="$errors->get('type')" class="mt-2"/>
                        </div>

                        <div>
                            <x-input-label for="music" value="Файл музыки (mp3/m4a/wav, до 30MB)"/>
                            <input id="music" name="music" type="file" required class="mt-1 block w-full text-sm text-slate-200 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-800 file:text-emerald-300"/>
                            <x-input-error :messages="$errors->get('music')" class="mt-2"/>
                        </div>

                        <div>
                            <x-primary-button>Загрузить</x-primary-button>
                        </div>
                    </form>
            </x-card>

            <x-card>
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div class="font-medium text-slate-100">Выходы и музыка</div>
                    <div class="text-sm text-slate-400">Скачивание доступно вам и секретарю.</div>
                </div>

                @if($performances->isEmpty())
                    <div class="text-sm text-slate-400">Пока нет выходов.</div>
                @else
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        @foreach($performances as $p)
                            @php($primary = $p->track)
                            @php($backup = $p->trackBackup)
                            @php($app = $p->apparatus ?? $p->category->apparatus ?? '—')
                            <div class="border border-slate-800 rounded-xl p-4 bg-slate-950/40">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="font-medium text-slate-100 truncate">
                                            #{{ $p->start_number ?? '—' }} · {{ $p->category->name }}
                                        </div>
                                        <div class="mt-2">
                                            <x-badge tone="violet">{{ $app }}</x-badge>
                                        </div>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <div class="text-sm font-medium text-slate-100">Скачать</div>
                                        <div class="mt-1 flex flex-col items-end gap-1">
                                            @if($primary)
                                                <a class="text-emerald-400 hover:text-emerald-300" href="{{ route('tracks.download', $primary) }}">Основной</a>
                                            @endif
                                            @if($backup)
                                                <a class="text-emerald-400 hover:text-emerald-300" href="{{ route('tracks.download', $backup) }}">Резерв</a>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <div class="text-slate-400">Основной</div>
                                        @if($primary)
                                            <div class="mt-1 text-slate-100 break-words">{{ $primary->original_name }}</div>
                                            <div class="text-xs text-slate-500 mt-1">
                                                {{ $primary->size_bytes ? number_format($primary->size_bytes / 1024 / 1024, 2) . ' MB' : '—' }}
                                            </div>
                                        @else
                                            <div class="mt-1 text-slate-500">нет</div>
                                        @endif
                                    </div>
                                    <div>
                                        <div class="text-slate-400">Резерв</div>
                                        @if($backup)
                                            <div class="mt-1 text-slate-100 break-words">{{ $backup->original_name }}</div>
                                            <div class="text-xs text-slate-500 mt-1">
                                                {{ $backup->size_bytes ? number_format($backup->size_bytes / 1024 / 1024, 2) . ' MB' : '—' }}
                                            </div>
                                        @else
                                            <div class="mt-1 text-slate-500">нет</div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>
    </div>
</x-app-layout>
