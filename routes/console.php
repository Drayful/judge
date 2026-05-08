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
