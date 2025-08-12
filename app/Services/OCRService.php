<?php

namespace App\Services;

use App\Models\Document;
use Exception;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OCRService
{
    protected string $tesseractPath;
    protected array $languages;

    public function __construct()
    {
        $this->tesseractPath = config('services.ocr.tesseract_path', '/usr/bin/tesseract');
        $this->languages = config('services.ocr.languages', ['eng', 'fra']);
    }

    /**
     * Perform OCR on a document
     */
    public function processDocument(Document $document, string $language = 'eng'): Document
    {
        try {
            // Check if tesseract is available
            if (! $this->isTesseractAvailable()) {
                throw new Exception('Tesseract OCR n\'est pas installé ou configuré.');
            }

            $inputPath = Storage::path($document->stored_name);
            $outputBasePath = storage_path('app/temp/ocr_' . Str::random(16));

            // Create temp directory
            $tempDir = dirname($outputBasePath);
            if (! file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            // For PDFs, we need to convert to images first
            if ($document->mime_type === 'application/pdf') {
                $extractedText = $this->ocrPdf($inputPath, $language);
            } elseif (str_starts_with($document->mime_type, 'image/')) {
                $extractedText = $this->ocrImage($inputPath, $language);
            } else {
                throw new Exception('Format de fichier non supporté pour l\'OCR.');
            }

            // Create searchable PDF with text layer
            $outputPdfPath = $this->createSearchablePdf($inputPath, $extractedText, $document);

            // Create new document record for OCR version
            $ocrDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '_OCR.pdf',
                'stored_name' => $outputPdfPath,
                'mime_type' => 'application/pdf',
                'size' => filesize(Storage::path($outputPdfPath)),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', Storage::path($outputPdfPath)),
                'search_content' => $extractedText, // Store extracted text for search
                'metadata' => [
                    'type' => 'ocr',
                    'source_document' => $document->id,
                    'ocr_language' => $language,
                    'text_extracted' => strlen($extractedText) > 0,
                    'created_at' => now()->toIso8601String(),
                ],
            ]);

            // Clean up temp files
            $this->cleanupTempFiles($outputBasePath);

            return $ocrDocument;

        } catch (Exception $e) {
            throw new Exception('Erreur OCR: ' . $e->getMessage());
        }
    }

    /**
     * OCR a PDF file
     */
    protected function ocrPdf(string $pdfPath, string $language): string
    {
        try {
            // Convert PDF to images using Imagick
            $imagick = new \Imagick();
            $imagick->setResolution(300, 300);
            $imagick->readImage($pdfPath);

            $extractedText = '';
            $pageCount = $imagick->getNumberImages();

            for ($i = 0; $i < $pageCount; $i++) {
                $imagick->setIteratorIndex($i);

                // Save page as temporary image
                $tempImagePath = storage_path('app/temp/page_' . $i . '_' . Str::random(8) . '.png');
                $imagick->setImageFormat('png');
                $imagick->writeImage($tempImagePath);

                // Perform OCR on the image
                $pageText = $this->ocrImage($tempImagePath, $language);
                $extractedText .= "--- Page " . ($i + 1) . " ---\n";
                $extractedText .= $pageText . "\n\n";

                // Clean up temp image
                unlink($tempImagePath);
            }

            $imagick->clear();
            $imagick->destroy();

            return $extractedText;

        } catch (\ImagickException $e) {
            throw new Exception('Erreur lors de la conversion PDF en images: ' . $e->getMessage());
        }
    }

    /**
     * OCR a single image
     */
    protected function ocrImage(string $imagePath, string $language): string
    {
        try {
            // Prepare output path for text
            $outputPath = storage_path('app/temp/ocr_output_' . Str::random(16));

            // Build tesseract command
            $command = sprintf(
                '%s "%s" "%s" -l %s',
                $this->tesseractPath,
                $imagePath,
                $outputPath,
                $language
            );

            // Execute tesseract
            $result = Process::run($command);

            if (! $result->successful()) {
                throw new Exception('Tesseract a échoué: ' . $result->errorOutput());
            }

            // Read the extracted text
            $textFile = $outputPath . '.txt';
            if (! file_exists($textFile)) {
                throw new Exception('Fichier de sortie OCR non trouvé.');
            }

            $extractedText = file_get_contents($textFile);

            // Clean up
            unlink($textFile);

            return $extractedText;

        } catch (Exception $e) {
            throw new Exception('Erreur OCR sur image: ' . $e->getMessage());
        }
    }

    /**
     * Create a searchable PDF with text layer
     */
    protected function createSearchablePdf(string $originalPath, string $extractedText, Document $document): string
    {
        // For now, we'll just save the extracted text in metadata
        // In a real implementation, you'd use a library like TCPDF to create a proper searchable PDF

        // Copy original file to new location
        $newFilename = pathinfo($document->stored_name, PATHINFO_FILENAME) . '_ocr.pdf';
        $newPath = 'documents/' . $document->tenant_id . '/' . $newFilename;

        Storage::copy($document->stored_name, $newPath);

        return $newPath;
    }

    /**
     * Check if Tesseract is available
     */
    protected function isTesseractAvailable(): bool
    {
        $result = Process::run($this->tesseractPath . ' --version');

        return $result->successful();
    }

    /**
     * Get available OCR languages
     */
    public function getAvailableLanguages(): array
    {
        try {
            $result = Process::run($this->tesseractPath . ' --list-langs');

            if (! $result->successful()) {
                return ['eng']; // Default to English
            }

            $output = $result->output();
            $lines = explode("\n", $output);

            // Skip header line and filter languages
            $languages = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (! empty($line) && ! str_contains($line, 'List of available languages')) {
                    $languages[] = $line;
                }
            }

            return $languages;

        } catch (Exception $e) {
            return ['eng']; // Default to English
        }
    }

    /**
     * Clean up temporary files
     */
    protected function cleanupTempFiles(string $basePath): void
    {
        // Remove any files matching the pattern
        $files = glob($basePath . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Extract text from document for indexing
     */
    public function extractTextForIndexing(Document $document): string
    {
        try {
            if ($document->mime_type === 'application/pdf') {
                // Try to extract text directly from PDF first
                $text = $this->extractTextFromPdf(Storage::path($document->stored_name));

                // If no text found, perform OCR
                if (empty(trim($text))) {
                    $text = $this->ocrPdf(Storage::path($document->stored_name), 'eng');
                }

                return $text;
            } elseif (str_starts_with($document->mime_type, 'image/')) {
                // Perform OCR on images
                return $this->ocrImage(Storage::path($document->stored_name), 'eng');
            } elseif (str_starts_with($document->mime_type, 'text/')) {
                // Direct text files
                return file_get_contents(Storage::path($document->stored_name));
            }

            return '';

        } catch (Exception $e) {
            \Log::error('Text extraction failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Extract text from PDF without OCR
     */
    protected function extractTextFromPdf(string $pdfPath): string
    {
        try {
            // Use pdftotext if available
            $result = Process::run('pdftotext -layout "' . $pdfPath . '" -');

            if ($result->successful()) {
                return $result->output();
            }

            return '';

        } catch (Exception $e) {
            return '';
        }
    }
}
