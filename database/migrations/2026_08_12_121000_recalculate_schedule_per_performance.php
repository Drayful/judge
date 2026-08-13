<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $categories = DB::table('categories')
            ->orderBy('schedule_chain')
            ->orderBy('schedule_sequence')
            ->orderBy('id')
            ->get([
                'id',
                'group_id',
                'stream_no',
                'starts_at_label',
                'minutes_per_athlete',
                'schedule_chain',
            ]);

        $groupNames = DB::table('groups')->pluck('name', 'id');

        $chains = $categories->groupBy(
            fn ($category) => $category->schedule_chain ?: 'category:'.$category->id,
        );

        foreach ($chains as $streams) {
            $cursor = null;

            foreach ($streams as $category) {
                $startLabel = $cursor?->format('H:i')
                    ?? substr((string) ($category->starts_at_label ?? ''), 0, 5);
                if ($startLabel === '') {
                    continue;
                }

                $cursor = $cursor ?? DateTimeImmutable::createFromFormat('!H:i', $startLabel);
                if (! $cursor instanceof DateTimeImmutable) {
                    $cursor = null;

                    continue;
                }

                $minutes = max(1, (int) ($category->minutes_per_athlete ?? 2));

                DB::table('performances')
                    ->where('category_id', $category->id)
                    ->update(['scheduled_at_label' => null]);

                $performanceIds = DB::table('performances')
                    ->where('category_id', $category->id)
                    ->where('status', '!=', 'withdrawn')
                    ->whereNull('withdrawn_at')
                    ->orderBy('order_index')
                    ->orderBy('id')
                    ->pluck('id');

                foreach ($performanceIds as $performanceId) {
                    DB::table('performances')
                        ->where('id', $performanceId)
                        ->update(['scheduled_at_label' => $cursor->format('H:i')]);
                    $cursor = $cursor->modify("+{$minutes} minutes");
                }

                $values = [
                    'starts_at_label' => $startLabel,
                    'ends_at_label' => $cursor->format('H:i'),
                ];

                $groupName = $category->group_id !== null ? $groupNames->get($category->group_id) : null;
                if ($groupName !== null && $category->stream_no !== null) {
                    $values['name'] = $groupName.' — Поток '.$category->stream_no
                        .' ('.$startLabel.'–'.$cursor->format('H:i').')';
                }

                DB::table('categories')->where('id', $category->id)->update($values);
            }
        }
    }

    public function down(): void
    {
        // Предыдущее расписание нельзя восстановить без потери актуальных изменений очереди.
    }
};
