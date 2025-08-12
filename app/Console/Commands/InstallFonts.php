<?php

namespace App\Console\Commands;

use App\Services\FontManager;
use Illuminate\Console\Command;

class InstallFonts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pdf:install-fonts {--all : Install all common fonts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install common fonts for PDF editing';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Installing common fonts for PDF editing...');

        $fontManager = new FontManager();

        if ($this->option('all')) {
            $this->info('Installing all common fonts...');
            $installed = $fontManager->installCommonFonts();

            if (count($installed) > 0) {
                $this->info('Successfully installed fonts: ' . implode(', ', $installed));
            } else {
                $this->warn('No fonts were installed. They may already exist.');
            }
        }

        // List available fonts
        $this->info("\nAvailable fonts:");
        $fonts = $fontManager->getAvailableFonts();
        foreach ($fonts as $font) {
            $this->line("  - {$font}");
        }

        return Command::SUCCESS;
    }
}
