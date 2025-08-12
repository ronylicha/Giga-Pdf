<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use ImagickPixel;
use Exception;

class PDFComparisonService
{
    /**
     * Compare two PDF documents visually
     */
    public function compareDocuments(
        Document $document1,
        Document $document2,
        array $options = []
    ): array {
        try {
            $path1 = Storage::path($document1->stored_name);
            $path2 = Storage::path($document2->stored_name);
            
            // Convert PDFs to images for comparison
            $images1 = $this->pdfToImages($path1);
            $images2 = $this->pdfToImages($path2);
            
            $pageCount1 = count($images1);
            $pageCount2 = count($images2);
            $maxPages = max($pageCount1, $pageCount2);
            
            $comparisonResults = [
                'document1' => [
                    'id' => $document1->id,
                    'name' => $document1->original_name,
                    'pages' => $pageCount1,
                ],
                'document2' => [
                    'id' => $document2->id,
                    'name' => $document2->original_name,
                    'pages' => $pageCount2,
                ],
                'total_pages_compared' => $maxPages,
                'differences' => [],
                'similarity_percentage' => 0,
                'has_differences' => false,
            ];
            
            $totalSimilarity = 0;
            $comparedPages = 0;
            
            // Compare each page
            for ($i = 0; $i < $maxPages; $i++) {
                $pageNum = $i + 1;
                
                if ($i >= $pageCount1) {
                    // Page exists only in document 2
                    $comparisonResults['differences'][] = [
                        'page' => $pageNum,
                        'type' => 'page_added',
                        'description' => "Page $pageNum exists only in second document",
                        'similarity' => 0,
                    ];
                    $comparisonResults['has_differences'] = true;
                } elseif ($i >= $pageCount2) {
                    // Page exists only in document 1
                    $comparisonResults['differences'][] = [
                        'page' => $pageNum,
                        'type' => 'page_removed',
                        'description' => "Page $pageNum exists only in first document",
                        'similarity' => 0,
                    ];
                    $comparisonResults['has_differences'] = true;
                } else {
                    // Compare the pages
                    $pageComparison = $this->comparePages(
                        $images1[$i],
                        $images2[$i],
                        $pageNum,
                        $options
                    );
                    
                    if ($pageComparison['has_differences']) {
                        $comparisonResults['differences'][] = $pageComparison;
                        $comparisonResults['has_differences'] = true;
                    }
                    
                    $totalSimilarity += $pageComparison['similarity'];
                    $comparedPages++;
                }
            }
            
            // Calculate overall similarity
            if ($comparedPages > 0) {
                $comparisonResults['similarity_percentage'] = round($totalSimilarity / $comparedPages, 2);
            }
            
            // Generate difference document if requested
            if ($options['generate_diff_pdf'] ?? false) {
                $diffDocument = $this->generateDiffPDF(
                    $document1,
                    $document2,
                    $comparisonResults,
                    $options
                );
                $comparisonResults['diff_document_id'] = $diffDocument->id;
            }
            
            // Cleanup temporary images
            foreach (array_merge($images1, $images2) as $imagePath) {
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
            }
            
            return $comparisonResults;
            
        } catch (Exception $e) {
            throw new Exception('Error comparing PDFs: ' . $e->getMessage());
        }
    }
    
    /**
     * Convert PDF to images
     */
    private function pdfToImages(string $pdfPath): array
    {
        $images = [];
        
        try {
            $imagick = new Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImage($pdfPath);
            
            $pageCount = $imagick->getNumberImages();
            
            for ($i = 0; $i < $pageCount; $i++) {
                $imagick->setIteratorIndex($i);
                
                // Convert to PNG
                $imagick->setImageFormat('png');
                
                // Save to temporary file
                $tempPath = tempnam(sys_get_temp_dir(), 'pdf_page_') . '.png';
                $imagick->writeImage($tempPath);
                
                $images[] = $tempPath;
            }
            
            $imagick->clear();
            $imagick->destroy();
            
        } catch (Exception $e) {
            throw new Exception('Error converting PDF to images: ' . $e->getMessage());
        }
        
        return $images;
    }
    
