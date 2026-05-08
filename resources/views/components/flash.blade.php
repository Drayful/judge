@if (session('status'))
    <div class="rounded-xl border border-emerald-700/80 bg-emerald-950/60 text-emerald-50 px-4 py-3 text-sm shadow-sm shadow-emerald-950/40">
        {{ session('status') }}
    </div>
@endif

@if ($errors->any())
    <div class="rounded-xl border border-rose-700/80 bg-rose-950/50 text-rose-50 px-4 py-3 text-sm">
        <div class="font-medium mb-1">Исправьте ошибки:</div>
        <ul class="list-disc list-inside space-y-0.5">
            @foreach ($errors->all() as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
@endif

