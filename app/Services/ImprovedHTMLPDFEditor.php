<?php

namespace App\Services;

use Exception;
use DOMDocument;
use DOMXPath;

class ImprovedHTMLPDFEditor
{
    /**
     * Convert PDF to HTML with improved layout preservation
     */
    public function convertPDFToHTML($pdfPath)
    {
        // Try different conversion methods in order of quality
        if ($this->commandExists('pdf2htmlEX')) {
            return $this->convertWithPdf2htmlEXImproved($pdfPath);
        }

        if ($this->commandExists('pdftohtml')) {
            return $this->convertWithPdftohtmlImproved($pdfPath);
        }

        return $this->customPDFToHTMLImproved($pdfPath);
    }

    /**
     * Improved pdf2htmlEX conversion with better styling
     */
    private function convertWithPdf2htmlEXImproved($pdfPath)
    {
        $htmlFile = tempnam(sys_get_temp_dir(), 'pdf_') . '.html';

        // Use enhanced parameters for better quality
        $command = sprintf(
            'pdf2htmlEX --zoom 1.5 --process-outline 0 --embed-css 1 --embed-font 1 --embed-image 1 --embed-javascript 0 --split-pages 0 --dest-dir %s %s %s 2>&1',
            escapeshellarg(dirname($htmlFile)),
            escapeshellarg($pdfPath),
            escapeshellarg(basename($htmlFile))
        );

        exec($command, $output, $returnCode);

        if ($returnCode === 0 && file_exists($htmlFile)) {
            $html = file_get_contents($htmlFile);
            unlink($htmlFile);

            return $this->enhanceHTML($html);
        }

        throw new Exception("pdf2htmlEX conversion failed");
    }

    /**
     * Improved pdftohtml conversion with better layout handling
     */
    private function convertWithPdftohtmlImproved($pdfPath)
    {
        $baseFile = tempnam(sys_get_temp_dir(), 'pdf_');
        $htmlFile = $baseFile . '.html';

        // Enhanced command with better parameters
        $command = sprintf(
            'pdftohtml -c -s -noframes -zoom 2.0 -fontfullname -nodrm %s %s 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($baseFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode === 0 && file_exists($htmlFile)) {
            $html = file_get_contents($htmlFile);
            
            // Get images if any
            $imageFiles = glob($baseFile . '-*.jpg') ?: [];
            $imageFiles = array_merge($imageFiles, glob($baseFile . '-*.png') ?: []);
            
            // Embed images as base64
            foreach ($imageFiles as $imageFile) {
                $imageData = base64_encode(file_get_contents($imageFile));
                $imageName = basename($imageFile);
                $mimeType = mime_content_type($imageFile);
                $html = str_replace(
                    $imageName,
                    "data:$mimeType;base64,$imageData",
                    $html
                );
                unlink($imageFile);
            }
            
            unlink($htmlFile);
            @unlink($baseFile);

            return $this->cleanupAndEnhanceHTML($html);
        }

        throw new Exception("pdftohtml conversion failed: " . implode("\n", $output));
    }

    /**
     * Enhanced HTML cleanup and improvement
     */
    private function cleanupAndEnhanceHTML($html)
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        $xpath = new DOMXPath($dom);
        
        // Process all images
        $images = $xpath->query('//img');
        foreach ($images as $img) {
            $width = $img->getAttribute('width');
            $height = $img->getAttribute('height');
            $src = $img->getAttribute('src');
            
            // Determine if image is background or content
            if ($width && $height) {
                $w = intval($width);
                $h = intval($height);
                
                // Large images likely to be backgrounds
                if ($w > 500 || $h > 700) {
                    // Create a wrapper div for background
                    $wrapper = $dom->createElement('div');
                    $wrapper->setAttribute('class', 'pdf-page-background');
                    $wrapper->setAttribute('style', sprintf(
                        'position: absolute; top: 0; left: 0; width: %dpx; height: %dpx; z-index: 0;',
                        $w, $h
                    ));
                    
                    $img->setAttribute('style', 'width: 100%; height: 100%; object-fit: contain;');
                    $img->parentNode->insertBefore($wrapper, $img);
                    $wrapper->appendChild($img);
                } else {
                    // Regular images - ensure proper z-index
                    $style = $img->getAttribute('style') ?: '';
                    if (strpos($style, 'z-index') === false) {
                        $style .= '; z-index: 2; position: relative;';
                        $img->setAttribute('style', $style);
                    }
                }
            }
        }
        
        // Process text elements to avoid duplication
        $paragraphs = $xpath->query('//p');
        $processedTexts = [];
        
