<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use DOMDocument;
use DOMXPath;

class UltimatePDFConverter
{
    private $dpi = 300; // Ultra high quality
    private $quality = 100;
    private $enableOCR = true;
    private $preserveVectors = true;
    
    /**
     * Convert PDF to ultimate quality HTML with perfect 1:1 rendering
     */
    public function convertToHTML($pdfPath)
    {
        try {
            // Analyze PDF structure first
            $pdfAnalysis = $this->analyzePDF($pdfPath);
            
            // Choose best conversion method based on PDF content
            if ($pdfAnalysis['has_vectors'] || $pdfAnalysis['has_forms']) {
                // Use hybrid approach for complex PDFs
                return $this->hybridConversion($pdfPath, $pdfAnalysis);
            } else {
                // Use ultra-high quality image conversion for simple PDFs
                return $this->ultraHighQualityConversion($pdfPath, $pdfAnalysis);
            }
            
        } catch (Exception $e) {
            Log::error('Ultimate PDF conversion failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Analyze PDF structure to determine best conversion approach
     */
    private function analyzePDF($pdfPath)
    {
        $analysis = [
            'pages' => 1,
            'width' => 595,
            'height' => 842,
            'has_text' => false,
            'has_images' => false,
            'has_vectors' => false,
            'has_forms' => false,
            'is_scanned' => false,
            'fonts' => [],
            'title' => '',
            'author' => ''
        ];
        
        // Get basic info
        $command = sprintf('pdfinfo %s 2>&1', escapeshellarg($pdfPath));
        exec($command, $output);
        
        foreach ($output as $line) {
            if (preg_match('/Pages:\s+(\d+)/', $line, $matches)) {
                $analysis['pages'] = intval($matches[1]);
            } elseif (preg_match('/Page size:\s+([0-9.]+)\s+x\s+([0-9.]+)/', $line, $matches)) {
                $analysis['width'] = floatval($matches[1]);
                $analysis['height'] = floatval($matches[2]);
            } elseif (preg_match('/Title:\s+(.+)/', $line, $matches)) {
                $analysis['title'] = trim($matches[1]);
            } elseif (preg_match('/Form:\s+(.+)/', $line, $matches)) {
                $analysis['has_forms'] = (trim($matches[1]) !== 'none');
            }
        }
        
        // Check for text
        $command = sprintf('pdftotext %s - 2>&1 | head -100', escapeshellarg($pdfPath));
        exec($command, $textOutput);
        $analysis['has_text'] = !empty(trim(implode('', $textOutput)));
        
        // Check for images
        $command = sprintf('pdfimages -list %s 2>&1 | grep -c "image"', escapeshellarg($pdfPath));
        exec($command, $imageOutput);
        $analysis['has_images'] = intval($imageOutput[0] ?? 0) > 0;
        
        // Check fonts
        $command = sprintf('pdffonts %s 2>&1', escapeshellarg($pdfPath));
        exec($command, $fontOutput);
        foreach ($fontOutput as $line) {
            if (preg_match('/^[A-Z]+\+(.+?)\s+/', $line, $matches)) {
                $analysis['fonts'][] = $matches[1];
            }
        }
        
        // Detect if scanned (has images but no selectable text)
        $analysis['is_scanned'] = $analysis['has_images'] && !$analysis['has_text'];
        
        return $analysis;
    }
    
    /**
     * Hybrid conversion for complex PDFs with vectors and forms
     */
    private function hybridConversion($pdfPath, $analysis)
    {
        $tempDir = sys_get_temp_dir() . '/' . uniqid('pdf_ultimate_');
        mkdir($tempDir, 0755, true);
        
        try {
            // Step 1: Extract vector graphics as SVG
            $svgElements = $this->extractVectorGraphics($pdfPath, $tempDir);
            
            // Step 2: Extract high-res background images
            $images = $this->extractHighResImages($pdfPath, $tempDir);
            
            // Step 3: Extract precise text with styling
            $textData = $this->extractStyledText($pdfPath);
            
            // Step 4: Extract form fields if present
            $formData = $analysis['has_forms'] ? $this->extractFormFields($pdfPath) : [];
            
            // Step 5: Build ultimate HTML combining all elements
            $html = $this->buildUltimateHTML($images, $textData, $svgElements, $formData, $analysis);
            
            // Cleanup
            $this->cleanupTempDir($tempDir);
            
            return $html;
            
        } catch (Exception $e) {
            $this->cleanupTempDir($tempDir);
            throw $e;
        }
    }
    
    /**
     * Ultra high quality conversion for maximum visual fidelity
     */
    private function ultraHighQualityConversion($pdfPath, $analysis)
    {
        $tempDir = sys_get_temp_dir() . '/' . uniqid('pdf_uhq_');
        mkdir($tempDir, 0755, true);
        
        try {
            // Convert at ultra high resolution
            $command = sprintf(
                'pdftocairo -png -r %d -cropbox %s %s/page 2>&1',
                $this->dpi,
                escapeshellarg($pdfPath),
                escapeshellarg($tempDir)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                // Try alternative with GraphicsMagick for better quality
                $command = sprintf(
                    'gm convert -density %d -quality %d %s +adjoin %s/page-%%03d.png 2>&1',
                    $this->dpi,
                    $this->quality,
                    escapeshellarg($pdfPath),
                    escapeshellarg($tempDir)
                );
                exec($command);
            }
            
            // Get generated images
            $images = glob($tempDir . '/*.png');
            if (empty($images)) {
                throw new Exception('No images generated from PDF');
            }
            
            // Sort by page number
            natsort($images);
            $images = array_values($images);
            
            // Extract text with precise positioning and styling
            $textData = $this->extractStyledText($pdfPath);
            
            // Build HTML
            $html = $this->buildUltraHighQualityHTML($images, $textData, $analysis);
            
            // Cleanup
            $this->cleanupTempDir($tempDir);
            
            return $html;
            
        } catch (Exception $e) {
            $this->cleanupTempDir($tempDir);
            throw $e;
        }
    }
    
    /**
     * Build ultimate HTML with all elements perfectly positioned
     */
    private function buildUltimateHTML($images, $textData, $svgElements, $formData, $analysis)
    {
        $html = '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($analysis['title'] ?: 'PDF Document') . '</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
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
            transition: transform 0.3s ease;
            border: 1px solid #e0e0e0;
        }
        
        /* Page separator */
        .pdf-page::after {
            content: "";
            position: absolute;
            bottom: -30px;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 2px;
            background: linear-gradient(to right, transparent, #ccc, transparent);
            z-index: 1;
        }
        
        .pdf-page:last-child::after {
            display: none;
        }
        
        /* Page number indicator */
        .pdf-page::before {
            content: attr(data-page-label);
            position: absolute;
            top: -25px;
            right: 10px;
            font-size: 12px;
            color: #666;
            font-weight: 500;
            background: white;
            padding: 2px 8px;
            border-radius: 3px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 2;
        }
        
        .pdf-page:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 50px rgba(0,0,0,0.3);
        }
        
        /* Background layer */
        .pdf-background-layer {
            position: relative;
            width: 100%;
            height: auto;
            user-select: none;
        }
        
        .pdf-background-layer img {
            width: 100%;
            height: auto;
            display: block;
            -webkit-user-drag: none;
            user-drag: none;
        }
        
        /* Vector graphics layer */
        .pdf-vector-layer {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 2;
        }
        
        .pdf-vector-layer svg {
            width: 100%;
            height: 100%;
        }
        
        /* Text layer */
        .pdf-text-layer {
            position: absolute;
            left: 0;
            top: 0;
            right: 0;
            bottom: 0;
            overflow: hidden;
            line-height: 1;
            z-index: 3;
        }
        
        .pdf-text {
            position: absolute;
            color: transparent;
            white-space: pre-wrap;
            cursor: text;
            transform-origin: 0% 0%;
            transition: all 0.2s ease;
        }
        
        /* Text selection and editing */
        .pdf-text::selection {
            background: rgba(0, 123, 255, 0.3);
            color: inherit;
        }
        
        .pdf-text::-moz-selection {
            background: rgba(0, 123, 255, 0.3);
            color: inherit;
        }
        
        .pdf-text[contenteditable="true"]:hover {
            background: rgba(255, 235, 59, 0.15);
            border-radius: 2px;
            padding: 2px;
        }
        
        .pdf-text[contenteditable="true"]:focus {
            color: inherit !important;
            background: rgba(255, 255, 255, 0.98);
            padding: 4px 6px;
            border-radius: 3px;
            outline: 2px solid #007bff;
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.25);
            z-index: 100;
            opacity: 1 !important;
        }
        
        /* Form fields layer */
        .pdf-form-layer {
            position: absolute;
            left: 0;
            top: 0;
            right: 0;
            bottom: 0;
            z-index: 4;
        }
        
        .pdf-form-field {
            position: absolute;
            border: 1px solid #ccc;
            background: rgba(255, 255, 255, 0.9);
            padding: 4px;
            font-size: 12px;
            font-family: inherit;
            transition: all 0.2s ease;
        }
        
        .pdf-form-field:hover {
            border-color: #007bff;
            background: white;
        }
        
        .pdf-form-field:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25);
            background: white;
            z-index: 101;
        }
        
