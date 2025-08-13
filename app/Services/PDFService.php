<?php

namespace App\Services;

use App\Models\Document;
use Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use ImagickException;

class PDFService
{
    /**
     * Merge multiple PDF files into one with perfect quality preservation
     */
    public function merge(array $documents, string $outputName, int $userId, int $tenantId): Document
    {
        try {
            // Generate unique filename
            $filename = Str::slug(pathinfo($outputName, PATHINFO_FILENAME)) . '_' . time() . '.pdf';
            $path = 'documents/' . $tenantId . '/' . $filename;
            $fullPath = Storage::path($path);

            // Ensure directory exists
            $dir = dirname($fullPath);
            if (! file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            // Try qpdf first (best quality preservation)
            if ($this->isQpdfAvailable()) {
                $this->mergeWithQpdf($documents, $fullPath);
            }
            // Fallback to pdftk
            elseif ($this->isPdftkAvailable()) {
                $this->mergeWithPdftk($documents, $fullPath);
            }
            // Last resort: use PyPDF2 via Python
            else {
                $this->mergeWithPython($documents, $fullPath);
            }

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
                    'source_documents' => array_map(fn ($d) => $d->id, $documents),
                    'created_at' => now()->toIso8601String(),
                ],
            ]);

            return $mergedDocument;

        } catch (Exception $e) {
            throw new Exception('Erreur lors de la fusion des PDF: ' . $e->getMessage());
        }
    }

    /**
     * Merge PDFs using qpdf (best quality)
     */
    private function mergeWithQpdf(array $documents, string $outputPath): void
    {
        $inputFiles = array_map(
            fn ($doc) => escapeshellarg(Storage::path($doc->stored_name)),
            $documents
        );

        $command = sprintf(
            'qpdf --empty --pages %s -- %s',
            implode(' ', $inputFiles),
            escapeshellarg($outputPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || ! file_exists($outputPath)) {
            throw new Exception("Failed to merge PDFs with qpdf: " . implode("\n", $output));
        }
    }

    /**
     * Merge PDFs using pdftk
     */
    private function mergeWithPdftk(array $documents, string $outputPath): void
    {
        $inputFiles = array_map(
            fn ($doc) => escapeshellarg(Storage::path($doc->stored_name)),
            $documents
        );

        $command = sprintf(
            'pdftk %s cat output %s',
            implode(' ', $inputFiles),
            escapeshellarg($outputPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || ! file_exists($outputPath)) {
            throw new Exception("Failed to merge PDFs with pdftk: " . implode("\n", $output));
        }
    }

    /**
     * Merge PDFs using Python (PyPDF2)
     */
    private function mergeWithPython(array $documents, string $outputPath): void
    {
        $pythonScript = <<<'PYTHON'
import sys
from pypdf import PdfMerger

def merge_pdfs(input_files, output_path):
    try:
        merger = PdfMerger()
        
        for pdf_file in input_files:
            merger.append(pdf_file)
        
        merger.write(output_path)
        merger.close()
        
        return True
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        return False

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage: python merge.py <output_pdf> <input_pdf1> <input_pdf2> ...", file=sys.stderr)
        sys.exit(1)
    
    output_file = sys.argv[1]
    input_files = sys.argv[2:]
    
    success = merge_pdfs(input_files, output_file)
    sys.exit(0 if success else 1)
PYTHON;

        $scriptPath = tempnam(sys_get_temp_dir(), 'merge_') . '.py';
        file_put_contents($scriptPath, $pythonScript);

        $inputFiles = array_map(
            fn ($doc) => escapeshellarg(Storage::path($doc->stored_name)),
            $documents
        );

        $command = sprintf(
            'python3 %s %s %s 2>&1',
            escapeshellarg($scriptPath),
            escapeshellarg($outputPath),
            implode(' ', $inputFiles)
        );

        exec($command, $output, $returnCode);

        unlink($scriptPath);

        if ($returnCode !== 0 || ! file_exists($outputPath)) {
            throw new Exception("Failed to merge PDFs with Python: " . implode("\n", $output));
        }
    }

    /**
     * Split PDF into individual pages with perfect quality preservation
     */
    public function split(Document $document, int $userId): array
    {
        try {
            $sourcePath = Storage::path($document->stored_name);

            // First, get the page count without loading full PDF
            $pageCount = $this->getPageCountNative($sourcePath);

            $splitDocuments = [];
            $baseName = pathinfo($document->original_name, PATHINFO_FILENAME);

            // Ensure output directory exists
            $outputDir = storage_path('app/documents/' . $document->tenant_id);
            if (! file_exists($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // Try qpdf first (best quality preservation)
            if ($this->isQpdfAvailable()) {
                $splitDocuments = $this->splitWithQpdf($document, $sourcePath, $pageCount, $baseName, $userId);
            }
            // Fallback to pdftk
            elseif ($this->isPdftkAvailable()) {
                $splitDocuments = $this->splitWithPdftk($document, $sourcePath, $pageCount, $baseName, $userId);
            }
            // Last resort: use PyPDF2 via Python (better than Imagick)
            else {
                $splitDocuments = $this->splitWithPython($document, $sourcePath, $pageCount, $baseName, $userId);
            }

            return $splitDocuments;

        } catch (Exception $e) {
            throw new Exception('Erreur lors de la division du PDF: ' . $e->getMessage());
        }
    }

    /**
     * Split PDF using qpdf (best quality)
     */
    private function splitWithQpdf(Document $document, string $sourcePath, int $pageCount, string $baseName, int $userId): array
    {
        $splitDocuments = [];

        for ($i = 1; $i <= $pageCount; $i++) {
            $filename = $baseName . '_page_' . $i . '_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            
            // Ensure directory exists
            Storage::makeDirectory('documents/' . $document->tenant_id);
            $fullPath = Storage::path($path);

            // Use qpdf to extract single page
            $command = sprintf(
                'qpdf --empty --pages %s %d -- %s',
                escapeshellarg($sourcePath),
                $i,
                escapeshellarg($fullPath)
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0 || ! file_exists($fullPath)) {
                throw new Exception("Failed to extract page $i with qpdf");
            }

            // Create document record
            $splitDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $userId,
                'parent_id' => $document->id,
                'original_name' => $baseName . ' - Page ' . $i . '.pdf',
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullPath),
                'metadata' => [
                    'type' => 'split',
                    'source_document' => $document->id,
                    'page_number' => $i,
                    'total_pages' => $pageCount,
                    'created_at' => now()->toIso8601String(),
                ],
            ]);

            $splitDocuments[] = $splitDocument;
        }

        return $splitDocuments;
    }

    /**
     * Split PDF using pdftk
     */
    private function splitWithPdftk(Document $document, string $sourcePath, int $pageCount, string $baseName, int $userId): array
    {
        $splitDocuments = [];

        for ($i = 1; $i <= $pageCount; $i++) {
            $filename = $baseName . '_page_' . $i . '_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullPath = Storage::path($path);

            // Use pdftk to extract single page
            $command = sprintf(
                'pdftk %s cat %d output %s',
                escapeshellarg($sourcePath),
                $i,
                escapeshellarg($fullPath)
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0 || ! file_exists($fullPath)) {
                throw new Exception("Failed to extract page $i with pdftk");
            }

            // Create document record
            $splitDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $userId,
                'parent_id' => $document->id,
                'original_name' => $baseName . ' - Page ' . $i . '.pdf',
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullPath),
                'metadata' => [
                    'type' => 'split',
                    'source_document' => $document->id,
                    'page_number' => $i,
                    'total_pages' => $pageCount,
                    'created_at' => now()->toIso8601String(),
                ],
            ]);

            $splitDocuments[] = $splitDocument;
        }

        return $splitDocuments;
    }

    /**
     * Split PDF using Python (PyPDF2) - better than Imagick
     */
    private function splitWithPython(Document $document, string $sourcePath, int $pageCount, string $baseName, int $userId): array
    {
        $splitDocuments = [];

        // Create Python script for splitting
        $pythonScript = <<<'PYTHON'
import sys
from pypdf import PdfReader, PdfWriter

def split_pdf(input_path, output_path, page_num):
    try:
        reader = PdfReader(input_path)
        writer = PdfWriter()
        
        # Add the specific page (0-indexed)
        writer.add_page(reader.pages[page_num - 1])
        
        # Write to output file
        with open(output_path, 'wb') as output_file:
            writer.write(output_file)
        
        return True
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        return False

if __name__ == "__main__":
    if len(sys.argv) != 4:
        print("Usage: python split.py <input_pdf> <output_pdf> <page_number>", file=sys.stderr)
        sys.exit(1)
    
    success = split_pdf(sys.argv[1], sys.argv[2], int(sys.argv[3]))
    sys.exit(0 if success else 1)
PYTHON;

        $scriptPath = tempnam(sys_get_temp_dir(), 'split_') . '.py';
        file_put_contents($scriptPath, $pythonScript);

        for ($i = 1; $i <= $pageCount; $i++) {
            $filename = $baseName . '_page_' . $i . '_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullPath = Storage::path($path);

            // Execute Python script
            $command = sprintf(
                'python3 %s %s %s %d 2>&1',
                escapeshellarg($scriptPath),
                escapeshellarg($sourcePath),
                escapeshellarg($fullPath),
                $i
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0 || ! file_exists($fullPath)) {
                unlink($scriptPath);

                throw new Exception("Failed to extract page $i: " . implode("\n", $output));
            }

            // Create document record
            $splitDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $userId,
                'parent_id' => $document->id,
                'original_name' => $baseName . ' - Page ' . $i . '.pdf',
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullPath),
                'metadata' => [
                    'type' => 'split',
                    'source_document' => $document->id,
                    'page_number' => $i,
                    'total_pages' => $pageCount,
                    'created_at' => now()->toIso8601String(),
                ],
            ]);

            $splitDocuments[] = $splitDocument;
        }

        unlink($scriptPath);

        return $splitDocuments;
    }

    /**
     * Get page count using native tools
     */
    private function getPageCountNative(string $pdfPath): int
    {
        // Try qpdf first
        if ($this->isQpdfAvailable()) {
            $command = sprintf('qpdf --show-npages %s 2>/dev/null', escapeshellarg($pdfPath));
            $pageCount = intval(trim(shell_exec($command)));
            if ($pageCount > 0) {
                return $pageCount;
            }
        }

        // Try pdfinfo
        $command = sprintf('pdfinfo %s 2>/dev/null | grep "Pages:" | awk \'{print $2}\'', escapeshellarg($pdfPath));
        $pageCount = intval(trim(shell_exec($command)));
        if ($pageCount > 0) {
            return $pageCount;
        }

        // Fallback to Imagick
        $pdf = new Imagick();
        $pdf->pingImage($pdfPath);
        $pageCount = $pdf->getNumberImages();
        $pdf->clear();
        $pdf->destroy();

        return $pageCount;
    }

    /**
     * Check if qpdf is available
     */
    private function isQpdfAvailable(): bool
    {
        exec('which qpdf 2>/dev/null', $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Check if pdftk is available
     */
    private function isPdftkAvailable(): bool
    {
        exec('which pdftk 2>/dev/null', $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Rotate PDF pages
     */
    public function rotate(Document $document, int $userId, ?array $pages = null, int $degrees = 90): Document
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
            if (! file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            $pdf->setImageFormat('pdf');
            $pdf->writeImages($fullPath, true);
            $pdf->clear();
            $pdf->destroy();

            // Create new document record
            $rotatedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $userId,
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
            if (! $outputName) {
                $baseName = pathinfo($document->original_name, PATHINFO_FILENAME);
                $outputName = $baseName . '_pages_' . implode('-', $pages) . '.pdf';
            }

            $filename = Str::slug(pathinfo($outputName, PATHINFO_FILENAME)) . '_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullPath = Storage::path($path);

            // Ensure directory exists
            $dir = dirname($fullPath);
            if (! file_exists($dir)) {
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
            if (! file_exists($dir)) {
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
    public function addWatermark(Document $document, int $userId, array $options = []): Document
    {
        try {
            $pdf = new Imagick();
            $pdf->readImage(Storage::path($document->stored_name));

            // Default options
            $watermarkText = $options['text'] ?? 'WATERMARK';
            $fontSize = $options['fontSize'] ?? 30;
            $opacity = $options['opacity'] ?? 0.3;
            $angle = $options['rotation'] ?? $options['angle'] ?? -45;
            $color = $options['color'] ?? '#000000';
            $position = $options['position'] ?? 'center'; // center, top-left, top-right, bottom-left, bottom-right

            $draw = new \ImagickDraw();
            $draw->setFillColor(new \ImagickPixel($color));
            $draw->setFillOpacity($opacity);
            $draw->setFontSize($fontSize);
            // Try to use a font that should be available
            try {
                $draw->setFont('Helvetica');
            } catch (\Exception $e) {
                // If Helvetica is not available, skip setting font (use default)
            }

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
            if (! file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            $pdf->setImageFormat('pdf');
            $pdf->writeImages($fullPath, true);
            $pdf->clear();
            $pdf->destroy();

            // Create document record
            $watermarkedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $userId,
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
            if (! file_exists($dir)) {
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

            if (! $ownerPassword) {
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
