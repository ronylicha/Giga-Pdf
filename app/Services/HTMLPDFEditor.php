<?php

namespace App\Services;

use Exception;

class HTMLPDFEditor
{
    /**
     * Edit PDF by converting to HTML, modifying, and converting back
     */
    public function editViaHTML($pdfPath, $modifications)
    {
        try {
            // Step 1: Convert PDF to HTML
            $htmlContent = $this->convertPDFToHTML($pdfPath);

            // Step 2: Apply modifications to HTML
            $modifiedHtml = $this->applyModificationsToHTML($htmlContent, $modifications);

            // Step 3: Convert HTML back to PDF
            $outputPath = $this->convertHTMLToPDF($modifiedHtml);

            return $outputPath;

        } catch (Exception $e) {
            throw new Exception("Failed to edit PDF via HTML: " . $e->getMessage());
        }
    }

    /**
     * Convert PDF to HTML with layout preservation
     */
    private function convertPDFToHTML($pdfPath)
    {
        // Method 1: Using pdf2htmlEX (best quality if available)
        if ($this->commandExists('pdf2htmlEX')) {
            return $this->convertWithPdf2htmlEX($pdfPath);
        }

        // Method 2: Using pdftohtml
        if ($this->commandExists('pdftohtml')) {
            return $this->convertWithPdftohtml($pdfPath);
        }

        // Method 3: Using custom extraction with styling
        return $this->customPDFToHTML($pdfPath);
    }

    /**
     * Convert with pdf2htmlEX (preserves layout best)
     */
    private function convertWithPdf2htmlEX($pdfPath)
    {
        $htmlFile = tempnam(sys_get_temp_dir(), 'pdf_') . '.html';

        $command = sprintf(
            'pdf2htmlEX --zoom 1.3 --process-outline 0 --dest-dir %s %s %s 2>&1',
            escapeshellarg(dirname($htmlFile)),
            escapeshellarg($pdfPath),
            escapeshellarg(basename($htmlFile))
        );

        exec($command, $output, $returnCode);

        if ($returnCode === 0 && file_exists($htmlFile)) {
            $html = file_get_contents($htmlFile);
            unlink($htmlFile);

            return $html;
        }

        throw new Exception("pdf2htmlEX conversion failed");
    }

    /**
     * Convert with pdftohtml
     */
    private function convertWithPdftohtml($pdfPath)
    {
        $baseFile = tempnam(sys_get_temp_dir(), 'pdf_');
        $htmlFile = $baseFile . '.html';

        // Use advanced flags for better layout preservation
        // -c: generate complex HTML with CSS
        // -s: single HTML file
        // -zoom 1.5: better text size
        // -fontfullname: use full font names
        $command = sprintf(
            'pdftohtml -c -s -noframes -zoom 1.5 -fontfullname %s %s 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($baseFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode === 0) {
            // pdftohtml creates the file with .html extension
            if (file_exists($htmlFile)) {
                $html = file_get_contents($htmlFile);
                unlink($htmlFile);
                @unlink($baseFile);

                return $this->cleanupPdftohtml($html);
            }
        }

        throw new Exception("pdftohtml conversion failed: " . implode("\n", $output));
    }

    /**
     * Clean up pdftohtml output
     */
    private function cleanupPdftohtml($html)
    {
        // Preserve background images and styling
        // Keep images but ensure they don't block text
        $html = preg_replace_callback(
            '/<img([^>]+)>/i',
            function ($matches) {
                $imgTag = $matches[0];
                // If it's a background image (usually large), put it in background
                if (strpos($imgTag, 'width') !== false && strpos($imgTag, 'height') !== false) {
                    preg_match('/width="([0-9]+)"/i', $imgTag, $width);
                    preg_match('/height="([0-9]+)"/i', $imgTag, $height);
                    if (isset($width[1]) && isset($height[1])) {
                        $w = intval($width[1]);
                        $h = intval($height[1]);
                        // If image is page-sized, make it background
                        if ($w > 500 || $h > 700) {
                            return str_replace('<img', '<img style="position:absolute;z-index:-1;opacity:1;"', $imgTag);
                        }
                    }
                }

                return $imgTag;
            },
            $html
        );

        // Preserve table structures
        $html = str_replace('<table', '<table style="border-collapse:collapse;"', $html);

        // Preserve fonts and colors by enhancing style attributes
        $html = preg_replace_callback(
            '/<p([^>]*)style="([^"]*)"([^>]*)>/i',
            function ($matches) {
                $style = $matches[2];
                // Ensure text is above background images
                if (strpos($style, 'z-index') === false) {
                    $style .= ';z-index:1;position:relative';
                }

                return '<p' . $matches[1] . 'style="' . $style . '"' . $matches[3] . '>';
            },
            $html
        );

        // Ensure UTF-8 encoding
        if (function_exists('mb_convert_encoding')) {
            $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        }

        return $html;
    }