        /* Annotations layer */
        .pdf-annotation-layer {
            position: absolute;
            left: 0;
            top: 0;
            right: 0;
            bottom: 0;
            pointer-events: none;
            z-index: 5;
        }
        
        .pdf-annotation {
            position: absolute;
            pointer-events: all;
        }
        
        .pdf-highlight {
            background: rgba(255, 235, 59, 0.4);
            mix-blend-mode: multiply;
        }
        
        .pdf-note {
            width: 24px;
            height: 24px;
            background: #ffd54f;
            border: 2px solid #f9a825;
            border-radius: 4px;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        
        .pdf-note:hover {
            transform: scale(1.2);
        }
        
        /* Loading animation */
        .pdf-loading {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 400px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        
        .pdf-loading::after {
            content: "";
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive design */
        @media (max-width: 920px) {
            body {
                padding: 10px;
            }
            
            #pdfContent {
                max-width: 100%;
            }
            
            .pdf-page {
                border-radius: 0;
                margin-bottom: 5px;
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
                margin: 0;
                page-break-after: always;
                page-break-inside: avoid;
                border-radius: 0;
                border: none;
            }
            
            /* Hide page separators and page numbers during print/PDF conversion */
            .pdf-page::after,
            .pdf-page::before {
                display: none !important;
            }
            
            .pdf-page:hover {
                transform: none;
            }
            
            .pdf-text[contenteditable="true"]:hover,
            .pdf-text[contenteditable="true"]:focus {
                background: transparent;
                outline: none;
                box-shadow: none;
                padding: 0;
            }
        }
        
        /* Special class for PDF export mode */
        .pdf-export-mode .pdf-page::after,
        .pdf-export-mode .pdf-page::before {
            display: none !important;
        }
        
        .pdf-export-mode .pdf-page {
            margin-bottom: 0 !important;
            border: none !important;
            box-shadow: none !important;
        }
        
        /* Custom fonts from PDF */';
        
        // Add font faces if available
        foreach ($analysis['fonts'] as $font) {
            $html .= '
        @font-face {
            font-family: "' . $font . '";
            src: local("' . $font . '");
        }';
        }
        
        $html .= '
    </style>
</head>
<body>
    <div id="pdfContent" class="pdf-content">';
        
        // Process each page
        foreach ($images as $index => $imagePath) {
            $pageNum = $index + 1;
            
            // Get image dimensions
            list($imgWidth, $imgHeight) = getimagesize($imagePath);
            $aspectRatio = $imgHeight / $imgWidth;
            
            // Convert image to base64
            $imageData = base64_encode(file_get_contents($imagePath));
            
            $html .= '
        <div class="pdf-page" data-page="' . $pageNum . '" data-page-label="Page ' . $pageNum . '" data-width="' . $imgWidth . '" data-height="' . $imgHeight . '" style="padding-bottom: ' . ($aspectRatio * 100) . '%;">
            <div class="pdf-background-layer">
                <img src="data:image/png;base64,' . $imageData . '" alt="Page ' . $pageNum . '" loading="lazy">
            </div>';
            
            // Add vector graphics if available
            if (isset($svgElements[$pageNum])) {
                $html .= '
            <div class="pdf-vector-layer">' . $svgElements[$pageNum] . '</div>';
            }
            
            // Add text layer
            $html .= '
            <div class="pdf-text-layer">';
            
            if (isset($textData[$pageNum])) {
                foreach ($textData[$pageNum] as $text) {
                    $html .= $this->renderTextElement($text, $analysis);
                }
            }
            
            $html .= '
            </div>';
            
            // Add form fields if present
            if (isset($formData[$pageNum])) {
                $html .= '
            <div class="pdf-form-layer">';
                
                foreach ($formData[$pageNum] as $field) {
                    $html .= $this->renderFormField($field);
                }
                
                $html .= '
            </div>';
            }
            
            $html .= '
        </div>';
        }
        
        $html .= '
    </div>
    
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const pages = document.querySelectorAll(".pdf-page");
            const textElements = document.querySelectorAll(".pdf-text");
            const formFields = document.querySelectorAll(".pdf-form-field");
            
            // Initialize text editing
            textElements.forEach(element => {
                let originalText = element.textContent;
                let originalStyle = element.getAttribute("style");
                
                element.addEventListener("focus", function() {
                    this.style.opacity = "1";
                    this.style.color = this.getAttribute("data-color") || "black";
                });
                
                element.addEventListener("blur", function() {
                    if (this.textContent !== originalText) {
                        this.setAttribute("data-modified", "true");
                        this.style.background = "rgba(255, 235, 59, 0.2)";
                    } else {
                        this.style.opacity = "0";
                        this.style.color = "transparent";
                    }
                });
                
                element.addEventListener("input", function() {
                    this.setAttribute("data-modified", "true");
                    // Auto-resize based on content
                    this.style.width = "auto";
                    this.style.height = "auto";
                });
            });
            
            // Initialize form fields
            formFields.forEach(field => {
                field.addEventListener("change", function() {
                    this.setAttribute("data-modified", "true");
                });
            });
            
            // Responsive text sizing
            function adjustTextSizes() {
                pages.forEach(page => {
                    const pageWidth = page.offsetWidth;
                    const originalWidth = parseInt(page.getAttribute("data-width"));
                    const scale = pageWidth / originalWidth;
                    
                    page.querySelectorAll(".pdf-text").forEach(text => {
                        const originalFontSize = parseFloat(text.getAttribute("data-font-size"));
                        text.style.fontSize = (originalFontSize * scale) + "px";
                    });
                    
                    page.querySelectorAll(".pdf-form-field").forEach(field => {
                        const originalFontSize = parseFloat(field.getAttribute("data-font-size") || 12);
                        field.style.fontSize = (originalFontSize * scale) + "px";
                    });
                });
            }
            
            // Adjust sizes on load and resize
            adjustTextSizes();
            let resizeTimeout;
            window.addEventListener("resize", function() {
                clearTimeout(resizeTimeout);
                resizeTimeout = setTimeout(adjustTextSizes, 100);
            });
            
            // Enable text selection across invisible text
            let isSelecting = false;
            
            document.addEventListener("mousedown", function(e) {
                if (e.target.classList.contains("pdf-text")) {
                    isSelecting = true;
                }
            });
            
            document.addEventListener("mouseup", function() {
                if (isSelecting) {
                    const selection = window.getSelection();
                    if (selection.toString().length > 0) {
                        // Make selected text visible temporarily
                        const range = selection.getRangeAt(0);
                        const elements = document.querySelectorAll(".pdf-text");
                        elements.forEach(el => {
                            if (selection.containsNode(el, true)) {
                                el.style.opacity = "1";
                                el.style.color = el.getAttribute("data-color") || "black";
                                el.style.background = "rgba(0, 123, 255, 0.1)";
                                
                                setTimeout(() => {
                                    if (!el.hasAttribute("data-modified")) {
                                        el.style.opacity = "0";
                                        el.style.color = "transparent";
                                        el.style.background = "transparent";
                                    }
                                }, 5000);
                            }
                        });
                    }
                    isSelecting = false;
                }
            });
            
            // Save functionality
            window.savePDFChanges = function() {
                const changes = [];
                
                document.querySelectorAll("[data-modified=true]").forEach(element => {
                    changes.push({
                        type: element.classList.contains("pdf-text") ? "text" : "form",
                        page: element.closest(".pdf-page").getAttribute("data-page"),
                        id: element.id,
                        value: element.textContent || element.value,
                        position: {
                            left: element.style.left,
                            top: element.style.top
                        }
                    });
                });
                
                return changes;
            };
            
            // Export functionality
            window.exportPDF = function() {
                // Add export mode class to hide visual separators
                document.getElementById("pdfContent").classList.add("pdf-export-mode");
                
                // Trigger print or your PDF export logic here
                console.log("Exporting PDF with changes:", window.savePDFChanges());
                
                // After export, remove the class
                setTimeout(() => {
                    document.getElementById("pdfContent").classList.remove("pdf-export-mode");
                }, 1000);
            };
            
            // Detect print events to hide separators
            window.addEventListener("beforeprint", function() {
                document.getElementById("pdfContent").classList.add("pdf-export-mode");
            });
            
            window.addEventListener("afterprint", function() {
                document.getElementById("pdfContent").classList.remove("pdf-export-mode");
            });
        });
    </script>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Build ultra high quality HTML
     */
    private function buildUltraHighQualityHTML($images, $textData, $analysis)
    {
        // Similar to buildUltimateHTML but simplified for non-vector PDFs
        return $this->buildUltimateHTML($images, $textData, [], [], $analysis);
    }
    
