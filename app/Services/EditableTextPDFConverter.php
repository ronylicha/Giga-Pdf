<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use DOMDocument;
use DOMXPath;

class EditableTextPDFConverter
{
    /**
     * Convert PDF to fully editable HTML with real text
     */
    public function convertToHTML($pdfPath)
    {
        try {
            // Extract all text with formatting and position
            $textData = $this->extractCompleteTextData($pdfPath);
            
            // Extract images separately 
            $images = $this->extractImages($pdfPath);
            
            // Get PDF metadata
            $pdfInfo = $this->getPDFInfo($pdfPath);
            
            // Build HTML with editable text
            return $this->buildEditableHTML($textData, $images, $pdfInfo);
            
        } catch (Exception $e) {
            Log::error('Editable text PDF conversion failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Extract complete text data with formatting
     */
    private function extractCompleteTextData($pdfPath)
    {
        $textData = [];
        
        // First try pdftohtml with XML for best results
        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_text_');
        $command = sprintf(
            'pdftohtml -xml -i -fontfullname -hidden %s %s 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($tempFile)
        );
        
        exec($command, $output, $returnCode);
        
        $xmlFile = $tempFile . '.xml';
        if (file_exists($xmlFile)) {
            $xml = simplexml_load_file($xmlFile);
            
            foreach ($xml->page as $page) {
                $pageNum = intval($page['number']);
                $pageWidth = floatval($page['width']);
                $pageHeight = floatval($page['height']);
                
                $textData[$pageNum] = [
                    'width' => $pageWidth,
                    'height' => $pageHeight,
                    'texts' => []
                ];
                
                // Extract all text elements
                foreach ($page->text as $text) {
                    $content = trim((string)$text);
                    if (empty($content)) continue;
                    
                    $textData[$pageNum]['texts'][] = [
                        'content' => $content,
                        'left' => floatval($text['left']),
                        'top' => floatval($text['top']),
                        'width' => floatval($text['width']),
                        'height' => floatval($text['height']),
                        'font' => (string)($text['font'] ?? 'Arial'),
                        'size' => intval($text['size'] ?? 12),
                        'bold' => isset($text['bold']) && $text['bold'] == 'yes',
                        'italic' => isset($text['italic']) && $text['italic'] == 'yes',
                        'color' => (string)($text['color'] ?? '#000000')
                    ];
                }
            }
            
            unlink($xmlFile);
            @unlink($tempFile);
            @unlink($tempFile . '.html');
        }
        
        // If no text found, try alternative method
        if (empty($textData)) {
            $textData = $this->extractTextWithPdfToText($pdfPath);
        }
        
        return $textData;
    }
    
    /**
     * Alternative text extraction using pdftotext
     */
    private function extractTextWithPdfToText($pdfPath)
    {
        $textData = [];
        
        // Get page count
        $pdfInfo = $this->getPDFInfo($pdfPath);
        $pageCount = $pdfInfo['pages'];
        
        for ($page = 1; $page <= $pageCount; $page++) {
            // Extract text with layout preservation
            $command = sprintf(
                'pdftotext -f %d -l %d -layout %s - 2>&1',
                $page,
                $page,
                escapeshellarg($pdfPath)
            );
            
            exec($command, $output);
            $pageText = implode("\n", $output);
            
            // Parse text into lines with position estimation
            $lines = explode("\n", $pageText);
            $textData[$page] = [
                'width' => $pdfInfo['width'],
                'height' => $pdfInfo['height'],
                'texts' => []
            ];
            
            $y = 50; // Starting Y position
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    $y += 15; // Empty line spacing
                    continue;
                }
                
                // Estimate X position based on leading spaces
                $leadingSpaces = strlen($line) - strlen(ltrim($line));
                $x = 50 + ($leadingSpaces * 5);
                
                $textData[$page]['texts'][] = [
                    'content' => trim($line),
                    'left' => $x,
                    'top' => $y,
                    'width' => strlen(trim($line)) * 7,
                    'height' => 12,
                    'font' => 'Arial',
                    'size' => 12,
                    'bold' => false,
                    'italic' => false,
                    'color' => '#000000'
                ];
                
                $y += 15; // Line height
            }
            
            $output = []; // Clear output for next page
        }
        
        return $textData;
    }
    
    /**
     * Extract images from PDF
     */
    private function extractImages($pdfPath)
    {
        $images = [];
        $tempDir = sys_get_temp_dir() . '/' . uniqid('pdf_img_');
        mkdir($tempDir, 0755, true);
        
        // Extract images using pdfimages
        $command = sprintf(
            'pdfimages -p -png %s %s/img 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($tempDir)
        );
        
        exec($command);
        
        // Collect extracted images
        $imageFiles = glob($tempDir . '/*.png');
        foreach ($imageFiles as $imageFile) {
            // Parse filename to get page number
            if (preg_match('/img-(\d+)-/', basename($imageFile), $matches)) {
                $pageNum = intval($matches[1]);
                if (!isset($images[$pageNum])) {
                    $images[$pageNum] = [];
                }
                
                $imageData = base64_encode(file_get_contents($imageFile));
                $images[$pageNum][] = [
                    'data' => $imageData,
                    'type' => 'image/png'
                ];
            }
            unlink($imageFile);
        }
        
        rmdir($tempDir);
        return $images;
    }
    
    /**
     * Build fully editable HTML
     */
    private function buildEditableHTML($textData, $images, $pdfInfo)
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
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        
        #pdfContent {
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
        }
        
