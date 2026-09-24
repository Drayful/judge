@unless(auth()->user()->isChiefJudge())
<details class="rounded-xl border border-slate-700 bg-slate-950 p-4 text-slate-100">
    <summary class="cursor-pointer font-semibold">Судьи и допустимые расхождения</summary>
    <form method="POST" action="{{ route('workflow.settings', $tournament) }}" class="mt-3 flex flex-wrap gap-3">
        @csrf
        <label>Между крайними <input name="max_panel_spread" type="number" step="0.001" min="0" max="99" required value="{{ $tournament->max_panel_spread ?? 1 }}" class="block rounded bg-slate-900"></label>
        <label>От среднего арифметического <input name="max_average_deviation" type="number" step="0.001" min="0" max="99" value="{{ $tournament->max_average_deviation }}" class="block rounded bg-slate-900"></label>
        <button class="rounded bg-emerald-700 px-4">Сохранить допуски</button>
    </form>
    <form method="POST" action="{{ route('workflow.judges.import', $tournament) }}" enctype="multipart/form-data" class="mt-4 flex flex-wrap gap-3">
        @csrf
        <label>Судьи Excel — столбцы «ФИО», «Школа» <input type="file" name="judges" accept=".xlsx,.xls" required class="block"></label>
        <button class="rounded bg-sky-700 px-4">Загрузить судей</button>
    </form>
</details>
@endunless
