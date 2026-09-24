<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Performance;
use App\Models\StreamSession;
use App\Models\Tournament;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TournamentWorkflowController extends Controller
{
    public function settings(Request $request, Tournament $tournament)
    {
        $data = $request->validate([
            'max_panel_spread' => ['required', 'numeric', 'min:0', 'max:99'],
            'max_average_deviation' => ['nullable', 'numeric', 'min:0', 'max:99'],
        ]);
        $tournament->forceFill($data)->save();

        return back()->with('status', 'Допуски сохранены. Отклонение от средней проверяется относительно среднего арифметического активных судей панели.');
    }

    public function importJudges(Request $request, Tournament $tournament)
    {
        $request->validate(['judges' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120']]);
        $book = IOFactory::load($request->file('judges')->getRealPath());
        $rows = $book->getActiveSheet()->toArray();
        $book->disconnectWorksheets();
        if (mb_strtolower(trim((string) ($rows[0][0] ?? ''))) !== 'фио') {
            throw ValidationException::withMessages(['judges' => 'Первая строка: ФИО | Школа.']);
        }
        DB::transaction(function () use ($rows, $tournament) {
            foreach (array_slice($rows, 1) as $row) {
                $name = trim((string) ($row[0] ?? ''));
                $club = trim((string) ($row[1] ?? ''));
                if ($name === '') {
                    continue;
                }
                if (mb_strlen($name) > 255 || mb_strlen($club) > 255) {
                    throw ValidationException::withMessages(['judges' => 'ФИО и школа: не более 255 символов.']);
                }
                DB::table('tournament_judges')->updateOrInsert(
                    ['tournament_id' => $tournament->id, 'name' => $name],
                    ['club' => $club ?: null, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        });

        return back()->with('status', 'Список судей загружен.');
    }

    public function bindJudge(Request $request, Tournament $tournament)
    {
        $data = $request->validate(['judge_id' => ['required', 'integer']]);
        DB::transaction(function () use ($request, $data, $tournament) {
            // Общая блокировка предотвращает одновременное закрепление одного судьи.
            Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            $judge = DB::table('tournament_judges')->where('tournament_id', $tournament->id)
                ->where('id', $data['judge_id'])->first();
            abort_unless($judge, 404);
            if ($judge->tablet_user_id !== null && (int) $judge->tablet_user_id !== $request->user()->id) {
                throw ValidationException::withMessages(['judge_id' => 'Судья уже закреплён за другим планшетом.']);
            }
            DB::table('tournament_judges')->where('tournament_id', $tournament->id)
                ->where('tablet_user_id', $request->user()->id)->update(['tablet_user_id' => null]);
            DB::table('tournament_judges')->where('id', $judge->id)
                ->update(['tablet_user_id' => $request->user()->id, 'updated_at' => now()]);
        });

        return back()->with('status', 'Судья закреплён за планшетом.');
    }

    public function streamState(Request $request, Category $category)
    {
        $data = $request->validate(['closed' => ['required', 'boolean'], 'session' => ['nullable', 'integer']]);
        DB::transaction(function () use ($data, $category) {
            $category = Category::query()->lockForUpdate()->findOrFail($category->id);
            $target = empty($data['session']) ? $category : $category->sessions()->lockForUpdate()->findOrFail($data['session']);
            if ($data['closed']) {
                $performances = Performance::query()->where('category_id', $category->id)
                    ->where('stream_session_id', $data['session'] ?? null)->where('status', 'performing')->lockForUpdate()->get();
                foreach ($performances as $performance) {
                    if ($performance->timer_started_at !== null && $performance->timer_ended_at === null) {
                        throw ValidationException::withMessages(['closed' => 'Сначала остановите официальный таймер.']);
                    }
                    $performance->recordFinishedAt();
                    $performance->status = 'done';
                    $performance->save();
                }
            }
            $target->forceFill(['closed_at' => $data['closed'] ? now() : null])->save();
        });

        return back()->with('status', $data['closed'] ? 'Поток завершён.' : 'Поток открыт.');
    }

    public function award(Request $request, StreamSession $session)
    {
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:0', 'max:240']]);
        DB::transaction(function () use ($data, $session) {
            $tournamentId = $session->category->tournament_id;
            Tournament::query()->lockForUpdate()->findOrFail($tournamentId);
            $session->refresh();
            if ($session->starts_at === null || $session->ends_at === null) {
                throw ValidationException::withMessages(['minutes' => 'Сначала укажите начало и конец потока.']);
            }
            $delta = (int) $data['minutes'] - (int) $session->award_minutes;
            $awardEnd = Carbon::parse($session->scheduled_on->format('Y-m-d').' '.$session->ends_at)->addMinutes((int) $data['minutes']);
            if ($awardEnd->format('Y-m-d') !== $session->scheduled_on->format('Y-m-d')) {
                throw ValidationException::withMessages(['minutes' => 'Награждение выходит за границы дня.']);
            }
            $following = StreamSession::query()->whereHas('category', fn ($q) => $q->where('tournament_id', $tournamentId))
                ->whereDate('scheduled_on', $session->scheduled_on)->where('starts_at', '>=', $session->ends_at)
                ->whereKeyNot($session->id)->orderBy('starts_at')->lockForUpdate()->get();
            foreach ($following as $next) {
                foreach (['starts_at', 'ends_at'] as $field) {
                    if ($next->$field === null) {
                        continue;
                    }
                    $time = Carbon::parse($session->scheduled_on->format('Y-m-d').' '.$next->$field)->addMinutes($delta);
                    if ($time->format('Y-m-d') !== $session->scheduled_on->format('Y-m-d')) {
                        throw ValidationException::withMessages(['minutes' => 'Сдвиг выходит за границы дня.']);
                    }
                    $next->$field = $time->format('H:i:s');
                }
                $next->save();
                foreach ($next->performances()->whereNotNull('scheduled_at_label')->get() as $performance) {
                    $performance->scheduled_at_label = Carbon::parse($performance->scheduled_at_label)->addMinutes($delta)->format('H:i');
                    $performance->save();
                }
            }
            $session->forceFill(['award_minutes' => $data['minutes']])->save();
        });

        return back()->with('status', 'Награждение сохранено; следующие потоки дня сдвинуты.');
    }
}
