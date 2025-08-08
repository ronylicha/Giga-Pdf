<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\SimplePDFEditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestPDFEditor extends Command
{
    protected $signature = 'test:pdf-editor {document_id}';
    protected $description = 'Test PDF editor functionality';

    public function handle()
    {
        $documentId = $this->argument('document_id');
        $document = Document::find($documentId);
        
        if (!$document) {
            $this->error("Document not found: {$documentId}");
            return 1;
        }
        
        $this->info("Testing PDF editor with document: {$document->original_name}");
        
        try {
            $editor = new SimplePDFEditor();
            $path = Storage::path($document->stored_name);
            
            if (!file_exists($path)) {
                $this->error("File not found: {$path}");
                return 1;
            }
            
            $this->info("File path: {$path}");
            $this->info("File exists: " . (file_exists($path) ? 'Yes' : 'No'));
            $this->info("File size: " . filesize($path) . " bytes");
            
            // Test simple modification
            $modifications = [
                [
                    'type' => 'add',
                    'page' => 1,
                    'x' => 100,
                    'y' => 100,
                    'text' => 'Test Text',
                    'fontSize' => 12,
                    'color' => '#000000'
                ]
            ];
            
            $this->info("Applying modification...");
            $outputPath = $editor->addTextOverlay($path, $modifications);
            
            if (file_exists($outputPath)) {
                $this->info("Success! Modified PDF saved to: {$outputPath}");
                $this->info("Modified file size: " . filesize($outputPath) . " bytes");
                
                // Clean up
                unlink($outputPath);
                $this->info("Temp file cleaned up.");
            } else {
                $this->error("Failed to create modified PDF");
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->error("Trace: " . $e->getTraceAsString());
            return 1;
        }
        
        return 0;
    }
}