    /**
     * Compare two page images
     */
    private function comparePages(
        string $imagePath1,
        string $imagePath2,
        int $pageNum,
        array $options
    ): array {
        $result = [
            'page' => $pageNum,
            'type' => 'content_change',
            'has_differences' => false,
            'similarity' => 100,
            'differences_found' => [],
        ];
        
        try {
            $image1 = new Imagick($imagePath1);
            $image2 = new Imagick($imagePath2);
            
            // Ensure images have the same dimensions
            $this->normalizeImageDimensions($image1, $image2);
            
            // Compare images
            $comparison = $image1->compareImages($image2, Imagick::METRIC_MEANSQUAREERROR);
            
            // Calculate similarity percentage
            $difference = $comparison[1];
            $similarity = (1 - $difference) * 100;
            $result['similarity'] = round($similarity, 2);
            
            // Determine if there are significant differences
            $threshold = $options['threshold'] ?? 95;
            if ($similarity < $threshold) {
                $result['has_differences'] = true;
                
                // Find specific differences
                if ($options['detailed_analysis'] ?? false) {
                    $differences = $this->findDetailedDifferences($image1, $image2);
                    $result['differences_found'] = $differences;
                }
                
                // Create difference image if requested
                if ($options['create_diff_images'] ?? false) {
                    $diffImagePath = $this->createDifferenceImage(
                        $image1,
                        $image2,
                        $pageNum,
                        $options
                    );
                    $result['diff_image'] = $diffImagePath;
                }
            }
            
            $result['description'] = $similarity < $threshold 
                ? "Page $pageNum has significant differences ({$result['similarity']}% similar)"
                : "Page $pageNum is identical or nearly identical";
            
            $image1->clear();
            $image1->destroy();
            $image2->clear();
            $image2->destroy();
            
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            $result['has_differences'] = true;
            $result['similarity'] = 0;
        }
        
        return $result;
    }
    
    /**
     * Normalize image dimensions
     */
    private function normalizeImageDimensions(Imagick $image1, Imagick $image2): void
    {
        $width1 = $image1->getImageWidth();
        $height1 = $image1->getImageHeight();
        $width2 = $image2->getImageWidth();
        $height2 = $image2->getImageHeight();
        
        if ($width1 !== $width2 || $height1 !== $height2) {
            // Resize to the larger dimensions
            $maxWidth = max($width1, $width2);
            $maxHeight = max($height1, $height2);
            
            $image1->resizeImage($maxWidth, $maxHeight, Imagick::FILTER_LANCZOS, 1, false);
            $image2->resizeImage($maxWidth, $maxHeight, Imagick::FILTER_LANCZOS, 1, false);
        }
    }
    
    /**
     * Find detailed differences between images
     */
    private function findDetailedDifferences(Imagick $image1, Imagick $image2): array
    {
        $differences = [];
        
        // Create difference mask
        $diff = clone $image1;
        $diff->compositeImage($image2, Imagick::COMPOSITE_DIFFERENCE, 0, 0);
        
        // Convert to grayscale for analysis
        $diff->setImageColorspace(Imagick::COLORSPACE_GRAY);
        
        // Threshold to highlight differences
        $diff->thresholdImage(0.1 * Imagick::getQuantum());
        
        // Find contours of differences
        $width = $diff->getImageWidth();
        $height = $diff->getImageHeight();
        
        // Scan for difference regions
        $regions = [];
        $visited = array_fill(0, $height, array_fill(0, $width, false));
        
        for ($y = 0; $y < $height; $y += 10) {
            for ($x = 0; $x < $width; $x += 10) {
                if (!$visited[$y][$x]) {
                    $pixel = $diff->getImagePixelColor($x, $y);
                    $colors = $pixel->getColor();
                    
                    if ($colors['r'] > 128) { // Significant difference
                        $region = $this->findRegion($diff, $x, $y, $visited);
                        if ($region['area'] > 100) { // Minimum area threshold
                            $regions[] = $region;
                        }
                    }
                }
            }
        }
        
        // Analyze each region
        foreach ($regions as $region) {
            $differences[] = [
                'x' => $region['x'],
                'y' => $region['y'],
                'width' => $region['width'],
                'height' => $region['height'],
                'type' => $this->classifyDifference($region),
            ];
        }
        
        $diff->clear();
        $diff->destroy();
        
        return $differences;
    }
    
