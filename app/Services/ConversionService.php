<?php

namespace App\Services;

use App\Exceptions\ConversionFailedException;
use App\Exceptions\InvalidDocumentException;
use App\Models\Document;
use App\Models\Conversion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;

class ConversionService
{
    protected $storageService;
    protected $libreOfficeService;
    protected $imagickService;
    protected $tesseractService;
    
    public function __construct(
        StorageService $storageService,
        LibreOfficeService $libreOfficeService,
        ImagickService $imagickService,
        TesseractService $tesseractService
    ) {
        $this->storageService = $storageService;
        $this->libreOfficeService = $libreOfficeService;
        $this->imagickService = $imagickService;
        $this->tesseractService = $tesseractService;
    }
    
    /**
     * Convert document to PDF
     */
    public function convertToPDF(Document $document, array $options = []): Document
    {
        $inputPath = $this->storageService->getPath($document);
        $outputPath = $this->storageService->getTempPath('pdf');
        
        try {
            // Mark conversion as processing
            $conversion = $this->createConversion($document, 'pdf', $options);
            $conversion->markAsProcessing();
            
            // Perform conversion based on mime type
            switch ($document->mime_type) {
                // Office documents
                case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                case 'application/msword':
                case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                case 'application/vnd.ms-excel':
                case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
                case 'application/vnd.ms-powerpoint':
                case 'application/vnd.oasis.opendocument.text':
                case 'application/vnd.oasis.opendocument.spreadsheet':
                case 'application/vnd.oasis.opendocument.presentation':
                case 'application/rtf':
                    $this->libreOfficeService->convertToPDF($inputPath, $outputPath);
                    break;
                    
                // Images
                case 'image/jpeg':
                case 'image/jpg':
                case 'image/png':
                case 'image/gif':
                case 'image/bmp':
                case 'image/tiff':
                case 'image/webp':
                    $this->imagickService->imageToPDF($inputPath, $outputPath);
                    break;
                    
                // HTML
                case 'text/html':
                    $this->convertHTMLToPDF($inputPath, $outputPath, $options);
                    break;
                    
                // Text files
                case 'text/plain':
                    $this->convertTextToPDF($inputPath, $outputPath, $options);
                    break;
                    
                // Markdown
                case 'text/markdown':
                case 'text/x-markdown':
                    $this->convertMarkdownToPDF($inputPath, $outputPath, $options);
                    break;
                    
                default:
                    throw new ConversionFailedException(
                        "Unsupported format for PDF conversion: {$document->mime_type}"
                    );
            }
            
            // Verify output file exists
            if (!file_exists($outputPath)) {
                throw new ConversionFailedException("PDF creation failed");
            }
            
            // Optimize PDF if requested
            if ($options['optimize'] ?? true) {
                $this->optimizePDF($outputPath);
            }
            
            // Extract metadata
            $metadata = $this->extractPDFMetadata($outputPath);
            
            // Create new document
            $pdfDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '.pdf',
                'stored_name' => '',
                'mime_type' => 'application/pdf',
                'size' => filesize($outputPath),
                'hash' => hash_file('sha256', $outputPath),
                'metadata' => $metadata,
                'parent_id' => $document->id,
                'page_count' => $metadata['pages'] ?? null,
            ]);
            
            // Move to permanent storage
            $permanentPath = $this->storageService->store($outputPath, $pdfDocument);
            $pdfDocument->update(['stored_name' => $permanentPath]);
            
            // Mark conversion as completed
            $conversion->markAsCompleted($pdfDocument->id);
            
            // Queue thumbnail generation
            dispatch(new \App\Jobs\GenerateDocumentThumbnail($pdfDocument));
            
            // Queue content indexing
            dispatch(new \App\Jobs\IndexDocumentContent($pdfDocument));
            
