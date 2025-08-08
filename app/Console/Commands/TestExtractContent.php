<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class TestExtractContent extends Command
{
    protected $signature = 'test:extract-content {document_id} {user_id=1}';
    protected $description = 'Test the extract content functionality';

    public function handle()
    {
        $documentId = $this->argument('document_id');
        $userId = $this->argument('user_id');
        
        $document = Document::find($documentId);
        $user = User::find($userId);
        
        if (!$document || !$user) {
            $this->error("Document or user not found");
            return 1;
        }
        
        // Login as user
        Auth::login($user);
        
        $this->info("Testing extract content for document: {$document->original_name}");
        
        try {
            // Get controller instance
            $controller = app(\App\Http\Controllers\DocumentController::class);
            
            // Call extractContent
            $response = $controller->extractContent($document);
            $data = json_decode($response->getContent(), true);
            
            if ($data['success'] ?? false) {
                $this->info("Success! Extracted " . count($data['content']) . " text elements");
                
                // Show first few elements
                foreach (array_slice($data['content'], 0, 3) as $index => $element) {
                    $this->info("Element {$index}: " . json_encode($element));
                }
            } else {
                $this->error("Failed: " . ($data['error'] ?? 'Unknown error'));
            }
            
        } catch (\Exception $e) {
            $this->error("Exception: " . $e->getMessage());
            $this->error("Trace: " . $e->getTraceAsString());
        }
        
        return 0;
    }
}