        .pdf-page {
            position: relative;
            margin: 0 auto 40px;
            background: white;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
            min-height: 1123px; /* A4 ratio */
        }
        
        /* Page separator */
        .pdf-page-separator {
            text-align: center;
            margin: 30px auto;
            position: relative;
            height: 30px;
        }
        
        .pdf-page-separator::before {
            content: "";
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(to right, transparent 10%, #ccc 30%, #ccc 70%, transparent 90%);
        }
        
        .pdf-page-separator .page-number {
            position: relative;
            display: inline-block;
            background: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            color: #666;
            font-weight: 500;
            border: 1px solid #ddd;
        }
        
        /* Image layer for backgrounds and graphics */
        .pdf-image-layer {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            pointer-events: none;
        }
        
        .pdf-image-layer img {
            max-width: 100%;
            height: auto;
        }
        
        /* Text layer - FULLY EDITABLE */
        .pdf-text-layer {
            position: relative;
            width: 100%;
            height: 100%;
            z-index: 2;
            min-height: inherit;
        }
        
        .pdf-text {
            position: absolute;
            white-space: pre-wrap;
            cursor: text;
            outline: none;
            transition: all 0.2s ease;
            padding: 2px;
            border-radius: 2px;
        }
        
        .pdf-text[contenteditable="true"]:hover {
            background: rgba(255, 235, 59, 0.1);
            box-shadow: 0 0 0 1px rgba(255, 235, 59, 0.3);
        }
        
        .pdf-text[contenteditable="true"]:focus {
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 0 0 2px #007bff;
            z-index: 10;
        }
        
        /* Text selection */
        .pdf-text::selection {
            background: rgba(0, 123, 255, 0.3);
        }
        
        .pdf-text::-moz-selection {
            background: rgba(0, 123, 255, 0.3);
        }
        
        /* Print styles */
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
                margin: 0;
                page-break-after: always;
                page-break-inside: avoid;
                border: none;
            }
            
            .pdf-page-separator {
                display: none !important;
            }
            
