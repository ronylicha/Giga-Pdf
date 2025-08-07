<?php

namespace App\Jobs;

use App\Models\Conversion;
use App\Models\Document;
use App\Services\ConversionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Exception;

class ProcessConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $conversion;
    
    /**
     * Create a new job instance.
     */
    public function __construct(Conversion $conversion)
    {
        $this->conversion = $conversion;
    }

    /**
     * Execute the job.
     */
    public function handle(ConversionService $conversionService): void
    {
        try {
            // Update status to processing
            $this->conversion->update([
                'status' => 'processing',
                'started_at' => now(),
            ]);
            
            // Get source document
            $sourceDocument = $this->conversion->document;
            $sourcePath = Storage::path($sourceDocument->stored_name);
            
            // Perform conversion
            $outputPath = $conversionService->convert(
                $sourcePath,
                $this->conversion->from_format,
                $this->conversion->to_format,
                $this->conversion->options ?? []
            );
            
            // Create result document
            $resultDocument = Document::create([
                'tenant_id' => $this->conversion->tenant_id,
                'user_id' => $this->conversion->user_id,
                'parent_id' => $sourceDocument->id,
                'original_name' => pathinfo($sourceDocument->original_name, PATHINFO_FILENAME) . '.' . $this->conversion->to_format,
                'stored_name' => 'conversions/' . $this->conversion->tenant_id . '/' . basename($outputPath),
                'mime_type' => $this->getMimeType($this->conversion->to_format),
                'size' => filesize($outputPath),
                'extension' => $this->conversion->to_format,
                'metadata' => [
                    'converted_from' => $sourceDocument->id,
                    'conversion_id' => $this->conversion->id,
                    'converted_at' => now()->toIso8601String(),
                ],
            ]);
            
            // Move converted file to proper location
            Storage::put($resultDocument->stored_name, file_get_contents($outputPath));
            unlink($outputPath); // Remove temp file
            
            // Update conversion record
            $this->conversion->update([
                'status' => 'completed',
                'result_document_id' => $resultDocument->id,
                'completed_at' => now(),
                'progress' => 100,
            ]);
            
        } catch (Exception $e) {
            $this->conversion->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Get MIME type for format
     */
    protected function getMimeType(string $format): string
    {
        return match($format) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/msword',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'ppt' => 'application/vnd.ms-powerpoint',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'html' => 'text/html',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            default => 'application/octet-stream',
        };
    }
}