        foreach ($paragraphs as $p) {
            $text = trim($p->textContent);
            $style = $p->getAttribute('style') ?: '';
            
            // Extract position from style
            preg_match('/left:\s*([0-9.]+)/', $style, $leftMatch);
            preg_match('/top:\s*([0-9.]+)/', $style, $topMatch);
            
            $left = isset($leftMatch[1]) ? floatval($leftMatch[1]) : 0;
            $top = isset($topMatch[1]) ? floatval($topMatch[1]) : 0;
            
            // Create position key to detect duplicates
            $posKey = round($left/10) . '_' . round($top/10) . '_' . substr($text, 0, 20);
            
            if (isset($processedTexts[$posKey])) {
                // Remove duplicate
                $p->parentNode->removeChild($p);
            } else {
                $processedTexts[$posKey] = true;
                
                // Ensure text is above background
                if (strpos($style, 'z-index') === false) {
                    $style .= '; z-index: 10; position: relative;';
                }
                
                // Add text shadow for better readability
                if (strpos($style, 'text-shadow') === false) {
                    $style .= '; text-shadow: 1px 1px 2px rgba(255,255,255,0.8);';
                }
                
                $p->setAttribute('style', $style);
            }
        }
        
        // Add enhanced CSS
        $head = $xpath->query('//head')->item(0);
        if ($head) {
            $styleElement = $dom->createElement('style');
            $css = '
                body {
                    margin: 0;
                    padding: 0;
                    background: #f5f5f5;
                }
                
                .pdf-container {
                    position: relative;
                    background: white;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                    margin: 20px auto;
                    max-width: 100%;
                }
                
                .pdf-page-background {
                    pointer-events: none;
                    user-select: none;
                }
                
                p, span, div {
                    position: relative;
                }
                
                /* Ensure text is selectable and editable */
                p[contenteditable="true"], 
                span[contenteditable="true"] {
                    outline: none;
                    cursor: text;
                    background: rgba(255,255,0,0.1);
                    transition: background 0.3s;
                }
                
                p[contenteditable="true"]:hover,
                span[contenteditable="true"]:hover {
                    background: rgba(255,255,0,0.2);
                }
                
                /* Fix for overlapping text */
                .text-block {
                    position: absolute;
                    white-space: pre-wrap;
                    word-wrap: break-word;
                }
                
                /* Profile images and avatars */
                img.avatar, img.profile-pic {
                    border-radius: 50%;
                    z-index: 15;
                    position: relative;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                }
                
                /* Card-like containers */
                .content-card {
                    background: white;
                    border-radius: 8px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                    padding: 20px;
                    margin: 10px;
                    position: relative;
                    z-index: 5;
                }
            ';
            $styleElement->appendChild($dom->createTextNode($css));
            $head->appendChild($styleElement);
        }
        
        // Wrap content in container
        $body = $xpath->query('//body')->item(0);
        if ($body) {
            $container = $dom->createElement('div');
            $container->setAttribute('class', 'pdf-container');
            $container->setAttribute('id', 'pdfContent');
            
            // Move all body children to container
            while ($body->firstChild) {
                $container->appendChild($body->firstChild);
            }
            
            $body->appendChild($container);
        }
        