    /**
     * Find connected region of differences
     */
    private function findRegion(Imagick $image, int $startX, int $startY, array &$visited): array
    {
        $minX = $startX;
        $maxX = $startX;
        $minY = $startY;
        $maxY = $startY;
        
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        
        // Simple flood fill to find connected region
        $stack = [[$startX, $startY]];
        
        while (!empty($stack)) {
            [$x, $y] = array_pop($stack);
            
            if ($x < 0 || $x >= $width || $y < 0 || $y >= $height || $visited[$y][$x]) {
                continue;
            }
            
            $visited[$y][$x] = true;
            
            $pixel = $image->getImagePixelColor($x, $y);
            $colors = $pixel->getColor();
            
            if ($colors['r'] > 128) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
                
                // Add neighbors
                $stack[] = [$x + 1, $y];
                $stack[] = [$x - 1, $y];
                $stack[] = [$x, $y + 1];
                $stack[] = [$x, $y - 1];
            }
        }
        
        return [
            'x' => $minX,
            'y' => $minY,
            'width' => $maxX - $minX,
            'height' => $maxY - $minY,
            'area' => ($maxX - $minX) * ($maxY - $minY),
        ];
    }
    
    /**
     * Classify type of difference
     */
    private function classifyDifference(array $region): string
    {
        $aspectRatio = $region['width'] / max($region['height'], 1);
        
        if ($aspectRatio > 5) {
            return 'text_change';
        } elseif ($aspectRatio < 0.2) {
            return 'vertical_change';
        } elseif ($region['area'] > 10000) {
            return 'large_change';
        } else {
            return 'small_change';
        }
    }
    
    /**
     * Create visual difference image
     */
    private function createDifferenceImage(
        Imagick $image1,
        Imagick $image2,
        int $pageNum,
        array $options
    ): string {
        // Create composite image showing differences
        $diff = clone $image1;
        
        // Apply difference composite
        $diff->compositeImage($image2, Imagick::COMPOSITE_DIFFERENCE, 0, 0);
        
        // Enhance differences with color
        if ($options['highlight_differences'] ?? true) {
            // Create colored overlay
            $overlay = clone $diff;
            $overlay->setImageColorspace(Imagick::COLORSPACE_RGB);
            
            // Colorize differences in red
            $overlay->colorizeImage('#FF0000', 0.5);
            
            // Blend with original
            $result = clone $image1;
            $result->compositeImage($overlay, Imagick::COMPOSITE_BLEND, 0, 0);
            
            $diff = $result;
        }
        
        // Save difference image
        $tempPath = tempnam(sys_get_temp_dir(), "diff_page_{$pageNum}_") . '.png';
        $diff->writeImage($tempPath);
        
        $diff->clear();
        $diff->destroy();
        
        return $tempPath;
    }
    
    /**
     * Generate PDF document with differences highlighted
     */
    private function generateDiffPDF(
        Document $document1,
        Document $document2,
        array $comparisonResults,
        array $options
    ): Document {
        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Add title page
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'PDF Comparison Report', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Ln(10);
        
        $pdf->Cell(0, 10, 'Document 1: ' . $document1->original_name, 0, 1);
        $pdf->Cell(0, 10, 'Document 2: ' . $document2->original_name, 0, 1);
        $pdf->Cell(0, 10, 'Comparison Date: ' . now()->format('Y-m-d H:i:s'), 0, 1);
        $pdf->Cell(0, 10, 'Overall Similarity: ' . $comparisonResults['similarity_percentage'] . '%', 0, 1);
        $pdf->Ln(10);
        
        // Add summary of differences
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Summary of Differences', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        
        foreach ($comparisonResults['differences'] as $diff) {
            $pdf->Cell(0, 8, '• ' . $diff['description'], 0, 1);
        }
        
        // Add difference images if available
        if ($options['include_diff_images'] ?? false) {
            foreach ($comparisonResults['differences'] as $diff) {
                if (isset($diff['diff_image']) && file_exists($diff['diff_image'])) {
                    $pdf->AddPage();
                    $pdf->SetFont('helvetica', 'B', 12);
                    $pdf->Cell(0, 10, 'Page ' . $diff['page'] . ' Differences', 0, 1, 'C');
                    $pdf->Image($diff['diff_image'], 15, 30, 180);
                }
            }
        }
        
        // Save PDF
        $filename = 'comparison_' . time() . '.pdf';
        $outputPath = 'documents/' . $document1->tenant_id . '/' . $filename;
        $fullOutputPath = Storage::path($outputPath);
        
        $dir = dirname($fullOutputPath);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $pdf->Output($fullOutputPath, 'F');
        
        // Create document record
        $comparisonDocument = Document::create([
            'tenant_id' => $document1->tenant_id,
            'user_id' => $document1->user_id,
            'original_name' => 'Comparison_' . $document1->original_name . '_vs_' . $document2->original_name,
            'stored_name' => $outputPath,
            'mime_type' => 'application/pdf',
            'size' => filesize($fullOutputPath),
            'extension' => 'pdf',
            'hash' => hash_file('sha256', $fullOutputPath),
            'metadata' => [
                'type' => 'comparison',
                'document1_id' => $document1->id,
                'document2_id' => $document2->id,
                'similarity' => $comparisonResults['similarity_percentage'],
                'differences_count' => count($comparisonResults['differences']),
                'created_at' => now()->toIso8601String(),
            ],
        ]);
        
        return $comparisonDocument;
    }
    
    /**
     * Compare text content of PDFs
     */
    public function compareText(Document $document1, Document $document2): array
    {
        $text1 = $this->extractText($document1);
        $text2 = $this->extractText($document2);
        
        // Calculate text similarity
        $similarity = $this->calculateTextSimilarity($text1, $text2);
        
        // Find differences
        $differences = $this->findTextDifferences($text1, $text2);
        
        return [
            'similarity' => $similarity,
            'differences' => $differences,
            'text1_length' => strlen($text1),
            'text2_length' => strlen($text2),
        ];
    }
    
    /**
     * Extract text from document
     */
    private function extractText(Document $document): string
    {
        $pdfPath = Storage::path($document->stored_name);
        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_text_');
        
        $command = sprintf(
            'pdftotext %s %s 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($tempFile)
        );
        
        exec($command, $output, $returnCode);
        
        $text = '';
        if ($returnCode === 0 && file_exists($tempFile)) {
            $text = file_get_contents($tempFile);
            unlink($tempFile);
        }
        
        return $text;
    }
    
    /**
     * Calculate text similarity using Levenshtein distance
     */
    private function calculateTextSimilarity(string $text1, string $text2): float
    {
        $maxLength = max(strlen($text1), strlen($text2));
        
        if ($maxLength === 0) {
            return 100;
        }
        
        // For large texts, use similar_text instead of levenshtein
        similar_text($text1, $text2, $percent);
        
        return round($percent, 2);
    }
    
    /**
     * Find text differences
     */
    private function findTextDifferences(string $text1, string $text2): array
    {
        $lines1 = explode("\n", $text1);
        $lines2 = explode("\n", $text2);
        
        $differences = [];
        $maxLines = max(count($lines1), count($lines2));
        
        for ($i = 0; $i < $maxLines; $i++) {
            $line1 = $lines1[$i] ?? '';
            $line2 = $lines2[$i] ?? '';
            
            if ($line1 !== $line2) {
                $differences[] = [
                    'line' => $i + 1,
                    'text1' => $line1,
                    'text2' => $line2,
                ];
            }
        }
        
        return $differences;
    }
}