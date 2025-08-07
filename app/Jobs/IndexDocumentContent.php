<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\TesseractService;
use App\Services\StorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;
use Exception;

class IndexDocumentContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $document;
    public $tries = 3;
    public $timeout = 300;
    
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
    public function handle(StorageService $storageService, TesseractService $tesseractService)
    {
        try {
            // Skip if already indexed
            if ($this->document->search_content && strlen($this->document->search_content) > 100) {
                return;
            }
            
            $documentPath = $storageService->getPath($this->document);
            $content = '';
            $metadata = $this->document->metadata ?? [];
            
            // Extract content based on document type
            switch ($this->document->mime_type) {
                case 'application/pdf':
                    $content = $this->extractPdfContent($documentPath);
                    
                    // If PDF has no text, try OCR
                    if (strlen(trim($content)) < 50 && $tesseractService->isAvailable()) {
                        Log::info('PDF has no text, attempting OCR', [
                            'document_id' => $this->document->id,
                        ]);
                        
                        try {
                            $ocrContent = $tesseractService->extractTextFromPDF($documentPath);
                            if (strlen($ocrContent) > strlen($content)) {
                                $content = $ocrContent;
                                $metadata['ocr_performed'] = true;
                            }
                        } catch (Exception $e) {
                            Log::warning('OCR failed for PDF', [
                                'document_id' => $this->document->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    break;
                    
                case 'text/plain':
                case 'text/html':
                case 'text/markdown':
                case 'text/csv':
                case 'application/json':
                case 'application/xml':
                    $content = file_get_contents($documentPath);
                    break;
                    
                case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                case 'application/msword':
                    $content = $this->extractDocxContent($documentPath);
                    break;
                    
                case 'image/jpeg':
                case 'image/jpg':
                case 'image/png':
                case 'image/gif':
                case 'image/bmp':
                case 'image/tiff':
                    // Use OCR for images if available
                    if ($tesseractService->isAvailable()) {
                        try {
                            $content = $tesseractService->extractText($documentPath);
                            $metadata['ocr_performed'] = true;
                        } catch (Exception $e) {
                            Log::warning('OCR failed for image', [
                                'document_id' => $this->document->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    break;
            }
            
            // Clean and truncate content
            $content = $this->cleanContent($content);
            
            // Extract additional metadata
            $metadata['indexed_at'] = now()->toIso8601String();
            $metadata['content_length'] = strlen($content);
            $metadata['word_count'] = str_word_count($content);
            
            // Update document
            $this->document->update([
                'search_content' => $content,
                'metadata' => array_merge($this->document->metadata ?? [], $metadata),
            ]);
            
            Log::info('Document content indexed successfully', [
                'document_id' => $this->document->id,
                'content_length' => strlen($content),
                'word_count' => $metadata['word_count'],
            ]);
            
        } catch (Exception $e) {
            Log::error('Failed to index document content', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Extract content from PDF
     */
    protected function extractPdfContent(string $path): string
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($path);
            
            $text = $pdf->getText();
            
            // Also extract metadata
            $details = $pdf->getDetails();
            
            if (!empty($details)) {
                $metadata = $this->document->metadata ?? [];
                
                foreach ($details as $key => $value) {
                    if (is_string($value) || is_numeric($value)) {
                        $metadata['pdf_' . strtolower($key)] = $value;
                    }
                }
                
                $this->document->update(['metadata' => $metadata]);
            }
            
            return $text;
            
        } catch (Exception $e) {
            Log::warning('PDF text extraction failed, trying alternative method', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
            ]);
            
            // Try with pdftotext command as fallback
            $outputFile = tempnam(sys_get_temp_dir(), 'pdf_text_');
            $command = sprintf(
                'pdftotext %s %s 2>&1',
                escapeshellarg($path),
                escapeshellarg($outputFile)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($outputFile)) {
                $text = file_get_contents($outputFile);
                @unlink($outputFile);
                return $text;
            }
            
            return '';
        }
    }
    
    /**
     * Extract content from DOCX
     */
    protected function extractDocxContent(string $path): string
    {
        try {
            $zip = new \ZipArchive();
            
            if ($zip->open($path) === true) {
                $content = '';
                
                // Read main document content
                if (($index = $zip->locateName('word/document.xml')) !== false) {
                    $xml = $zip->getFromIndex($index);
                    $dom = new \DOMDocument();
                    $dom->loadXML($xml);
                    
                    // Extract text from paragraphs
                    $paragraphs = $dom->getElementsByTagName('p');
                    foreach ($paragraphs as $paragraph) {
                        $texts = $paragraph->getElementsByTagName('t');
                        foreach ($texts as $text) {
                            $content .= $text->nodeValue . ' ';
                        }
                        $content .= "\n";
                    }
                }
                
                $zip->close();
                return $content;
            }
            
        } catch (Exception $e) {
            Log::warning('DOCX content extraction failed', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        return '';
    }
    
    /**
     * Clean and normalize content
     */
    protected function cleanContent(string $content): string
    {
        // Remove excessive whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        
        // Remove non-printable characters
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
        
        // Trim
        $content = trim($content);
        
        // Limit length for search indexing (max 1MB)
        if (strlen($content) > 1048576) {
            $content = substr($content, 0, 1048576);
        }
        
        return $content;
    }
    
    /**
     * Handle job failure
     */
    public function failed(Exception $exception)
    {
        Log::error('Document indexing job failed permanently', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);
        
        // Mark document as indexing failed
        $this->document->update([
            'metadata' => array_merge($this->document->metadata ?? [], [
                'indexing_failed' => true,
                'indexing_error' => $exception->getMessage(),
                'indexing_failed_at' => now()->toIso8601String(),
            ]),
        ]);
    }
}