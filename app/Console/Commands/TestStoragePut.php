<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestStoragePut extends Command
{
    protected $signature = 'test:storage-put';
    protected $description = 'Test Storage::put functionality';

    public function handle()
    {
        $testContent = "Test PDF content";
        $testPath = 'temp/1/test_' . time() . '.pdf';

        // Also test simpler path
        $simplePath = 'test_' . time() . '.txt';

        $this->info("Testing Storage::put with path: $testPath");

        // First test simple path
        $this->info("\n1. Testing simple path: $simplePath");
        $simpleResult = Storage::put($simplePath, $testContent);
        $this->info("Simple path result: " . ($simpleResult ? 'SUCCESS' : 'FAILED'));

        if ($simpleResult && Storage::exists($simplePath)) {
            $this->info("Simple file created at: " . Storage::path($simplePath));
            Storage::delete($simplePath);
        }

        $this->info("\n2. Testing nested path: $testPath");

        try {
            // Test Storage::put
            $result = Storage::put($testPath, $testContent);

            if ($result) {
                $this->info("Storage::put returned: true");

                // Check if file exists
                if (Storage::exists($testPath)) {
                    $this->info("File exists in storage");
                    $fullPath = Storage::path($testPath);
                    $this->info("Full path: $fullPath");

                    if (file_exists($fullPath)) {
                        $this->info("File exists on filesystem");
                        $this->info("File size: " . filesize($fullPath) . " bytes");
                    } else {
                        $this->error("File does NOT exist on filesystem");
                    }

                    // Clean up
                    Storage::delete($testPath);
                    $this->info("Test file deleted");
                } else {
                    $this->error("File does NOT exist in storage");
                }
            } else {
                $this->error("Storage::put returned: false");
            }

        } catch (\Exception $e) {
            $this->error("Exception: " . $e->getMessage());
        }

        return 0;
    }
}