            return $pdfDocument;
            
        } catch (Exception $e) {
            Log::error('PDF conversion failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            if (isset($conversion)) {
                $conversion->markAsFailed($e->getMessage());
            }
            
            throw new ConversionFailedException(
                "Failed to convert document to PDF: " . $e->getMessage(),
                0,
                $e
            );
        } finally {
            // Clean up temp file
            if (isset($outputPath) && file_exists($outputPath)) {
                @unlink($outputPath);
            }
        }
    }
    
    /**
     * Convert PDF to another format
     */
    public function convertFromPDF(Document $document, string $targetFormat, array $options = []): Document
    {
        if (!$document->isPdf()) {
            throw new InvalidDocumentException("Document is not a PDF");
        }
        
        $inputPath = $this->storageService->getPath($document);
        $extension = $this->getExtensionForFormat($targetFormat);
        $outputPath = $this->storageService->getTempPath($extension);
        
        try {
            // Create conversion record
            $conversion = $this->createConversion($document, $targetFormat, $options);
            $conversion->markAsProcessing();
            
            // Perform conversion based on target format
            switch ($targetFormat) {
                case 'docx':
                case 'doc':
                    $this->libreOfficeService->convertFromPDF($inputPath, $outputPath, 'docx');
                    $mimeType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                    break;
                    
                case 'xlsx':
                case 'xls':
                    $this->libreOfficeService->convertFromPDF($inputPath, $outputPath, 'xlsx');
                    $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                    break;
                    
                case 'pptx':
                case 'ppt':
                    $this->libreOfficeService->convertFromPDF($inputPath, $outputPath, 'pptx');
                    $mimeType = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
                    break;
                    
                case 'jpg':
                case 'jpeg':
                case 'png':
                case 'gif':
                    $this->convertPDFToImages($inputPath, $outputPath, $targetFormat, $options);
                    $mimeType = "image/{$targetFormat}";
                    break;
                    
                case 'html':
                    $this->convertPDFToHTML($inputPath, $outputPath, $options);
                    $mimeType = 'text/html';
                    break;
                    
                case 'txt':
                    $this->extractTextFromPDF($inputPath, $outputPath, $options);
                    $mimeType = 'text/plain';
                    break;
                    
                default:
                    throw new ConversionFailedException(
                        "Unsupported target format: {$targetFormat}"
                    );
            }
            
            // Create new document
            $convertedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '.' . $extension,
                'stored_name' => '',
                'mime_type' => $mimeType,
                'size' => filesize($outputPath),
                'hash' => hash_file('sha256', $outputPath),
                'parent_id' => $document->id,
            ]);
            
            // Move to permanent storage
            $permanentPath = $this->storageService->store($outputPath, $convertedDocument);
            $convertedDocument->update(['stored_name' => $permanentPath]);
            
            // Mark conversion as completed
            $conversion->markAsCompleted($convertedDocument->id);
            
            return $convertedDocument;
            
        } catch (Exception $e) {
            Log::error('Conversion from PDF failed', [
                'document_id' => $document->id,
                'target_format' => $targetFormat,
                'error' => $e->getMessage()
            ]);
            
            if (isset($conversion)) {
                $conversion->markAsFailed($e->getMessage());
            }
            
            throw $e;
        } finally {
            if (isset($outputPath) && file_exists($outputPath)) {
                @unlink($outputPath);
            }
        }
    }
    
    /**
     * Convert HTML to PDF
     */
    protected function convertHTMLToPDF(string $inputPath, string $outputPath, array $options = []): void
    {
        $html = file_get_contents($inputPath);
        
        // Use mPDF for HTML to PDF conversion
        $mpdf = new \Mpdf\Mpdf([
            'tempDir' => storage_path('app/temp'),
            'format' => $options['format'] ?? 'A4',
            'orientation' => $options['orientation'] ?? 'P',
            'margin_left' => $options['margin_left'] ?? 15,
            'margin_right' => $options['margin_right'] ?? 15,
            'margin_top' => $options['margin_top'] ?? 16,
            'margin_bottom' => $options['margin_bottom'] ?? 16,
        ]);
        
        $mpdf->WriteHTML($html);
        $mpdf->Output($outputPath, 'F');
    }
    
    /**
     * Convert text to PDF
     */
    protected function convertTextToPDF(string $inputPath, string $outputPath, array $options = []): void
    {
        $text = file_get_contents($inputPath);
        
        $mpdf = new \Mpdf\Mpdf([
            'tempDir' => storage_path('app/temp'),
            'format' => $options['format'] ?? 'A4',
            'orientation' => $options['orientation'] ?? 'P',
        ]);
        
        // Convert plain text to HTML
        $html = '<pre style="font-family: monospace; white-space: pre-wrap;">' . 
                htmlspecialchars($text) . 
                '</pre>';
        
        $mpdf->WriteHTML($html);
        $mpdf->Output($outputPath, 'F');
    }
    
    /**
     * Convert Markdown to PDF
     */
    protected function convertMarkdownToPDF(string $inputPath, string $outputPath, array $options = []): void
    {
        $markdown = file_get_contents($inputPath);
        
        // Parse markdown to HTML
        $parsedown = new \Parsedown();
        $html = $parsedown->text($markdown);
        
        // Add CSS for better formatting
        $styledHtml = '
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; }
                    h1, h2, h3 { color: #333; }
                    code { background: #f4f4f4; padding: 2px 4px; }
                    pre { background: #f4f4f4; padding: 10px; overflow-x: auto; }
                    blockquote { border-left: 3px solid #ccc; margin-left: 0; padding-left: 10px; }
                    table { border-collapse: collapse; width: 100%; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f2f2f2; }
                </style>
            </head>
            <body>' . $html . '</body>
            </html>
        ';
        
        $mpdf = new \Mpdf\Mpdf([
            'tempDir' => storage_path('app/temp'),
            'format' => $options['format'] ?? 'A4',
            'orientation' => $options['orientation'] ?? 'P',
        ]);
        
        $mpdf->WriteHTML($styledHtml);
        $mpdf->Output($outputPath, 'F');
    }
    
    /**
     * Convert PDF to images
     */
    protected function convertPDFToImages(string $inputPath, string $outputPath, string $format, array $options = []): void
    {
        $imagick = new \Imagick();
        $imagick->setResolution($options['dpi'] ?? 300, $options['dpi'] ?? 300);
        $imagick->readImage($inputPath);
        
        $images = [];
        foreach ($imagick as $pageNumber => $page) {
            $page->setImageFormat($format);
            $page->setImageCompressionQuality($options['quality'] ?? 95);
            
            if ($options['page'] ?? false) {
                // Single page requested
                if ($pageNumber == $options['page'] - 1) {
                    $page->writeImage($outputPath);
                    break;
                }
            } else {
                // All pages - create a ZIP
                $pageOutputPath = str_replace(
                    '.' . $format,
                    '_page_' . ($pageNumber + 1) . '.' . $format,
                    $outputPath
                );
                
                $page->writeImage($pageOutputPath);
                $images[] = $pageOutputPath;
            }
        }
        
        // If multiple pages, create ZIP
        if (count($images) > 1) {
            $zip = new \ZipArchive();
            $zipPath = str_replace('.' . $format, '.zip', $outputPath);
            
            if ($zip->open($zipPath, \ZipArchive::CREATE) === TRUE) {
                foreach ($images as $image) {
                    $zip->addFile($image, basename($image));
                }
                $zip->close();
                
                // Clean up individual images
                foreach ($images as $image) {
                    @unlink($image);
                }
                
                // Use ZIP as output
                rename($zipPath, $outputPath);
            }
        } elseif (count($images) == 1) {
            rename($images[0], $outputPath);
        }
        
        $imagick->clear();
        $imagick->destroy();
    }
    
    /**
     * Convert PDF to HTML
     */
    protected function convertPDFToHTML(string $inputPath, string $outputPath, array $options = []): void
    {
        // Use pdf2htmlEX or similar tool
        $command = sprintf(
            'pdf2htmlEX --zoom 1.3 --font-size-multiplier 1.0 --dest-dir %s %s',
            escapeshellarg(dirname($outputPath)),
            escapeshellarg($inputPath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new ConversionFailedException("PDF to HTML conversion failed");
        }
        
        // Rename output file
        $generatedFile = dirname($outputPath) . '/' . pathinfo($inputPath, PATHINFO_FILENAME) . '.html';
        if (file_exists($generatedFile)) {
            rename($generatedFile, $outputPath);
        }
    }
    
    /**
     * Extract text from PDF
     */
    protected function extractTextFromPDF(string $inputPath, string $outputPath, array $options = []): void
    {
        // Use pdftotext command
        $command = sprintf(
            'pdftotext %s %s',
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            // Fallback to PHP library
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($inputPath);
            $text = $pdf->getText();
            file_put_contents($outputPath, $text);
        }
    }
    
    /**
     * Optimize PDF
     */
    protected function optimizePDF(string $pdfPath): void
    {
        $optimizedPath = $pdfPath . '_optimized';
        
        $command = sprintf(
            'gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/printer ' .
            '-dNOPAUSE -dQUIET -dBATCH -sOutputFile=%s %s 2>&1',
            escapeshellarg($optimizedPath),
            escapeshellarg($pdfPath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($optimizedPath)) {
            // Replace with optimized version if smaller
            if (filesize($optimizedPath) < filesize($pdfPath)) {
                unlink($pdfPath);
                rename($optimizedPath, $pdfPath);
            } else {
                unlink($optimizedPath);
            }
        }
    }
    
    /**
     * Extract PDF metadata
     */
    protected function extractPDFMetadata(string $pdfPath): array
    {
        $metadata = [];
        
        try {
            // Use pdfinfo command
            $command = sprintf('pdfinfo %s 2>&1', escapeshellarg($pdfPath));
            exec($command, $output);
            
            foreach ($output as $line) {
                if (preg_match('/^([^:]+):\s+(.*)$/', $line, $matches)) {
                    $key = str_replace(' ', '_', strtolower($matches[1]));
                    $metadata[$key] = trim($matches[2]);
                }
            }
            
            // Extract page count
            if (isset($metadata['pages'])) {
                $metadata['pages'] = (int) $metadata['pages'];
            }
            
        } catch (Exception $e) {
            Log::warning('Failed to extract PDF metadata', [
                'file' => $pdfPath,
                'error' => $e->getMessage()
            ]);
        }
        
        return $metadata;
    }
    
    /**
     * Create conversion record
     */
    protected function createConversion(Document $document, string $toFormat, array $options = []): Conversion
    {
        $fromFormat = $this->getFormatFromMimeType($document->mime_type);
        
        return Conversion::create([
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->id,
            'user_id' => auth()->id(),
            'from_format' => $fromFormat,
            'to_format' => $toFormat,
            'status' => 'pending',
            'options' => $options,
        ]);
    }
    
    /**
     * Get format from mime type
     */
    protected function getFormatFromMimeType(string $mimeType): string
    {
        $map = [
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'text/html' => 'html',
            'text/plain' => 'txt',
            'text/markdown' => 'md',
        ];
        
        return $map[$mimeType] ?? 'unknown';
    }
    
    /**
     * Get extension for format
     */
    protected function getExtensionForFormat(string $format): string
    {
        return strtolower($format);
    }
}