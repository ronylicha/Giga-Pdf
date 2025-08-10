<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Cleanup temporary PDF files daily
        $schedule->command('pdf:cleanup-temp')->daily();
        
        // Optimize storage weekly
        $schedule->command('pdf:optimize-storage')->weekly();
        
        // Reindex search content monthly
        $schedule->command('pdf:reindex-search')->monthly();

        // Cleanup temporary documents daily
        $schedule->command('documents:cleanup-temp --hours=24')->daily();

        // Restart queue workers hourly for memory leaks
        $schedule->command('queue:restart')->hourly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
