<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\RealPDFEditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestRealPDFEditor extends Command
{
    protected $signature = 'test:real-pdf-editor {document_id}';
    protected $description = 'Test RealPDFEditor service';

    public function handle()
    {
        $documentId = $this->argument('document_id');
        $document = Document::find($documentId);

        if (! $document) {
            $this->error("Document not found");

            return 1;
        }

        $this->info("Testing RealPDFEditor with document: {$document->original_name}");

        try {
            // Get content service to extract text
            $contentService = new \App\Services\PDFContentService();
            $path = Storage::path($document->stored_name);

            $this->info("Extracting text elements...");
            $textElements = $contentService->extractTextWithPositions($path);

            if (empty($textElements)) {
                $this->error("No text elements found");

                return 1;
            }

            $this->info("Found " . count($textElements) . " text elements");

            // Select first element to replace
            $firstElement = $textElements[0];
            $this->info("First element: " . json_encode($firstElement));

            // Create modification
            $modification = [
                'type' => 'replace',
                'page' => $firstElement['page'],
                'x' => $firstElement['x'],
                'y' => $firstElement['y'],
                'width' => $firstElement['width'],
                'height' => $firstElement['height'],
                'oldText' => $firstElement['text'],
                'newText' => 'TEXTE REMPLACÉ',
                'fontSize' => $firstElement['size'] ?? 12,
                'color' => '#FF0000',
            ];

            $this->info("\nApplying modification...");
            $this->info("Old text: " . $firstElement['text']);
            $this->info("New text: TEXTE REMPLACÉ");

            // Test RealPDFEditor
            $editor = new RealPDFEditor();
            $modifiedPath = $editor->replaceText($path, [$modification]);

            if (file_exists($modifiedPath)) {
                $this->info("Success! Modified PDF created");

                // Save to test location
                $testPath = '/tmp/real_pdf_test_' . time() . '.pdf';
                copy($modifiedPath, $testPath);
                $this->info("Test file saved to: {$testPath}");

                // Extract text from modified PDF to verify
                $this->info("\nVerifying modification...");
                exec("pdftotext {$testPath} - | head -20", $output);
                $this->info("First lines of modified PDF:");
                foreach (array_slice($output, 0, 5) as $line) {
                    $this->info("  " . $line);
                }

                // Clean up
                unlink($modifiedPath);
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
