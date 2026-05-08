<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-slate-100 leading-tight">
                Секретарь · Турниры
            </h2>
            <x-badge tone="violet">{{ $tournaments->count() }} турниров</x-badge>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="w-full px-0 space-y-4">
            <x-flash />

            <x-card>
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div class="text-sm text-slate-400">
                        Создайте турнир и добавьте категории (предмет/снаряд) и участников в очередь.
                    </div>
                    <div class="flex items-center gap-3">
                        <a class="text-emerald-400 hover:text-emerald-300 text-sm font-medium" href="{{ route('secretary.categories') }}">Категории →</a>
                        <a class="text-emerald-400 hover:text-emerald-300 text-sm font-medium" href="{{ route('secretary.athletes') }}">Атлеты →</a>
                    </div>
                </div>

                <form method="POST" action="{{ route('secretary.tournaments.store') }}" class="grid grid-cols-1 md:grid-cols-5 gap-3 mb-6">
                    @csrf
                    <div class="md:col-span-2">
                        <x-input-label value="Название" />
                        <x-text-input name="name" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label value="Начало" />
                        <x-text-input name="starts_on" type="date" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label value="Конец" />
                        <x-text-input name="ends_on" type="date" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label value="Часовой пояс" />
                        <x-text-input name="timezone" class="mt-1 block w-full" placeholder="Asia/Almaty" />
                        <label class="mt-2 flex items-center gap-2 text-sm text-slate-300">
                            <input type="checkbox" name="is_published" value="1" class="rounded border-slate-600 bg-slate-950 text-emerald-500 focus:ring-emerald-500">
                            Опубликован
                        </label>
                    </div>
                    <div class="md:col-span-5 flex justify-end">
                        <x-primary-button>Создать турнир</x-primary-button>
                    </div>
                </form>

                @if($tournaments->isEmpty())
                    <div class="text-sm text-slate-400">
                        Пока нет турниров. Создайте первый — он появится в списке ниже.
                    </div>
                @else
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        @foreach($tournaments as $t)
                            <div class="border border-slate-800 rounded-xl p-4 bg-slate-950/40 hover:bg-slate-900/50 transition">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="font-medium text-slate-100 truncate">
                                            {{ $t->name }}
                                        </div>
                                        <div class="text-sm text-slate-400 mt-1">
                                            {{ $t->starts_on?->format('Y-m-d') ?? '—' }} → {{ $t->ends_on?->format('Y-m-d') ?? '—' }}
                                        </div>
                                        <div class="text-sm text-slate-400 mt-1 break-words">
                                            TZ: {{ $t->timezone }}
                                        </div>
                                    </div>
                                    <div class="shrink-0">
                                        <x-badge :tone="$t->is_published ? 'green' : 'gray'">
                                            {{ $t->is_published ? 'published' : 'draft' }}
                                        </x-badge>
                                    </div>
                                </div>

                                <div class="mt-3 flex justify-end">
                                    <a class="text-emerald-400 hover:text-emerald-300 font-medium" href="{{ route('secretary.tournament', $t) }}">
                                        Управлять →
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>
    </div>
</x-app-layout>
