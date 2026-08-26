<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $accounts = [
            [
                'name' => 'Средняя DB',
                'email' => 'db-average@local.test',
                'role' => 'judge_db_average',
                'slot' => 'DB_AVG',
            ],
            [
                'name' => 'Средняя DA',
                'email' => 'da-average@local.test',
                'role' => 'judge_da_average',
                'slot' => 'DA_AVG',
            ],
        ];

        foreach ($accounts as $account) {
            $existing = DB::table('users')->where('email', $account['email'])->first();
            if ($existing !== null) {
                DB::table('users')->where('id', $existing->id)->update([
                    'name' => $account['name'],
                    'role' => $account['role'],
                    'slot' => $account['slot'],
                    'updated_at' => $now,
                ]);

                continue;
            }

            DB::table('users')->insert($account + [
                'password' => Hash::make('password'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('users')
            ->whereIn('email', ['db-average@local.test', 'da-average@local.test'])
            ->whereIn('role', ['judge_db_average', 'judge_da_average'])
            ->delete();
    }
};
