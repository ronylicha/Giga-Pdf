<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use DOMDocument;
use DOMXPath;

class WkHtmlToPdfConverter
{
    private $wkhtmltopdfPath = '/usr/local/bin/wkhtmltopdf';
    
    /**
     * Convert HTML to PDF using wkhtmltopdf with page break handling
     */
    public function convertHtmlToPdf($htmlContent, $outputPath, $options = [])
    {
        try {
            // Preprocess HTML to handle page breaks properly
            $processedHtml = $this->preprocessHtmlForPageBreaks($htmlContent);
            
            // Save processed HTML to temporary file
            $tempHtmlFile = tempnam(sys_get_temp_dir(), 'html_') . '.html';
            file_put_contents($tempHtmlFile, $processedHtml);
            
            // Build wkhtmltopdf command with optimized options
            $command = $this->buildWkhtmltopdfCommand($tempHtmlFile, $outputPath, $options);
            
            // Execute command
            exec($command, $output, $returnCode);
            
            // Clean up temp file
            @unlink($tempHtmlFile);
            
            if ($returnCode !== 0) {
                throw new Exception('wkhtmltopdf conversion failed: ' . implode("\n", $output));
            }
            
            // Post-process PDF to remove blank pages
            $this->removeBlankPages($outputPath);
            
            return true;
            
        } catch (Exception $e) {
            Log::error('WkHtmlToPdf conversion failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Preprocess HTML to ensure proper page breaks
     */
    private function preprocessHtmlForPageBreaks($html)
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);
        
        // Find all page containers
        $pageContainers = $xpath->query('//div[@class="pdf-page-container"]');
        
        if ($pageContainers->length > 0) {
            // Add explicit page break styles
            foreach ($pageContainers as $index => $container) {
                $style = $container->getAttribute('style');
                
                // Ensure each page starts on a new page
                if ($index > 0) {
                    $style .= '; page-break-before: always;';
                }
                
                // Prevent content from breaking inside a page
                $style .= '; page-break-inside: avoid;';
                
                // Set fixed height to match PDF page size
                $style .= '; min-height: 297mm; max-height: 297mm;'; // A4 height
                $style .= '; width: 210mm;'; // A4 width
                $style .= '; margin: 0; padding: 20mm;';
                $style .= '; box-sizing: border-box;';
                $style .= '; overflow: hidden;';
                
                $container->setAttribute('style', $style);
                
                // Remove visual page markers for PDF generation
                $markers = $xpath->query('.//div[@class="pdf-page-break-marker"]', $container);
                foreach ($markers as $marker) {
                    $marker->parentNode->removeChild($marker);
                }
            }
        }
        
        // Add print-specific styles
        $head = $xpath->query('//head')->item(0);
        if ($head) {
            $printStyle = $dom->createElement('style');
            $printStyle->nodeValue = '
                @media print {
                    * {
                        -webkit-print-color-adjust: exact !important;
                        print-color-adjust: exact !important;
                    }
                    
                    body {
                        margin: 0 !important;
                        padding: 0 !important;
                        background: white !important;
                    }
                    
                    .pdf-page-container {
                        page-break-before: always;
                        page-break-inside: avoid;
                        page-break-after: auto;
                        position: relative !important;
                        margin: 0 !important;
                        padding: 0 !important;
                        width: 210mm !important;
                        height: 297mm !important;
                        overflow: hidden !important;
                        background: white !important;
                        box-shadow: none !important;
                        border: none !important;
                    }
                    
                    .pdf-page-container:first-child {
                        page-break-before: auto;
                    }
                    
                    .pdf-page-content {
                        position: relative !important;
                        width: 100% !important;
                        height: 100% !important;
                        padding: 20mm !important;
                        box-sizing: border-box !important;
                    }
                    
                    /* Hide elements that should not appear in PDF */
                    .pdf-page-break-marker,
                    .toolbar,
                    .instructions,
                    .page-marker,
                    .delete-btn,
                    .no-print {
                        display: none !important;
                    }
                    
                    /* Ensure images and text maintain their positions */
                    [style*="position: absolute"] {
                        position: absolute !important;
                    }
                    
                    /* Prevent empty pages */
                    .empty-page,
                    :empty:not(img):not(input):not(br):not(hr) {
                        display: none !important;
                    }
                }
                
                /* Remove any default margins that might cause blank pages */
                @page {
                    margin: 0;
                    size: A4;
                }
                
                /* Ensure first page doesn\'t have extra spacing */
                @page :first {
                    margin-top: 0;
                }
            ';
            $head->appendChild($printStyle);
        }
        
        return $dom->saveHTML();
    }
    
    /**
     * Build wkhtmltopdf command with optimized options
     */
    private function buildWkhtmltopdfCommand($inputFile, $outputFile, $options = [])
    {
        // Check if wkhtmltopdf exists
        if (!file_exists($this->wkhtmltopdfPath)) {
            $this->wkhtmltopdfPath = 'wkhtmltopdf'; // Try system path
        }
        
        // Default options optimized for avoiding blank pages
        $defaultOptions = [
            '--page-size' => 'A4',
            '--margin-top' => '0',
            '--margin-bottom' => '0',
            '--margin-left' => '0',
            '--margin-right' => '0',
            '--disable-smart-shrinking' => '',
            '--print-media-type' => '',
            '--no-background' => false, // Keep backgrounds
            '--enable-local-file-access' => '',
            '--javascript-delay' => '1000',
            '--no-stop-slow-scripts' => '',
            '--debug-javascript' => '',
            '--load-error-handling' => 'ignore',
            '--load-media-error-handling' => 'ignore',
            '--dpi' => '96',
            '--image-quality' => '94',
            '--enable-forms' => '',
        ];
        
        // Merge with user options
        $finalOptions = array_merge($defaultOptions, $options);
        
        // Build command
        $command = escapeshellcmd($this->wkhtmltopdfPath);
        
        foreach ($finalOptions as $key => $value) {
            if ($value === false) {
                continue;
            }
            if ($value === '' || $value === true) {
                $command .= ' ' . escapeshellarg($key);
            } else {
                $command .= ' ' . escapeshellarg($key) . ' ' . escapeshellarg($value);
            }
        }
        
        $command .= ' ' . escapeshellarg($inputFile) . ' ' . escapeshellarg($outputFile) . ' 2>&1';
        
        return $command;
    }
    
    /**
     * Remove blank pages from PDF using PyMuPDF
     */
    private function removeBlankPages($pdfPath)
    {
        $pythonScript = <<<'PYTHON'
import sys
import fitz  # PyMuPDF

def remove_blank_pages(pdf_path):
    try:
        doc = fitz.open(pdf_path)
        non_blank_pages = []
        
        for page_num in range(len(doc)):
            page = doc[page_num]
            
            # Check if page has content
            # A page is considered blank if it has very little text and no images
            text = page.get_text().strip()
            images = page.get_images()
            drawings = page.get_drawings()
            
            # Page is not blank if it has:
            # - More than 10 characters of text
            # - Any images
            # - Any vector drawings
            has_content = (
                len(text) > 10 or 
                len(images) > 0 or 
                len(drawings) > 0
            )
            
            if has_content:
                non_blank_pages.append(page_num)
        
        # If all pages are blank or only one page exists, keep at least one
        if not non_blank_pages:
            non_blank_pages = [0]
        
        # Create new document with only non-blank pages
        if len(non_blank_pages) < len(doc):
            new_doc = fitz.open()
            for page_num in non_blank_pages:
                new_doc.insert_pdf(doc, from_page=page_num, to_page=page_num)
            
            # Save the cleaned PDF
            new_doc.save(pdf_path + '.cleaned', garbage=4, deflate=True)
            new_doc.close()
            doc.close()
            
            # Replace original with cleaned version
            import os
            os.replace(pdf_path + '.cleaned', pdf_path)
            
            return len(doc) - len(non_blank_pages)  # Return number of removed pages
        
        doc.close()
        return 0
        
    except Exception as e:
        print(f"Error removing blank pages: {e}", file=sys.stderr)
        return -1

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python remove_blank_pages.py <pdf_file>", file=sys.stderr)
        sys.exit(1)
    
    removed_count = remove_blank_pages(sys.argv[1])
    if removed_count > 0:
        print(f"Removed {removed_count} blank pages")
    elif removed_count == 0:
        print("No blank pages found")
    else:
        print("Error processing PDF")
        sys.exit(1)
PYTHON;
        
        // Save Python script to temp file
        $scriptFile = tempnam(sys_get_temp_dir(), 'remove_blank_') . '.py';
        file_put_contents($scriptFile, $pythonScript);
        
        // Execute Python script
        $command = sprintf(
            'python3 %s %s 2>&1',
            escapeshellarg($scriptFile),
            escapeshellarg($pdfPath)
        );
        
        exec($command, $output, $returnCode);
        
        // Clean up temp script
        @unlink($scriptFile);
        
        if ($returnCode === 0) {
            Log::info('Blank pages removed: ' . implode("\n", $output));
        } else {
            Log::warning('Could not remove blank pages: ' . implode("\n", $output));
        }
    }
    
    /**
     * Alternative HTML to PDF conversion using headless Chrome
     */
    public function convertWithChrome($htmlContent, $outputPath, $options = [])
    {
        // This is an alternative using Chrome/Chromium headless
        // which often handles page breaks better than wkhtmltopdf
        
        $tempHtmlFile = tempnam(sys_get_temp_dir(), 'html_') . '.html';
        file_put_contents($tempHtmlFile, $this->preprocessHtmlForPageBreaks($htmlContent));
        
        $command = sprintf(
            'chromium-browser --headless --disable-gpu --print-to-pdf=%s --no-margins --print-background %s 2>&1',
            escapeshellarg($outputPath),
            escapeshellarg('file://' . $tempHtmlFile)
        );
        
        exec($command, $output, $returnCode);
        
        @unlink($tempHtmlFile);
        
        if ($returnCode !== 0) {
            // Try with google-chrome if chromium fails
            $command = str_replace('chromium-browser', 'google-chrome', $command);
            exec($command, $output, $returnCode);
        }
        
        if ($returnCode === 0) {
            $this->removeBlankPages($outputPath);
            return true;
        }
        
        throw new Exception('Chrome PDF conversion failed: ' . implode("\n", $output));
    }
}