<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled tasks: overdue checks every 5 minutes, low-stock refresh
// hourly, audit purge + file checksum drill nightly.
Schedule::command('vetops:mark-overdue-rentals')->everyFiveMinutes();
Schedule::command('vetops:refresh-stock-levels')->hourly();
Schedule::command('vetops:purge-audit-logs')->dailyAt('02:15');
Schedule::command('vetops:verify-file-checksums --since=24h')->dailyAt('03:30');
