<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Backups on a timetable (phase 35), as Settings → Backup and Export says.
| These only run if the server has one cron job calling
| `php artisan schedule:run` every minute — README, "Backups".
*/
Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->when(fn () => settings('backup.frequency') === 'daily')
    ->withoutOverlapping();

Schedule::command('backup:run')
    ->weeklyOn(0, '02:00')
    ->when(fn () => settings('backup.frequency') === 'weekly')
    ->withoutOverlapping();
