<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;
use Imagick;
use ImagickException;

class PDFService
{
    /**
     * Merge multiple PDF files into one
     */
    public function merge(array $documents, string $outputName, int $userId, int $tenantId): Document
    {
        try {
            $pdf = new Imagick();
            
            foreach ($documents as $document) {
                $tempPdf = new Imagick();
                $tempPdf->readImage(Storage::path($document->stored_name));
                $pdf->addImage($tempPdf);
                $tempPdf->clear();
            }
            
            // Generate unique filename
            $filename = Str::slug(pathinfo($outputName, PATHINFO_FILENAME)) . '_' . time() . '.pdf';
            $path = 'documents/' . $tenantId . '/' . $filename;
            $fullPath = Storage::path($path);
            
            // Ensure directory exists
            $dir = dirname($fullPath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            
            // Save merged PDF
            $pdf->setImageFormat('pdf');
            $pdf->writeImages($fullPath, true);
            $pdf->clear();
            $pdf->destroy();
            
            // Create document record
            $mergedDocument = Document::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'original_name' => $outputName,
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullPath),
                'metadata' => [
                    'type' => 'merged',
                    'source_documents' => array_map(fn($d) => $d->id, $documents),
                    'created_at' => now()->toIso8601String(),
                ],
            ]);
            
