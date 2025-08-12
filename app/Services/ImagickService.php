<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Imagick;
use ImagickException;

class ImagickService
{
    /**
     * Convert image to PDF
     */
    public function imageToPDF(string $inputPath, string $outputPath): bool
    {
        if (! file_exists($inputPath)) {
            throw new Exception("Input file not found: {$inputPath}");
        }

        try {
            $imagick = new Imagick($inputPath);

            // Set PDF format
            $imagick->setImageFormat('pdf');

            // Set compression
            $imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
            $imagick->setImageCompressionQuality(95);

            // Write PDF
            $imagick->writeImage($outputPath);

            // Clean up
            $imagick->clear();
            $imagick->destroy();

            return true;

        } catch (ImagickException $e) {
            Log::error('Imagick conversion to PDF failed', [
                'input' => $inputPath,
                'output' => $outputPath,
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Image to PDF conversion failed: ' . $e->getMessage());
        }
    }

    /**
     * Convert multiple images to single PDF
     */
    public function imagesToPDF(array $inputPaths, string $outputPath): bool
    {
        if (empty($inputPaths)) {
            throw new Exception("No input files provided");
        }

        try {
            $pdf = new Imagick();

            foreach ($inputPaths as $inputPath) {
                if (! file_exists($inputPath)) {
                    Log::warning("Image file not found, skipping: {$inputPath}");

                    continue;
                }

                $image = new Imagick($inputPath);
                $image->setImageFormat('pdf');
                $pdf->addImage($image);
                $image->clear();
                $image->destroy();
            }

            if ($pdf->getNumberImages() === 0) {
                throw new Exception("No valid images to convert");
            }

            // Write combined PDF
            $pdf->writeImages($outputPath, true);

            // Clean up
            $pdf->clear();
            $pdf->destroy();

            return true;

        } catch (ImagickException $e) {
            Log::error('Imagick multi-image to PDF conversion failed', [
                'inputs' => $inputPaths,
                'output' => $outputPath,
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Images to PDF conversion failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate thumbnail from document
     */
    public function generateThumbnail(string $inputPath, string $outputPath, int $width = 200, int $height = 200): bool
    {
        if (! file_exists($inputPath)) {
            throw new Exception("Input file not found: {$inputPath}");
        }

        try {
            $imagick = new Imagick();

            // For PDFs, set resolution before reading
            if (strtolower(pathinfo($inputPath, PATHINFO_EXTENSION)) === 'pdf') {
                $imagick->setResolution(150, 150);
                $imagick->readImage($inputPath . '[0]'); // Read first page only
            } else {
                $imagick->readImage($inputPath);
            }

            // Convert to RGB if necessary
            if ($imagick->getImageColorspace() !== Imagick::COLORSPACE_RGB) {
                $imagick->transformImageColorspace(Imagick::COLORSPACE_RGB);
            }

            // Resize to fit within bounds while maintaining aspect ratio
            $imagick->thumbnailImage($width, $height, true);

            // Set format to JPEG for smaller file size
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
            $imagick->setImageCompressionQuality(85);

            // Add white background for transparent images
            if ($imagick->getImageAlphaChannel()) {
                $imagick->setImageBackgroundColor('white');
                $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $imagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }

            // Write thumbnail
            $imagick->writeImage($outputPath);

            // Clean up
            $imagick->clear();
            $imagick->destroy();

            return true;

        } catch (ImagickException $e) {
            Log::error('Thumbnail generation failed', [
                'input' => $inputPath,
                'output' => $outputPath,
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Thumbnail generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Extract pages from PDF as images
     */
    public function extractPDFPages(string $inputPath, string $outputDir, string $format = 'jpeg', int $dpi = 150): array
    {
        if (! file_exists($inputPath)) {
            throw new Exception("Input file not found: {$inputPath}");
        }

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        try {
            $pdf = new Imagick();
            $pdf->setResolution($dpi, $dpi);
            $pdf->readImage($inputPath);

            $pages = [];
            $pageCount = $pdf->getNumberImages();

            for ($i = 0; $i < $pageCount; $i++) {
                $pdf->setIteratorIndex($i);

                // Set format
                $pdf->setImageFormat($format);
                $pdf->setImageCompression(Imagick::COMPRESSION_JPEG);
                $pdf->setImageCompressionQuality(95);

                // Generate filename
                $filename = sprintf('page_%03d.%s', $i + 1, $format);
                $outputPath = $outputDir . '/' . $filename;

                // Write page
                $pdf->writeImage($outputPath);

                $pages[] = [
                    'page' => $i + 1,
                    'path' => $outputPath,
                    'filename' => $filename,
                ];
            }

            // Clean up
            $pdf->clear();
            $pdf->destroy();

            return $pages;

        } catch (ImagickException $e) {
            Log::error('PDF page extraction failed', [
                'input' => $inputPath,
                'output_dir' => $outputDir,
                'error' => $e->getMessage(),
            ]);

            throw new Exception('PDF page extraction failed: ' . $e->getMessage());
        }
    }

    /**
     * Rotate image or PDF page
     */
    public function rotate(string $inputPath, string $outputPath, float $degrees): bool
    {
        if (! file_exists($inputPath)) {
            throw new Exception("Input file not found: {$inputPath}");
        }

        try {
            $imagick = new Imagick($inputPath);

            // Rotate
            $imagick->rotateImage(new \ImagickPixel('white'), $degrees);

            // Write rotated image
            $imagick->writeImage($outputPath);

            // Clean up
            $imagick->clear();
            $imagick->destroy();

            return true;

        } catch (ImagickException $e) {
            Log::error('Image rotation failed', [
                'input' => $inputPath,
                'output' => $outputPath,
                'degrees' => $degrees,
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Image rotation failed: ' . $e->getMessage());
        }
    }

    /**
     * Compress image
     */
    public function compress(string $inputPath, string $outputPath, int $quality = 85): bool
    {
        if (! file_exists($inputPath)) {
            throw new Exception("Input file not found: {$inputPath}");
        }

        try {
            $imagick = new Imagick($inputPath);

            // Set compression quality
            $imagick->setImageCompressionQuality($quality);

            // Strip metadata to reduce size
            $imagick->stripImage();

            // Write compressed image
            $imagick->writeImage($outputPath);

            // Clean up
            $imagick->clear();
            $imagick->destroy();

            return true;

        } catch (ImagickException $e) {
            Log::error('Image compression failed', [
                'input' => $inputPath,
                'output' => $outputPath,
                'quality' => $quality,
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Image compression failed: ' . $e->getMessage());
        }
    }

    /**
     * Add watermark to image or PDF
     */
    public function addWatermark(string $inputPath, string $watermarkPath, string $outputPath, array $options = []): bool
    {
        if (! file_exists($inputPath)) {
            throw new Exception("Input file not found: {$inputPath}");
        }

        if (! file_exists($watermarkPath)) {
            throw new Exception("Watermark file not found: {$watermarkPath}");
        }

        try {
            $image = new Imagick($inputPath);
            $watermark = new Imagick($watermarkPath);

            // Set opacity
            $opacity = $options['opacity'] ?? 0.3;
            $watermark->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity, Imagick::CHANNEL_ALPHA);

            // Calculate position
            $position = $options['position'] ?? 'center';
            $x = 0;
            $y = 0;

            switch ($position) {
                case 'top-left':
                    $x = 10;
                    $y = 10;

                    break;
                case 'top-right':
                    $x = $image->getImageWidth() - $watermark->getImageWidth() - 10;
                    $y = 10;

                    break;
                case 'bottom-left':
                    $x = 10;
                    $y = $image->getImageHeight() - $watermark->getImageHeight() - 10;

                    break;
                case 'bottom-right':
                    $x = $image->getImageWidth() - $watermark->getImageWidth() - 10;
                    $y = $image->getImageHeight() - $watermark->getImageHeight() - 10;

                    break;
                case 'center':
                default:
                    $x = ($image->getImageWidth() - $watermark->getImageWidth()) / 2;
                    $y = ($image->getImageHeight() - $watermark->getImageHeight()) / 2;

                    break;
            }

            // Apply watermark
            $image->compositeImage($watermark, Imagick::COMPOSITE_OVER, $x, $y);

            // Write result
            $image->writeImage($outputPath);

            // Clean up
            $image->clear();
            $image->destroy();
            $watermark->clear();
            $watermark->destroy();

            return true;

        } catch (ImagickException $e) {
            Log::error('Watermark application failed', [
                'input' => $inputPath,
                'watermark' => $watermarkPath,
                'output' => $outputPath,
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Watermark application failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if Imagick is available
     */
    public function isAvailable(): bool
    {
        return extension_loaded('imagick');
    }

    /**
     * Get supported formats
     */
    public function getSupportedFormats(): array
    {
        try {
            $imagick = new Imagick();

            return $imagick->queryFormats();
        } catch (Exception $e) {
            return [];
        }
    }
}
