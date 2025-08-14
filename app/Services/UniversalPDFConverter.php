<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use DOMDocument;
use DOMXPath;

class UniversalPDFConverter
{
    private $dpi = 300; // High quality for image extraction
    private $debug = true;
    
    /**
     * UNIVERSAL PDF TO HTML CONVERTER - THE ONLY ONE WE USE
     * Uses pdftohtml for accurate positioning and formatting
     */
    public function convertToHTML($pdfPath, $options = [])
    {
        try {
            Log::info('Starting UNIVERSAL PDF conversion with pdftohtml', ['path' => $pdfPath]);
            
            $outputDir = sys_get_temp_dir() . '/pdf_' . uniqid();
            if (!mkdir($outputDir, 0777, true)) {
                throw new Exception("Cannot create temporary directory");
            }
            
            // Use pdftohtml for accurate conversion
            $outputFile = $outputDir . '/output';
            $command = sprintf(
                'pdftohtml -c -noframes -zoom 1.3 -fmt png %s %s 2>&1',
                escapeshellarg($pdfPath),
                escapeshellarg($outputFile)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                Log::error('pdftohtml failed', ['output' => $output]);
                throw new Exception('pdftohtml conversion failed');
            }
            
            // Read the generated HTML
            $htmlFile = $outputFile . '.html';
            if (!file_exists($htmlFile)) {
                throw new Exception('HTML output file not found');
            }
            
            $html = file_get_contents($htmlFile);
            
            // Process and enhance the HTML
            $html = $this->enhanceHTML($html, $outputDir);
            
            // Clean up temporary files
            $this->cleanupTempFiles($outputDir);
            
            Log::info('Universal PDF conversion successful');
            return $html;
            
        } catch (Exception $e) {
            Log::error('Universal PDF conversion failed: ' . $e->getMessage());
            if (isset($outputDir)) {
                $this->cleanupTempFiles($outputDir);
            }
            throw $e;
        }
    }
    
