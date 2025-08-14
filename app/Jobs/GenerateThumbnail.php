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
            // Store thumbnails in public disk for web access
            $thumbnailPath = 'thumbnails/' . $this->document->tenant_id . '/' . $this->document->id . '.jpg';

            // Create directory if it doesn't exist
            $thumbnailDir = dirname(Storage::disk('public')->path($thumbnailPath));
            if (!file_exists($thumbnailDir)) {
                mkdir($thumbnailDir, 0755, true);
            }

            // Generate thumbnail based on file type
            if ($this->document->mime_type === 'application/pdf') {
                $this->generatePdfThumbnail($sourcePath, $thumbnailPath);
            } elseif (str_starts_with($this->document->mime_type, 'image/')) {
                $this->generateImageThumbnail($sourcePath, $thumbnailPath);
            } else {
                // For other file types, create a generic thumbnail
                \Log::info('Skipping thumbnail generation for non-PDF/image file: ' . $this->document->mime_type);
                return;
            }

            // Update document with thumbnail path
            $this->document->update([
                'thumbnail_path' => $thumbnailPath,
            ]);

            \Log::info('Thumbnail generated successfully for document ' . $this->document->id);

        } catch (Exception $e) {
            \Log::error('Failed to generate thumbnail for document ' . $this->document->id, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Generate thumbnail for PDF
     */
    protected function generatePdfThumbnail(string $sourcePath, string $thumbnailPath): void
    {
        try {
            // Check if source file exists
            if (!file_exists($sourcePath)) {
                throw new Exception('Source file not found: ' . $sourcePath);
            }

            $imagick = new Imagick();
            // Set resolution before reading the image
            $imagick->setResolution(150, 150);
            // Read only the first page of the PDF
            $imagick->readImage($sourcePath . '[0]');
            // Set background color to white for PDFs with transparency
            $imagick->setImageBackgroundColor('white');
            $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            // Merge layers
            $imagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            // Convert to JPEG
            $imagick->setImageFormat('jpeg');
            // Create thumbnail with aspect ratio preservation
            $imagick->thumbnailImage(300, 400, true, false);
            // Set compression quality
            $imagick->setImageCompressionQuality(85);

            // Save thumbnail to public disk
            $thumbnailContent = $imagick->getImageBlob();
            Storage::disk('public')->put($thumbnailPath, $thumbnailContent);

            $imagick->clear();
            $imagick->destroy();

            \Log::info('PDF thumbnail generated: ' . $thumbnailPath);
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
            // Check if source file exists
            if (!file_exists($sourcePath)) {
                throw new Exception('Source file not found: ' . $sourcePath);
            }

            $imagick = new Imagick($sourcePath);
            // Handle orientation for photos
            $imagick->autoOrient();
            // Set background color to white for images with transparency
            $imagick->setImageBackgroundColor('white');
            $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            // Merge layers if needed
            $imagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            // Create thumbnail with aspect ratio preservation
            $imagick->thumbnailImage(300, 400, true, false);
            // Convert to JPEG
            $imagick->setImageFormat('jpeg');
            // Set compression quality
            $imagick->setImageCompressionQuality(85);

            // Save thumbnail to public disk
            $thumbnailContent = $imagick->getImageBlob();
            Storage::disk('public')->put($thumbnailPath, $thumbnailContent);

            $imagick->clear();
            $imagick->destroy();

            \Log::info('Image thumbnail generated: ' . $thumbnailPath);
        } catch (Exception $e) {
            throw new Exception('Failed to generate image thumbnail: ' . $e->getMessage());
        }
    }
}
