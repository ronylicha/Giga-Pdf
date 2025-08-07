<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\ConversionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestConversion extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'conversion:test {document_id} {format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test PDF to Word conversion';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $documentId = $this->argument('document_id');
        $format = $this->argument('format');
        
        $this->info("Testing conversion of document {$documentId} to {$format}");
        
        try {
            $document = Document::findOrFail($documentId);
            $this->info("Found document: " . $document->original_name);
            
            $inputPath = Storage::path($document->stored_name);
            $this->info("Input path: " . $inputPath);
            
            if (!file_exists($inputPath)) {
                $this->error("Input file does not exist!");
                return 1;
            }
            
            $this->info("Input file exists, size: " . filesize($inputPath) . " bytes");
            
            $conversionService = new ConversionService();
            
            $this->info("Starting conversion...");
            $this->info("From format: " . $document->extension);
            $this->info("To format: " . $format);
            
            // Test if we can extract text first
            $this->info("Testing text extraction...");
            $command = sprintf('pdftotext %s /tmp/test_text.txt 2>&1', escapeshellarg($inputPath));
            exec($command, $textOutput, $textReturnCode);
            $this->info("pdftotext return code: " . $textReturnCode);
            $this->info("pdftotext output: " . implode("\n", $textOutput));
            
            if (file_exists('/tmp/test_text.txt')) {
                $textContent = file_get_contents('/tmp/test_text.txt');
                $this->info("Extracted text length: " . strlen($textContent));
                $this->info("First 200 chars: " . substr($textContent, 0, 200));
                unlink('/tmp/test_text.txt');
            }
            
            $outputPath = $conversionService->convert(
                $inputPath,
                $document->extension,
                $format,
                []
            );
            
            $this->info("Conversion completed!");
            $this->info("Output file: " . $outputPath);
            $this->info("Output size: " . filesize($outputPath) . " bytes");
            
            // Clean up
            if (file_exists($outputPath)) {
                unlink($outputPath);
                $this->info("Cleaned up output file");
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("Conversion failed: " . $e->getMessage());
            $this->error("Stack trace:");
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}
