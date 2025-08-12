<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\User;
use App\Services\SimplePDFEditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class TestFullModification extends Command
{
    protected $signature = 'test:full-modification {document_id} {user_id=1}';
    protected $description = 'Test full modification workflow';

    public function handle()
    {
        $documentId = $this->argument('document_id');
        $userId = $this->argument('user_id');

        $document = Document::find($documentId);
        $user = User::find($userId);

        if (! $document || ! $user) {
            $this->error("Document or user not found");

            return 1;
        }

        Auth::login($user);

        $this->info("Testing full modification for document: {$document->original_name}");

        try {
            // Step 1: Extract content
            $controller = app(\App\Http\Controllers\DocumentController::class);
            $response = $controller->extractContent($document);
            $data = json_decode($response->getContent(), true);

            if (! ($data['success'] ?? false) || empty($data['content'])) {
                $this->error("No content extracted");

                return 1;
            }

            $this->info("Extracted " . count($data['content']) . " text elements");

            // Step 2: Select first text element
            $firstElement = $data['content'][0];
            $this->info("First element: " . json_encode($firstElement));

            // Step 3: Test direct modification with SimplePDFEditor
            $this->info("\nTesting direct modification with SimplePDFEditor...");

            $editor = new SimplePDFEditor();
            $path = Storage::path($document->stored_name);

            $modification = [
                'type' => 'replace',
                'page' => $firstElement['page'],
                'x' => $firstElement['x'],
                'y' => $firstElement['y'],
                'width' => $firstElement['width'],
                'height' => $firstElement['height'],
                'oldText' => $firstElement['text'],
                'newText' => 'TEXTE MODIFIÉ',
                'text' => 'TEXTE MODIFIÉ',
                'fontSize' => 14,
                'color' => '#FF0000',
            ];

            $this->info("Applying modification: " . json_encode($modification));

            $outputPath = $editor->addTextOverlay($path, [$modification]);

            if (file_exists($outputPath)) {
                $this->info("Success! Modified PDF saved to: {$outputPath}");
                $this->info("Modified file size: " . filesize($outputPath) . " bytes");

                // Save to a test location
                $testPath = '/tmp/test_modified_' . time() . '.pdf';
                copy($outputPath, $testPath);
                $this->info("Test file saved to: {$testPath}");

                // Clean up
                unlink($outputPath);
            } else {
                $this->error("Failed to create modified PDF");
            }

        } catch (\Exception $e) {
            $this->error("Exception: " . $e->getMessage());
            $this->error("Trace: " . $e->getTraceAsString());

            return 1;
        }

        return 0;
    }
}
