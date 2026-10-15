<?php

namespace App\Services;

use App\Models\Category;
use App\Models\StreamSession;
use App\Models\Tournament;
use App\Models\TournamentAward;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Блоки стартового листа по дням: потоки (сессии) и награждения.
 * Порядок внутри дня задаёт секретарь (sheet_position); новые блоки без
 * позиции встают по времени между уже упорядоченными.
 */
class StartListService
{
    /**
     * @return Collection<string, Collection<int, array<string, mixed>>> ключ — дата Y-m-d, '' — без даты
     */
    public function days(Tournament $tournament): Collection
    {
        $categories = Category::query()
            ->with(['group', 'sessions', 'performances.athlete.members', 'performances.track'])
            ->where('tournament_id', $tournament->id)
            ->orderedByPerformanceTime()
            ->get();

        $items = collect();
        foreach ($categories as $category) {
            if ($category->sessions->isEmpty()) {
                $items->push($this->streamItem($category, null, $category->performances, false));

                continue;
            }
            foreach ($category->sessions->sortBy('session_no') as $session) {
                $items->push($this->streamItem($category, $session, $category->performances->where('stream_session_id', $session->id), false));
            }
            $unassigned = $category->performances->whereNull('stream_session_id');
            if ($unassigned->isNotEmpty()) {
                $items->push($this->streamItem($category, null, $unassigned, true));
            }
        }

        foreach (TournamentAward::query()->where('tournament_id', $tournament->id)->get() as $award) {
            $items->push([
                'type' => 'award',
                'key' => 'award:'.$award->id,
                'award' => $award,
                'date' => $award->scheduled_on?->format('Y-m-d') ?? '',
                'start' => $this->hm($award->starts_at),
                'end' => $this->hm($award->ends_at),
                'position' => $award->sheet_position,
                'tiebreak' => '',
            ]);
        }

        return $items
            ->groupBy('date')
            ->sortKeysUsing(fn (string $a, string $b) => ($a === '' ? '9999' : $a) <=> ($b === '' ? '9999' : $b))
            ->map(fn (Collection $day) => $this->order($day));
    }

    /**
     * Сохранить порядок блоков дня, как его расставил секретарь.
     *
     * @param  list<string>  $keys  'session:ID' | 'category:ID' | 'award:ID'
     */
    public function saveOrder(Tournament $tournament, string $date, array $keys): void
    {
        $day = $this->days($tournament)->get($date, collect());
        $known = $day->pluck('key')->all();
        if (count($keys) !== count($known) || array_diff($keys, $known) !== [] || count(array_unique($keys)) !== count($keys)) {
            throw new DomainException('Список блоков дня изменился. Обновите страницу и повторите.');
        }

        DB::transaction(function () use ($keys) {
            foreach (array_values($keys) as $i => $key) {
                [$type, $id] = explode(':', $key, 2);
                $model = match ($type) {
                    'session' => StreamSession::query()->find($id),
                    'category' => Category::query()->find($id),
                    'award' => TournamentAward::query()->find($id),
                };
                $model?->forceFill(['sheet_position' => $i + 1])->save();
            }
        });
    }

    /**
     * Поставить новое/изменённое награждение на место по его времени.
     */
    public function placeAwardByTime(TournamentAward $award): void
    {
        $award->forceFill(['sheet_position' => null])->save();
        $date = $award->scheduled_on?->format('Y-m-d') ?? '';
        $day = $this->days($award->tournament)->get($date, collect());
        if ($day->contains(fn (array $item) => $item['key'] !== 'award:'.$award->id && $item['position'] !== null)) {
            $this->saveOrder($award->tournament, $date, $day->pluck('key')->all());
        }
    }

    /** @return array<string, mixed> */
    private function streamItem(Category $category, ?StreamSession $session, Collection $performances, bool $unassigned): array
    {
        $date = $session?->scheduled_on?->format('Y-m-d') ?? '';
        $start = $session ? $this->hm($session->starts_at) : $this->hm($category->starts_at_label);
        $end = $session ? $this->hm($session->ends_at) : $this->hm($category->ends_at_label);

        return [
            'type' => 'stream',
            'key' => $session ? 'session:'.$session->id : 'category:'.$category->id,
            'category' => $category,
            'session' => $session,
            'performances' => $performances->sortBy([['order_index', 'asc'], ['id', 'asc']])->values(),
            'unassigned' => $unassigned,
            'date' => $unassigned ? '' : $date,
            'start' => $unassigned ? null : $start,
            'end' => $unassigned ? null : $end,
            'position' => $unassigned ? null : ($session ? $session->sheet_position : $category->sheet_position),
            'tiebreak' => str_pad((string) ($category->group_id ?? 0), 8, '0', STR_PAD_LEFT)
                .str_pad((string) ($category->stream_no ?? $category->id), 6, '0', STR_PAD_LEFT)
                .str_pad((string) ($session?->session_no ?? 0), 4, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $day
     * @return Collection<int, array<string, mixed>>
     */
    private function order(Collection $day): Collection
    {
        $timeKey = fn (array $item) => ($item['start'] ?? '99:99').'|'.($item['type'] === 'award' ? '1' : '0').'|'.$item['tiebreak'];

        $ordered = $day->filter(fn (array $item) => $item['position'] !== null)
            ->sortBy(fn (array $item) => sprintf('%08d', $item['position']))
            ->values()
            ->all();
        $loose = $day->filter(fn (array $item) => $item['position'] === null)->sortBy($timeKey)->values();

        foreach ($loose as $item) {
            $at = count($ordered);
            if ($item['start'] !== null) {
                foreach ($ordered as $i => $placed) {
                    if ($placed['start'] !== null && $placed['start'] > $item['start']) {
                        $at = $i;
                        break;
                    }
                }
            }
            array_splice($ordered, $at, 0, [$item]);
        }

        $previous = null;
        foreach ($ordered as $i => $item) {
            $ordered[$i]['out_of_order'] = $previous !== null && $item['start'] !== null && $item['start'] < $previous;
            if ($item['start'] !== null) {
                $previous = $item['start'];
            }
        }

        return collect($ordered);
    }

    private function hm(mixed $time): ?string
    {
        $value = trim((string) $time);

        return $value !== '' ? substr($value, 0, 5) : null;
    }
}
