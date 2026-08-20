<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Performance;
use App\Models\StreamSession;
use App\Support\PerformanceApparatus;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GroupStreamSessionService
{
    /**
     * @param  array{title:?string,scheduled_on:string,starts_at:?string,ends_at:?string,apparatus:list<string>}  $data
     */
    public function create(Category $source, array $data): StreamSession
    {
        return DB::transaction(function () use ($source, $data) {
            $categories = $this->lockedGroupCategories($source);
            $categoryIds = $categories->pluck('id');
            $sessionNo = ((int) StreamSession::query()
                ->whereIn('category_id', $categoryIds)
                ->max('session_no')) + 1;

            foreach ($categories as $category) {
                $this->ensureApparatusAvailable($category, $data['apparatus']);
            }

            $created = null;
            foreach ($categories as $category) {
                $session = StreamSession::query()->create([
                    ...$data,
                    'category_id' => $category->id,
                    'session_no' => $sessionNo,
                ]);
                $this->syncPerformances($category, $session);

                if ($category->is($source)) {
                    $created = $session;
                }
            }

            return $created ?? throw new DomainException('Исходный поток не найден в группе.');
        });
    }

    /**
     * @param  array{title:?string,scheduled_on:string,starts_at:?string,ends_at:?string,apparatus:list<string>}  $data
     */
    public function update(Category $source, StreamSession $sourceSession, array $data): void
    {
        DB::transaction(function () use ($source, $sourceSession, $data) {
            $categories = $this->lockedGroupCategories($source);
            $sessionNo = (int) $sourceSession->session_no;
            $sessions = StreamSession::query()
                ->whereIn('category_id', $categories->pluck('id'))
                ->where('session_no', $sessionNo)
                ->lockForUpdate()
                ->get();

            $this->ensureSessionsAreEditable($sessions);

            foreach ($categories as $category) {
                $this->ensureApparatusAvailable($category, $data['apparatus'], $sessionNo);
            }

            foreach ($categories as $category) {
                $session = StreamSession::query()->updateOrCreate(
                    ['category_id' => $category->id, 'session_no' => $sessionNo],
                    $data,
                );
                $this->syncPerformances($category, $session);
            }
        });
    }

    public function delete(Category $source, StreamSession $sourceSession): void
    {
        DB::transaction(function () use ($source, $sourceSession) {
            $categories = $this->lockedGroupCategories($source);
            $sessions = StreamSession::query()
                ->whereIn('category_id', $categories->pluck('id'))
                ->where('session_no', $sourceSession->session_no)
                ->lockForUpdate()
                ->get();

            $this->ensureSessionsAreEditable($sessions);

            foreach ($sessions as $session) {
                $session->performances()
                    ->where('status', 'scheduled')
                    ->update(['stream_session_id' => null]);
                $session->delete();
            }
        });
    }

    /**
     * Новый поток наследует уже настроенные дни группы.
     */
    public function copyGroupScheduleToNewCategory(Category $category): void
    {
        if ($category->group_id === null || $category->sessions()->exists()) {
            return;
        }

        $template = Category::query()
            ->where('group_id', $category->group_id)
            ->whereKeyNot($category->id)
            ->whereHas('sessions')
            ->orderBy('stream_no')
            ->orderBy('id')
            ->first();

        if ($template === null) {
            return;
        }

        foreach ($template->sessions()->get() as $session) {
            StreamSession::query()->create([
                'category_id' => $category->id,
                'session_no' => $session->session_no,
                'title' => $session->title,
                'scheduled_on' => $session->scheduled_on,
                'starts_at' => $session->starts_at,
                'ends_at' => $session->ends_at,
                'apparatus' => $session->apparatus,
            ]);
        }
    }

    /** @return Collection<int, Category> */
    private function lockedGroupCategories(Category $source): Collection
    {
        $categories = Category::query()
            ->when(
                $source->group_id !== null,
                fn ($query) => $query->where('group_id', $source->group_id),
                fn ($query) => $query->whereKey($source->id),
            )
            ->orderBy('stream_no')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if (! $categories->contains(fn (Category $category) => $category->is($source))) {
            throw new DomainException('Исходный поток не найден в группе.');
        }

        return $categories;
    }

    /** @param Collection<int, StreamSession> $sessions */
    private function ensureSessionsAreEditable(Collection $sessions): void
    {
        if ($sessions->isEmpty()) {
            throw new DomainException('Сессия больше не существует. Обновите страницу.');
        }

        $started = Performance::query()
            ->whereIn('stream_session_id', $sessions->pluck('id'))
            ->where('status', '!=', 'scheduled')
            ->exists();

        if ($started) {
            throw new DomainException('Нельзя изменить расписание группы: эта сессия уже началась хотя бы в одном потоке.');
        }
    }

    /** @param list<string> $apparatus */
    private function ensureApparatusAvailable(Category $category, array $apparatus, ?int $exceptSessionNo = null): void
    {
        $requested = array_map(
            fn (string $label) => PerformanceApparatus::sessionKey($label),
            $apparatus,
        );

        $conflict = $category->sessions()
            ->when($exceptSessionNo !== null, fn ($query) => $query->where('session_no', '!=', $exceptSessionNo))
            ->get()
            ->contains(function (StreamSession $session) use ($requested) {
                $existing = array_map(
                    fn (string $label) => PerformanceApparatus::sessionKey($label),
                    $session->apparatus ?? [],
                );

                return array_intersect($requested, $existing) !== [];
            });

        if ($conflict) {
            throw new DomainException('Один предмет нельзя назначить в несколько сессий одной группы.');
        }
    }

    private function syncPerformances(Category $category, StreamSession $session): void
    {
        $sessionApparatus = array_map(
            fn (string $apparatus) => PerformanceApparatus::sessionKey($apparatus),
            $session->apparatus ?? [],
        );

        Performance::query()
            ->where('category_id', $category->id)
            ->where('stream_session_id', $session->id)
            ->where('status', 'scheduled')
            ->update(['stream_session_id' => null]);

        Performance::query()
            ->where('category_id', $category->id)
            ->where('status', 'scheduled')
            ->get(['id', 'apparatus'])
            ->filter(fn (Performance $performance) => in_array(
                PerformanceApparatus::sessionKey($performance->apparatus),
                $sessionApparatus,
                true,
            ))
            ->each(fn (Performance $performance) => $performance->update(['stream_session_id' => $session->id]));
    }
}