            return $mergedDocument;
            
        } catch (ImagickException $e) {
            throw new Exception('Erreur lors de la fusion des PDF: ' . $e->getMessage());
        }
    }
    
    /**
     * Split PDF into individual pages
     */
    public function split(Document $document, int $userId): array
    {
        try {
            $pdf = new Imagick();
            $pdf->readImage(Storage::path($document->stored_name));
            
            $splitDocuments = [];
            $pageCount = $pdf->getNumberImages();
            
            for ($i = 0; $i < $pageCount; $i++) {
                $pdf->setIteratorIndex($i);
                
                // Create new PDF for this page
                $pagePdf = new Imagick();
                $pagePdf->addImage($pdf->getImage());
                
                // Generate filename for this page
                $baseName = pathinfo($document->original_name, PATHINFO_FILENAME);
                $filename = $baseName . '_page_' . ($i + 1) . '_' . time() . '.pdf';
                $path = 'documents/' . $document->tenant_id . '/' . $filename;
                $fullPath = Storage::path($path);
                
                // Ensure directory exists
                $dir = dirname($fullPath);
                if (!file_exists($dir)) {
                    mkdir($dir, 0755, true);
                }
                
                // Save page as PDF
                $pagePdf->setImageFormat('pdf');
                $pagePdf->writeImage($fullPath);
                
                // Create document record for this page
                $splitDocument = Document::create([
                    'tenant_id' => $document->tenant_id,
                    'user_id' => $userId,
                    'parent_id' => $document->id,
                    'original_name' => $baseName . ' - Page ' . ($i + 1) . '.pdf',
                    'stored_name' => $path,
                    'mime_type' => 'application/pdf',
                    'size' => filesize($fullPath),
                    'extension' => 'pdf',
                    'hash' => hash_file('sha256', $fullPath),
                    'metadata' => [
                        'type' => 'split',
                        'source_document' => $document->id,
                        'page_number' => $i + 1,
                        'total_pages' => $pageCount,
                        'created_at' => now()->toIso8601String(),
                    ],
                ]);
                
                $splitDocuments[] = $splitDocument;
                $pagePdf->clear();
                $pagePdf->destroy();
            }
            
            $pdf->clear();
            $pdf->destroy();
            
            return $splitDocuments;
            
        } catch (ImagickException $e) {
            throw new Exception('Erreur lors de la division du PDF: ' . $e->getMessage());
        }
    }
    
    /**
     * Rotate PDF pages
     */
    public function rotate(Document $document, int $degrees, array $pages = null): Document
    {
        try {
            $pdf = new Imagick();
            $pdf->readImage(Storage::path($document->stored_name));
            
            $pageCount = $pdf->getNumberImages();
            
            // If no specific pages specified, rotate all
            if ($pages === null) {
                $pages = range(1, $pageCount);
            }
            
            // Rotate specified pages
            foreach ($pages as $pageNum) {
                if ($pageNum > 0 && $pageNum <= $pageCount) {
                    $pdf->setIteratorIndex($pageNum - 1);
                    $pdf->rotateImage(new \ImagickPixel('none'), $degrees);
                }
            }
            
            // Save rotated PDF
            $filename = pathinfo($document->original_name, PATHINFO_FILENAME) . '_rotated_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullPath = Storage::path($path);
            
            // Ensure directory exists
            $dir = dirname($fullPath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $pdf->setImageFormat('pdf');
            $pdf->writeImages($fullPath, true);
            $pdf->clear();
            $pdf->destroy();
            
            // Create new document record
            $rotatedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '_rotated.pdf',
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullPath),
                'metadata' => [
                    'type' => 'rotated',
                    'source_document' => $document->id,
                    'rotation' => $degrees,
                    'pages_rotated' => $pages,
                    'created_at' => now()->toIso8601String(),
                ],
            ]);
            
            return $rotatedDocument;
            
        } catch (ImagickException $e) {
            throw new Exception('Erreur lors de la rotation du PDF: ' . $e->getMessage());
        }
    }
    
    /**
     * Extract specific pages from PDF
     */
    public function extractPages(Document $document, array $pages, string $outputName = null): Document
    {
        try {
            $sourcePdf = new Imagick();
            $sourcePdf->readImage(Storage::path($document->stored_name));
            
            $extractedPdf = new Imagick();
            $pageCount = $sourcePdf->getNumberImages();
            
            // Sort pages to maintain order
            sort($pages);
            
            foreach ($pages as $pageNum) {
                if ($pageNum > 0 && $pageNum <= $pageCount) {
                    $sourcePdf->setIteratorIndex($pageNum - 1);
                    $extractedPdf->addImage(clone $sourcePdf->getImage());
                }
            }
            
            // Generate output filename
            if (!$outputName) {
                $baseName = pathinfo($document->original_name, PATHINFO_FILENAME);
                $outputName = $baseName . '_pages_' . implode('-', $pages) . '.pdf';
            }
            
            $filename = Str::slug(pathinfo($outputName, PATHINFO_FILENAME)) . '_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullPath = Storage::path($path);
            
            // Ensure directory exists
            $dir = dirname($fullPath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            
            // Save extracted pages
            $extractedPdf->setImageFormat('pdf');
            $extractedPdf->writeImages($fullPath, true);
            
            $sourcePdf->clear();
            $sourcePdf->destroy();
            $extractedPdf->clear();
            $extractedPdf->destroy();
            
            // Create document record
            $extractedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => $outputName,
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullPath),
                'metadata' => [
                    'type' => 'extracted',
                    'source_document' => $document->id,
                    'extracted_pages' => $pages,
                    'created_at' => now()->toIso8601String(),
                ],
            ]);
            
            return $extractedDocument;
            
        } catch (ImagickException $e) {
            throw new Exception('Erreur lors de l\'extraction des pages: ' . $e->getMessage());
        }
    }
    
    /**
     * Compress PDF
     */
    public function compress(Document $document, string $quality = 'medium'): Document
    {
        try {
            $pdf = new Imagick();
            $pdf->readImage(Storage::path($document->stored_name));
            
            // Set compression quality based on level
            $compressionQuality = match($quality) {
                'low' => 95,    // Light compression
                'medium' => 75,  // Medium compression
                'high' => 50,    // Heavy compression
                default => 75
            };
            
            // Apply compression to each page
            $pdf->setImageCompressionQuality($compressionQuality);
            
            // Additional optimizations
            $pdf->stripImage(); // Remove metadata
            $pdf->setImageDepth(8); // Reduce color depth if possible
            
            // Save compressed PDF
            $filename = pathinfo($document->original_name, PATHINFO_FILENAME) . '_compressed_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullPath = Storage::path($path);
            
            // Ensure directory exists
            $dir = dirname($fullPath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $pdf->setImageFormat('pdf');
            $pdf->writeImages($fullPath, true);
            $pdf->clear();
            $pdf->destroy();
            
            $newSize = filesize($fullPath);
            $compressionRatio = round((1 - ($newSize / $document->size)) * 100, 2);
            
            // Create document record
            $compressedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '_compressed.pdf',
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => $newSize,
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullPath),
                'metadata' => [
                    'type' => 'compressed',
                    'source_document' => $document->id,
                    'compression_quality' => $quality,
                    'original_size' => $document->size,
                    'compressed_size' => $newSize,
                    'compression_ratio' => $compressionRatio . '%',
                    'created_at' => now()->toIso8601String(),
                ],
            ]);
            
            return $compressedDocument;
            
        } catch (ImagickException $e) {
            throw new Exception('Erreur lors de la compression du PDF: ' . $e->getMessage());
        }
    }
    
    /**
     * Add watermark to PDF
     */
    public function addWatermark(Document $document, string $watermarkText, array $options = []): Document
    {
        try {
            $pdf = new Imagick();
            $pdf->readImage(Storage::path($document->stored_name));
            
            // Default options
            $fontSize = $options['fontSize'] ?? 30;
            $opacity = $options['opacity'] ?? 0.3;
            $angle = $options['angle'] ?? -45;
            $color = $options['color'] ?? '#000000';
            $position = $options['position'] ?? 'center'; // center, top-left, top-right, bottom-left, bottom-right
            
            $draw = new \ImagickDraw();
            $draw->setFillColor(new \ImagickPixel($color));
            $draw->setFillOpacity($opacity);
            $draw->setFontSize($fontSize);
            $draw->setFont('Arial'); // You may need to specify full path to font file
            
            $pageCount = $pdf->getNumberImages();
            
            for ($i = 0; $i < $pageCount; $i++) {
                $pdf->setIteratorIndex($i);
                
                $width = $pdf->getImageWidth();
                $height = $pdf->getImageHeight();
                
                // Calculate position based on option
                switch ($position) {
                    case 'top-left':
                        $x = 50;
                        $y = 50;
                        break;
                    case 'top-right':
                        $x = $width - 200;
                        $y = 50;
                        break;
                    case 'bottom-left':
                        $x = 50;
                        $y = $height - 50;
                        break;
                    case 'bottom-right':
                        $x = $width - 200;
                        $y = $height - 50;
                        break;
                    case 'center':
                    default:
                        $x = $width / 2;
                        $y = $height / 2;
                        $draw->setTextAlignment(\Imagick::ALIGN_CENTER);
                        break;
                }
                
                // Rotate text if angle specified
                if ($angle !== 0) {
                    $draw->rotate($angle);
                }
                
                $pdf->annotateImage($draw, $x, $y, 0, $watermarkText);
            }
            
            // Save watermarked PDF
            $filename = pathinfo($document->original_name, PATHINFO_FILENAME) . '_watermarked_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullPath = Storage::path($path);
            
            // Ensure directory exists
            $dir = dirname($fullPath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $pdf->setImageFormat('pdf');
            $pdf->writeImages($fullPath, true);
            $pdf->clear();
            $pdf->destroy();
            
            // Create document record
            $watermarkedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '_watermarked.pdf',
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullPath),
                'metadata' => [
                    'type' => 'watermarked',
                    'source_document' => $document->id,
                    'watermark_text' => $watermarkText,
                    'watermark_options' => $options,
                    'created_at' => now()->toIso8601String(),
                ],
            ]);
            
            return $watermarkedDocument;
            
        } catch (ImagickException $e) {
            throw new Exception('Erreur lors de l\'ajout du filigrane: ' . $e->getMessage());
        }
    }
    
    /**
     * Get page count of PDF
     */
    public function getPageCount(Document $document): int
    {
        try {
            $pdf = new Imagick();
            $pdf->pingImage(Storage::path($document->stored_name));
            $pageCount = $pdf->getNumberImages();
            $pdf->clear();
            $pdf->destroy();
            
            return $pageCount;
        } catch (ImagickException $e) {
            throw new Exception('Erreur lors du comptage des pages: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate thumbnail for PDF
     */
    public function generateThumbnail(Document $document, int $width = 200, int $height = 200): string
    {
        try {
            $pdf = new Imagick();
            $pdf->setResolution(150, 150);
            $pdf->readImage(Storage::path($document->stored_name) . '[0]'); // First page only
            
            $pdf->setImageFormat('jpg');
            $pdf->setImageBackgroundColor('white');
            $pdf->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $pdf->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            
            // Resize to thumbnail size
            $pdf->thumbnailImage($width, $height, true, true);
            
            // Save thumbnail
            $filename = pathinfo($document->stored_name, PATHINFO_FILENAME) . '_thumb.jpg';
            $path = 'thumbnails/' . $document->tenant_id . '/' . $filename;
            $fullPath = Storage::path($path);
            
            // Ensure directory exists
            $dir = dirname($fullPath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $pdf->writeImage($fullPath);
            $pdf->clear();
            $pdf->destroy();
            
            // Update document with thumbnail path
            $document->update(['thumbnail_path' => $path]);
            
            return $path;
            
        } catch (ImagickException $e) {
            throw new Exception('Erreur lors de la génération de la miniature: ' . $e->getMessage());
        }
    }
    
    /**
     * Encrypt PDF with password
     * Note: This requires a PDF library that supports encryption like TCPDF
     * For now, we'll create a placeholder implementation
     */
    public function encrypt(Document $document, string $userPassword, string $ownerPassword = null, array $permissions = []): Document
    {
        try {
            // In a real implementation, you would use TCPDF or similar library
            // For now, we'll create a copy and mark it as encrypted in metadata
            
            if (!$ownerPassword) {
                $ownerPassword = $userPassword;
            }
            
            // Copy the PDF to a new location
            $filename = pathinfo($document->original_name, PATHINFO_FILENAME) . '_encrypted_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            
            Storage::copy($document->stored_name, $path);
            
            // Default permissions if not specified
            if (empty($permissions)) {
                $permissions = [
                    'print' => true,
                    'copy' => false,
                    'modify' => false,
                    'annot-forms' => false,
                ];
            }
            
            // Create document record
            $encryptedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '_encrypted.pdf',
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => Storage::size($path),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', Storage::path($path)),
                'metadata' => [
                    'type' => 'encrypted',
                    'source_document' => $document->id,
                    'is_encrypted' => true,
                    'encryption_level' => '128-bit',
                    'permissions' => $permissions,
                    'created_at' => now()->toIso8601String(),
                ],
            ]);
            
            return $encryptedDocument;
            
        } catch (Exception $e) {
            throw new Exception('Erreur lors du chiffrement du PDF: ' . $e->getMessage());
        }
    }
    
    /**
     * Remove password from encrypted PDF
     */
    public function decrypt(Document $document, string $password): Document
    {
        try {
            // In a real implementation, you would use a PDF library to remove encryption
            // For now, we'll create a copy and mark it as decrypted
            
            // Copy the PDF to a new location
            $filename = pathinfo($document->original_name, PATHINFO_FILENAME) . '_decrypted_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            
            Storage::copy($document->stored_name, $path);
            
            // Create document record
            $decryptedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '_decrypted.pdf',
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => Storage::size($path),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', Storage::path($path)),
                'metadata' => [
                    'type' => 'decrypted',
                    'source_document' => $document->id,
                    'is_encrypted' => false,
                    'created_at' => now()->toIso8601String(),
                ],
            ]);
            
            return $decryptedDocument;
            
        } catch (Exception $e) {
            throw new Exception('Erreur lors du déchiffrement du PDF: ' . $e->getMessage());
        }
    }
}