<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('demo:clear-competition-data {--force : Без подтверждения}', function () {
    if (! $this->option('force')) {
        if (! $this->confirm('Удалить всех атлетов, категории (потоки), выступления, музыку и связанные данные? Турниры и пользователи останутся.', false)) {
            return 1;
        }
    }

    \Illuminate\Support\Facades\DB::transaction(function () {
        \App\Models\MusicTrack::query()->delete();
        \App\Models\Inquiry::query()->delete();
        \App\Models\JudgeScore::query()->delete();
        \App\Models\Performance::query()->whereNotNull('original_performance_id')->delete();
        \App\Models\Performance::query()->delete();
        \App\Models\Category::query()->delete();
        \App\Models\Athlete::query()->delete();
    });

    $this->info('Готово: athletes, categories, performances (и связанное) очищены.');

    return 0;
})->purpose('Очистка данных для повторного импорта Excel (тест)');

Artisan::command('users:sound-operator {email} {--name=Звукорежиссёр}', function () {
    $email = (string) $this->argument('email');
    $existing = \App\Models\User::query()->where('email', $email)->first();
    if ($existing !== null && $existing->role !== 'sound_operator') {
        $this->error("{$email} уже занят аккаунтом с ролью {$existing->role}. Укажите другой email.");

        return 1;
    }
    $password = (string) $this->secret('Пароль (не меньше 8 символов)');
    if (mb_strlen($password) < 8) {
        $this->error('Пароль слишком короткий.');

        return 1;
    }

    $user = \App\Models\User::query()->updateOrCreate(
        ['email' => $email],
        [
            'name' => (string) $this->option('name'),
            'password' => \Illuminate\Support\Facades\Hash::make($password),
            'role' => 'sound_operator',
            'slot' => null,
        ],
    );
    if ($user->email_verified_at === null) {
        $user->forceFill(['email_verified_at' => now()])->save();
    }

    $this->info("Готово: {$email} — роль «Музыка выступлений» (/music-operator).");

    return 0;
})->purpose('Создать (или обновить) аккаунт звукорежиссёра');