    /**
     * Render a text element with all its properties
     */
    private function renderTextElement($text, $analysis)
    {
        $leftPercent = ($text['x'] / $analysis['width']) * 100;
        $topPercent = ($text['y'] / $analysis['height']) * 100;
        $fontSize = $text['size'] ?? 12;
        $fontFamily = $text['font'] ?? 'inherit';
        $color = $text['color'] ?? '#000000';
        $fontWeight = $text['bold'] ?? false ? 'bold' : 'normal';
        $fontStyle = $text['italic'] ?? false ? 'italic' : 'normal';
        
        return sprintf(
            '<span class="pdf-text" id="text-%s" style="left: %.4f%%; top: %.4f%%; font-size: %dpx; font-family: %s; font-weight: %s; font-style: %s; opacity: 0;" data-font-size="%d" data-color="%s" contenteditable="true">%s</span>',
            uniqid(),
            $leftPercent,
            $topPercent,
            $fontSize,
            $fontFamily,
            $fontWeight,
            $fontStyle,
            $fontSize,
            $color,
            htmlspecialchars($text['text'], ENT_QUOTES)
        );
    }
    
    /**
     * Render a form field
     */
    private function renderFormField($field)
    {
        $type = $field['type'] ?? 'text';
        $leftPercent = $field['x'] ?? 0;
        $topPercent = $field['y'] ?? 0;
        $widthPercent = $field['width'] ?? 10;
        $heightPercent = $field['height'] ?? 2;
        $value = $field['value'] ?? '';
        $name = $field['name'] ?? uniqid('field_');
        
        if ($type === 'checkbox') {
            return sprintf(
                '<input type="checkbox" class="pdf-form-field" name="%s" style="left: %.2f%%; top: %.2f%%;" %s>',
                $name,
                $leftPercent,
                $topPercent,
                $field['checked'] ? 'checked' : ''
            );
        } elseif ($type === 'select') {
            $html = sprintf(
                '<select class="pdf-form-field" name="%s" style="left: %.2f%%; top: %.2f%%; width: %.2f%%;">',
                $name,
                $leftPercent,
                $topPercent,
                $widthPercent
            );
            foreach ($field['options'] ?? [] as $option) {
                $html .= sprintf('<option value="%s">%s</option>', $option, $option);
            }
            $html .= '</select>';
            return $html;
        } else {
            return sprintf(
                '<input type="%s" class="pdf-form-field" name="%s" value="%s" style="left: %.2f%%; top: %.2f%%; width: %.2f%%;">',
                $type,
                $name,
                htmlspecialchars($value),
                $leftPercent,
                $topPercent,
                $widthPercent
            );
        }
    }
    