    /**
     * Enhance the HTML output from pdftohtml
     */
    private function enhanceHTML($html, $outputDir)
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);
        
        // Make all text elements editable and add draggable class
        $textNodes = $xpath->query('//div[contains(@style, "position:absolute")] | //p[contains(@style, "position:absolute")] | //span[contains(@style, "position:absolute")]');
        foreach ($textNodes as $node) {
            if ($node->nodeValue && trim($node->nodeValue) !== '') {
                $node->setAttribute('contenteditable', 'true');
                // Add class without overwriting existing classes
                $existingClass = $node->getAttribute('class');
                $node->setAttribute('class', trim($existingClass . ' draggable-element pdf-text'));
            }
        }
        
        // Process images - convert relative paths to base64 and add draggable class
        $images = $xpath->query('//img');
        foreach ($images as $img) {
            $src = $img->getAttribute('src');
            if ($src && !str_starts_with($src, 'data:')) {
                $imagePath = $outputDir . '/' . basename($src);
                if (file_exists($imagePath)) {
                    $imageData = file_get_contents($imagePath);
                    $mimeType = mime_content_type($imagePath);
                    $base64 = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
                    $img->setAttribute('src', $base64);
                }
            }
            // Add draggable class to all images without overwriting
            $existingClass = $img->getAttribute('class');
            $img->setAttribute('class', trim($existingClass . ' draggable-element pdf-image'));
        }
        
        // Add our custom styles while preserving pdftohtml's positioning
        $head = $xpath->query('//head')->item(0);
        if (!$head) {
            $head = $dom->createElement('head');
            $dom->documentElement->insertBefore($head, $dom->documentElement->firstChild);
        }
        
        // Add enhanced styles
        $style = $dom->createElement('style');
        $style->nodeValue = '
            /* Import Google Fonts for better rendering */
            @import url("https://fonts.googleapis.com/css2?family=Barlow:wght@300;400;500;600;700&family=Merriweather:wght@300;400;700&family=Roboto:wght@300;400;500;700&display=swap");
            
            /* Enhanced styles for editable content */
            body {
                margin: 0;
                padding: 20px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
            }
            
            /* PDF page container with page marker */
            .pdf-page-wrapper {
                position: relative;
                margin: 0 auto 40px;
                max-width: 900px;
            }
            
            /* Page marker - not exported to PDF */
            .page-marker {
                position: absolute;
                top: -35px;
                left: 0;
                background: linear-gradient(135deg, #667eea, #764ba2);
                color: white;
                padding: 5px 15px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
                box-shadow: 0 2px 10px rgba(0,0,0,0.2);
                z-index: 100;
                pointer-events: none;
            }
            
            @media print {
                .page-marker {
                    display: none !important;
                }
            }
            
            #page-container {
                position: relative;
                margin: 0 auto;
                background: white;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                border-radius: 8px;
                overflow: visible;
            }
            
            /* Font fallbacks for PDF fonts */
            @font-face {
                font-family: "AAAAAA+Barlow";
                src: local("Barlow"), local("Barlow Regular");
            }
            
            @font-face {
                font-family: "BAAAAA+Magazine";
                src: local("Magazine"), local("Georgia"), local("serif");
            }
            
            @font-face {
                font-family: "CAAAAA+Merriweather-Light-18pt";
                src: local("Merriweather Light"), local("Merriweather");
                font-weight: 300;
            }
            
            @font-face {
                font-family: "DAAAAA+Merriweather-Light-18pt";
                src: local("Merriweather Light"), local("Merriweather");
                font-weight: 300;
            }
            
            @font-face {
                font-family: "EAAAAA+Merriweather-Light-18pt";
                src: local("Merriweather Light"), local("Merriweather");
                font-weight: 300;
            }
            
            /* Generic font mappings */
            div[style*="font-family:AAAAAA"],
            p[style*="font-family:AAAAAA"],
            span[style*="font-family:AAAAAA"] {
                font-family: "Barlow", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important;
            }
            
            div[style*="font-family:BAAAAA"],
            p[style*="font-family:BAAAAA"],
            span[style*="font-family:BAAAAA"] {
                font-family: Georgia, "Times New Roman", serif !important;
            }
            
            div[style*="Merriweather"],
            p[style*="Merriweather"],
            span[style*="Merriweather"] {
                font-family: "Merriweather", Georgia, serif !important;
            }
            
            /* Draggable elements styling - minimal impact */
            .draggable-element {
                transition: outline 0.2s;
                cursor: move !important;
            }
            
            .draggable-element:hover {
                outline: 2px solid rgba(66, 153, 225, 0.5) !important;
                outline-offset: 2px;
                z-index: 1000;
            }
            
            .draggable-element.dragging {
                outline: 2px solid rgba(66, 153, 225, 0.8) !important;
                z-index: 9999 !important;
                opacity: 0.9;
            }
            
            /* Delete button is styled inline in JavaScript for better control */
            
            /* Make editable elements interactive */
            [contenteditable="true"] {
                outline: none;
                transition: background-color 0.2s, box-shadow 0.2s;
                cursor: text;
                min-width: 1px;
                padding: 1px 2px;
                border-radius: 2px;
            }
            
            [contenteditable="true"]:hover {
                background-color: rgba(66, 153, 225, 0.05);
            }
            
            [contenteditable="true"]:focus {
                background-color: rgba(66, 153, 225, 0.1);
                box-shadow: 0 0 0 1px rgba(66, 153, 225, 0.2);
            }
            
            /* Preserve pdftohtml positioning */
            div[style*="position:absolute"] {
                white-space: nowrap;
            }
            
            /* Image styling - preserve absolute positioning */
            img {
                max-width: none;
                height: auto;
            }
            
            /* Images keep their original display */
            img.draggable-element {
                transition: outline 0.2s;
            }
            
            img.draggable-element:hover {
                outline: 2px solid rgba(66, 153, 225, 0.5);
                outline-offset: 2px;
            }
            
            /* Hide toolbar and instructions for print */
            @media print {
                .toolbar, .instructions, .page-marker, .delete-btn {
                    display: none !important;
                }
                body {
                    background: white !important;
                    padding: 0 !important;
                }
                #page-container {
                    box-shadow: none !important;
                }
            }
        ';
        $head->appendChild($style);
        
        // Add JavaScript for enhanced editing with drag & drop and delete
        $script = $dom->createElement('script');
        $script->nodeValue = '
            document.addEventListener("DOMContentLoaded", function() {
                let selectedElement = null;
                let isDragging = false;
                let dragOffset = { x: 0, y: 0 };
                
                // Make all positioned elements draggable and deletable
                function makeElementInteractive(element) {
                    // Skip if already processed
                    if (element.dataset.interactive === "true") return;
                    element.dataset.interactive = "true";
                    
                    // Add delete button directly to element
                    const deleteBtn = document.createElement("button");
                    deleteBtn.className = "delete-btn";
                    deleteBtn.innerHTML = "×";
                    deleteBtn.style.cssText = `
                        position: absolute !important;
                        top: -12px !important;
                        right: -12px !important;
                        width: 24px !important;
                        height: 24px !important;
                        border-radius: 50% !important;
                        background: #ff4444 !important;
                        color: white !important;
                        border: 2px solid white !important;
                        font-size: 18px !important;
                        line-height: 20px !important;
                        cursor: pointer !important;
                        z-index: 10001 !important;
                        box-shadow: 0 2px 5px rgba(0,0,0,0.3) !important;
                        display: none;
                        align-items: center !important;
                        justify-content: center !important;
                        font-weight: bold !important;
                        padding: 0 !important;
                        margin: 0 !important;
                        text-align: center !important;
                    `;
                    
                    deleteBtn.onclick = function(e) {
                        e.stopPropagation();
                        e.preventDefault();
                        if (confirm("Supprimer cet élément ?")) {
                            element.remove();
                            // Trigger save
                            window.dispatchEvent(new CustomEvent("pdf-content-changed", {
                                detail: { content: document.body.innerHTML }
                            }));
                        }
                    };
                    
                    // For absolutely positioned elements, append delete button directly
                    // For others, ensure relative positioning
                    const currentPosition = window.getComputedStyle(element).position;
                    if (currentPosition !== "absolute" && currentPosition !== "fixed") {
                        element.style.position = "relative";
                    }
                    element.appendChild(deleteBtn);
                    
                    // Show/hide delete button on hover
                    element.addEventListener("mouseenter", function() {
                        deleteBtn.style.display = "flex";
                    });
                    
                    element.addEventListener("mouseleave", function() {
                        if (!element.classList.contains("selected")) {
                            deleteBtn.style.display = "none";
                        }
                    });
                    
                    // Mouse events for dragging
                    element.addEventListener("mousedown", function(e) {
                        if (e.button !== 0) return; // Only left click
                        if (e.target === deleteBtn) return; // Do not drag when clicking delete
                        
                        // For text elements, only drag if Alt key is pressed
                        if (element.contentEditable === "true" && !e.altKey) return;
                        
                        // Prevent text selection while dragging
                        e.preventDefault();
                        
                        selectedElement = element;
                        isDragging = true;
                        element.classList.add("dragging");
                        element.classList.add("selected");
                        
                        // Calculate offset from mouse to element position
                        const rect = element.getBoundingClientRect();
                        dragOffset.x = e.clientX - rect.left;
                        dragOffset.y = e.clientY - rect.top;
                    });
                }
                
                // Global mouse move for dragging
                document.addEventListener("mousemove", function(e) {
                    if (!isDragging || !selectedElement) return;
                    
                    // Calculate new position
                    const parentRect = selectedElement.parentElement.getBoundingClientRect();
                    const newX = e.clientX - parentRect.left - dragOffset.x;
                    const newY = e.clientY - parentRect.top - dragOffset.y;
                    
                    // Update position
                    selectedElement.style.left = newX + "px";
                    selectedElement.style.top = newY + "px";
                });
                
                // Global mouse up to stop dragging
                document.addEventListener("mouseup", function() {
                    if (selectedElement) {
                        selectedElement.classList.remove("dragging");
                        // Keep selected class for delete button visibility
                        setTimeout(() => {
                            if (selectedElement) {
                                selectedElement.classList.remove("selected");
                            }
                        }, 3000);
                    }
                    isDragging = false;
                    selectedElement = null;
                });
                
                // Add page markers to pages
                function addPageMarkers() {
                    const pages = document.querySelectorAll("#page-container, .pdf-page, div[id*=\'page\']");
                    pages.forEach((page, index) => {
                        const wrapper = document.createElement("div");
                        wrapper.className = "pdf-page-wrapper";
                        page.parentNode.insertBefore(wrapper, page);
                        wrapper.appendChild(page);
                        
                        const marker = document.createElement("div");
                        marker.className = "page-marker";
                        marker.textContent = "Page " + (index + 1);
                        wrapper.appendChild(marker);
                    });
                }
                
                // Apply to all draggable elements after a short delay to ensure DOM is ready
                setTimeout(function() {
                    document.querySelectorAll(".draggable-element").forEach(makeElementInteractive);
                    console.log("Made " + document.querySelectorAll(".draggable-element").length + " elements interactive");
                }, 100);
                
                // Add page markers
                addPageMarkers();
                
                // Special handling for images
                document.querySelectorAll("img.draggable-element").forEach(function(img) {
                    // Add resize on double-click
                    img.addEventListener("dblclick", function(e) {
                        e.preventDefault();
                        const currentWidth = img.offsetWidth;
                        const newWidth = prompt("Nouvelle largeur (px):", currentWidth);
                        if (newWidth && !isNaN(newWidth)) {
                            img.style.width = newWidth + "px";
                            img.style.height = "auto";
                        }
                    });
                });
                
                // Auto-save functionality for text
                let saveTimeout;
                document.querySelectorAll("[contenteditable=true]").forEach(function(element) {
                    
                    element.addEventListener("input", function() {
                        clearTimeout(saveTimeout);
                        saveTimeout = setTimeout(function() {
                            console.log("Auto-saving...");
                            window.dispatchEvent(new CustomEvent("pdf-content-changed", {
                                detail: { content: document.body.innerHTML }
                            }));
                        }, 1000);
                    });
                    
                    // Prevent formatting loss on paste
                    element.addEventListener("paste", function(e) {
                        e.preventDefault();
                        const text = (e.clipboardData || window.clipboardData).getData("text");
                        document.execCommand("insertText", false, text);
                    });
                });
                
                // Enhanced toolbar with new features
                const toolbar = document.createElement("div");
                toolbar.style.cssText = "position: fixed; top: 20px; right: 20px; background: white; padding: 10px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 1000;";
                toolbar.innerHTML = `
                    <button onclick="document.execCommand(\'bold\')" title="Gras" style="margin: 0 5px; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; font-weight: bold;">B</button>
                    <button onclick="document.execCommand(\'italic\')" title="Italique" style="margin: 0 5px; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; font-style: italic;">I</button>
                    <button onclick="document.execCommand(\'underline\')" title="Souligné" style="margin: 0 5px; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; text-decoration: underline;">U</button>
                    <button onclick="window.print()" title="Imprimer" style="margin: 0 5px; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">🖨️</button>
                    <button onclick="location.reload()" title="Réinitialiser" style="margin: 0 5px; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">↻</button>
                `;
                document.body.appendChild(toolbar);
                
                // Instructions overlay
                const instructions = document.createElement("div");
                instructions.style.cssText = "position: fixed; bottom: 20px; left: 20px; background: rgba(0,0,0,0.8); color: white; padding: 15px; border-radius: 8px; font-size: 12px; max-width: 300px; z-index: 1000;";
                instructions.innerHTML = `
                    <h4 style="margin: 0 0 10px 0;">Instructions :</h4>
                    <ul style="margin: 0; padding-left: 20px;">
                        <li><strong>Déplacer:</strong> Glissez les éléments (Alt+Glisser pour le texte)</li>
                        <li><strong>Supprimer:</strong> Survolez et cliquez sur le bouton ×</li>
                        <li><strong>Redimensionner:</strong> Double-cliquez sur les images</li>
                        <li><strong>Éditer:</strong> Cliquez directement sur le texte</li>
                    </ul>
                    <button onclick="this.parentElement.remove()" style="margin-top: 10px; padding: 5px 10px; border: none; background: white; color: black; border-radius: 4px; cursor: pointer;">Fermer</button>
                `;
                document.body.appendChild(instructions);
            });
        ';
        $head->appendChild($script);
        
        return $dom->saveHTML();
    }
    
    /**
     * Clean up temporary files
     */
    private function cleanupTempFiles($dir)
    {
        if (!is_dir($dir)) return;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->cleanupTempFiles($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
    
    /**
     * Fallback method using Python extractor if pdftohtml fails
     */
    public function convertToHTMLFallback($pdfPath, $options = [])
    {
        try {
            Log::info('Using fallback Python extractor', ['path' => $pdfPath]);
            
            // Use Python script for extraction
            $scriptPath = resource_path('scripts/python/universal_pdf_extractor.py');
            $command = sprintf(
                'cd %s && python3 %s %s 2>&1',
                escapeshellarg(dirname($scriptPath)),
                escapeshellarg($scriptPath),
                escapeshellarg($pdfPath)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                Log::error('Python extraction failed', ['output' => $output]);
                throw new Exception('Python extraction failed');
            }
            
            $jsonOutput = implode("\n", $output);
            $data = json_decode($jsonOutput, true);
            
            if (!$data || json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON from Python script');
            }
            
            return $this->buildHTMLFromExtractedData($data);
            
        } catch (Exception $e) {
            Log::error('Fallback conversion failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Build HTML from extracted data (fallback method)
     */
    private function buildHTMLFromExtractedData($data)
    {
        $html = '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Editor</title>
    <style>
        body {
            margin: 0;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .pdf-page {
            position: relative;
            margin: 0 auto 40px;
            background: white;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            border-radius: 8px;
            overflow: hidden;
        }
        .pdf-content {
            position: relative;
            width: 100%;
            height: 100%;
        }
        .pdf-text {
            position: absolute;
            cursor: text;
            outline: none;
            padding: 2px;
            border-radius: 3px;
            transition: background-color 0.2s;
        }
        .pdf-text:hover {
            background-color: rgba(66, 153, 225, 0.1);
        }
        .pdf-text:focus {
            background-color: rgba(66, 153, 225, 0.2);
        }
        .pdf-image {
            position: absolute;
            max-width: 100%;
            height: auto;
        }
    </style>
</head>
<body>
    <div id="pdfContent">';
        
        // Process each page
        foreach ($data['pages'] as $pageNum => $page) {
            $pageWidth = $page['width'] ?? 595;
            $pageHeight = $page['height'] ?? 842;
            
            $html .= sprintf(
                '<div class="pdf-page" style="width: %dpx; height: %dpx; margin: 0 auto 40px;">
                    <div class="pdf-content">',
                $pageWidth,
                $pageHeight
            );
            
            // Add images
            if (!empty($page['images'])) {
                foreach ($page['images'] as $image) {
                    $html .= sprintf(
                        '<img class="pdf-image" src="%s" style="left: %.2f%%; top: %.2f%%; width: %.2f%%; position: absolute;" />',
                        $image['data'],
                        ($image['x'] / $pageWidth) * 100,
                        ($image['y'] / $pageHeight) * 100,
                        ($image['width'] / $pageWidth) * 100
                    );
                }
            }
            
            // Add text
            if (!empty($page['text'])) {
                foreach ($page['text'] as $text) {
                    $style = '';
                    if (!empty($text['font_family'])) {
                        $style .= 'font-family: ' . $text['font_family'] . ';';
                    }
                    if (!empty($text['font_size'])) {
                        $style .= 'font-size: ' . $text['font_size'] . 'px;';
                    }
                    if (!empty($text['font_weight'])) {
                        $style .= 'font-weight: ' . $text['font_weight'] . ';';
                    }
                    if (!empty($text['font_style'])) {
                        $style .= 'font-style: ' . $text['font_style'] . ';';
                    }
                    
                    $html .= sprintf(
                        '<div class="pdf-text" contenteditable="true" style="left: %.2f%%; top: %.2f%%; %s position: absolute;">%s</div>',
                        ($text['x'] / $pageWidth) * 100,
                        ($text['y'] / $pageHeight) * 100,
                        $style,
                        htmlspecialchars($text['text'])
                    );
                }
            }
            
            $html .= '</div></div>';
        }
        
        $html .= '</div>
</body>
</html>';
        
        return $html;
    }
}