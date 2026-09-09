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
        if (!ini_get('openssl.cafile')) {
            foreach ([
                'C:\\laragon\\etc\\ssl\\cacert.pem',
                base_path('cacert.pem'),
            ] as $ca) {
                if (file_exists($ca)) {
                    ini_set('openssl.cafile', $ca);
                    break;
                }
            }
        }

        // Spatie delegates Gate checks to User::hasPermissionTo, including overrides.
    }
}
