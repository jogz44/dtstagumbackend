<?php

namespace App\Providers;

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
        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
            if (in_array($ability, ['documents.view_confidential', 'can_view_confidential'], true)) {
                return null;
            }

            return ($user->username === 'admin' || $user->hasAnyRole(\App\Support\RoleNames::adminRoles())) ? true : null;
        });
    }
}