            .pdf-text[contenteditable="true"]:hover,
            .pdf-text[contenteditable="true"]:focus {
                background: transparent;
                box-shadow: none;
            }
        }
        
        /* Export mode - hides visual elements */
        .pdf-export-mode .pdf-page-separator {
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
    <div id="pdfContent">';
        
        // Process each page
        foreach ($textData as $pageNum => $pageData) {
            // Add page separator (except for first page)
            if ($pageNum > 1) {
                $html .= '
        <div class="pdf-page-separator">
            <span class="page-number">Page ' . $pageNum . '</span>
        </div>';
            }
            
            $pageWidth = $pageData['width'];
            $pageHeight = $pageData['height'];
            
            $html .= '
        <div class="pdf-page" data-page="' . $pageNum . '" data-width="' . $pageWidth . '" data-height="' . $pageHeight . '">';
            
            // Add image layer if there are images for this page
            if (isset($images[$pageNum])) {
                $html .= '
            <div class="pdf-image-layer">';
                foreach ($images[$pageNum] as $image) {
                    $html .= '
                <img src="data:' . $image['type'] . ';base64,' . $image['data'] . '" alt="Background image">';
                }
                $html .= '
            </div>';
            }
            
            // Add text layer with editable text
            $html .= '
            <div class="pdf-text-layer">';
            
            foreach ($pageData['texts'] as $text) {
                // Calculate position as percentage for responsive layout
                $leftPercent = ($text['left'] / $pageWidth) * 100;
                $topPercent = ($text['top'] / $pageHeight) * 100;
                
                // Build style string
                $style = sprintf(
                    'left: %.2f%%; top: %.2f%%; font-size: %dpx; font-family: %s; color: %s;',
                    $leftPercent,
                    $topPercent,
                    $text['size'],
                    $text['font'],
                    $text['color']
                );
                
                if ($text['bold']) {
                    $style .= ' font-weight: bold;';
                }
                if ($text['italic']) {
                    $style .= ' font-style: italic;';
                }
                
                $html .= sprintf(
                    '
                <div class="pdf-text" contenteditable="true" style="%s" data-original="%s">%s</div>',
                    $style,
                    htmlspecialchars($text['content'], ENT_QUOTES),
                    htmlspecialchars($text['content'], ENT_QUOTES)
                );
            }
            
            $html .= '
            </div>
        </div>';
        }
        
        $html .= '
    </div>
    
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const textElements = document.querySelectorAll(".pdf-text");
            
            // Track changes
            textElements.forEach(element => {
                element.addEventListener("input", function() {
                    this.setAttribute("data-modified", "true");
                    this.style.backgroundColor = "rgba(76, 175, 80, 0.1)";
                });
                
                element.addEventListener("blur", function() {
                    if (this.textContent.trim() === "") {
                        this.remove();
                    }
                });
                
                // Enable better text editing
                element.addEventListener("keydown", function(e) {
                    // Allow all text editing keys
                    e.stopPropagation();
                });
            });
            
            // Handle print/export events
            window.addEventListener("beforeprint", function() {
                document.getElementById("pdfContent").classList.add("pdf-export-mode");
            });
            
            window.addEventListener("afterprint", function() {
                document.getElementById("pdfContent").classList.remove("pdf-export-mode");
            });
            
            // Save functionality
            window.savePDFChanges = function() {
                const changes = [];
                
                document.querySelectorAll("[data-modified=true]").forEach(element => {
                    changes.push({
                        page: element.closest(".pdf-page").getAttribute("data-page"),
                        original: element.getAttribute("data-original"),
                        new: element.textContent,
                        position: {
                            left: element.style.left,
                            top: element.style.top
                        }
                    });
                });
                
                return changes;
            };
            
            // Add new text functionality
            document.querySelectorAll(".pdf-page").forEach(page => {
                page.addEventListener("dblclick", function(e) {
                    if (e.target.classList.contains("pdf-text-layer")) {
                        const rect = this.getBoundingClientRect();
                        const x = ((e.clientX - rect.left) / rect.width) * 100;
                        const y = ((e.clientY - rect.top) / rect.height) * 100;
                        
                        const newText = document.createElement("div");
                        newText.className = "pdf-text";
                        newText.contentEditable = "true";
                        newText.style.left = x + "%";
                        newText.style.top = y + "%";
                        newText.style.fontSize = "12px";
                        newText.style.color = "#000000";
                        newText.textContent = "New text";
                        newText.setAttribute("data-modified", "true");
                        
                        e.target.appendChild(newText);
                        newText.focus();
                        
                        // Select all text for easy replacement
                        const range = document.createRange();
                        range.selectNodeContents(newText);
                        const selection = window.getSelection();
                        selection.removeAllRanges();
                        selection.addRange(range);
                    }
                });
            });
        });
    </script>
</body>
</html>';
        
        return $html;
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