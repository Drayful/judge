<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedSmallInteger('minutes_per_athlete')->nullable()->default(2)->after('ends_at_label');
            $table->string('schedule_chain', 80)->nullable()->after('minutes_per_athlete')->index();
            $table->unsignedInteger('schedule_sequence')->nullable()->after('schedule_chain');
        });

        Schema::table('performances', function (Blueprint $table) {
            $table->string('scheduled_at_label', 16)->nullable()->after('order_index');
        });

        $this->backfillExistingSchedules();
    }

    public function down(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            $table->dropColumn('scheduled_at_label');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['schedule_chain']);
            $table->dropColumn(['minutes_per_athlete', 'schedule_chain', 'schedule_sequence']);
        });
    }

    private function backfillExistingSchedules(): void
    {
        $categories = DB::table('categories')
            ->orderBy('group_id')
            ->orderBy('stream_no')
            ->orderBy('id')
            ->get(['id', 'group_id', 'stream_no', 'starts_at_label']);

        foreach ($categories->groupBy(fn ($category) => $category->group_id ?? 'category:'.$category->id) as $groupId => $streams) {
            $cursor = null;
            $sequence = 0;

            foreach ($streams as $category) {
                $startLabel = $cursor?->format('H:i') ?? substr((string) ($category->starts_at_label ?? ''), 0, 5);
                if ($startLabel === '') {
                    continue;
                }

                $cursor = $cursor ?? DateTimeImmutable::createFromFormat('!H:i', $startLabel);
                if (! $cursor instanceof DateTimeImmutable) {
                    $cursor = null;

                    continue;
                }

                $performanceIds = DB::table('performances')
                    ->where('category_id', $category->id)
                    ->where('status', '!=', 'withdrawn')
                    ->orderBy('order_index')
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->values();

                foreach ($performanceIds as $performanceId) {
                    DB::table('performances')
                        ->where('id', $performanceId)
                        ->update(['scheduled_at_label' => $cursor->format('H:i')]);
                    $cursor = $cursor->modify('+2 minutes');
                }

                $values = [
                    'starts_at_label' => $startLabel,
                    'ends_at_label' => $cursor->format('H:i'),
                    'minutes_per_athlete' => 2,
                ];
                if ($category->group_id !== null) {
                    $values['schedule_chain'] = 'group:'.$category->group_id;
                    $values['schedule_sequence'] = ++$sequence;
                }

                DB::table('categories')->where('id', $category->id)->update($values);
            }
        }
    }
};
