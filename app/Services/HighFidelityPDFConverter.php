<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class HighFidelityPDFConverter
{
    private $dpi = 150;
    private $quality = 95;
    
    /**
     * Convert PDF to high-fidelity HTML with exact layout preservation
     */
    public function convertToHTML($pdfPath)
    {
        try {
            // Skip PDF.js for now as it needs special handling
            // Method 1: Try image-based approach with OCR overlay (best visual fidelity)
            $html = $this->convertWithImageBasedApproach($pdfPath);
            if ($html) {
                return $html;
            }
            
            // Method 2: Enhanced pdftohtml with corrections
            return $this->convertWithEnhancedPdfToHtml($pdfPath);
            
        } catch (Exception $e) {
            Log::error('High-fidelity PDF conversion failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * PDF.js based conversion - renders PDF using canvas
     */
    private function convertWithPDFJS($pdfPath)
    {
        // Generate a unique ID for this conversion
        $conversionId = uniqid('pdf_');
        
        // Copy PDF to public temporary location for PDF.js access
        $publicPath = 'temp/' . $conversionId . '.pdf';
        $fullPublicPath = public_path($publicPath);
        
        // Ensure temp directory exists
        if (!file_exists(dirname($fullPublicPath))) {
            mkdir(dirname($fullPublicPath), 0755, true);
        }
        
        copy($pdfPath, $fullPublicPath);
        
        // Get PDF info
        $pdfInfo = $this->getPDFInfo($pdfPath);
        
        // Build HTML with PDF.js viewer
        $html = '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Editor</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        #pdfContent {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin: 0 auto;
            position: relative;
        }
        
        .pdf-page {
            position: relative;
            margin-bottom: 20px;
            background: white;
        }
        
        .pdf-page canvas {
            display: block;
            width: 100%;
            height: auto;
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
        }
        
        .pdf-text-layer > span {
            color: transparent;
            position: absolute;
            white-space: pre;
            cursor: text;
            transform-origin: 0% 0%;
        }
        
        .pdf-text-layer ::selection {
            background: rgba(0, 123, 255, 0.3);
        }
        
        .pdf-annotation-layer {
            position: absolute;
            left: 0;
            top: 0;
            right: 0;
            bottom: 0;
            overflow: hidden;
        }
        
        .pdf-loading {
            text-align: center;
            padding: 50px;
            color: #666;
        }
        
        /* Make content editable */
        [contenteditable="true"] {
            outline: none;
            background: rgba(255, 235, 59, 0.1);
            cursor: text;
        }
        
        [contenteditable="true"]:focus {
            background: rgba(255, 235, 59, 0.2);
        }
    </style>
</head>
<body>
    <div id="pdfContent">
        <div class="pdf-loading">Chargement du PDF...</div>
    </div>
    
    <script>
        // Configure PDF.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";
        
        async function renderPDF() {
            const url = "/' . $publicPath . '";
            const container = document.getElementById("pdfContent");
            
            try {
                // Load the PDF
                const loadingTask = pdfjsLib.getDocument(url);
                const pdf = await loadingTask.promise;
                
                // Clear loading message
                container.innerHTML = "";
                
                // Render each page
                for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
                    const page = await pdf.getPage(pageNum);
                    
                    // Create page container
                    const pageDiv = document.createElement("div");
                    pageDiv.className = "pdf-page";
                    pageDiv.setAttribute("data-page-number", pageNum);
                    
                    // Set up canvas
                    const canvas = document.createElement("canvas");
                    const context = canvas.getContext("2d");
                    
                    // Scale for high quality
                    const scale = 2.0;
                    const viewport = page.getViewport({ scale });
                    
                    canvas.width = viewport.width;
                    canvas.height = viewport.height;
                    canvas.style.width = (viewport.width / scale) + "px";
                    canvas.style.height = (viewport.height / scale) + "px";
                    
                    pageDiv.style.width = (viewport.width / scale) + "px";
                    pageDiv.style.height = (viewport.height / scale) + "px";
                    
                    // Render PDF page
                    await page.render({
                        canvasContext: context,
                        viewport: viewport
                    }).promise;
                    
                    pageDiv.appendChild(canvas);
                    
                    // Add text layer for selection and editing
                    const textLayerDiv = document.createElement("div");
                    textLayerDiv.className = "pdf-text-layer";
                    
                    const textContent = await page.getTextContent();
                    
                    // Render text layer
                    pdfjsLib.renderTextLayer({
                        textContent: textContent,
                        container: textLayerDiv,
                        viewport: viewport,
                        textDivs: []
                    });
                    
                    pageDiv.appendChild(textLayerDiv);
                    
                    // Add to container
                    container.appendChild(pageDiv);
                }
                
                // Make text editable
                setTimeout(() => {
                    document.querySelectorAll(".pdf-text-layer span").forEach(span => {
                        span.contentEditable = true;
                        span.style.opacity = "1";
                        span.style.color = "inherit";
                    });
                }, 500);
                
            } catch (error) {
                console.error("Error rendering PDF:", error);
                container.innerHTML = "<div class=\"pdf-loading\">Erreur lors du chargement du PDF</div>";
            }
        }
        
        // Start rendering when page loads
        document.addEventListener("DOMContentLoaded", renderPDF);
    </script>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Image-based approach - converts each page to image then overlays text
     */
    private function convertWithImageBasedApproach($pdfPath)
    {
        $tempDir = sys_get_temp_dir() . '/' . uniqid('pdf_');
        mkdir($tempDir, 0755, true);
        
        try {
            // Convert PDF pages to images
            $command = sprintf(
                'pdftocairo -png -r %d %s %s/page 2>&1',
                $this->dpi,
                escapeshellarg($pdfPath),
                escapeshellarg($tempDir)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception('Failed to convert PDF to images');
            }
            
            // Get all generated images
            $images = glob($tempDir . '/page-*.png');
            if (empty($images)) {
                throw new Exception('No images generated from PDF');
            }
            
            // Extract text with coordinates
            $textData = $this->extractTextWithCoordinates($pdfPath);
            
            // Get PDF info
            $pdfInfo = $this->getPDFInfo($pdfPath);
            
            // Build HTML
            $html = $this->buildImageBasedHTML($images, $textData, $pdfInfo);
            
            // Cleanup
            array_map('unlink', $images);
            rmdir($tempDir);
            
            return $html;
            
        } catch (Exception $e) {
            // Cleanup on error
            if (file_exists($tempDir)) {
                array_map('unlink', glob($tempDir . '/*'));
                rmdir($tempDir);
            }
            throw $e;
        }
    }
    
    /**
     * Build HTML from images with text overlay
     */
    private function buildImageBasedHTML($images, $textData, $pdfInfo)
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
            background: #e9ecef;
            padding: 20px;
        }
        
        #pdfContent {
            max-width: ' . $pdfInfo['width'] . 'pt;
            margin: 0 auto;
        }
        
        .pdf-page {
            position: relative;
            background: white;
            margin-bottom: 50px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        /* Visual page separator */
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
            background: #e9ecef;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            color: #666;
            font-weight: 500;
        }
        
        .pdf-page img {
            width: 100%;
            height: auto;
            display: block;
            user-select: none;
            pointer-events: none;
        }
        
        .pdf-text-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
        }
        
        .pdf-text-overlay span {
            position: absolute;
            color: transparent;
            pointer-events: all;
            cursor: text;
            white-space: pre;
        }
        
        .pdf-text-overlay span:hover {
            background: rgba(255, 235, 59, 0.2);
        }
        
        .pdf-text-overlay span[contenteditable="true"] {
            color: black !important;
            background: rgba(255, 255, 255, 0.9);
            padding: 2px;
            border-radius: 2px;
        }
        
        @media print {
            body {
                padding: 0;
                background: white;
            }
            
            .pdf-page {
                box-shadow: none;
                margin: 0 !important;
                page-break-after: always;
                border: none !important;
            }
            
            /* Hide page separators during print/PDF conversion */
            .pdf-page-separator {
                display: none !important;
            }
        }
        
        /* Class to add when exporting to PDF */
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
        foreach ($images as $index => $imagePath) {
            $pageNum = $index + 1;
            $imageData = base64_encode(file_get_contents($imagePath));
            
            // Add separator between pages
            if ($pageNum > 1) {
                $html .= '
        <div class="pdf-page-separator">
            <span class="page-number">Page ' . $pageNum . '</span>
        </div>';
            }
            
            $html .= '
        <div class="pdf-page" data-page="' . $pageNum . '">
            <img src="data:image/png;base64,' . $imageData . '" alt="Page ' . $pageNum . '">
            <div class="pdf-text-overlay">';
            
            // Add text overlay for this page
            if (isset($textData[$pageNum])) {
                foreach ($textData[$pageNum] as $text) {
                    // Calculate position as percentage
                    $leftPercent = ($text['x'] / $pdfInfo['width']) * 100;
                    $topPercent = ($text['y'] / $pdfInfo['height']) * 100;
                    $fontSize = $text['size'] ?? 12;
                    
                    $html .= sprintf(
                        '<span style="left: %.2f%%; top: %.2f%%; font-size: %dpx;" contenteditable="true">%s</span>',
                        $leftPercent,
                        $topPercent,
                        $fontSize,
                        htmlspecialchars($text['text'])
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
        // Make text visible when editing
        document.addEventListener("DOMContentLoaded", function() {
            // Handle print/export events to hide separators
            window.addEventListener("beforeprint", function() {
                document.getElementById("pdfContent").classList.add("pdf-export-mode");
            });
            
            window.addEventListener("afterprint", function() {
                document.getElementById("pdfContent").classList.remove("pdf-export-mode");
            });
            
            const textSpans = document.querySelectorAll(".pdf-text-overlay span");
            
            textSpans.forEach(span => {
                span.addEventListener("focus", function() {
                    this.style.color = "black";
                });
                
                span.addEventListener("blur", function() {
                    if (this.textContent.trim() === "") {
                        this.remove();
                    } else {
                        this.style.color = "transparent";
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
     * Enhanced pdftohtml with corrections
     */
    private function convertWithEnhancedPdfToHtml($pdfPath)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_');
        $htmlFile = $tempFile . '.html';
        
        try {
            // Use pdftohtml with optimal settings
            $command = sprintf(
                'pdftohtml -enc UTF-8 -fmt png -c -s -noframes -zoom 2.0 -fontfullname %s %s 2>&1',
                escapeshellarg($pdfPath),
                escapeshellarg($tempFile)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0 || !file_exists($htmlFile)) {
                throw new Exception('pdftohtml conversion failed');
            }
            
            // Read generated HTML
            $html = file_get_contents($htmlFile);
            
            // Get generated images
            $imageFiles = glob($tempFile . '*.png') ?: [];
            $imageFiles = array_merge($imageFiles, glob($tempFile . '*.jpg') ?: []);
            
            // Embed images as base64
            foreach ($imageFiles as $imageFile) {
                $imageName = basename($imageFile);
                $imageData = base64_encode(file_get_contents($imageFile));
                $mimeType = mime_content_type($imageFile);
                
                $html = str_replace(
                    $imageName,
                    "data:$mimeType;base64,$imageData",
                    $html
                );
            }
            
            // Enhance HTML structure
            $html = $this->enhanceGeneratedHTML($html);
            
            // Cleanup
            unlink($htmlFile);
            @unlink($tempFile);
            foreach ($imageFiles as $imageFile) {
                unlink($imageFile);
            }
            
            return $html;
            
        } catch (Exception $e) {
            // Cleanup on error
            @unlink($htmlFile);
            @unlink($tempFile);
            throw $e;
        }
    }
    
    /**
     * Enhance generated HTML for better editing
     */
    private function enhanceGeneratedHTML($html)
    {
        // Load into DOM for manipulation
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        // Add viewport meta
        $head = $dom->getElementsByTagName('head')->item(0);
        if ($head) {
            $viewport = $dom->createElement('meta');
            $viewport->setAttribute('name', 'viewport');
            $viewport->setAttribute('content', 'width=device-width, initial-scale=1.0');
            $head->appendChild($viewport);
        }
        
        // Find all text elements and make them editable
        $xpath = new \DOMXPath($dom);
        $textElements = $xpath->query('//p | //span | //div[not(*)]');
        
        foreach ($textElements as $element) {
            if (trim($element->textContent) !== '') {
                $element->setAttribute('contenteditable', 'true');
                
                // Add hover effect
                $style = $element->getAttribute('style') ?: '';
                $element->setAttribute('data-original-style', $style);
            }
        }
        
        // Add enhanced CSS
        $style = $dom->createElement('style');
        $css = '
            body {
                background: #f5f5f5;
                padding: 20px;
                margin: 0;
            }
            
            #pdfContent {
                background: white;
                box-shadow: 0 2px 20px rgba(0,0,0,0.1);
                margin: 0 auto;
                padding: 20px;
                border-radius: 8px;
            }
            
            [contenteditable="true"] {
                outline: none;
                transition: all 0.3s;
                cursor: text;
                min-height: 1em;
            }
            
            [contenteditable="true"]:hover {
                background: rgba(255, 235, 59, 0.1);
                box-shadow: 0 0 0 2px rgba(255, 235, 59, 0.3);
            }
            
            [contenteditable="true"]:focus {
                background: rgba(255, 235, 59, 0.2);
                box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.3);
            }
            
            img {
                max-width: 100%;
                height: auto;
            }
            
            /* Fix overlapping elements */
            p, span, div {
                position: relative;
            }
            
            /* Ensure proper layering */
            img[style*="z-index:-1"],
            img[style*="z-index: -1"] {
                position: absolute !important;
                z-index: 0 !important;
            }
        ';
        $style->appendChild($dom->createTextNode($css));
        
        if ($head) {
            $head->appendChild($style);
        }
        
        // Wrap content in container if not already
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body && !$dom->getElementById('pdfContent')) {
            $container = $dom->createElement('div');
            $container->setAttribute('id', 'pdfContent');
            
            // Move all body children to container
            while ($body->firstChild) {
                $container->appendChild($body->firstChild);
            }
            
            $body->appendChild($container);
        }
        
        // Add JavaScript for better interaction
        $script = $dom->createElement('script');
        $js = '
            document.addEventListener("DOMContentLoaded", function() {
                // Track changes
                let hasChanges = false;
                
                document.querySelectorAll("[contenteditable=true]").forEach(elem => {
                    elem.addEventListener("input", function() {
                        hasChanges = true;
                        this.style.backgroundColor = "rgba(76, 175, 80, 0.1)";
                    });
                    
                    elem.addEventListener("blur", function() {
                        if (this.textContent.trim() === "") {
                            this.style.display = "none";
                        }
                    });
                });
                
                // Warn before leaving if changes
                window.addEventListener("beforeunload", function(e) {
                    if (hasChanges) {
                        e.preventDefault();
                        e.returnValue = "";
                    }
                });
            });
        ';
        $script->appendChild($dom->createTextNode($js));
        $body->appendChild($script);
        
        return $dom->saveHTML();
    }
    
    /**
     * Extract text with coordinates from PDF
     */
    private function extractTextWithCoordinates($pdfPath)
    {
        $command = sprintf(
            'pdftotext -layout -bbox-layout %s - 2>&1',
            escapeshellarg($pdfPath)
        );
        
        exec($command, $output);
        
        $textData = [];
        $currentPage = 1;
        
        // Parse XML output
        $xml = implode("\n", $output);
        if (strpos($xml, '<page') !== false) {
            $dom = new \DOMDocument();
            @$dom->loadXML($xml);
            
            $pages = $dom->getElementsByTagName('page');
            foreach ($pages as $page) {
                $pageNum = $page->getAttribute('number') ?: $currentPage;
                $textData[$pageNum] = [];
                
                $words = $page->getElementsByTagName('word');
                foreach ($words as $word) {
                    $textData[$pageNum][] = [
                        'text' => $word->textContent,
                        'x' => floatval($word->getAttribute('xMin')),
                        'y' => floatval($word->getAttribute('yMin')),
                        'width' => floatval($word->getAttribute('xMax')) - floatval($word->getAttribute('xMin')),
                        'height' => floatval($word->getAttribute('yMax')) - floatval($word->getAttribute('yMin')),
                        'size' => round(floatval($word->getAttribute('yMax')) - floatval($word->getAttribute('yMin')))
                    ];
                }
                
                $currentPage++;
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
            'keywords' => ''
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
            } elseif (preg_match('/Keywords:\s+(.+)/', $line, $matches)) {
                $info['keywords'] = trim($matches[1]);
            }
        }
        
        return $info;
    }
}