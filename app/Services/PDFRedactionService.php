<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Tcpdf\Fpdi;
use Exception;

class PDFRedactionService
{
    /**
     * Redact sensitive content from PDF
     */
    public function redactDocument(
        Document $document,
        array $redactionAreas,
        array $options = []
    ): Document {
        try {
            $sourcePath = Storage::path($document->stored_name);
            
            // Generate output filename
            $filename = Str::slug(pathinfo($document->original_name, PATHINFO_FILENAME)) 
                . '_redacted_' . time() . '.pdf';
            $outputPath = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullOutputPath = Storage::path($outputPath);
            
            // Ensure directory exists
            $dir = dirname($fullOutputPath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            // Create new PDF with FPDI
            $pdf = new Fpdi();
            
            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Import existing PDF pages
            $pageCount = $pdf->setSourceFile($sourcePath);
            
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                
                // Add a page with same orientation and size
                $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                
                // Use the imported page
                $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height'], true);
                
                // Apply redactions for this page
                if (isset($redactionAreas[$pageNo])) {
                    $this->applyRedactions($pdf, $redactionAreas[$pageNo], $options);
                }
            }
            
            // Output the redacted PDF
            $pdf->Output($fullOutputPath, 'F');
            
            // Create new document record
            $redactedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => str_replace('.pdf', '_redacted.pdf', $document->original_name),
                'stored_name' => $outputPath,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullOutputPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullOutputPath),
                'metadata' => array_merge($document->metadata ?? [], [
                    'type' => 'redacted',
                    'source_document' => $document->id,
                    'redacted_at' => now()->toIso8601String(),
                    'redaction_count' => count($redactionAreas, COUNT_RECURSIVE) - count($redactionAreas),
                    'redaction_reason' => $options['reason'] ?? 'Sensitive content removal',
                ]),
            ]);
            
            return $redactedDocument;
            
        } catch (Exception $e) {
            throw new Exception('Error redacting PDF: ' . $e->getMessage());
        }
    }
    
    /**
     * Apply redactions to a page
     */
    private function applyRedactions(Fpdi $pdf, array $areas, array $options): void
    {
        $redactionColor = $options['color'] ?? [0, 0, 0]; // Black by default
        $redactionText = $options['text'] ?? ''; // Optional redaction text
        
        foreach ($areas as $area) {
            $x = $area['x'];
            $y = $area['y'];
            $width = $area['width'];
            $height = $area['height'];
            
            // Set fill color for redaction
            $pdf->SetFillColor($redactionColor[0], $redactionColor[1], $redactionColor[2]);
            
            // Draw filled rectangle to cover content
            $pdf->Rect($x, $y, $width, $height, 'F');
            
            // Add redaction text if provided
            if (!empty($redactionText)) {
                $pdf->SetTextColor(255, 255, 255); // White text
                $pdf->SetFont('helvetica', 'B', 10);
                $pdf->SetXY($x, $y + ($height / 2) - 3);
                $pdf->Cell($width, 6, $redactionText, 0, 0, 'C');
                $pdf->SetTextColor(0, 0, 0); // Reset to black
            }
        }
    }
    
    /**
     * Search and redact text patterns
     */
    public function redactByPattern(
        Document $document,
        array $patterns,
        array $options = []
    ): Document {
        try {
            // Extract text with positions
            $textPositions = $this->extractTextWithPositions($document);
            
            // Find areas to redact based on patterns
            $redactionAreas = [];
            
            foreach ($textPositions as $pageNo => $pageText) {
                $redactionAreas[$pageNo] = [];
                
                foreach ($patterns as $pattern) {
                    if ($pattern['type'] === 'regex') {
                        $matches = [];
                        preg_match_all($pattern['pattern'], $pageText['text'], $matches, PREG_OFFSET_CAPTURE);
                        
                        foreach ($matches[0] as $match) {
                            $text = $match[0];
                            $offset = $match[1];
                            
                            // Find position of matched text
                            $position = $this->findTextPosition($pageText['positions'], $offset, strlen($text));
                            
                            if ($position) {
                                $redactionAreas[$pageNo][] = $position;
                            }
                        }
                    } elseif ($pattern['type'] === 'text') {
                        $searchText = $pattern['text'];
                        $offset = 0;
                        
                        while (($pos = stripos($pageText['text'], $searchText, $offset)) !== false) {
                            $position = $this->findTextPosition($pageText['positions'], $pos, strlen($searchText));
                            
                            if ($position) {
                                $redactionAreas[$pageNo][] = $position;
                            }
                            
                            $offset = $pos + strlen($searchText);
                        }
                    }
                }
                
                // Remove empty page entries
                if (empty($redactionAreas[$pageNo])) {
                    unset($redactionAreas[$pageNo]);
                }
            }
            
            // Apply redactions
            return $this->redactDocument($document, $redactionAreas, $options);
            
        } catch (Exception $e) {
            throw new Exception('Error redacting by pattern: ' . $e->getMessage());
        }
    }
    
    /**
     * Extract text with position information
     */
    private function extractTextWithPositions(Document $document): array
    {
        $pdfPath = Storage::path($document->stored_name);
        $textPositions = [];
        
        // Use pdftotext with bbox option to get text positions
        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_text_');
        $command = sprintf(
            'pdftotext -bbox %s %s 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($tempFile)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($tempFile)) {
            $xmlContent = file_get_contents($tempFile);
            
            // Parse XML to get text positions
            $xml = simplexml_load_string($xmlContent);
            
            if ($xml) {
                foreach ($xml->page as $page) {
                    $pageNo = (int)$page['number'];
                    $pageText = '';
                    $positions = [];
                    
                    foreach ($page->word as $word) {
                        $text = (string)$word;
                        $x = (float)$word['xMin'];
                        $y = (float)$word['yMin'];
                        $width = (float)$word['xMax'] - $x;
                        $height = (float)$word['yMax'] - $y;
                        
                        $positions[] = [
                            'text' => $text,
                            'x' => $x,
                            'y' => $y,
                            'width' => $width,
                            'height' => $height,
                            'offset' => strlen($pageText),
                        ];
                        
                        $pageText .= $text . ' ';
                    }
                    
                    $textPositions[$pageNo] = [
                        'text' => $pageText,
                        'positions' => $positions,
                    ];
                }
            }
            
            unlink($tempFile);
        }
        
        return $textPositions;
    }
    
    /**
     * Find text position based on offset and length
     */
    private function findTextPosition(array $positions, int $offset, int $length): ?array
    {
        $startPos = null;
        $endPos = null;
        
        foreach ($positions as $pos) {
            if ($pos['offset'] <= $offset && $pos['offset'] + strlen($pos['text']) > $offset) {
                $startPos = $pos;
            }
            
            if ($pos['offset'] < $offset + $length && $pos['offset'] + strlen($pos['text']) >= $offset + $length) {
                $endPos = $pos;
                break;
            }
        }
        
        if ($startPos && $endPos) {
            return [
                'x' => $startPos['x'],
                'y' => $startPos['y'],
                'width' => $endPos['x'] + $endPos['width'] - $startPos['x'],
                'height' => max($startPos['height'], $endPos['height']),
            ];
        }
        
        return null;
    }
    
    /**
     * Redact common sensitive patterns
     */
    public function redactSensitiveData(Document $document, array $options = []): Document
    {
        $patterns = [
            // Social Security Numbers
            [
                'type' => 'regex',
                'pattern' => '/\b\d{3}-\d{2}-\d{4}\b/',
                'name' => 'SSN',
            ],
            // Credit Card Numbers
            [
                'type' => 'regex',
                'pattern' => '/\b(?:\d{4}[-\s]?){3}\d{4}\b/',
                'name' => 'Credit Card',
            ],
            // Email Addresses
            [
                'type' => 'regex',
                'pattern' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
                'name' => 'Email',
            ],
            // Phone Numbers
            [
                'type' => 'regex',
                'pattern' => '/\b(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/',
                'name' => 'Phone',
            ],
            // Bank Account Numbers
            [
                'type' => 'regex',
                'pattern' => '/\b\d{8,17}\b/',
                'name' => 'Account Number',
            ],
        ];
        
        // Add custom patterns if provided
        if (!empty($options['custom_patterns'])) {
            $patterns = array_merge($patterns, $options['custom_patterns']);
        }
        
        // Filter patterns based on options
        if (!empty($options['only_patterns'])) {
            $patterns = array_filter($patterns, function($pattern) use ($options) {
                return in_array($pattern['name'], $options['only_patterns']);
            });
        }
        
        return $this->redactByPattern($document, $patterns, $options);
    }
    
    /**
     * Redact specific keywords from PDF
     */
    public function redactByKeywords(Document $document, array $options): Document
    {
        $keywords = $options['keywords'] ?? [];
        $caseSensitive = $options['case_sensitive'] ?? false;
        $wholeWord = $options['whole_word'] ?? false;
        
        // Convert keywords to patterns
        $patterns = [];
        foreach ($keywords as $keyword) {
            $escapedKeyword = preg_quote($keyword, '/');
            
            // Build pattern based on options
            $pattern = '';
            if ($wholeWord) {
                $pattern = '/\b' . $escapedKeyword . '\b/';
            } else {
                $pattern = '/' . $escapedKeyword . '/';
            }
            
            // Add case sensitivity flag
            if (!$caseSensitive) {
                $pattern .= 'i';
            }
            
            $patterns[] = [
                'type' => 'regex',
                'pattern' => $pattern,
                'name' => 'Keyword: ' . $keyword,
            ];
        }
        
        return $this->redactByPattern($document, $patterns, $options);
    }
    
    /**
     * Create audit log for redaction
     */
    public function logRedaction(Document $document, array $details): void
    {
        activity()
            ->performedOn($document)
            ->withProperties([
                'action' => 'redaction',
                'areas_redacted' => $details['areas_count'] ?? 0,
                'patterns_used' => $details['patterns'] ?? [],
                'keywords_count' => $details['keywords_count'] ?? 0,
                'reason' => $details['reason'] ?? 'Sensitive content removal',
                'user' => auth()->id(),
                'timestamp' => now()->toIso8601String(),
            ])
            ->log('Document redacted');
    }
}