    /**
     * Custom PDF to HTML conversion using extraction
     */
    private function customPDFToHTML($pdfPath)
    {
        // Extract text with positions
        $contentService = new PDFContentService();
        $elements = $contentService->extractTextWithPositions($pdfPath);

        // Get PDF dimensions
        $dimensions = $this->getPDFDimensions($pdfPath);

        // Build HTML with absolute positioning
        $html = $this->buildHTMLFromElements($elements, $dimensions);

        return $html;
    }

    /**
     * Get PDF dimensions
     */
    private function getPDFDimensions($pdfPath)
    {
        $command = sprintf('pdfinfo %s 2>&1', escapeshellarg($pdfPath));
        exec($command, $output);

        $dimensions = ['width' => 595, 'height' => 842]; // A4 defaults

        foreach ($output as $line) {
            if (preg_match('/Page size:\s+([0-9.]+)\s+x\s+([0-9.]+)/', $line, $matches)) {
                $dimensions['width'] = floatval($matches[1]);
                $dimensions['height'] = floatval($matches[2]);

                break;
            }
        }

        return $dimensions;
    }

    /**
     * Build HTML from extracted elements
     */
    private function buildHTMLFromElements($elements, $dimensions)
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            size: ' . $dimensions['width'] . 'pt ' . $dimensions['height'] . 'pt;
            margin: 0;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
        }
        .page {
            position: relative;
            width: ' . $dimensions['width'] . 'pt;
            height: ' . $dimensions['height'] . 'pt;
            page-break-after: always;
            margin: 0;
            padding: 0;
        }
        .text-element {
            position: absolute;
            white-space: nowrap;
            overflow: visible;
        }
        @media print {
            .page {
                page-break-after: always;
            }
        }
    </style>
