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
            
            // First, get the number of pages in the PDF
            $pageCountCommand = sprintf('pdfinfo %s 2>&1 | grep "Pages:" | awk \'{print $2}\'', escapeshellarg($pdfPath));
            $pageCountOutput = shell_exec($pageCountCommand);
            $pageCount = intval($pageCountOutput ? trim($pageCountOutput) : 0);
            
            if ($pageCount == 0) {
                $pageCount = 1; // Default to 1 if detection fails
            }
            
            Log::info('PDF has ' . $pageCount . ' pages');
            
            // Try to extract pages individually for better control
            $pageFiles = [];
            $extractionSuccess = true;
            
            for ($i = 1; $i <= $pageCount; $i++) {
                $pageFile = $outputDir . '/page' . $i;
                
                // Extract individual page using -f (first) and -l (last) options
                $command = sprintf(
                    'pdftohtml -f %d -l %d -c -noframes -zoom 1.3 -fmt png -nomerge -enc UTF-8 %s %s 2>&1',
                    $i, $i,
                    escapeshellarg($pdfPath),
                    escapeshellarg($pageFile)
                );
                
                exec($command, $output, $returnCode);
                
                if ($returnCode === 0 && file_exists($pageFile . '.html')) {
                    $pageFiles[$i] = $pageFile . '.html';
                    Log::info('Extracted page ' . $i);
                } else {
                    Log::warning('Failed to extract page ' . $i);
                    $extractionSuccess = false;
                    break;
                }
            }
            
            if ($extractionSuccess && count($pageFiles) > 0) {
                // Successfully extracted individual pages, merge them with markers
                $mergedFile = $this->mergePagesFromFiles($pageFiles, $outputDir);
                $html = file_get_contents($mergedFile);
                // Don't call enhanceHTML here since mergePagesFromFiles already handles everything
            } else {
                // Fallback to single file mode
                Log::info('Using single file mode as fallback');
                
                $command = sprintf(
                    'pdftohtml -c -noframes -zoom 1.3 -fmt png -nomerge -enc UTF-8 %s %s 2>&1',
                    escapeshellarg($pdfPath),
                    escapeshellarg($outputDir . '/output')
                );
                
                exec($command, $output, $returnCode);
                
                if ($returnCode !== 0) {
                    Log::error('pdftohtml failed', ['output' => $output]);
                    throw new Exception('pdftohtml conversion failed');
                }
                
                $htmlFile = $outputDir . '/output.html';
                if (!file_exists($htmlFile)) {
                    throw new Exception('HTML output file not found');
                }
                
                $html = file_get_contents($htmlFile);
                
                // Only enhance HTML for fallback mode
                $html = $this->enhanceHTML($html, $outputDir);
            }
            
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
        
        // Detect and wrap pages with containers and markers
        $this->wrapPagesWithMarkers($dom, $xpath);
        
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
        
        // Process images - convert relative paths to base64 and ensure individual positioning
        $images = $xpath->query('//img');
        foreach ($images as $img) {
            $src = $img->getAttribute('src');
            
            // Convert relative image paths to base64
            if ($src && !str_starts_with($src, 'data:')) {
                $imagePath = $outputDir . '/' . basename($src);
                if (file_exists($imagePath)) {
                    $imageData = file_get_contents($imagePath);
                    $mimeType = mime_content_type($imagePath);
                    $base64 = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
                    $img->setAttribute('src', $base64);
                }
            }
            
            // Ensure each image has absolute positioning
            $style = $img->getAttribute('style');
            if (!str_contains($style, 'position:')) {
                // Add absolute positioning if not present
                $style = 'position:absolute; ' . $style;
                $img->setAttribute('style', $style);
            }
            
            // Parse and ensure individual position values
            if (!str_contains($style, 'left:')) {
                // Try to extract position from parent or default
                $parent = $img->parentNode;
                $parentStyle = $parent ? $parent->getAttribute('style') : '';
                if (preg_match('/left:\s*(\d+\.?\d*)(px|%)/', $parentStyle, $matches)) {
                    $style .= '; left:' . $matches[1] . $matches[2];
                } else {
                    $style .= '; left:0px';
                }
            }
            
            if (!str_contains($style, 'top:')) {
                // Try to extract position from parent or default
                $parent = $img->parentNode;
                $parentStyle = $parent ? $parent->getAttribute('style') : '';
                if (preg_match('/top:\s*(\d+\.?\d*)(px|%)/', $parentStyle, $matches)) {
                    $style .= '; top:' . $matches[1] . $matches[2];
                } else {
                    $style .= '; top:0px';
                }
            }
            
            // Update the style attribute
            $img->setAttribute('style', $style);
            
            // Add draggable class to all images without overwriting
            $existingClass = $img->getAttribute('class');
            $img->setAttribute('class', trim($existingClass . ' draggable-element pdf-image'));
            
            // Add data attributes for easier position tracking
            if (preg_match('/left:\s*(\d+\.?\d*)(px|%)/', $style, $leftMatch)) {
                $img->setAttribute('data-x', $leftMatch[1]);
                $img->setAttribute('data-x-unit', $leftMatch[2]);
            }
            if (preg_match('/top:\s*(\d+\.?\d*)(px|%)/', $style, $topMatch)) {
                $img->setAttribute('data-y', $topMatch[1]);
                $img->setAttribute('data-y-unit', $topMatch[2]);
            }
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
            
            /* PDF Pages Container */
            #pdf-pages-container {
                position: relative;
                margin: 0 auto;
                max-width: 1200px;
            }
            
            /* Individual Page Container */
            .pdf-page-container {
                position: relative;
                margin: 0 auto 40px;
                background: white;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                border-radius: 8px;
                overflow: visible;
                page-break-inside: avoid;
                page-break-after: always;
                min-width: 800px;
            }
            
            /* Page Break Marker - Visual separator between pages */
            .pdf-page-break-marker {
                position: relative;
                width: 100%;
                text-align: center;
                margin: 30px 0;
                padding: 20px;
                background: linear-gradient(90deg, transparent, #667eea, #764ba2, #667eea, transparent);
                background-size: 200% 1px;
                background-position: center;
                background-repeat: no-repeat;
                color: #667eea;
                font-weight: 600;
                font-size: 14px;
                letter-spacing: 2px;
                text-transform: uppercase;
                user-select: none;
                opacity: 0.7;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 20px;
            }
            
            .pdf-page-break-marker::before,
            .pdf-page-break-marker::after {
                content: "";
                flex: 1;
                height: 2px;
                background: linear-gradient(90deg, transparent, #667eea, #764ba2);
            }
            
            .pdf-page-break-marker::before {
                background: linear-gradient(90deg, transparent, #764ba2, #667eea);
            }
            
            /* Page Content Container */
            .pdf-page-content {
                position: relative;
                width: 100%;
                min-height: 842px; /* A4 height in pixels at 72 DPI */
                padding: 0;
                overflow: visible;
            }
            
            /* Hide page markers in print/PDF export */
            @media print {
                .pdf-page-break-marker {
                    display: none !important;
                    page-break-before: always;
                }
                
                .pdf-page-container {
                    margin: 0 !important;
                    box-shadow: none !important;
                    border-radius: 0 !important;
                    page-break-inside: avoid;
                    page-break-after: always;
                }
                
                .pdf-page-content {
                    page-break-inside: avoid;
                }
                
                body {
                    background: white !important;
                    padding: 0 !important;
                }
                
                #pdf-pages-container {
                    margin: 0 !important;
                }
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
                
                // Auto-save functionality DISABLED - manual save only
                // Commenting out auto-save to prevent unwanted saves
                /*
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
                */
                
                // Track unsaved changes
                let hasUnsavedChanges = false;
                
                // Function to mark document as having unsaved changes
                window.markAsUnsaved = function() {
                    if (!hasUnsavedChanges) {
                        hasUnsavedChanges = true;
                        const saveBtn = document.getElementById("saveButton");
                        if (saveBtn) {
                            saveBtn.innerHTML = "💾 Sauvegarder*";
                            saveBtn.style.background = "#FF6B6B";
                            saveBtn.style.animation = "pulse 2s infinite";
                        }
                    }
                };
                
                // Warn before leaving if there are unsaved changes
                window.addEventListener("beforeunload", function(e) {
                    if (hasUnsavedChanges) {
                        const confirmationMessage = "Vous avez des modifications non sauvegardées. Êtes-vous sûr de vouloir quitter?";
                        e.returnValue = confirmationMessage;
                        return confirmationMessage;
                    }
                });
                
                // Add keyboard shortcut for saving (Ctrl+S or Cmd+S)
                document.addEventListener("keydown", function(e) {
                    if ((e.ctrlKey || e.metaKey) && e.key === "s") {
                        e.preventDefault();
                        if (hasUnsavedChanges) {
                            window.manualSave();
                        }
                    }
                });
                
                // Add pulse animation CSS
                const pulseStyle = document.createElement("style");
                pulseStyle.innerHTML = `
                    @keyframes pulse {
                        0% { box-shadow: 0 0 0 0 rgba(255, 107, 107, 0.7); }
                        70% { box-shadow: 0 0 0 10px rgba(255, 107, 107, 0); }
                        100% { box-shadow: 0 0 0 0 rgba(255, 107, 107, 0); }
                    }
                `;
                document.head.appendChild(pulseStyle);
                
                // Manual save functionality - user must explicitly save
                document.querySelectorAll("[contenteditable=true]").forEach(function(element) {
                    
                    // Track changes
                    element.addEventListener("input", function() {
                        window.markAsUnsaved();
                    });
                    
                    // Prevent formatting loss on paste
                    element.addEventListener("paste", function(e) {
                        e.preventDefault();
                        const text = (e.clipboardData || window.clipboardData).getData("text");
                        document.execCommand("insertText", false, text);
                    });
                });
                
                // Enhanced toolbar with new features and manual save button
                const toolbar = document.createElement("div");
                toolbar.style.cssText = "position: fixed; top: 20px; right: 20px; background: white; padding: 10px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 1000;";
                toolbar.innerHTML = `
                    <button onclick="document.execCommand(\'bold\')" title="Gras" style="margin: 0 5px; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; font-weight: bold;">B</button>
                    <button onclick="document.execCommand(\'italic\')" title="Italique" style="margin: 0 5px; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; font-style: italic;">I</button>
                    <button onclick="document.execCommand(\'underline\')" title="Souligné" style="margin: 0 5px; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; text-decoration: underline;">U</button>
                    <button id="saveButton" onclick="manualSave()" title="Sauvegarder" style="margin: 0 5px; padding: 5px 10px; border: 1px solid #4CAF50; background: #4CAF50; color: white; border-radius: 4px; cursor: pointer; font-weight: bold;">💾 Sauvegarder</button>
                    <button onclick="window.print()" title="Imprimer" style="margin: 0 5px; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">🖨️</button>
                    <button onclick="location.reload()" title="Réinitialiser" style="margin: 0 5px; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">↻</button>
                `;
                
                // Manual save function
                window.manualSave = function() {
                    const saveBtn = document.getElementById("saveButton");
                    
                    // Change button to show saving status
                    saveBtn.innerHTML = "⏳ Sauvegarde...";
                    saveBtn.disabled = true;
                    saveBtn.style.background = "#FFA500";
                    saveBtn.style.animation = "none";
                    
                    // Trigger save event
                    window.dispatchEvent(new CustomEvent("pdf-content-changed", {
                        detail: { content: document.body.innerHTML }
                    }));
                    
                    // Show success feedback after a short delay
                    setTimeout(function() {
                        saveBtn.innerHTML = "✅ Sauvegardé!";
                        saveBtn.style.background = "#4CAF50";
                        hasUnsavedChanges = false; // Reset unsaved changes flag
                        
                        // Reset button after 2 seconds
                        setTimeout(function() {
                            saveBtn.innerHTML = "💾 Sauvegarder";
                            saveBtn.disabled = false;
                            saveBtn.style.background = "#4CAF50";
                            saveBtn.style.animation = "none";
                        }, 2000);
                    }, 500);
                    
                    console.log("Manual save triggered");
                };
                document.body.appendChild(toolbar);
                
                // Instructions overlay
                const instructions = document.createElement("div");
                instructions.style.cssText = "position: fixed; bottom: 20px; left: 20px; background: rgba(0,0,0,0.8); color: white; padding: 15px; border-radius: 8px; font-size: 12px; max-width: 300px; z-index: 1000;";
                instructions.innerHTML = `
                    <h4 style="margin: 0 0 10px 0;">Instructions :</h4>
                    <ul style="margin: 0; padding-left: 20px;">
                        <li><strong>Sauvegarder:</strong> Cliquez sur 💾 ou Ctrl+S</li>
                        <li><strong>Déplacer:</strong> Glissez les éléments (Alt+Glisser pour le texte)</li>
                        <li><strong>Supprimer:</strong> Survolez et cliquez sur le bouton ×</li>
                        <li><strong>Redimensionner:</strong> Double-cliquez sur les images</li>
                        <li><strong>Éditer:</strong> Cliquez directement sur le texte</li>
                    </ul>
                    <div style="margin-top: 10px; padding: 8px; background: rgba(255,107,107,0.2); border-radius: 4px;">
                        <strong>⚠️ Note:</strong> La sauvegarde est manuelle. Le bouton devient rouge quand il y a des modifications non sauvegardées.
                    </div>
                    <button onclick="this.parentElement.remove()" style="margin-top: 10px; padding: 5px 10px; border: none; background: white; color: black; border-radius: 4px; cursor: pointer;">Fermer</button>
                `;
                document.body.appendChild(instructions);
            });
        ';
        $head->appendChild($script);
        
        return $dom->saveHTML();
    }
    
    /**
     * Merge pages from individual HTML files with markers
     */
    private function mergePagesFromFiles($pageFiles, $outputDir)
    {
        // Collect all unique styles from all pages
        $allStyles = [];
        $pageContents = [];
        $fontClasses = [];  // Track all font classes used
        $styleProperties = [];  // Track style properties for each class
        
        foreach ($pageFiles as $pageNum => $pageFile) {
            if (file_exists($pageFile)) {
                $pageHtml = file_get_contents($pageFile);
                
                // Extract styles from head and analyze them
                if (preg_match_all('/<style[^>]*>(.*?)<\/style>/si', $pageHtml, $styleMatches)) {
                    foreach ($styleMatches[1] as $style) {
                        // Add style if not already present (avoid duplicates)
                        $styleHash = md5($style);
                        if (!isset($allStyles[$styleHash])) {
                            $allStyles[$styleHash] = $style;
                            
                            // Analyze font classes (ft00, ft01, etc.)
                            if (preg_match_all('/\.(ft\d+)\{([^}]+)\}/i', $style, $classMatches)) {
                                for ($i = 0; $i < count($classMatches[1]); $i++) {
                                    $className = $classMatches[1][$i];
                                    $properties = $classMatches[2][$i];
                                    
                                    // Parse properties
                                    $fontClasses[$className] = true;
                                    
                                    // Extract font-size, font-family, color
                                    if (preg_match('/font-size:\s*([^;]+);/i', $properties, $sizeMatch)) {
                                        $styleProperties[$className]['font-size'] = trim($sizeMatch[1]);
                                    }
                                    if (preg_match('/font-family:\s*([^;]+);/i', $properties, $familyMatch)) {
                                        $styleProperties[$className]['font-family'] = trim($familyMatch[1]);
                                    }
                                    if (preg_match('/color:\s*([^;]+);/i', $properties, $colorMatch)) {
                                        $styleProperties[$className]['color'] = trim($colorMatch[1]);
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Extract the page div with all its content to preserve positioning context
                // Use a more robust approach to extract the entire page div with nested content
                $dom = new \DOMDocument();
                @$dom->loadHTML($pageHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                $xpath = new \DOMXPath($dom);
                
                // Find the page div (e.g., page1-div, page2-div, etc.)
                $pageDivs = $xpath->query("//div[starts-with(@id, 'page') and contains(@id, '-div')]");
                
                if ($pageDivs->length > 0) {
                    // Get the first page div
                    $pageDiv = $pageDivs->item(0);
                    
                    // Make text elements editable and add draggable class while preserving original classes
                    $textNodes = $xpath->query('.//div[contains(@style, "position:absolute")] | .//p[contains(@style, "position:absolute")] | .//span[contains(@style, "position:absolute")]', $pageDiv);
                    foreach ($textNodes as $node) {
                        if ($node->nodeValue && trim($node->nodeValue) !== '') {
                            $node->setAttribute('contenteditable', 'true');
                            // IMPORTANT: Preserve existing classes (like ft07 for styles)
                            $existingClass = $node->getAttribute('class');
                            if ($existingClass) {
                                $node->setAttribute('class', $existingClass . ' draggable-element pdf-text');
                            } else {
                                $node->setAttribute('class', 'draggable-element pdf-text');
                            }
                            
                            // Enhance detection of italic text (words like "should", "must", etc.)
                            $content = $node->nodeValue;
                            if (preg_match('/\b(should|must|could|would|shall|may|might|can|will)\b/i', $content)) {
                                // Check if this word should be italicized based on context
                                $innerHTML = $dom->saveHTML($node);
                                if (strpos($innerHTML, '<i>') === false && strpos($innerHTML, '<em>') === false) {
                                    // Auto-detect and wrap with italic tags if needed
                                    $newContent = preg_replace('/\b(should|must|could|would|shall|may|might|can|will)\b/i', '<em>$1</em>', $content);
                                    if ($newContent !== $content) {
                                        // Create new HTML with emphasized words
                                        $fragment = $dom->createDocumentFragment();
                                        @$fragment->appendXML($newContent);
                                        $node->nodeValue = '';
                                        $node->appendChild($fragment);
                                    }
                                }
                            }
                        }
                    }
                    
                    // Process images
                    $images = $xpath->query('.//img', $pageDiv);
                    foreach ($images as $img) {
                        $existingClass = $img->getAttribute('class');
                        if ($existingClass) {
                            $img->setAttribute('class', $existingClass . ' draggable-element pdf-image');
                        } else {
                            $img->setAttribute('class', 'draggable-element pdf-image');
                        }
                    }
                    
                    // Save the modified page div
                    $pageContents[$pageNum] = $dom->saveHTML($pageDiv);
                } else {
                    // Fallback: extract body content if page div not found
                    if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $pageHtml, $bodyMatch)) {
                        $pageContents[$pageNum] = $bodyMatch[1];
                    }
                }
            }
        }
        
        // Build HTML with preserved structure
        $mergedHtml = '<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="" xml:lang="">
<head>
<meta charset="UTF-8">
<title>PDF Document</title>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
';
        
        // Add all collected styles to head (includes font definitions)
        if (!empty($allStyles)) {
            $mergedHtml .= '<style type="text/css">' . "\n";
            foreach ($allStyles as $style) {
                $mergedHtml .= $style . "\n";
            }
            $mergedHtml .= '</style>' . "\n";
        }
        
        // Generate and add adaptive styles based on detected font classes
        $adaptiveStyles = $this->generateAdaptiveStyles($fontClasses, $styleProperties);
        $mergedHtml .= '<style type="text/css">' . "\n";
        $mergedHtml .= $adaptiveStyles;
        $mergedHtml .= '</style>' . "\n";
        
        // Add font face mappings and imports FIRST for pdftohtml generated fonts
        $mergedHtml .= '<style type="text/css">
            /* Import actual Google Fonts - MUST come first */
            @import url("https://fonts.googleapis.com/css2?family=Barlow:wght@300;400;500;600;700&family=Merriweather:wght@300;400;700;900&family=Magazine+Grotesque:wght@400&display=swap");
            
            /* Font mappings for PDF fonts to real fonts */
            @font-face {
                font-family: "AAAAAA+Barlow";
                src: local("Barlow"), local("Barlow Regular");
                font-weight: 400;
            }
            @font-face {
                font-family: "BAAAAA+Magazine";
                src: local("Magazine Grotesque"), local("Georgia"), local("serif");
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
            @font-face {
                font-family: "FAAAAA+Barlow-Medium";
                src: local("Barlow Medium"), local("Barlow");
                font-weight: 500;
            }
            @font-face {
                font-family: "GAAAAA+ArialMT";
                src: local("Arial"), local("Helvetica"), local("sans-serif");
            }
            @font-face {
                font-family: "HAAAAA+Merriweather-Light-18pt";
                src: local("Merriweather Light"), local("Merriweather");
                font-weight: 300;
            }
            
            /* Ensure colors are preserved - Override any default styles */
            .ft00 { color: #ababab !important; } /* Light gray for menu */
            .ft01 { color: #ababab !important; }
            .ft02 { color: #252d4a !important; } /* Dark blue for body text */
            .ft03 { color: #2a3356 !important; } /* Slightly different dark blue */
            .ft04 { color: #252d4a !important; }
            .ft05 { color: #6b6d72 !important; } /* Gray for metadata */
            .ft06 { color: #ababab !important; }
            .ft07 { color: #9d9ea2 !important; } /* Light gray for main title */
            .ft08 { color: #000000 !important; } /* Black for small text */
            
            /* Ensure font sizes are exact */
            p[class*="ft"] {
                margin: 0 !important;
                padding: 0 !important;
                line-height: 1.2 !important;
            }
            
            /* Preserve bold text */
            b, strong {
                font-weight: 700 !important;
                display: inline !important;
                position: relative !important;
                vertical-align: baseline !important;
            }
            
            /* Fix alignment for bold text within positioned paragraphs */
            p[style*="position:absolute"] b,
            p[style*="position:absolute"] strong {
                position: relative !important;
                display: inline !important;
                line-height: inherit !important;
                vertical-align: baseline !important;
            }
            
            /* Ensure proper text rendering */
            p[style*="position:absolute"] {
                display: block !important;
                white-space: nowrap !important;
            }
            
            /* Fix code blocks and monospace text */
            .ft09, .ft10, .ft11, .ft12, .ft13, .ft14, .ft15, .ft16, .ft17, .ft18, .ft19, .ft20,
            .ft21, .ft22, .ft23, .ft24, .ft25, .ft26, .ft27, .ft28, .ft29, .ft30 {
                font-family: "Courier New", Courier, monospace !important;
                background-color: #f5f5f5;
                padding: 2px 4px;
                border-radius: 3px;
            }
            
            /* Ensure code blocks maintain proper spacing */
            p[class*="ft"][style*="background"] {
                white-space: pre !important;
                font-family: monospace !important;
            }
            
            /* Fix overlapping text issues */
            .pdf-page div[id*="page"] {
                position: relative !important;
                min-height: 1100px;
            }
            
            /* Adjust text positioning for better alignment */
            p[style*="position:absolute"] {
                z-index: 1;
            }
            
            /* Ensure images stay in background */
            .pdf-page img {
                position: relative;
                z-index: 0;
            }
        </style>' . "\n";
        
        // Add additional styles for page separation and markers
        $mergedHtml .= '<style>
            body {
                margin: 0;
                padding: 20px;
                background: #f5f5f5;
                -webkit-font-smoothing: antialiased;
                -moz-osx-font-smoothing: grayscale;
            }
            
            /* Wrapper for each page to provide spacing */
            .pdf-page-wrapper {
                margin: 0 auto 40px;
                background: white;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                position: relative;
            }
            
            /* Page marker styling */
            .pdf-page-marker {
                background: #007bff;
                color: white;
                padding: 10px;
                text-align: center;
                font-weight: bold;
                font-size: 14px;
                margin: 20px auto;
                max-width: 800px;
                border-radius: 4px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            }
            
            /* Preserve original page div dimensions and positioning context */
            div[id^="page"][id$="-div"] {
                /* Keep original positioning context */
                margin: 0 auto;
            }
            
            @media print {
                .pdf-page-marker {
                    page-break-before: always;
                    display: none;
                }
            }
        </style>';
        
        $mergedHtml .= '</head><body bgcolor="#FFFFFF">';
        
        // Wrap all content in pdfContent div for Vue component
        $mergedHtml .= '<div id="pdfContent">';
        
        // Include adaptive styles INSIDE the pdfContent div so they are loaded with the content in Vue
        $mergedHtml .= '<style type="text/css">' . "\n";
        
        // First include original styles
        foreach ($allStyles as $style) {
            $mergedHtml .= $style . "\n";
        }
        
        // Then add our adaptive styles that override and enhance
        $mergedHtml .= $adaptiveStyles;
        
        $mergedHtml .= '</style>' . "\n";
        
        // Now add all page contents with markers
        foreach ($pageContents as $pageNum => $pageContent) {
            // Process images to convert to base64
            $pageContent = $this->convertImagesToBase64($pageContent, $outputDir);
            
            // Add page marker (except for first page)
            if ($pageNum > 1) {
                $mergedHtml .= '<div class="pdf-page-marker" data-page-number="' . $pageNum . '">';
                $mergedHtml .= 'Page ' . $pageNum;
                $mergedHtml .= '</div>';
            }
            
            // Add page wrapper with pdf-page class for Vue component
            $mergedHtml .= '<div class="pdf-page pdf-page-wrapper" data-page="' . $pageNum . '">';
            
            // Add the original page div with all its content (preserves positioning context)
            $mergedHtml .= $pageContent;
            
            $mergedHtml .= '</div>';
        }
        
        // Close pdfContent div
        $mergedHtml .= '</div>';
        
        $mergedHtml .= '</body></html>';
        
        // Write the merged HTML to file
        $mergedFile = $outputDir . '/document.html';
        file_put_contents($mergedFile, $mergedHtml);
        
        return $mergedFile;
    }
    
    /**
     * Generate adaptive CSS styles based on detected font classes
     */
    private function generateAdaptiveStyles($fontClasses, $styleProperties)
    {
        $css = '';
        
        // Analyze font sizes to detect hierarchy (titles, subtitles, body text)
        $fontSizes = [];
        foreach ($styleProperties as $className => $props) {
            if (isset($props['font-size'])) {
                $size = $this->parseFontSize($props['font-size']);
                $fontSizes[$className] = $size;
            }
        }
        
        // Determine the largest font size (likely titles)
        $maxSize = !empty($fontSizes) ? max($fontSizes) : 16;
        $avgSize = !empty($fontSizes) ? array_sum($fontSizes) / count($fontSizes) : 14;
        
        // Generate improved styles for each detected font class
        foreach ($fontClasses as $className => $unused) {
            if (isset($styleProperties[$className])) {
                $props = $styleProperties[$className];
                
                // Preserve original properties with enhancements
                $css .= ".$className { \n";
                
                if (isset($props['font-size'])) {
                    $size = $this->parseFontSize($props['font-size']);
                    $css .= "    font-size: {$props['font-size']} !important;\n";
                    
                    // Auto-detect titles and make them bold
                    if ($size >= $maxSize * 0.9) {
                        $css .= "    font-weight: bold !important;\n";
                    }
                    
                    // Improve line height based on size
                    if ($size > $avgSize * 1.5) {
                        $css .= "    line-height: 1.3 !important;\n";
                        $css .= "    margin-bottom: 0.5em !important;\n";
                    } else {
                        $css .= "    line-height: 1.5 !important;\n";
                    }
                } else {
                    $css .= "    line-height: 1.5;\n";
                }
                
                if (isset($props['font-family'])) {
                    // Map PDF fonts to web fonts intelligently
                    $fontFamily = $this->mapFontFamily($props['font-family']);
                    $css .= "    font-family: $fontFamily !important;\n";
                }
                
                if (isset($props['color'])) {
                    // Enhance color contrast if needed
                    $enhancedColor = $this->enhanceColorContrast($props['color']);
                    $css .= "    color: {$enhancedColor} !important;\n";
                }
                
                $css .= "}\n\n";
                
                // Add special handling for elements with multiple styles
                $css .= "/* Enhanced styles for $className with formatting */\n";
                $css .= ".$className b, .$className strong { \n";
                $css .= "    font-weight: 700 !important;\n";
                $css .= "    display: inline !important;\n";
                $css .= "}\n";
                
                $css .= ".$className i, .$className em { \n";
                $css .= "    font-style: italic !important;\n";
                $css .= "    display: inline !important;\n";
                $css .= "}\n";
                
                $css .= ".$className u { \n";
                $css .= "    text-decoration: underline !important;\n";
                $css .= "    display: inline !important;\n";
                $css .= "}\n\n";
            }
        }
        
        // Add general improvements for complex styled elements
        $css .= "/* Improved positioning for elements with multiple styles */\n";
        $css .= "p[class*='ft'] b i, p[class*='ft'] i b,\n";
        $css .= "p[class*='ft'] b u, p[class*='ft'] u b,\n";
        $css .= "p[class*='ft'] i u, p[class*='ft'] u i { \n";
        $css .= "    position: relative !important;\n";
        $css .= "    display: inline !important;\n";
        $css .= "    vertical-align: baseline !important;\n";
        $css .= "    line-height: inherit !important;\n";
        $css .= "}\n\n";
        
        // Auto-detect and style italic words
        $css .= "/* Auto-style for italic emphasis words */\n";
        $css .= "em, i { \n";
        $css .= "    font-style: italic !important;\n";
        $css .= "    display: inline !important;\n";
        $css .= "}\n\n";
        
        // Improve paragraph spacing based on position
        $css .= "/* Smart paragraph spacing */\n";
        $css .= "p[style*='position:absolute'] { \n";
        $css .= "    margin: 0;\n";
        $css .= "    padding: 0;\n";
        $css .= "}\n\n";
        
        // Detect and style title elements (usually at top of page)
        $css .= "/* Auto-detect titles and headers */\n";
        foreach ($fontSizes as $className => $size) {
            if ($size >= $maxSize * 0.8) {
                $css .= ".$className { \n";
                $css .= "    font-weight: 700 !important;\n";
                $css .= "    color: #252d4a !important;\n";
                $css .= "    margin-bottom: 1em !important;\n";
                $css .= "}\n";
            }
        }
        $css .= "\n";
        
        // Fix highlighted text
        $css .= "/* Fix for highlighted text */\n";
        $css .= "mark, .highlighted { \n";
        $css .= "    background-color: yellow !important;\n";
        $css .= "    color: inherit !important;\n";
        $css .= "    padding: 0 2px !important;\n";
        $css .= "    display: inline !important;\n";
        $css .= "}\n\n";
        
        // Ensure proper stacking and positioning
        $css .= "/* Ensure proper element stacking */\n";
        $css .= "p[style*='position:absolute'] { \n";
        $css .= "    z-index: 10;\n";
        $css .= "    display: block;\n";
        $css .= "    white-space: nowrap;\n";
        $css .= "}\n\n";
        
        $css .= "/* Fix overlapping issues */\n";
        $css .= "p[style*='position:absolute'] * { \n";
        $css .= "    position: relative !important;\n";
        $css .= "    z-index: inherit;\n";
        $css .= "}\n";
        
        return $css;
    }
    
    /**
     * Parse font size to numeric value
     */
    private function parseFontSize($fontSize)
    {
        // Extract numeric value from font-size (e.g., "16px" -> 16)
        if (preg_match('/(\d+(?:\.\d+)?)/', $fontSize, $matches)) {
            return floatval($matches[1]);
        }
        return 14; // Default size
    }
    
    /**
     * Enhance color contrast for better readability
     */
    private function enhanceColorContrast($color)
    {
        // Skip if already has good contrast or is very dark
        if ($color === '#000000' || $color === '#000') {
            return $color;
        }
        
        // Parse hex color
        $hex = ltrim($color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        
        if (strlen($hex) === 6) {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            
            // Calculate luminance
            $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
            
            // If color is too light (low contrast), darken it
            if ($luminance > 0.7) {
                $r = max(0, $r - 50);
                $g = max(0, $g - 50);
                $b = max(0, $b - 50);
                return sprintf('#%02x%02x%02x', $r, $g, $b);
            }
            
            // If color is grayish and light, enhance to darker blue for titles
            if ($luminance > 0.5 && abs($r - $g) < 30 && abs($g - $b) < 30) {
                // Convert light gray to dark blue for better readability
                return '#252d4a';
            }
        }
        
        return $color;
    }
    
    /**
     * Map PDF font families to appropriate web fonts
     */
    private function mapFontFamily($pdfFont)
    {
        // Remove PDF-specific prefixes
        $cleanFont = preg_replace('/^[A-Z]+\+/', '', $pdfFont);
        
        // Common font mappings
        $fontMappings = [
            'Barlow' => '"Barlow", "Helvetica Neue", Arial, sans-serif',
            'Merriweather' => '"Merriweather", Georgia, serif',
            'ArialMT' => 'Arial, "Helvetica Neue", sans-serif',
            'Times' => '"Times New Roman", Times, serif',
            'Courier' => '"Courier New", Courier, monospace',
            'Helvetica' => '"Helvetica Neue", Helvetica, Arial, sans-serif',
            'Georgia' => 'Georgia, "Times New Roman", serif',
            'Verdana' => 'Verdana, Geneva, sans-serif',
            'Tahoma' => 'Tahoma, Geneva, sans-serif',
            'Trebuchet' => '"Trebuchet MS", Helvetica, sans-serif',
            'Comic' => '"Comic Sans MS", cursive',
            'Impact' => 'Impact, Charcoal, sans-serif',
            'Lucida' => '"Lucida Console", Monaco, monospace',
            'Palatino' => '"Palatino Linotype", "Book Antiqua", Palatino, serif',
            'Garamond' => 'Garamond, serif',
            'Bookman' => '"Bookman Old Style", serif',
            'Avant' => '"Avant Garde", sans-serif'
        ];
        
        // Check for matches
        foreach ($fontMappings as $key => $value) {
            if (stripos($cleanFont, $key) !== false) {
                return $value;
            }
        }
        
        // Default fallback based on font characteristics
        if (stripos($cleanFont, 'serif') !== false) {
            return 'Georgia, "Times New Roman", serif';
        } elseif (stripos($cleanFont, 'mono') !== false || stripos($cleanFont, 'code') !== false) {
            return '"Courier New", Courier, monospace';
        } else {
            return 'Arial, "Helvetica Neue", sans-serif';
        }
    }
    
    /**
     * Convert image sources to base64 in HTML content
     */
    private function convertImagesToBase64($html, $outputDir)
    {
        // Find all img tags and convert their src to base64
        return preg_replace_callback('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', function($matches) use ($outputDir) {
            $imgTag = $matches[0];
            $src = $matches[1];
            
            // Skip if already base64
            if (strpos($src, 'data:') === 0) {
                return $imgTag;
            }
            
            // Try to find the image file
            $imagePath = $outputDir . '/' . basename($src);
            if (file_exists($imagePath)) {
                $imageData = file_get_contents($imagePath);
                $mimeType = mime_content_type($imagePath);
                $base64 = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
                
                // Replace src with base64
                $imgTag = preg_replace('/src=["\'][^"\']+["\']/', 'src="' . $base64 . '"', $imgTag);
            }
            
            return $imgTag;
        }, $html);
    }
    
    /**
     * Merge separate page HTML files with page markers (legacy method)
     */
    private function mergePagesWithMarkers($outputDir, $pageCount)
    {
        $mergedHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>PDF Document</title></head><body>';
        
        // Process each page file
        for ($i = 1; $i <= $pageCount; $i++) {
            $pageFile = $outputDir . '/page-' . $i . '.html';
            
            if (!file_exists($pageFile)) {
                // Try alternative naming conventions
                $pageFile = $outputDir . '/page' . $i . '.html';
                if (!file_exists($pageFile)) {
                    $pageFile = $outputDir . '/page_' . $i . '.html';
                }
            }
            
            if (file_exists($pageFile)) {
                $pageHtml = file_get_contents($pageFile);
                
                // Extract body content from page HTML
                $dom = new DOMDocument();
                @$dom->loadHTML($pageHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                $xpath = new DOMXPath($dom);
                
                // Get the body content
                $body = $xpath->query('//body')->item(0);
                if ($body) {
                    // Create page container
                    $pageContainer = '<div class="pdf-page-container" data-page="' . $i . '" id="pdf-page-' . $i . '">';
                    
                    // Add page marker (except for first page)
                    if ($i > 1) {
                        $pageContainer .= '<div class="pdf-page-break-marker" data-page-number="' . $i . '">';
                        $pageContainer .= '<span>Page ' . $i . '</span>';
                        $pageContainer .= '</div>';
                    }
                    
                    // Add page content container
                    $pageContainer .= '<div class="pdf-page-content" style="position: relative; min-height: 842px;">';
                    
                    // Extract and process all elements from body
                    foreach ($body->childNodes as $child) {
                        if ($child->nodeType === XML_ELEMENT_NODE) {
                            // Convert images to base64 if needed
                            $this->processImagesInNode($child, $outputDir);
                            $pageContainer .= $dom->saveHTML($child);
                        }
                    }
                    
                    $pageContainer .= '</div></div>';
                    
                    $mergedHtml .= $pageContainer;
                }
            } else {
                Log::warning('Page file not found: ' . $pageFile);
            }
        }
        
        $mergedHtml .= '</body></html>';
        
        return $mergedHtml;
    }
    
    /**
     * Process images in a DOM node to convert to base64
     */
    private function processImagesInNode($node, $outputDir)
    {
        if ($node->nodeName === 'img') {
            $src = $node->getAttribute('src');
            if ($src && !str_starts_with($src, 'data:')) {
                $imagePath = $outputDir . '/' . basename($src);
                if (file_exists($imagePath)) {
                    $imageData = file_get_contents($imagePath);
                    $mimeType = mime_content_type($imagePath);
                    $base64 = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
                    $node->setAttribute('src', $base64);
                }
            }
        }
        
        // Process child nodes recursively
        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $this->processImagesInNode($child, $outputDir);
                }
            }
        }
    }
    
    /**
     * Wrap pages with containers and add visual markers
     */
    private function wrapPagesWithMarkers($dom, $xpath)
    {
        // Find all elements that might indicate a page
        // pdftohtml usually uses absolute positioning with specific patterns
        $body = $xpath->query('//body')->item(0);
        if (!$body) return;
        
        // Get all absolutely positioned elements
        $allElements = $xpath->query('//*[contains(@style, "position:absolute")]');
        
        // Detect page boundaries by looking for repeated patterns
        // pdftohtml often repeats the same Y positions for elements on different pages
        $pageBreakPositions = [];
        $positionCounts = [];
        
        // Count occurrences of each top position
        foreach ($allElements as $element) {
            $style = $element->getAttribute('style');
            if (preg_match('/top:\s*(\d+)px/', $style, $matches)) {
                $topPos = intval($matches[1]);
                if (!isset($positionCounts[$topPos])) {
                    $positionCounts[$topPos] = 0;
                }
                $positionCounts[$topPos]++;
            }
        }
        
        // Find positions that repeat (likely page numbers or headers)
        $pageHeight = 0;
        foreach ($positionCounts as $pos => $count) {
            // If a position appears 3+ times, it's likely a page marker
            if ($count >= 3 && $pos > 1000) {
                $pageHeight = $pos + 100; // Add some buffer
                break;
            }
        }
        
        // If no repeating pattern found, use default
        if ($pageHeight == 0) {
            $pageHeight = 1100; // Default A4 height approximation
        }
        
        // Group elements by detected page height
        $pageGroups = [];
        $elementIndex = 0;
        $currentPage = 0;
        $processedElements = [];
        
        foreach ($allElements as $element) {
            $style = $element->getAttribute('style');
            if (preg_match('/top:\s*(\d+)px/', $style, $matches)) {
                $topPosition = intval($matches[1]);
                
                // Check if this element is already processed
                $elementId = spl_object_hash($element);
                if (isset($processedElements[$elementId])) {
                    continue;
                }
                
                // Determine which page this element belongs to
                // Reset to page 0 when we see top positions near 0-100 after high positions
                if ($elementIndex > 0 && $topPosition < 100 && isset($lastTopPosition) && $lastTopPosition > 1000) {
                    $currentPage++;
                }
                
                if (!isset($pageGroups[$currentPage])) {
                    $pageGroups[$currentPage] = [];
                }
                
                $pageGroups[$currentPage][] = $element;
                $processedElements[$elementId] = true;
                $lastTopPosition = $topPosition;
                $elementIndex++;
            }
        }
        
        // If we detected multiple pages or elements spanning multiple pages, wrap them
        if (count($pageGroups) > 0) {
            // Create a new body structure
            $newBody = $dom->createElement('div');
            $newBody->setAttribute('id', 'pdf-pages-container');
            
            foreach ($pageGroups as $pageNum => $elements) {
                // Create page wrapper
                $pageWrapper = $dom->createElement('div');
                $pageWrapper->setAttribute('class', 'pdf-page-container');
                $pageWrapper->setAttribute('data-page', $pageNum + 1);
                $pageWrapper->setAttribute('id', 'pdf-page-' . ($pageNum + 1));
                
                // Create visual page marker
                $pageMarker = $dom->createElement('div');
                $pageMarker->setAttribute('class', 'pdf-page-break-marker');
                $pageMarker->setAttribute('data-page-number', $pageNum + 1);
                $pageMarker->textContent = '--- Page ' . ($pageNum + 1) . ' ---';
                
                // Add marker before page content
                if ($pageNum > 0) {
                    $pageWrapper->appendChild($pageMarker);
                }
                
                // Create page content container
                $pageContent = $dom->createElement('div');
                $pageContent->setAttribute('class', 'pdf-page-content');
                $pageContent->setAttribute('style', 'position: relative; page-break-after: always;');
                
                // Move elements to this page
                foreach ($elements as $element) {
                    // Adjust top position relative to page
                    $style = $element->getAttribute('style');
                    if (preg_match('/top:\s*(\d+)px/', $style, $matches)) {
                        $originalTop = intval($matches[1]);
                        $newTop = $originalTop % $pageHeight; // Use modulo to wrap positions
                        $newStyle = preg_replace('/top:\s*\d+px/', 'top: ' . $newTop . 'px', $style);
                        $element->setAttribute('style', $newStyle);
                    }
                    
                    // Clone and append to page content
                    $clonedElement = $element->cloneNode(true);
                    $pageContent->appendChild($clonedElement);
                }
                
                $pageWrapper->appendChild($pageContent);
                $newBody->appendChild($pageWrapper);
            }
            
            // Replace body content
            while ($body->firstChild) {
                $body->removeChild($body->firstChild);
            }
            
            // Add the new structure
            while ($newBody->firstChild) {
                $body->appendChild($newBody->firstChild);
            }
        }
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