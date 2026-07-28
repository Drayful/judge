<?php

use App\Support\PerformanceApparatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stream_sessions')->orderBy('id')->each(function (object $session): void {
            $apparatus = json_decode((string) $session->apparatus, true);
            if (! is_array($apparatus) || $apparatus === []) {
                return;
            }

            $keys = array_map(
                fn (mixed $label) => PerformanceApparatus::sessionKey(is_string($label) ? $label : null),
                $apparatus,
            );

            DB::table('performances')
                ->where('category_id', $session->category_id)
                ->whereNull('stream_session_id')
                ->where('status', 'scheduled')
                ->orderBy('id')
                ->each(function (object $performance) use ($keys, $session): void {
                    if (in_array(PerformanceApparatus::sessionKey($performance->apparatus), $keys, true)) {
                        DB::table('performances')
                            ->where('id', $performance->id)
                            ->update(['stream_session_id' => $session->id]);
                    }
                });
        });
    }

    public function down(): void
    {
        // Данные не откатываем: привязка сессии является корректировкой расписания.
    }
};
