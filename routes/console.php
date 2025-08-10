<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule tasks
Schedule::command('documents:cleanup-temp --hours=24')->hourly();
Schedule::command('pdf:cleanup-temp')->daily();
Schedule::command('telescope:prune')->daily();
Schedule::command('backup:run')->daily();
Schedule::command('monitor:tenant-limits')->hourly();
Schedule::command('queue:restart')->hourly();
