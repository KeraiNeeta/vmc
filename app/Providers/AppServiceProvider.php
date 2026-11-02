<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        Gate::before(static fn (User $user): ?bool => $user->hasRole('SUPER_ADMIN') ? true : null);

        RateLimiter::for('login', static function (Request $request): Limit {
            $email = $request->input('email');
            $key = is_string($email) ? Str::lower(trim($email)) : 'unknown';

            return Limit::perMinute(5)->by($request->ip().'|'.$key);
        });
    }
}
