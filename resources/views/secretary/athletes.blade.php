<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-slate-100 leading-tight">
                Секретарь · Атлеты
            </h2>
            <x-badge tone="violet">{{ $athletes->count() }} атлетов</x-badge>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="w-full px-0 space-y-4">
            <x-flash />

            <x-card>
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div class="text-sm text-slate-400">
                        Добавляйте атлетов, затем записывайте их в категории (очередь выступлений).
                    </div>
                    <div class="flex items-center gap-3">
                        <a class="text-emerald-400 hover:text-emerald-300 text-sm font-medium" href="{{ route('secretary.tournaments') }}">Турниры →</a>
                        <a class="text-emerald-400 hover:text-emerald-300 text-sm font-medium" href="{{ route('secretary.categories') }}">Категории →</a>
                    </div>
                </div>

                <form method="POST" action="{{ route('secretary.athletes.store') }}" class="grid grid-cols-1 md:grid-cols-6 gap-3 mb-6">
                    @csrf
                    <div>
                        <x-input-label value="Фамилия" />
                        <x-text-input name="last_name" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label value="Имя" />
                        <x-text-input name="first_name" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label value="Дата рождения" />
                        <x-text-input name="birthdate" type="date" class="mt-1 block w-full" />
                    </div>
                    <div class="md:col-span-2">
                        <x-input-label value="Клуб" />
                        <x-text-input name="club" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label value="Тренер" />
                        <x-text-input name="coach" class="mt-1 block w-full" />
                    </div>
                    <div class="md:col-span-6 flex justify-end">
                        <x-primary-button>Добавить атлета</x-primary-button>
                    </div>
                </form>

                <div class="-mx-2 px-2">
                    <div class="hidden sm:block">
                        <table class="w-full text-sm table-fixed">
                            <thead class="text-left text-slate-400">
                            <tr class="border-b border-slate-800">
                                <th class="py-3 pr-4 font-medium">ФИО</th>
                                <th class="py-3 pr-4 font-medium w-36">Дата рождения</th>
                                <th class="py-3 pr-4 font-medium w-64">Клуб</th>
                                <th class="py-3 pr-4 font-medium w-64">Тренер</th>
                            </tr>
                            </thead>
                            <tbody class="text-slate-100 divide-y divide-slate-800">
                            @foreach($athletes as $a)
                                <tr class="hover:bg-slate-800/40">
                                    <td class="py-3 pr-4 font-medium truncate">{{ $a->last_name }} {{ $a->first_name }}</td>
                                    <td class="py-3 pr-4 text-slate-300">{{ $a->birthdate?->format('Y-m-d') ?? '—' }}</td>
                                    <td class="py-3 pr-4 text-slate-300 truncate">{{ $a->club ?? '—' }}</td>
                                    <td class="py-3 pr-4 text-slate-300 truncate">{{ $a->coach ?? '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="sm:hidden space-y-3">
                        @foreach($athletes as $a)
                            <div class="border border-slate-800 rounded-xl p-4 bg-slate-950/40">
                                <div class="font-medium text-slate-100">
                                    {{ $a->last_name }} {{ $a->first_name }}
                                </div>
                                <div class="text-sm text-slate-400 mt-1">
                                    ДР: {{ $a->birthdate?->format('Y-m-d') ?? '—' }}
                                </div>
                                <div class="text-sm text-slate-400 mt-2 break-words">
                                    Клуб: {{ $a->club ?? '—' }}
                                </div>
                                <div class="text-sm text-slate-400 mt-1 break-words">
                                    Тренер: {{ $a->coach ?? '—' }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
