<x-public-upload-layout>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-slate-100">Загрузка музыки и фотографии</h1>
            <p class="mt-2 text-sm text-slate-400">Введите ИИН атлетки, чтобы загрузить музыку для её выступлений и фотографию.</p>
        </div>

        @if(session('status'))
            <div class="rounded-lg border border-emerald-700/60 bg-emerald-950/50 p-4 text-sm text-emerald-200">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('public-athlete-upload.search') }}" class="rounded-xl border border-slate-800 bg-slate-900/70 p-5">
            @csrf
            <label for="iin" class="block text-sm font-medium text-slate-200">ИИН атлетки</label>
            <div class="mt-2 flex flex-col gap-3 sm:flex-row">
                <input id="iin" name="iin" value="{{ old('iin', request('iin')) }}" inputmode="numeric" pattern="\d{12}" maxlength="12" required autofocus
                       class="block w-full rounded-lg border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-500 focus:ring-emerald-500"
                       placeholder="12 цифр">
                <button type="submit" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-emerald-500">Продолжить</button>
            </div>
            @error('iin')<p class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror
        </form>

        @isset($athlete)
            <form method="POST" action="{{ route('public-athlete-upload.store', $athlete) }}" enctype="multipart/form-data" class="space-y-5 rounded-xl border border-slate-800 bg-slate-900/70 p-5">
                @csrf
                <div>
                    <h2 class="text-lg font-semibold text-slate-100">{{ $athlete->last_name }} {{ $athlete->first_name }}</h2>
                    <p class="mt-1 text-sm text-slate-400">Выберите музыку для нужных предметов. Уже загруженную музыку можно заменить.</p>
                </div>

                <div>
                    <label for="photo" class="block text-sm font-medium text-slate-200">Фотография атлетки</label>
                    <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp"
                           class="mt-2 block w-full text-sm text-slate-200 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-800 file:px-4 file:py-2 file:text-sm file:font-medium file:text-emerald-300">
                    <p class="mt-1 text-xs text-slate-500">JPG, PNG или WebP, до 10 МБ.</p>
                    @error('photo')<p class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror
                </div>

                <div class="space-y-3">
                    <h3 class="text-sm font-medium text-slate-200">Музыка по предметам</h3>
                    @forelse($performances as $performance)
                        @php($apparatus = $performance->apparatus ?? $performance->category->apparatus ?? 'Предмет не указан')
                        <div class="rounded-lg border border-slate-800 bg-slate-950/60 p-4">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <div class="font-medium text-slate-100">{{ $apparatus }}</div>
                                <div class="text-xs text-slate-500">{{ $performance->category->name }}</div>
                            </div>
                            @if($performance->track)
                                <p class="mb-2 text-xs text-emerald-300">Сейчас: {{ $performance->track->original_name }}</p>
                            @endif
                            <input name="music[{{ $performance->id }}]" type="file" accept="audio/mpeg,audio/mp4,audio/x-m4a,audio/wav"
                                   class="block w-full text-sm text-slate-200 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-800 file:px-4 file:py-2 file:text-sm file:font-medium file:text-emerald-300">
                            @error('music.'.$performance->id)<p class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror
                        </div>
                    @empty
                        <p class="rounded-lg border border-amber-700/60 bg-amber-950/30 p-4 text-sm text-amber-200">Для этой атлетки пока нет активных выступлений. Можно загрузить только фотографию.</p>
                    @endforelse
                </div>

                @error('upload')<p class="text-sm text-rose-300">{{ $message }}</p>@enderror

                <button type="submit" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-emerald-500">Загрузить файлы</button>
            </form>
        @endisset
    </div>
</x-public-upload-layout>
