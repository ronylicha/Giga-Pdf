<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CleanupTempPdf extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pdf:cleanup-temp {--days=1 : Number of days to keep temp files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up temporary PDF files';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = $this->option('days');
        $cutoffDate = Carbon::now()->subDays($days);
        
        $this->info("Cleaning up temporary files older than {$days} day(s)...");
        
        // Clean up temp directory
        $tempPath = storage_path('app/temp');
        if (is_dir($tempPath)) {
            $files = glob($tempPath . '/*');
            $deletedCount = 0;
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    $fileTime = Carbon::createFromTimestamp(filemtime($file));
                    if ($fileTime->lt($cutoffDate)) {
                        unlink($file);
                        $deletedCount++;
                    }
                }
            }
            
            $this->info("Deleted {$deletedCount} temporary file(s).");
        }
        
        // Clean up system temp directory (PDF-related files)
        $systemTemp = sys_get_temp_dir();
        $patterns = ['pdf_*', 'edited_*.html', 'output_*.pdf', 'converted_*'];
        $deletedSystem = 0;
        
        foreach ($patterns as $pattern) {
            $files = glob($systemTemp . '/' . $pattern);
            foreach ($files as $file) {
                if (is_file($file)) {
                    $fileTime = Carbon::createFromTimestamp(filemtime($file));
                    if ($fileTime->lt($cutoffDate)) {
                        @unlink($file);
                        $deletedSystem++;
                    }
                }
            }
        }
        
        if ($deletedSystem > 0) {
            $this->info("Deleted {$deletedSystem} system temporary file(s).");
        }
        
        $this->info('Cleanup completed successfully.');
        
        return Command::SUCCESS;
    }
}