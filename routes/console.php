<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (config('services.pmis.sync_enabled')) {
    Schedule::command('pmis:sync-employees --commit')
        ->dailyAt(config('services.pmis.sync_time', '06:00'))
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/pmis-sync.log'));
}
