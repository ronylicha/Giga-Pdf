<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\Tcpdf\Fpdi as TcpdfFpdi;
use TCPDF;

class PDFContentService
{
    protected $fontManager;

    public function __construct()
    {
        $this->fontManager = new FontManager();
    }
    /**
     * Extract text content from PDF with position information
     */
    public function extractTextWithPositions($pdfPath)
    {
        $textElements = [];

        // First try with pdftohtml which gives better position information
        $tempHtmlFile = tempnam(sys_get_temp_dir(), 'pdf_') . '.xml';
        $command = sprintf(
            'pdftohtml -xml -i -noframes %s %s 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($tempHtmlFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode === 0 && file_exists($tempHtmlFile)) {
            $xml = file_get_contents($tempHtmlFile);
            $textElements = $this->parseXMLContent($xml);
            unlink($tempHtmlFile);
        } else {
            // Fallback to pdftotext with layout
            $command = sprintf(
                'pdftotext -layout -bbox %s - 2>&1',
                escapeshellarg($pdfPath)
            );

            exec($command, $output, $returnCode);

            if ($returnCode === 0) {
                $textElements = $this->parseTextLayout(implode("\n", $output));
            }
        }

        return $textElements;
    }

    /**
     * Parse XML content from pdftohtml
     */
    private function parseXMLContent($xmlContent)
    {
        $textElements = [];

        try {
            // Remove DOCTYPE declaration if present
            $xmlContent = preg_replace('/<!DOCTYPE[^>]*>/', '', $xmlContent);

            // Parse XML
            $xml = simplexml_load_string($xmlContent);

            if ($xml === false) {
                return [];
            }

            // Iterate through pages
            foreach ($xml->page as $page) {
                $pageNumber = (int)$page['number'];
                $pageHeight = (float)$page['height'];
                $pageWidth = (float)$page['width'];

                // Extract text elements
                foreach ($page->text as $text) {
                    $content = trim((string)$text);
                    if (empty($content)) {
                        continue;
                    }

                    $textElements[] = [
                        'text' => $content,
                        'page' => $pageNumber,
                        'x' => (float)$text['left'],
                        'y' => (float)$text['top'],
                        'width' => (float)$text['width'],
                        'height' => (float)$text['height'],
                        'font' => (string)$text['font'],
                        'size' => (float)$text['size'],
                    ];
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error parsing PDF XML: ' . $e->getMessage());
        }

        return $textElements;
    }

    /**
     * Parse text layout from pdftotext
     */
    private function parseTextLayout($content)
    {
        $textElements = [];
        $lines = explode("\n", $content);
        $currentPage = 1;
        $yPosition = 0;

        foreach ($lines as $line) {
            // Check for page break
            if (preg_match('/\f/', $line)) {
                $currentPage++;
                $yPosition = 0;

                continue;
            }

            // Skip empty lines
            $trimmedLine = trim($line);
            if (empty($trimmedLine)) {
                $yPosition += 12; // Approximate line height

                continue;
            }

            // Find text position in line (accounting for leading spaces)
            $xPosition = strlen($line) - strlen(ltrim($line));
            $xPosition = $xPosition * 7; // Approximate character width

            $textElements[] = [
                'text' => $trimmedLine,
                'page' => $currentPage,
                'x' => $xPosition,
                'y' => $yPosition,
                'width' => strlen($trimmedLine) * 7, // Approximate width
                'height' => 12, // Approximate height
                'font' => 'default',
                'size' => 12,
            ];

            $yPosition += 12;
        }

        return $textElements;
    }

    /**
     * Replace text in PDF using TCPDF for better text handling
     */
    public function replaceText($pdfPath, $replacements)
    {
        // Use TCPDF with FPDI for better text manipulation
        $pdf = new TcpdfFpdi();

        // Disable automatic page break
        $pdf->SetAutoPageBreak(false);

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Get the number of pages
        $pageCount = $pdf->setSourceFile($pdfPath);

        // Process each page
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);

            // Add a page with the same size
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);

            // Use the imported page as template
            $pdf->useTemplate($templateId);

            // Apply text replacements for this page
            if (isset($replacements[$pageNo])) {
                foreach ($replacements[$pageNo] as $replacement) {
                    $this->applyTextReplacement($pdf, $replacement);
                }
            }
        }

        // Save the modified PDF - use system temp directory to avoid path issues
        $outputPath = tempnam(sys_get_temp_dir(), 'pdf_') . '.pdf';

        // Output the PDF
        $pdf->Output($outputPath, 'F');

