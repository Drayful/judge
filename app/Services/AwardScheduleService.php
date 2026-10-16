<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Group;
use App\Models\Performance;
use App\Models\StreamSession;
use App\Models\Tournament;
use App\Models\TournamentAward;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Награждение в любое время сдвигает выступления этого дня.
 *
 * - Начало потоков дня, стоящих после начала награждения, сдвигается на его длительность
 *   (при удалении/переносе — обратно).
 * - Внутри потока выход, попавший на награждение, переносится на его окончание —
 *   это учитывает StreamScheduleService при каждом пересчёте, поэтому сдвиг не теряется.
 * - Награждение без окончания длительности не имеет и время не сдвигает.
 */
class AwardScheduleService
{
    public function __construct(private readonly StreamScheduleService $schedule) {}

    public function create(Tournament $tournament, array $data): TournamentAward
    {
        return DB::transaction(function () use ($tournament, $data) {
            Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            $award = $tournament->awards()->create($data);
            $this->shiftDay($tournament, $this->date($award), $this->start($award), $this->duration($award));
            $this->recalculate($tournament, [$this->date($award)]);

            return $award;
        });
    }

    public function update(TournamentAward $award, array $data): void
    {
        DB::transaction(function () use ($award, $data) {
            $tournament = Tournament::query()->lockForUpdate()->findOrFail($award->tournament_id);
            $award->refresh();
            [$oldDate, $oldStart, $oldEnd, $oldDuration] = [$this->date($award), $this->start($award), $this->end($award), $this->duration($award)];
            $award->fill($data);
            // Время сравниваем по ЧЧ:ММ: в базе оно хранится с секундами.
            if ([$oldDate, $oldStart, $oldEnd] === [$this->date($award), $this->start($award), $this->end($award)]) {
                $award->save();

                return;
            }

            // Сначала возвращаем то, что сдвинуло прежнее время, затем сдвигаем по новому.
            $this->shiftDay($tournament, $oldDate, $oldEnd, -$oldDuration);
            $award->save();
            $this->shiftDay($tournament, $this->date($award), $this->start($award), $this->duration($award));
            $this->recalculate($tournament, [$oldDate, $this->date($award)]);
        });
    }

    public function delete(TournamentAward $award): void
    {
        DB::transaction(function () use ($award) {
            $tournament = Tournament::query()->lockForUpdate()->findOrFail($award->tournament_id);
            $award->refresh();
            $this->shiftDay($tournament, $this->date($award), $this->end($award), -$this->duration($award));
            $award->delete();
            $this->recalculate($tournament, [$this->date($award)]);
        });
    }

    /**
     * Сдвинуть на $delta минут всё, что в этот день начинается не раньше $from.
     */
    private function shiftDay(Tournament $tournament, ?string $date, ?int $from, int $delta): void
    {
        if ($date === null || $from === null || $delta === 0) {
            return;
        }

        $sessions = StreamSession::query()
            ->with('category.group')
            ->whereIn('category_id', Category::query()->where('tournament_id', $tournament->id)->select('id'))
            ->whereDate('scheduled_on', $date)
            ->whereNotNull('starts_at')
            ->lockForUpdate()
            ->get();

        foreach ($sessions as $session) {
            $start = $this->minutes($session->starts_at);
            $end = $session->ends_at !== null ? $this->minutes($session->ends_at) : null;
            $ownSchedule = $session->category?->group?->schedule_mode === 'sessions';

            if ($start >= $from) {
                $session->starts_at = $this->clock($start + $delta);
            }
            if ($end !== null && $end > $from) {
                $session->ends_at = $this->clock($end + $delta);
            }
            $session->save();

            // Группы со страницы «Потоки» пересчитываются целиком; у старых групп
            // время выходов хранится как есть — сдвигаем его напрямую.
            if (! $ownSchedule) {
                Performance::query()->where('stream_session_id', $session->id)->whereNotNull('scheduled_at_label')->get(['id', 'scheduled_at_label'])
                    ->each(function (Performance $performance) use ($from, $delta) {
                        $time = $this->minutes($performance->scheduled_at_label);
                        if ($time >= $from) {
                            Performance::query()->whereKey($performance->id)->update(['scheduled_at_label' => $this->clock($time + $delta)]);
                        }
                    });
            }
        }
    }

    /** @param list<?string> $dates */
    private function recalculate(Tournament $tournament, array $dates): void
    {
        $dates = array_values(array_unique(array_filter($dates)));
        if ($dates === []) {
            return;
        }

        $groupIds = StreamSession::query()
            ->join('categories', 'categories.id', '=', 'stream_sessions.category_id')
            ->where('categories.tournament_id', $tournament->id)
            ->where(function ($query) use ($dates) {
                foreach ($dates as $date) {
                    $query->orWhereDate('stream_sessions.scheduled_on', $date);
                }
            })
            ->whereNotNull('categories.group_id')
            ->distinct()
            ->pluck('categories.group_id');

        Group::query()->whereIn('id', $groupIds)->where('schedule_mode', 'sessions')->get()
            ->each(fn (Group $group) => $this->schedule->recalculateGroupSessions($group));
    }

    private function duration(TournamentAward $award): int
    {
        $start = $this->start($award);
        $end = $this->end($award);

        return $start !== null && $end !== null ? max(0, $end - $start) : 0;
    }

    private function date(TournamentAward $award): ?string
    {
        return $award->scheduled_on?->format('Y-m-d');
    }

    private function start(TournamentAward $award): ?int
    {
        return $award->starts_at ? $this->minutes($award->starts_at) : null;
    }

    private function end(TournamentAward $award): ?int
    {
        return $award->ends_at ? $this->minutes($award->ends_at) : null;
    }

    private function minutes(mixed $time): int
    {
        $value = substr(trim((string) $time), 0, 5);

        return ((int) substr($value, 0, 2)) * 60 + (int) substr($value, 3, 2);
    }

    private function clock(int $minutes): string
    {
        if ($minutes < 0 || $minutes >= 24 * 60) {
            throw new DomainException('Сдвиг из-за награждения выходит за границы дня. Измените время награждения.');
        }

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