        return $dom->saveHTML();
    }

    /**
     * Enhanced HTML with better structure
     */
    private function enhanceHTML($html)
    {
        // Add viewport meta tag
        if (strpos($html, 'viewport') === false) {
            $html = str_replace(
                '<head>',
                '<head><meta name="viewport" content="width=device-width, initial-scale=1.0">',
                $html
            );
        }
        
        // Add custom CSS for better editing experience
        $customCSS = '
        <style>
            /* Custom enhancements for PDF editor */
            [contenteditable="true"] {
                min-height: 1em;
                outline: 1px dashed #ccc;
                padding: 2px;
            }
            
            [contenteditable="true"]:focus {
                outline: 2px solid #007bff;
                background: rgba(0,123,255,0.05);
            }
            
            /* Prevent text duplication */
            .duplicate-text {
                display: none !important;
            }
            
            /* Image handling */
            img {
                max-width: 100%;
                height: auto;
            }
            
            /* Shadow effects */
            .has-shadow {
                filter: drop-shadow(2px 2px 4px rgba(0,0,0,0.2));
            }
        </style>
        ';
        
        $html = str_replace('</head>', $customCSS . '</head>', $html);
        
        return $html;
    }

    /**
     * Custom improved PDF to HTML conversion
     */
    private function customPDFToHTMLImproved($pdfPath)
    {
        // Extract images separately
        $imagesDir = tempnam(sys_get_temp_dir(), 'pdf_images_');
        unlink($imagesDir);
        mkdir($imagesDir);
        
        // Extract images with pdfimages
        $command = sprintf(
            'pdfimages -j %s %s/page 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($imagesDir)
        );
        exec($command);
        
        // Get text with coordinates
        $textElements = $this->extractTextWithCoordinates($pdfPath);
        
        // Get PDF info
        $pdfInfo = $this->getPDFInfo($pdfPath);
        
        // Build enhanced HTML
        $html = $this->buildEnhancedHTML($textElements, $imagesDir, $pdfInfo);
        
        // Cleanup
        array_map('unlink', glob("$imagesDir/*"));
        rmdir($imagesDir);
        
        return $html;
    }

    /**
     * Extract text with precise coordinates
     */
    private function extractTextWithCoordinates($pdfPath)
    {
        $command = sprintf(
            'pdftotext -layout -bbox %s - 2>&1',
            escapeshellarg($pdfPath)
        );
        
        exec($command, $output);
        
        $elements = [];
        $currentPage = 1;
        
        foreach ($output as $line) {
            if (preg_match('/<page number="(\d+)"/', $line, $matches)) {
                $currentPage = intval($matches[1]);
            } elseif (preg_match('/<word xMin="([0-9.]+)" yMin="([0-9.]+)" xMax="([0-9.]+)" yMax="([0-9.]+)">([^<]+)<\/word>/', $line, $matches)) {
                $elements[] = [
                    'page' => $currentPage,
                    'x' => floatval($matches[1]),
                    'y' => floatval($matches[2]),
                    'width' => floatval($matches[3]) - floatval($matches[1]),
                    'height' => floatval($matches[4]) - floatval($matches[2]),
                    'text' => html_entity_decode($matches[5])
                ];
            }
        }
        
        return $elements;
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
            'author' => ''
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
            }
        }
        
        return $info;
    }

    /**
     * Build enhanced HTML from elements
     */
    private function buildEnhancedHTML($elements, $imagesDir, $pdfInfo)
    {
        $html = '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($pdfInfo['title'] ?: 'Document PDF') . '</title>
    <style>
        * {
            box-sizing: border-box;
        }
        
        body {
            margin: 0;
            padding: 20px;
            background: #e9ecef;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        
        #pdfContent {
            position: relative;
            background: white;
            margin: 0 auto;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .pdf-page {
            position: relative;
            width: ' . $pdfInfo['width'] . 'pt;
            min-height: ' . $pdfInfo['height'] . 'pt;
            margin: 0 auto;
            page-break-after: always;
            background: white;
        }
        
        .pdf-page:not(:last-child) {
            border-bottom: 1px solid #dee2e6;
        }
        
        .pdf-background-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
            opacity: 1;
        }
        
        .pdf-text {
            position: absolute;
            z-index: 10;
            white-space: pre-wrap;
            cursor: text;
            line-height: 1.2;
        }
        
        .pdf-text:hover {
            background: rgba(255, 235, 59, 0.2);
        }
        
        .pdf-image {
            position: absolute;
            z-index: 5;
            max-width: 100%;
            height: auto;
        }
        
        .author-image {
            border-radius: 50%;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            z-index: 15;
        }
        
        @media print {
            body {
                padding: 0;
                background: white;
            }
            
            #pdfContent {
                box-shadow: none;
                border-radius: 0;
            }
            
            .pdf-page {
                page-break-after: always;
            }
        }
    </style>
</head>
<body>
    <div id="pdfContent" class="pdf-content">';
        
        // Group elements by page
        $pages = [];
        foreach ($elements as $element) {
            $page = $element['page'] ?? 1;
            if (!isset($pages[$page])) {
                $pages[$page] = [];
            }
            $pages[$page][] = $element;
        }
        
        // Process each page
        foreach ($pages as $pageNum => $pageElements) {
            $html .= '<div class="pdf-page" data-page="' . $pageNum . '">';
            
            // Add background image if exists
            $bgImage = $imagesDir . '/page-' . str_pad($pageNum, 3, '0', STR_PAD_LEFT) . '-000.jpg';
            if (file_exists($bgImage)) {
                $imageData = base64_encode(file_get_contents($bgImage));
                $html .= '<img class="pdf-background-image" src="data:image/jpeg;base64,' . $imageData . '" alt="">';
            }
            
            // Add text elements
            foreach ($pageElements as $element) {
                $fontSize = round($element['height'] * 0.75);
                $html .= sprintf(
                    '<span class="pdf-text" style="left: %spx; top: %spx; font-size: %spx;" contenteditable="true">%s</span>',
                    $element['x'],
                    $element['y'],
                    $fontSize,
                    htmlspecialchars($element['text'], ENT_QUOTES)
                );
            }
            
            $html .= '</div>';
        }
        
        $html .= '
    </div>
</body>
</html>';
        
        return $html;
    }

    /**
     * Check if command exists
     */
    private function commandExists($command)
    {
        $output = shell_exec("which $command 2>/dev/null");
        return !empty($output);
    }
}