        return $outputPath;
    }

    /**
     * Apply a single text replacement
     */
    private function applyTextReplacement($pdf, $replacement)
    {
        // Get appropriate font using FontManager
        $requestedFont = $replacement['font'] ?? 'helvetica';
        $fontName = $this->fontManager->getFontName($requestedFont);

        // Set font
        $pdf->SetFont($fontName, '', $replacement['fontSize'] ?? 12);

        // Set text color
        if (isset($replacement['color'])) {
            $rgb = $this->hexToRgb($replacement['color']);
            $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
        }

        // Cover the old text with a white rectangle if needed
        if ($replacement['coverOld'] ?? true) {
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect($replacement['x'], $replacement['y'], $replacement['width'], $replacement['height'], 'F');
        }

        // Add the new text
        $pdf->SetXY($replacement['x'], $replacement['y']);
        $pdf->Cell($replacement['width'], $replacement['height'], $replacement['newText'], 0, 0, $replacement['align'] ?? 'L');
    }

    /**
     * Add text to PDF at specific position
     */
    public function addText($pdfPath, $textAdditions)
    {
        $pdf = new TcpdfFpdi();

        // Disable automatic page break
        $pdf->SetAutoPageBreak(false);

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Get the number of pages
        $pageCount = $pdf->setSourceFile($pdfPath);

        // Process each page
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);

            // Add a page with the same size
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);

            // Use the imported page as template
            $pdf->useTemplate($templateId);

            // Add new text for this page
            if (isset($textAdditions[$pageNo])) {
                foreach ($textAdditions[$pageNo] as $textItem) {
                    $this->addTextItem($pdf, $textItem);
                }
            }
        }

        // Save the modified PDF - use system temp directory to avoid path issues
        $outputPath = tempnam(sys_get_temp_dir(), 'pdf_') . '.pdf';

        // Output the PDF
        $pdf->Output($outputPath, 'F');

        return $outputPath;
    }

    /**
     * Add a single text item
     */
    private function addTextItem($pdf, $textItem)
    {
        // Get appropriate font using FontManager
        $requestedFont = $textItem['font'] ?? 'helvetica';
        $fontName = $this->fontManager->getFontName($requestedFont, $textItem['style'] ?? '');

        // Set font
        $pdf->SetFont(
            $fontName,
            $textItem['style'] ?? '',
            $textItem['fontSize'] ?? 12
        );

        // Set text color
        if (isset($textItem['color'])) {
            $rgb = $this->hexToRgb($textItem['color']);
            $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
        }

        // Add background if specified
        if (isset($textItem['backgroundColor'])) {
            $rgb = $this->hexToRgb($textItem['backgroundColor']);
            $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
            $pdf->Rect($textItem['x'], $textItem['y'], $textItem['width'] ?? 50, $textItem['height'] ?? 10, 'F');
        }

        // Add the text
        $pdf->SetXY($textItem['x'], $textItem['y']);

        if (isset($textItem['width']) && isset($textItem['height'])) {
            // Multi-line text
            $pdf->MultiCell(
                $textItem['width'],
                $textItem['height'],
                $textItem['text'],
                $textItem['border'] ?? 0,
                $textItem['align'] ?? 'L',
                $textItem['fill'] ?? false
            );
        } else {
            // Single line text
            $pdf->Text($textItem['x'], $textItem['y'], $textItem['text']);
        }
    }

    /**
     * Delete text from PDF (cover with white rectangle)
     */
    public function deleteText($pdfPath, $deletions)
    {
        $pdf = new TcpdfFpdi();

        // Disable automatic page break
        $pdf->SetAutoPageBreak(false);

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Get the number of pages
        $pageCount = $pdf->setSourceFile($pdfPath);

        // Process each page
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);

            // Add a page with the same size
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);

            // Use the imported page as template
            $pdf->useTemplate($templateId);

            // Apply deletions for this page
            if (isset($deletions[$pageNo])) {
                foreach ($deletions[$pageNo] as $deletion) {
                    // Cover with white rectangle
                    $pdf->SetFillColor(255, 255, 255);
                    $pdf->Rect($deletion['x'], $deletion['y'], $deletion['width'], $deletion['height'], 'F');
                }
            }
        }

        // Save the modified PDF - use system temp directory to avoid path issues
        $outputPath = tempnam(sys_get_temp_dir(), 'pdf_') . '.pdf';

        // Output the PDF
        $pdf->Output($outputPath, 'F');

        return $outputPath;
    }

    /**
     * Convert hex color to RGB
     */
    private function hexToRgb($hex)
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Extract form fields from PDF
     */
    public function extractFormFields($pdfPath)
    {
        // Use pdftk or similar tool to extract form fields
        $command = "pdftk " . escapeshellarg($pdfPath) . " dump_data_fields";
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return [];
        }

        return $this->parseFormFields($output);
    }

    /**
     * Parse form fields from pdftk output
     */
    private function parseFormFields($output)
    {
        $fields = [];
        $currentField = [];

        foreach ($output as $line) {
            if (strpos($line, '---') === 0) {
                if (! empty($currentField)) {
                    $fields[] = $currentField;
                    $currentField = [];
                }
            } else {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    $currentField[$key] = $value;
                }
            }
        }

        if (! empty($currentField)) {
            $fields[] = $currentField;
        }

        return $fields;
    }

    /**
     * Fill form fields in PDF
     */
    public function fillFormFields($pdfPath, $fieldData)
    {
        // Create FDF file with form data
        $fdfData = $this->createFDF($fieldData);
        $fdfPath = Storage::path('temp/' . uniqid() . '.fdf');
        file_put_contents($fdfPath, $fdfData);

        // Use pdftk to fill the form
        $outputPath = Storage::path('temp/' . uniqid() . '.pdf');
        $command = sprintf(
            "pdftk %s fill_form %s output %s flatten",
            escapeshellarg($pdfPath),
            escapeshellarg($fdfPath),
            escapeshellarg($outputPath)
        );

        exec($command, $output, $returnCode);

        // Clean up FDF file
        unlink($fdfPath);

        if ($returnCode !== 0) {
            throw new Exception("Failed to fill form fields");
        }

        return $outputPath;
    }

    /**
     * Create FDF data for form filling
     */
    private function createFDF($data)
    {
        $fdf = "%FDF-1.2\n1 0 obj\n<< /FDF << /Fields [\n";

        foreach ($data as $field => $value) {
            $fdf .= "<< /T (" . $this->escapeFDF($field) . ") /V (" . $this->escapeFDF($value) . ") >>\n";
        }

        $fdf .= "] >> >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";

        return $fdf;
    }

    /**
     * Escape string for FDF format
     */
    private function escapeFDF($str)
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $str);
    }
}