    /**
     * Extract vector graphics as SVG
     */
    private function extractVectorGraphics($pdfPath, $tempDir)
    {
        $svgElements = [];
        
        // Try to extract SVG using pdf2svg or similar tools
        $command = sprintf(
            'pdf2svg %s %s/page-%%d.svg all 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($tempDir)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            $svgFiles = glob($tempDir . '/page-*.svg');
            foreach ($svgFiles as $svgFile) {
                preg_match('/page-(\d+)\.svg/', $svgFile, $matches);
                $pageNum = intval($matches[1]);
                $svgElements[$pageNum] = file_get_contents($svgFile);
                unlink($svgFile);
            }
        }
        
        return $svgElements;
    }
    
    /**
     * Extract high resolution images
     */
    private function extractHighResImages($pdfPath, $tempDir)
    {
        // Convert at maximum quality
        $command = sprintf(
            'pdftocairo -png -r %d -cropbox %s %s/page 2>&1',
            $this->dpi,
            escapeshellarg($pdfPath),
            escapeshellarg($tempDir)
        );
        
        exec($command, $output, $returnCode);
        
        $images = glob($tempDir . '/page-*.png');
        natsort($images);
        
        return array_values($images);
    }
    
    /**
     * Extract styled text with font information
     */
    private function extractStyledText($pdfPath)
    {
        $textData = [];
        
        // Use pdftohtml to get styled text
        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_text_');
        $command = sprintf(
            'pdftohtml -xml -i -fontfullname %s %s 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($tempFile)
        );
        
        exec($command);
        
        $xmlFile = $tempFile . '.xml';
        if (file_exists($xmlFile)) {
            $xml = simplexml_load_file($xmlFile);
            
            foreach ($xml->page as $page) {
                $pageNum = intval($page['number']);
                $textData[$pageNum] = [];
                
                foreach ($page->text as $text) {
                    $textData[$pageNum][] = [
                        'text' => (string)$text,
                        'x' => floatval($text['left']),
                        'y' => floatval($text['top']),
                        'width' => floatval($text['width']),
                        'height' => floatval($text['height']),
                        'size' => intval($text['font-size'] ?? 12),
                        'font' => (string)($text['font'] ?? 'Arial'),
                        'color' => (string)($text['color'] ?? '#000000'),
                        'bold' => isset($text['font-weight']) && $text['font-weight'] == 'bold',
                        'italic' => isset($text['font-style']) && $text['font-style'] == 'italic'
                    ];
                }
            }
            
            unlink($xmlFile);
        }
        
        @unlink($tempFile);
        
        return $textData;
    }
    
