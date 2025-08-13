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
        
        // Cleanup LibreOffice temporary files daily
        $schedule->command('libreoffice:cleanup')->daily()->at('01:00');

        // Restart queue workers hourly for memory leaks
        $schedule->command('queue:restart')->hourly();

        // Backup database and files daily at 2 AM
        $schedule->command('backup:run --only-db')->dailyAt('02:00');
        $schedule->command('backup:run --only-files')->weeklyOn(1, '03:00'); // Monday at 3 AM

        // Clean old backups
        $schedule->command('backup:clean')->daily()->at('04:00');

        // Monitor tenant limits daily
        $schedule->command('monitor:tenant-limits --alert')->daily()->at('09:00');

        // Monitor storage usage weekly
        $schedule->command('monitor:storage-usage --alert')->weekly()->sundays()->at('10:00');

        // Monitor queue health every 30 minutes
        $schedule->command('monitor:queue-health --alert')->everyThirtyMinutes();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
