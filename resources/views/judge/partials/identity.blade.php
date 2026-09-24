@php
    $roster = \Illuminate\Support\Facades\DB::table('tournament_judges')->where('tournament_id', $tournament->id)->orderBy('name')->get();
@endphp
@if($roster->isNotEmpty())
<form method="POST" action="{{ route('workflow.judges.bind', $tournament) }}" class="flex shrink-0 items-center gap-2 text-sm">
    @csrf
    <label for="judge-identity">Судья</label>
    <select id="judge-identity" name="judge_id" required class="rounded bg-slate-900 text-white">
        <option value="">Выберите ФИО</option>
        @foreach($roster as $person)
            <option value="{{ $person->id }}" @selected($person->tablet_user_id === auth()->id()) @disabled($person->tablet_user_id && $person->tablet_user_id !== auth()->id())>{{ $person->name }} · {{ $person->club }}</option>
        @endforeach
    </select>
    <button class="rounded bg-sky-700 px-3 py-2 text-white">Закрепить</button>
</form>
@endif
