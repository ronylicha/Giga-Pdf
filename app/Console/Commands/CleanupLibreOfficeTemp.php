<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanupLibreOfficeTemp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'libreoffice:cleanup 
                            {--days=1 : Number of days to keep files}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up temporary LibreOffice conversion files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = $this->option('days');
        $dryRun = $this->option('dry-run');
        
        $tempPath = storage_path('app/libreoffice/temp');
        
        if (!File::exists($tempPath)) {
            $this->warn('LibreOffice temp directory does not exist: ' . $tempPath);
            return Command::SUCCESS;
        }
        
        $this->info('Cleaning up LibreOffice temporary files older than ' . $days . ' day(s)...');
        
        $deletedFiles = 0;
        $deletedDirs = 0;
        $freedSpace = 0;
        
        // Clean up old conversion directories
        $directories = File::directories($tempPath);
        foreach ($directories as $dir) {
            $dirTime = File::lastModified($dir);
            $cutoffTime = now()->subDays($days)->timestamp;
            
            if ($dirTime < $cutoffTime) {
                if ($dryRun) {
                    $this->line('Would delete directory: ' . basename($dir));
                } else {
                    // Calculate space before deletion
                    $size = $this->getDirectorySize($dir);
                    $freedSpace += $size;
                    
                    // Delete directory and its contents
                    File::deleteDirectory($dir);
                    $deletedDirs++;
                    
                    $this->line('Deleted directory: ' . basename($dir) . ' (' . $this->formatBytes($size) . ')');
                }
            }
        }
        
        // Clean up any orphaned files in the temp directory
        $files = File::files($tempPath);
        foreach ($files as $file) {
            $fileTime = File::lastModified($file);
            $cutoffTime = now()->subDays($days)->timestamp;
            
            if ($fileTime < $cutoffTime) {
                if ($dryRun) {
                    $this->line('Would delete file: ' . basename($file));
                } else {
                    $size = File::size($file);
                    $freedSpace += $size;
                    
                    File::delete($file);
                    $deletedFiles++;
                    
                    $this->line('Deleted file: ' . basename($file) . ' (' . $this->formatBytes($size) . ')');
                }
            }
        }
        
        // Clean up cache directory
        $cachePath = storage_path('app/libreoffice/cache');
        if (File::exists($cachePath)) {
            $cacheFiles = File::allFiles($cachePath);
            foreach ($cacheFiles as $file) {
                $fileTime = File::lastModified($file);
                $cutoffTime = now()->subDays($days * 7)->timestamp; // Keep cache longer
                
                if ($fileTime < $cutoffTime) {
                    if ($dryRun) {
                        $this->line('Would delete cache file: ' . $file->getRelativePathname());
                    } else {
                        $size = File::size($file);
                        $freedSpace += $size;
                        
                        File::delete($file);
                        $deletedFiles++;
                    }
                }
            }
        }
        
        if ($dryRun) {
            $this->info('Dry run completed. No files were actually deleted.');
        } else {
            $this->info('Cleanup completed!');
            $this->info('Deleted ' . $deletedDirs . ' directories and ' . $deletedFiles . ' files');
            $this->info('Freed up ' . $this->formatBytes($freedSpace) . ' of disk space');
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Get the size of a directory
     */
    private function getDirectorySize($dir): int
    {
        $size = 0;
        
        foreach (File::allFiles($dir) as $file) {
            $size += $file->getSize();
        }
        
        return $size;
    }
    
    /**
     * Format bytes to human readable format
     */
    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}