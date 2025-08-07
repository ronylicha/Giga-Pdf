<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Conversion;
use App\Jobs\ProcessConversion;
use Illuminate\Console\Command;

class QueueTestConversion extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'conversion:queue-test {document_id} {format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test conversion via queue system';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $documentId = $this->argument('document_id');
        $format = $this->argument('format');
        
        $this->info("Creating conversion job for document {$documentId} to {$format}");
        
        try {
            $document = Document::findOrFail($documentId);
            $this->info("Found document: " . $document->original_name);
            
            // Create conversion record
            $conversion = Conversion::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'document_id' => $document->id,
                'from_format' => $document->extension,
                'to_format' => $format,
                'status' => 'pending',
                'options' => [],
            ]);
            
            $this->info("Created conversion record with ID: " . $conversion->id);
            
            // Dispatch the job
            ProcessConversion::dispatch($conversion);
            
            $this->info("Conversion job dispatched to queue!");
            $this->info("Monitor the conversion with: php artisan tinker --execute=\"App\\Models\\Conversion::find({$conversion->id})\"");
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("Failed to create conversion job: " . $e->getMessage());
            return 1;
        }
    }
}
