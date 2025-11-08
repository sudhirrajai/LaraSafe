<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('backups:dispatch-due')->everyMinute();
Schedule::command('app:cleanup-expired-backups')->everyMinute();
Schedule::command('backups:cleanup')->dailyAt('02:00');