<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\ImagickService;
use App\Services\StorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class GenerateDocumentThumbnail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $document;
    public $tries = 3;
    public $timeout = 120;
    
    /**
     * Create a new job instance.
     */
    public function __construct(Document $document)
    {
        $this->document = $document;
        $this->onQueue('low');
    }
    
    /**
     * Execute the job.
     */
    public function handle(ImagickService $imagickService, StorageService $storageService)
    {
        try {
            // Skip if thumbnail already exists
            if ($this->document->thumbnail_path && $storageService->getSize($this->document)) {
                return;
            }
            
            // Get document path
            $documentPath = $storageService->getPath($this->document);
            
            // Generate thumbnail path
            $thumbnailPath = $storageService->getTempPath('jpg');
            
            // Generate thumbnail based on document type
            if ($this->document->isPdf() || $this->document->isImage()) {
                $imagickService->generateThumbnail($documentPath, $thumbnailPath, 300, 300);
            } else {
                // For other documents, use a default icon based on type
                $this->generateDefaultThumbnail($thumbnailPath);
            }
            
            // Store thumbnail
            if (file_exists($thumbnailPath)) {
                $tenant = $this->document->tenant;
                $storagePath = sprintf(
                    'tenants/%s/thumbnails/%s.jpg',
                    $tenant->slug,
                    $this->document->id
                );
                
                \Storage::disk('local')->put($storagePath, file_get_contents($thumbnailPath));
                
                // Update document
                $this->document->update([
                    'thumbnail_path' => $storagePath,
                ]);
                
                // Clean up temp file
                @unlink($thumbnailPath);
                
                Log::info('Thumbnail generated successfully', [
                    'document_id' => $this->document->id,
                    'path' => $storagePath,
                ]);
            }
            
        } catch (Exception $e) {
            Log::error('Failed to generate thumbnail', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Don't retry for certain errors
            if ($e->getMessage() === 'File not found') {
                $this->fail($e);
            }
            
            throw $e;
        }
    }
    
    /**
     * Generate default thumbnail based on file type
     */
    protected function generateDefaultThumbnail(string $outputPath): void
    {
        // Map mime types to icon files
        $iconMap = [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word.png',
            'application/msword' => 'word.png',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'excel.png',
            'application/vnd.ms-excel' => 'excel.png',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'powerpoint.png',
            'application/vnd.ms-powerpoint' => 'powerpoint.png',
            'text/plain' => 'text.png',
            'text/html' => 'html.png',
            'application/zip' => 'zip.png',
            'default' => 'file.png',
        ];
        
        $iconFile = $iconMap[$this->document->mime_type] ?? $iconMap['default'];
        $iconPath = public_path('images/file-icons/' . $iconFile);
        
        if (file_exists($iconPath)) {
            copy($iconPath, $outputPath);
        } else {
            // Create a simple placeholder
            $image = imagecreatetruecolor(300, 300);
            $bgColor = imagecolorallocate($image, 240, 240, 240);
            $textColor = imagecolorallocate($image, 100, 100, 100);
            
            imagefill($image, 0, 0, $bgColor);
            
            // Add file extension as text
            $extension = strtoupper($this->document->getExtension());
            $fontSize = 5;
            $textWidth = imagefontwidth($fontSize) * strlen($extension);
            $textHeight = imagefontheight($fontSize);
            $x = (300 - $textWidth) / 2;
            $y = (300 - $textHeight) / 2;
            
            imagestring($image, $fontSize, $x, $y, $extension, $textColor);
            
            imagejpeg($image, $outputPath, 90);
            imagedestroy($image);
        }
    }
    
    /**
     * Handle job failure
     */
    public function failed(Exception $exception)
    {
        Log::error('Thumbnail generation job failed permanently', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);
    }
}