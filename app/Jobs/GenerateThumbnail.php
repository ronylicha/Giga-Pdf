<?php

namespace App\Jobs;

use App\Models\Document;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Imagick;

class GenerateThumbnail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected $document;

    /**
     * Create a new job instance.
     */
    public function __construct(Document $document)
    {
        $this->document = $document;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $sourcePath = Storage::path($this->document->stored_name);
            $thumbnailPath = 'thumbnails/' . $this->document->tenant_id . '/' . $this->document->id . '.jpg';

            // Generate thumbnail based on file type
            if ($this->document->mime_type === 'application/pdf') {
                $this->generatePdfThumbnail($sourcePath, $thumbnailPath);
            } elseif (str_starts_with($this->document->mime_type, 'image/')) {
                $this->generateImageThumbnail($sourcePath, $thumbnailPath);
            } else {
                // For other file types, create a generic thumbnail
                return;
            }

            // Update document with thumbnail path
            $this->document->update([
                'thumbnail_path' => $thumbnailPath,
            ]);

        } catch (Exception $e) {
            \Log::error('Failed to generate thumbnail for document ' . $this->document->id, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate thumbnail for PDF
     */
    protected function generatePdfThumbnail(string $sourcePath, string $thumbnailPath): void
    {
        try {
            $imagick = new Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImage($sourcePath . '[0]'); // Read first page
            $imagick->setImageFormat('jpg');
            $imagick->thumbnailImage(300, 400, true);
            $imagick->setImageCompressionQuality(85);

            $thumbnailContent = $imagick->getImageBlob();
            Storage::put($thumbnailPath, $thumbnailContent);

            $imagick->destroy();
        } catch (Exception $e) {
            throw new Exception('Failed to generate PDF thumbnail: ' . $e->getMessage());
        }
    }

    /**
     * Generate thumbnail for image
     */
    protected function generateImageThumbnail(string $sourcePath, string $thumbnailPath): void
    {
        try {
            $imagick = new Imagick($sourcePath);
            $imagick->thumbnailImage(300, 400, true);
            $imagick->setImageFormat('jpg');
            $imagick->setImageCompressionQuality(85);

            $thumbnailContent = $imagick->getImageBlob();
            Storage::put($thumbnailPath, $thumbnailContent);

            $imagick->destroy();
        } catch (Exception $e) {
            throw new Exception('Failed to generate image thumbnail: ' . $e->getMessage());
        }
    }
}
