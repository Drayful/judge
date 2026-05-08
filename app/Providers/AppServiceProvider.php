<?php

namespace App\Providers;

use App\Models\MusicTrack;
use App\Models\User;
use App\Policies\MusicTrackPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(MusicTrack::class, MusicTrackPolicy::class);
    }
}
