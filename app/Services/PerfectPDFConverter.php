<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use DOMDocument;
use DOMXPath;

class PerfectPDFConverter
{
    /**
     * Convert PDF to perfect 1:1 HTML representation
     */
    public function convertToHTML($pdfPath)
    {
        try {
            // Use the render-as-image approach for perfect visual fidelity
            return $this->renderPDFAsHighQualityImage($pdfPath);
        } catch (Exception $e) {
            Log::error('Perfect PDF conversion failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Render PDF as high-quality images with text overlay for editing
     */
    private function renderPDFAsHighQualityImage($pdfPath)
    {
        $tempDir = sys_get_temp_dir() . '/' . uniqid('pdf_perfect_');
        mkdir($tempDir, 0755, true);
        
        try {
            // Get PDF information first
            $pdfInfo = $this->getPDFInfo($pdfPath);
            
            // Convert to high-resolution PNG images for perfect visual fidelity
            $command = sprintf(
                'pdftocairo -png -r 200 -cropbox %s %s/page 2>&1',
                escapeshellarg($pdfPath),
                escapeshellarg($tempDir)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                // Fallback to pdftoppm if pdftocairo fails
                $command = sprintf(
                    'pdftoppm -png -r 200 -cropbox %s %s/page 2>&1',
                    escapeshellarg($pdfPath),
                    escapeshellarg($tempDir)
                );
                exec($command);
            }
            
            // Get all generated images
            $images = glob($tempDir . '/*.png');
            if (empty($images)) {
                throw new Exception('No images generated from PDF');
            }
            
            // Sort images by page number
            natsort($images);
            $images = array_values($images);
            
            // Extract text with exact coordinates for overlay
            $textData = $this->extractPreciseTextData($pdfPath);
            
            // Build the perfect HTML
            $html = $this->buildPerfectHTML($images, $textData, $pdfInfo);
            
            // Cleanup temp files
            foreach ($images as $image) {
                @unlink($image);
            }
            @rmdir($tempDir);
            
            return $html;
            
        } catch (Exception $e) {
            // Cleanup on error
            if (file_exists($tempDir)) {
                array_map('unlink', glob($tempDir . '/*'));
                @rmdir($tempDir);
            }
            throw $e;
        }
    }
    
    /**
     * Build perfect HTML with exact PDF rendering
     */
    private function buildPerfectHTML($images, $textData, $pdfInfo)
    {
        $html = '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($pdfInfo['title'] ?: 'PDF Document') . '</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f0f0f0;
            padding: 0;
            margin: 0;
        }
        
        #pdfContent {
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
            background: white;
        }
        
        .pdf-page {
            position: relative;
            margin: 0 auto 40px auto;
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
            overflow: hidden;
            border: 1px solid #e0e0e0;
        }
        
        .pdf-page:first-child {
            margin-top: 30px;
        }
        
        .pdf-page:last-child {
            margin-bottom: 30px;
        }
        
        /* Page divider */
        .pdf-page-divider {
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: -20px auto;
            position: relative;
            z-index: 1;
        }
        
        .pdf-page-divider::before {
            content: "";
            position: absolute;
            width: 200px;
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, #d0d0d0 20%, #d0d0d0 80%, transparent 100%);
        }
        
        .pdf-page-divider span {
            background: #f0f0f0;
            padding: 0 15px;
            color: #888;
            font-size: 11px;
            font-weight: 500;
            position: relative;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .pdf-page-image {
            width: 100%;
            height: auto;
            display: block;
            user-select: none;
            -webkit-user-drag: none;
        }
        
        .pdf-text-layer {
            position: absolute;
            left: 0;
            top: 0;
            right: 0;
            bottom: 0;
            overflow: hidden;
            opacity: 0;
            line-height: 1;
            font-size: 12px;
        }
        
        .pdf-text {
            position: absolute;
            color: transparent;
            white-space: pre;
            cursor: text;
            transform-origin: 0% 0%;
            line-height: 1;
        }
        
        /* Make text selectable */
        .pdf-text::selection {
            background: rgba(0, 123, 255, 0.3);
        }
        
        .pdf-text::-moz-selection {
            background: rgba(0, 123, 255, 0.3);
        }
        
        /* Editable text styling */
        .pdf-text[contenteditable="true"]:focus {
            color: black !important;
            background: rgba(255, 255, 255, 0.95);
            padding: 2px 4px;
            border-radius: 2px;
            outline: 2px solid #007bff;
            z-index: 10;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .pdf-text[contenteditable="true"]:hover {
            background: rgba(255, 235, 59, 0.1);
            outline: 1px dashed #999;
        }
        
        /* Ensure proper stacking */
        .pdf-page-image {
            position: relative;
            z-index: 1;
        }
        
        .pdf-text-layer {
            z-index: 2;
        }
        
        /* Loading state */
        .pdf-loading {
            text-align: center;
            padding: 50px;
            color: #666;
        }
        
        /* Responsive design */
        @media (max-width: 920px) {
            #pdfContent {
                max-width: 100%;
            }
            
            .pdf-page:first-child {
                margin-top: 0;
            }
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            #pdfContent {
                max-width: 100%;
            }
            
            .pdf-page {
                box-shadow: none;
                margin: 0 !important;
                page-break-after: always;
                page-break-inside: avoid;
                border: none !important;
            }
            
            /* Hide page dividers during print/PDF conversion */
            .pdf-page-divider {
                display: none !important;
            }
            
            .pdf-text-layer {
                display: none;
            }
        }
        
        /* Class for PDF export - hides visual separators */
        .pdf-export-mode .pdf-page-divider {
            display: none !important;
        }
        
        .pdf-export-mode .pdf-page {
            margin: 0 !important;
            border: none !important;
            box-shadow: none !important;
        }
    </style>
</head>
<body>
    <div id="pdfContent" class="pdf-content">';
        
        // Add each page
        foreach ($images as $index => $imagePath) {
            $pageNum = $index + 1;
            
            // Get image dimensions
            list($imgWidth, $imgHeight) = getimagesize($imagePath);
            
            // Convert image to base64
            $imageData = base64_encode(file_get_contents($imagePath));
            $mimeType = 'image/png';
            
            // Add page divider (except for first page)
            if ($pageNum > 1) {
                $html .= '
        <div class="pdf-page-divider">
            <span>Page ' . $pageNum . '</span>
        </div>';
            }
            
            $html .= '
        <div class="pdf-page" data-page="' . $pageNum . '" data-width="' . $imgWidth . '" data-height="' . $imgHeight . '">
            <img class="pdf-page-image" src="data:' . $mimeType . ';base64,' . $imageData . '" alt="Page ' . $pageNum . '">
            <div class="pdf-text-layer">';
            
            // Add text overlay for this page
            if (isset($textData[$pageNum])) {
                foreach ($textData[$pageNum] as $text) {
                    // Calculate position as percentage for responsive layout
                    $leftPercent = ($text['x'] / $pdfInfo['width']) * 100;
                    $topPercent = ($text['y'] / $pdfInfo['height']) * 100;
                    
                    // Calculate font size relative to page
                    $fontSize = isset($text['size']) ? $text['size'] : 12;
                    $fontSizePercent = ($fontSize / $pdfInfo['height']) * 100;
                    
                    $html .= sprintf(
                        '<span class="pdf-text" style="left: %.4f%%; top: %.4f%%; font-size: %.2fvw;" contenteditable="true" data-original="%s">%s</span>',
                        $leftPercent,
                        $topPercent,
                        $fontSizePercent * 10, // Adjust scale for viewport width
                        htmlspecialchars($text['text'], ENT_QUOTES),
                        htmlspecialchars($text['text'], ENT_QUOTES)
                    );
                }
            }
            
            $html .= '
            </div>
        </div>';
        }
        
        $html .= '
    </div>
    
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Handle text editing
            const textElements = document.querySelectorAll(".pdf-text");
            
            textElements.forEach(element => {
                // Show text when focused
                element.addEventListener("focus", function() {
                    this.style.opacity = "1";
                });
                
                // Hide text when blurred (unless changed)
                element.addEventListener("blur", function() {
                    const originalText = this.getAttribute("data-original");
                    if (this.textContent !== originalText) {
                        this.style.color = "black";
                        this.style.background = "rgba(255, 255, 0, 0.3)";
                    } else {
                        this.style.opacity = "0";
                    }
                });
                
                // Track changes
                element.addEventListener("input", function() {
                    this.setAttribute("data-modified", "true");
                });
            });
            
            // Responsive font sizing
            function adjustTextSizes() {
                document.querySelectorAll(".pdf-page").forEach(page => {
                    const pageWidth = page.offsetWidth;
                    const originalWidth = parseInt(page.getAttribute("data-width"));
                    const scale = pageWidth / originalWidth;
                    
                    page.querySelectorAll(".pdf-text").forEach(text => {
                        const baseFontSize = parseFloat(text.style.fontSize);
                        text.style.fontSize = (baseFontSize * scale) + "px";
                    });
                });
            }
            
            // Adjust on load and resize
            adjustTextSizes();
            window.addEventListener("resize", adjustTextSizes);
            
            // Detect print/export events to hide separators
            window.addEventListener("beforeprint", function() {
                document.getElementById("pdfContent").classList.add("pdf-export-mode");
            });
            
            window.addEventListener("afterprint", function() {
                document.getElementById("pdfContent").classList.remove("pdf-export-mode");
            });
            
            // Enable text selection across the invisible text layer
            document.addEventListener("mouseup", function() {
                const selection = window.getSelection();
                if (selection.toString().length > 0) {
                    // Make selected text temporarily visible
                    const range = selection.getRangeAt(0);
                    const container = range.commonAncestorContainer;
                    if (container.nodeType === 3) { // Text node
                        const span = container.parentElement;
                        if (span.classList.contains("pdf-text")) {
                            span.style.opacity = "1";
                            span.style.color = "transparent";
                            setTimeout(() => {
                                if (!span.hasAttribute("data-modified")) {
                                    span.style.opacity = "0";
                                }
                            }, 3000);
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Extract precise text data with coordinates
     */
    private function extractPreciseTextData($pdfPath)
    {
        $textData = [];
        
        // Try pdftotext with bbox for precise positioning
        $command = sprintf(
            'pdftotext -bbox-layout %s - 2>&1',
            escapeshellarg($pdfPath)
        );
        
        exec($command, $output);
        $xml = implode("\n", $output);
        
        // Parse the XML output
        if (strpos($xml, '<?xml') !== false) {
            $dom = new DOMDocument();
            @$dom->loadXML($xml);
            
            $xpath = new DOMXPath($dom);
            $pages = $xpath->query('//page');
            
            foreach ($pages as $page) {
                $pageNum = $page->getAttribute('number');
                if (!$pageNum) $pageNum = 1;
                
                $textData[$pageNum] = [];
                
                // Get all words on this page
                $words = $xpath->query('.//word', $page);
                foreach ($words as $word) {
                    $text = trim($word->textContent);
                    if (empty($text)) continue;
                    
                    $textData[$pageNum][] = [
                        'text' => $text,
                        'x' => floatval($word->getAttribute('xMin')),
                        'y' => floatval($word->getAttribute('yMin')),
                        'width' => floatval($word->getAttribute('xMax')) - floatval($word->getAttribute('xMin')),
                        'height' => floatval($word->getAttribute('yMax')) - floatval($word->getAttribute('yMin')),
                        'size' => round(floatval($word->getAttribute('yMax')) - floatval($word->getAttribute('yMin')))
                    ];
                }
            }
        } else {
            // Fallback: Try simple text extraction
            $command = sprintf(
                'pdftotext -layout %s - 2>&1',
                escapeshellarg($pdfPath)
            );
            
            exec($command, $output);
            
            // Basic text extraction without precise positioning
            $pageNum = 1;
            $y = 20;
            foreach ($output as $line) {
                if (strpos($line, "\f") !== false) {
                    $pageNum++;
                    $y = 20;
                    continue;
                }
                
                if (trim($line) !== '') {
                    if (!isset($textData[$pageNum])) {
                        $textData[$pageNum] = [];
                    }
                    
                    $textData[$pageNum][] = [
                        'text' => $line,
                        'x' => 50,
                        'y' => $y,
                        'width' => 500,
                        'height' => 12,
                        'size' => 12
                    ];
                    
                    $y += 15;
                }
            }
        }
        
        return $textData;
    }
    
    /**
     * Get PDF information
     */
    private function getPDFInfo($pdfPath)
    {
        $command = sprintf('pdfinfo %s 2>&1', escapeshellarg($pdfPath));
        exec($command, $output);
        
        $info = [
            'pages' => 1,
            'width' => 595,
            'height' => 842,
            'title' => '',
            'author' => '',
            'subject' => '',
            'creator' => ''
        ];
        
        foreach ($output as $line) {
            if (preg_match('/Pages:\s+(\d+)/', $line, $matches)) {
                $info['pages'] = intval($matches[1]);
            } elseif (preg_match('/Page size:\s+([0-9.]+)\s+x\s+([0-9.]+)/', $line, $matches)) {
                $info['width'] = floatval($matches[1]);
                $info['height'] = floatval($matches[2]);
            } elseif (preg_match('/Title:\s+(.+)/', $line, $matches)) {
                $info['title'] = trim($matches[1]);
            } elseif (preg_match('/Author:\s+(.+)/', $line, $matches)) {
                $info['author'] = trim($matches[1]);
            } elseif (preg_match('/Subject:\s+(.+)/', $line, $matches)) {
                $info['subject'] = trim($matches[1]);
            } elseif (preg_match('/Creator:\s+(.+)/', $line, $matches)) {
                $info['creator'] = trim($matches[1]);
            }
        }
        
        return $info;
    }
}