</head>
<body>';

        // Group elements by page
        $pages = [];
        foreach ($elements as $element) {
            $page = $element['page'] ?? 1;
            if (! isset($pages[$page])) {
                $pages[$page] = [];
            }
            $pages[$page][] = $element;
        }

        // Create HTML for each page
        foreach ($pages as $pageNum => $pageElements) {
            $html .= '<div class="page" data-page="' . $pageNum . '">';

            foreach ($pageElements as $element) {
                $id = 'text-' . md5(json_encode($element));
                $fontSize = $element['size'] ?? 12;

                $html .= sprintf(
                    '<div class="text-element" id="%s" style="left: %spx; top: %spx; font-size: %spt;" data-original="%s">%s</div>',
                    $id,
                    $element['x'] ?? 0,
                    $element['y'] ?? 0,
                    $fontSize,
                    htmlspecialchars($element['text'], ENT_QUOTES),
                    htmlspecialchars($element['text'], ENT_QUOTES)
                );
            }

            $html .= '</div>';
        }

        $html .= '</body></html>';

        return $html;
    }

    /**
     * Apply modifications to HTML content
     */
    private function applyModificationsToHTML($html, $modifications)
    {
        // Load HTML into DOMDocument
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $xpath = new \DOMXPath($dom);

        foreach ($modifications as $mod) {
            $this->applyModificationToDOM($dom, $xpath, $mod);
        }

        // Save modified HTML
        $modifiedHtml = $dom->saveHTML();

        return $modifiedHtml;
    }

    /**
     * Apply a single modification to DOM
     */
    private function applyModificationToDOM($dom, $xpath, $mod)
    {
        // Find elements that match the position and text
        $page = $mod['page'] ?? 1;
        $x = $mod['x'] ?? 0;
        $y = $mod['y'] ?? 0;
        $tolerance = 10; // pixels tolerance for position matching

        // First try: Look for pdftohtml output format (p tags with style)
        $query = "//p[@style]";

        $elements = $xpath->query($query);
        $foundAndModified = false;

        foreach ($elements as $element) {
            $style = $element->getAttribute('style');

            // Parse position from style
            if (preg_match('/left:\s*([0-9.]+)px/', $style, $leftMatch) &&
                preg_match('/top:\s*([0-9.]+)px/', $style, $topMatch)) {

                $elemX = floatval($leftMatch[1]);
                $elemY = floatval($topMatch[1]);

                // Check if position matches (with tolerance)
                if (abs($elemX - $x) <= $tolerance && abs($elemY - $y) <= $tolerance) {
                    $currentText = trim($element->textContent);

                    // Check if text matches (for replace operations)
                    if ($mod['type'] === 'replace' && isset($mod['oldText'])) {
                        if ($currentText === trim($mod['oldText'])) {
                            // Replace the text
                            $element->textContent = $mod['newText'] ?? '';

                            // Update style if needed
                            if (isset($mod['color'])) {
                                $style .= ';color:' . $mod['color'];
                                $element->setAttribute('style', $style);
                            }

                            $foundAndModified = true;

                            break;
                        }
                    } elseif ($mod['type'] === 'delete') {
                        // Remove the element
                        $element->parentNode->removeChild($element);
                        $foundAndModified = true;

                        break;
                    }
                }
            }
        }

        // If not found, try our custom format (div with data-page)
        if (! $foundAndModified) {
            $query = sprintf(
                "//div[@data-page='%d']//div[@class='text-element']",
                $page
            );

            $elements = $xpath->query($query);

            foreach ($elements as $element) {
                $style = $element->getAttribute('style');
                $original = $element->getAttribute('data-original');

                // Parse position from style
                if (preg_match('/left:\s*([0-9.]+)px/', $style, $leftMatch) &&
                    preg_match('/top:\s*([0-9.]+)px/', $style, $topMatch)) {

                    $elemX = floatval($leftMatch[1]);
                    $elemY = floatval($topMatch[1]);

                    // Check if position matches (with tolerance)
                    if (abs($elemX - $x) <= $tolerance && abs($elemY - $y) <= $tolerance) {
                        // Check if text matches (for replace operations)
                        if ($mod['type'] === 'replace' && isset($mod['oldText'])) {
                            if (trim($original) === trim($mod['oldText']) ||
                                trim($element->textContent) === trim($mod['oldText'])) {
                                // Replace the text
                                $element->textContent = $mod['newText'] ?? '';

                                // Update style if needed
                                if (isset($mod['color'])) {
                                    $style .= '; color: ' . $mod['color'];
                                    $element->setAttribute('style', $style);
                                }

                                $foundAndModified = true;

                                break;
                            }
                        } elseif ($mod['type'] === 'delete') {
                            // Remove the element
                            $element->parentNode->removeChild($element);
                            $foundAndModified = true;

                            break;
                        }
                    }
                }
            }
        }

        // For 'add' type, create new element
        if ($mod['type'] === 'add' && isset($mod['text'])) {
            // Try to find the page container
            $pageDiv = $xpath->query("//div[@data-page='{$page}']")->item(0);

            // If not found, try pdftohtml format
            if (! $pageDiv) {
                $pageDiv = $xpath->query("//div[contains(@id, 'page')]")->item(0);
            }

            // If still not found, add to body
            if (! $pageDiv) {
                $pageDiv = $dom->getElementsByTagName('body')->item(0);
            }

            if ($pageDiv) {
                $newElement = $dom->createElement('p', htmlspecialchars($mod['text']));

                $style = sprintf(
                    'position:absolute;left:%spx;top:%spx;white-space:nowrap',
                    $x,
                    $y
                );

                if (isset($mod['fontSize'])) {
                    $style .= ';font-size:' . $mod['fontSize'] . 'pt';
                }

                if (isset($mod['color'])) {
                    $style .= ';color:' . $mod['color'];
                }

                $newElement->setAttribute('style', $style);
                $pageDiv->appendChild($newElement);
            }
        }
    }

    /**
     * Convert HTML back to PDF
     */
    private function convertHTMLToPDF($html)
    {
        // Save HTML to temp file
        $htmlFile = tempnam(sys_get_temp_dir(), 'modified_') . '.html';
        file_put_contents($htmlFile, $html);

        // Method 1: Try wkhtmltopdf (best quality)
        if ($this->commandExists('wkhtmltopdf')) {
            $pdfPath = $this->convertWithWkhtmltopdf($htmlFile);
            unlink($htmlFile);

            return $pdfPath;
        }

        // Method 2: Use TCPDF
        $pdfPath = $this->convertWithTCPDF($html);
        unlink($htmlFile);

        return $pdfPath;
    }

    /**
     * Convert with wkhtmltopdf
     */
    private function convertWithWkhtmltopdf($htmlFile)
    {
        $pdfFile = tempnam(sys_get_temp_dir(), 'output_') . '.pdf';

        // Enhanced wkhtmltopdf options for perfect layout preservation
        $command = sprintf(
            'wkhtmltopdf --enable-local-file-access --no-stop-slow-scripts ' .
            '--page-size A4 --dpi 300 --print-media-type ' .
            '--margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 0 ' .
            '--disable-smart-shrinking --zoom 1.0 ' .
            '--javascript-delay 1000 ' .
            '--enable-forms ' .
            '--encoding UTF-8 ' .
            '--load-error-handling ignore ' .
            '--load-media-error-handling ignore ' .
            '%s %s 2>&1',
            escapeshellarg($htmlFile),
            escapeshellarg($pdfFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode === 0 && file_exists($pdfFile)) {
            return $pdfFile;
        }

        throw new Exception("wkhtmltopdf conversion failed: " . implode("\n", $output));
    }

    /**
     * Convert with TCPDF
     */
    private function convertWithTCPDF($html)
    {
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);

        // Add a page
        $pdf->AddPage();

        // Write HTML
        $pdf->writeHTML($html, true, false, true, false, '');

        // Output to file
        $pdfFile = tempnam(sys_get_temp_dir(), 'output_') . '.pdf';
        $pdf->Output($pdfFile, 'F');

        return $pdfFile;
    }

    /**
     * Check if command exists
     */
    private function commandExists($command)
    {
        $result = shell_exec("which {$command} 2>/dev/null");

        return ! empty($result);
    }
}
