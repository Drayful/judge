<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-slate-100 leading-tight">
                Секретарь · Категории
            </h2>
            <div class="flex items-center gap-3">
                <a class="text-emerald-400 hover:text-emerald-300 text-sm font-medium" href="{{ route('secretary.tournaments') }}">Турниры</a>
                <a class="text-emerald-400 hover:text-emerald-300 text-sm font-medium" href="{{ route('secretary.athletes') }}">Атлеты</a>
                <x-badge tone="violet">{{ $categories->count() }} категорий</x-badge>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="w-full px-0 space-y-4">
            <x-flash />

            <x-card>
                <div class="text-sm text-slate-400 mb-4">
                    Выберите категорию, чтобы открыть очередь выступлений.
                </div>

                <div class="-mx-2 px-2">
                    <div class="hidden sm:block">
                        <table class="w-full text-sm table-fixed">
                            <thead class="text-left text-slate-400">
                            <tr class="border-b border-slate-800">
                                <th class="py-3 pr-4 font-medium">Турнир</th>
                                <th class="py-3 pr-4 font-medium">Категория</th>
                                <th class="py-3 pr-4 font-medium w-28">Программа</th>
                                <th class="py-3 pr-4 font-medium w-28">Снаряд</th>
                                <th class="py-3 text-right font-medium w-28">Открыть</th>
                            </tr>
                            </thead>
                            <tbody class="text-slate-100 divide-y divide-slate-800">
                            @foreach($categories as $c)
                                <tr class="hover:bg-slate-800/40">
                                    <td class="py-3 pr-4 text-slate-300 truncate">{{ $c->tournament?->name ?? '—' }}</td>
                                    <td class="py-3 pr-4 font-medium truncate">{{ $c->name }}</td>
                                    <td class="py-3 pr-4 text-slate-300">{{ $c->program }}</td>
                                    <td class="py-3 pr-4">
                                        @if($c->apparatus)
                                            <x-badge tone="violet">{{ $c->apparatus }}</x-badge>
                                        @else
                                            <span class="text-slate-500">—</span>
                                        @endif
                                    </td>
                                    <td class="py-3 text-right">
                                        <a class="text-sky-300 hover:text-sky-200" href="{{ route('secretary.queue.review', $c) }}">Просмотр →</a>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="sm:hidden space-y-3">
                        @foreach($categories as $c)
                            <div class="border border-slate-800 rounded-xl p-4 bg-slate-950/40">
                                <div class="font-medium text-slate-100">
                                    {{ $c->name }}
                                </div>
                                <div class="text-sm text-slate-400 mt-1">
                                    {{ $c->tournament?->name ?? '—' }}
                                </div>
                                <div class="text-sm text-slate-400 mt-2 flex flex-wrap gap-2 items-center">
                                    <x-badge tone="gray">{{ $c->program }}</x-badge>
                                    @if($c->apparatus)
                                        <x-badge tone="violet">{{ $c->apparatus }}</x-badge>
                                    @else
                                        <x-badge tone="gray">—</x-badge>
                                    @endif
                                </div>
                                <div class="mt-3 flex justify-end">
                                    <a class="text-sky-300 hover:text-sky-200 font-medium" href="{{ route('secretary.queue.review', $c) }}">Просмотр →</a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
