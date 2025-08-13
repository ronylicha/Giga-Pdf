<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class SetupLibreOffice extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'libreoffice:setup 
                            {--force : Force recreation of directories}
                            {--check : Check LibreOffice installation status}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup LibreOffice directories and permissions for PDF conversions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('check')) {
            return $this->checkInstallation();
        }

        $this->info('Setting up LibreOffice for Giga-PDF...');

        // Create directories
        $this->createDirectories();

        // Set permissions
        $this->setPermissions();

        // Test LibreOffice
        $this->testLibreOffice();

        $this->info('');
        $this->info('✅ LibreOffice setup completed successfully!');

        return Command::SUCCESS;
    }

    /**
     * Create necessary directories
     */
    protected function createDirectories(): void
    {
        $this->info('Creating directories...');

        $directories = [
            'libreoffice' => storage_path('app/libreoffice'),
            'libreoffice/cache' => storage_path('app/libreoffice/cache'),
            'libreoffice/config' => storage_path('app/libreoffice/config'),
            'libreoffice/temp' => storage_path('app/libreoffice/temp'),
            'conversions' => storage_path('app/conversions'),
        ];

        foreach ($directories as $name => $path) {
            if (! is_dir($path)) {
                mkdir($path, 0775, true);
                $this->line("  ✓ Created: $name");
            } elseif ($this->option('force')) {
                // Clean and recreate if force option is used
                $this->cleanDirectory($path);
                $this->line("  ✓ Cleaned: $name");
            } else {
                $this->line("  ✓ Exists: $name");
            }
        }
    }

    /**
     * Set proper permissions
     */
    protected function setPermissions(): void
    {
        $this->info('Setting permissions...');

        $webUser = 'www-data';
        $webGroup = 'www-data';

        $directories = [
            storage_path('app/libreoffice'),
            storage_path('app/conversions'),
        ];

        foreach ($directories as $dir) {
            // Set permissions
            $process = new Process(['chmod', '-R', '775', $dir]);
            $process->run();

            // Try to change ownership if running as root
            if (function_exists('posix_getuid') && posix_getuid() === 0) {
                $process = new Process(['chown', '-R', "$webUser:$webGroup", $dir]);
                $process->run();

                if ($process->isSuccessful()) {
                    $this->line("  ✓ Ownership set to $webUser:$webGroup for " . basename($dir));
                }
            } else {
                $this->warn("  ⚠ Run as root to set ownership to $webUser:$webGroup");
            }
        }
    }

    /**
     * Test LibreOffice installation
     */
    protected function testLibreOffice(): void
    {
        $this->info('Testing LibreOffice...');

        // Check if LibreOffice is installed
        $process = new Process(['which', 'libreoffice']);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('  ✗ LibreOffice is not installed!');
            $this->warn('  Install it with: sudo apt-get install libreoffice');

            return;
        }

        $this->line('  ✓ LibreOffice found at: ' . trim($process->getOutput()));

        // Get LibreOffice version
        $process = new Process(['libreoffice', '--version']);
        $process->run();

        if ($process->isSuccessful()) {
            $version = trim($process->getOutput());
            $this->line('  ✓ Version: ' . $version);
        }

        // Test a simple conversion
        $this->testConversion();
    }

    /**
     * Test a simple conversion
     */
    protected function testConversion(): void
    {
        $this->info('Testing conversion capability...');

        // Create a test HTML file
        $testFile = storage_path('app/libreoffice/temp/test.html');
        file_put_contents($testFile, '<html><body><h1>Test</h1><p>LibreOffice test conversion</p></body></html>');

        $outputDir = storage_path('app/libreoffice/temp');
        $cacheDir = storage_path('app/libreoffice/cache');
        $configDir = storage_path('app/libreoffice/config');

        // Build LibreOffice command
        $command = sprintf(
            'env HOME=%s libreoffice --headless --invisible --nodefault --nolockcheck --nologo --norestore ' .
            '-env:UserInstallation=file://%s --convert-to pdf --outdir %s %s 2>&1',
            escapeshellarg($cacheDir),
            $configDir,
            escapeshellarg($outputDir),
            escapeshellarg($testFile)
        );

        // Execute conversion
        exec($command, $output, $returnCode);

        $pdfFile = storage_path('app/libreoffice/temp/test.pdf');

        if ($returnCode === 0 && file_exists($pdfFile)) {
            $this->line('  ✓ Test conversion successful!');
            // Clean up test files
            @unlink($testFile);
            @unlink($pdfFile);
        } else {
            $this->warn('  ⚠ Test conversion failed');
            $this->warn('  Output: ' . implode("\n  ", $output));
            @unlink($testFile);
        }
    }

    /**
     * Check installation status
     */
    protected function checkInstallation(): int
    {
        $this->info('Checking LibreOffice installation status...');
        $this->info('');

        $status = Command::SUCCESS;

        // Check LibreOffice binary
        $process = new Process(['which', 'libreoffice']);
        $process->run();

        if ($process->isSuccessful()) {
            $this->info('✓ LibreOffice: Installed at ' . trim($process->getOutput()));
        } else {
            $this->error('✗ LibreOffice: Not installed');
            $status = Command::FAILURE;
        }

        // Check directories
        $directories = [
            'LibreOffice cache' => storage_path('app/libreoffice/cache'),
            'LibreOffice config' => storage_path('app/libreoffice/config'),
            'LibreOffice temp' => storage_path('app/libreoffice/temp'),
            'Conversions' => storage_path('app/conversions'),
        ];

        $this->info('');
        $this->info('Directories:');

        foreach ($directories as $name => $path) {
            if (is_dir($path)) {
                $this->info("✓ $name: " . (is_writable($path) ? 'Exists and writable' : 'Exists but not writable'));
                if (! is_writable($path)) {
                    $status = Command::FAILURE;
                }
            } else {
                $this->error("✗ $name: Does not exist");
                $status = Command::FAILURE;
            }
        }

        // Check permissions
        $this->info('');
        $this->info('Permissions:');

        $libreofficeDir = storage_path('app/libreoffice');
        if (is_dir($libreofficeDir)) {
            $stat = stat($libreofficeDir);
            $perms = substr(sprintf('%o', $stat['mode']), -4);
            $owner = function_exists('posix_getpwuid') ? posix_getpwuid($stat['uid'])['name'] : 'unknown';
            $group = function_exists('posix_getgrgid') ? posix_getgrgid($stat['gid'])['name'] : 'unknown';

            $this->info("LibreOffice directory: $perms (owner: $owner:$group)");

            if ($owner !== 'www-data') {
                $this->warn("⚠ Directory should be owned by www-data for web server access");
            }
        }

        return $status;
    }

    /**
     * Clean a directory
     */
    protected function cleanDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->cleanDirectory($file);
                @rmdir($file);
            } else {
                @unlink($file);
            }
        }
    }
}