    /**
     * Extract form fields from PDF
     */
    private function extractFormFields($pdfPath)
    {
        $formData = [];
        
        // Use pdftk or similar to extract form field data
        $command = sprintf(
            'pdftk %s dump_data_fields 2>&1',
            escapeshellarg($pdfPath)
        );
        
        exec($command, $output);
        
        // Parse form field data
        $currentField = [];
        foreach ($output as $line) {
            if (strpos($line, 'FieldName:') === 0) {
                if (!empty($currentField)) {
                    $pageNum = $currentField['page'] ?? 1;
                    if (!isset($formData[$pageNum])) {
                        $formData[$pageNum] = [];
                    }
                    $formData[$pageNum][] = $currentField;
                }
                $currentField = ['name' => trim(substr($line, 10))];
            } elseif (strpos($line, 'FieldType:') === 0) {
                $currentField['type'] = strtolower(trim(substr($line, 10)));
            } elseif (strpos($line, 'FieldValue:') === 0) {
                $currentField['value'] = trim(substr($line, 11));
            }
        }
        
        if (!empty($currentField)) {
            $pageNum = $currentField['page'] ?? 1;
            if (!isset($formData[$pageNum])) {
                $formData[$pageNum] = [];
            }
            $formData[$pageNum][] = $currentField;
        }
        
        return $formData;
    }
    
    /**
     * Cleanup temporary directory
     */
    private function cleanupTempDir($tempDir)
    {
        if (file_exists($tempDir)) {
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($tempDir);
        }
    }
}