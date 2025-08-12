<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class TestAPIModification extends Command
{
    protected $signature = 'test:api-modification {document_id} {user_id=1}';
    protected $description = 'Test the PDF modification API';

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

        // Login as user
        Auth::login($user);

        $this->info("Testing modification for document: {$document->original_name}");
        $this->info("User: {$user->name}");

        // Simulate controller call - use dependency injection
        $controller = app(\App\Http\Controllers\DocumentController::class);

        // Create request
        $request = new \Illuminate\Http\Request([
            'modification' => [
                'type' => 'add',
                'page' => 1,
                'x' => 100,
                'y' => 200,
                'newText' => 'Modified by API Test',
                'fontSize' => 14,
                'color' => '#FF0000',
            ],
            'temp_document_id' => null,
        ]);

        try {
            // Call the method
            $response = $controller->applyModification($request, $document);

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getContent(), true);
                $this->info("Success!");
                $this->info("Temp document ID: " . ($data['temp_document']['id'] ?? 'N/A'));
                $this->info("Serve URL: " . ($data['serve_url'] ?? 'N/A'));
            } else {
                $this->error("Failed with status: " . $response->getStatusCode());
                $this->error("Response: " . $response->getContent());
            }

        } catch (\Exception $e) {
            $this->error("Exception: " . $e->getMessage());
            $this->error("Trace: " . $e->getTraceAsString());
        }

        return 0;
    }
}
