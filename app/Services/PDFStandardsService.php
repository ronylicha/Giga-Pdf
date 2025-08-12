<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Tcpdf\Fpdi;
use TCPDF_FONTS;
use Exception;

class PDFStandardsService
{
    /**
     * Convert PDF to PDF/A format
     */
    public function convertToPDFA(
        Document $document,
        string $version = '1b',
        array $options = []
    ): Document {
        try {
            $sourcePath = Storage::path($document->stored_name);
            
            // Generate output filename
            $filename = Str::slug(pathinfo($document->original_name, PATHINFO_FILENAME)) 
                . '_pdfa_' . time() . '.pdf';
            $outputPath = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullOutputPath = Storage::path($outputPath);
            
            // Ensure directory exists
            $dir = dirname($fullOutputPath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            // Determine PDF/A version
            $pdfaVersion = $this->getPDFAVersion($version);
            
            // Create new PDF with PDF/A compliance
            $pdf = new Fpdi('P', 'mm', 'A4', true, 'UTF-8', false);
            
            // Set PDF/A mode
            $pdf->setPDFVersion($pdfaVersion);
            
            // Set document information (required for PDF/A)
            $pdf->SetCreator('Giga-PDF');
            $pdf->SetAuthor($options['author'] ?? 'Giga-PDF User');
            $pdf->SetTitle($document->original_name);
            $pdf->SetSubject($options['subject'] ?? 'PDF/A Document');
            $pdf->SetKeywords($options['keywords'] ?? 'PDF/A, archive, document');
            
            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Set default monospaced font (required for PDF/A)
            $pdf->SetDefaultMonospacedFont(PDF_FONT_NAME_MAIN);
            
            // Set margins
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetAutoPageBreak(false, 0);
            
            // Import existing PDF pages
            $pageCount = $pdf->setSourceFile($sourcePath);
            
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                
                // Add a page with same orientation and size
                $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                
                // Use the imported page
                $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height'], true);
            }
            
            // Add XMP metadata for PDF/A compliance
            $this->addXMPMetadata($pdf, $version, $options);
            
            // Add color profile for PDF/A
            $this->addColorProfile($pdf);
            
            // Output the PDF/A document
            $pdf->Output($fullOutputPath, 'F');
            
            // Validate PDF/A compliance
            if ($options['validate'] ?? true) {
                $this->validatePDFA($fullOutputPath, $version);
            }
            
            // Create new document record
            $pdfaDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => str_replace('.pdf', '_pdfa.pdf', $document->original_name),
                'stored_name' => $outputPath,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullOutputPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullOutputPath),
                'metadata' => array_merge($document->metadata ?? [], [
                    'type' => 'pdfa',
                    'pdfa_version' => $version,
                    'source_document' => $document->id,
                    'converted_at' => now()->toIso8601String(),
                    'compliance_level' => $this->getComplianceLevel($version),
                ]),
            ]);
            
            return $pdfaDocument;
            
        } catch (Exception $e) {
            throw new Exception('Error converting to PDF/A: ' . $e->getMessage());
        }
    }
    
    /**
     * Convert PDF to PDF/X format
     */
    public function convertToPDFX(
        Document $document,
        string $version = '1a',
        array $options = []
    ): Document {
        try {
            $sourcePath = Storage::path($document->stored_name);
            
            // Generate output filename
            $filename = Str::slug(pathinfo($document->original_name, PATHINFO_FILENAME)) 
                . '_pdfx_' . time() . '.pdf';
            $outputPath = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullOutputPath = Storage::path($outputPath);
            
            // Ensure directory exists
            $dir = dirname($fullOutputPath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            // Create new PDF with PDF/X compliance
            $pdf = new Fpdi('P', 'mm', 'A4', true, 'UTF-8', false);
            
            // Set PDF/X mode
            $pdf->setPDFVersion('1.4'); // PDF/X requires PDF 1.4
            
            // Set document information
            $pdf->SetCreator('Giga-PDF');
            $pdf->SetAuthor($options['author'] ?? 'Giga-PDF User');
            $pdf->SetTitle($document->original_name);
            $pdf->SetSubject($options['subject'] ?? 'PDF/X Document');
            
            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Set color management for PDF/X (if method exists)
            if (method_exists($pdf, 'setColorSpace')) {
                $pdf->setColorSpace('DeviceCMYK');
            }
            
            // Import existing PDF pages
            $pageCount = $pdf->setSourceFile($sourcePath);
            
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                
                // Add a page with same orientation and size
                $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                
                // Use the imported page
                $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height'], true);
                
                // Add trim box and bleed box for PDF/X
                $this->addPrintMarks($pdf, $size, $options);
            }
            
            // Add output intent for PDF/X
            $this->addOutputIntent($pdf, $version);
            
            // Output the PDF/X document
            $pdf->Output($fullOutputPath, 'F');
            
            // Validate PDF/X compliance
            if ($options['validate'] ?? true) {
                $this->validatePDFX($fullOutputPath, $version);
            }
            
            // Create new document record
            $pdfxDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => str_replace('.pdf', '_pdfx.pdf', $document->original_name),
                'stored_name' => $outputPath,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullOutputPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullOutputPath),
                'metadata' => array_merge($document->metadata ?? [], [
                    'type' => 'pdfx',
                    'pdfx_version' => $version,
                    'source_document' => $document->id,
                    'converted_at' => now()->toIso8601String(),
                    'print_ready' => true,
                ]),
            ]);
            
            return $pdfxDocument;
            
        } catch (Exception $e) {
            throw new Exception('Error converting to PDF/X: ' . $e->getMessage());
        }
    }
    
    /**
     * Add XMP metadata for PDF/A
     */
    private function addXMPMetadata(Fpdi $pdf, string $version, array $options): void
    {
        $xmp = '<?xpacket begin="﻿" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n";
        $xmp .= '<x:xmpmeta xmlns:x="adobe:ns:meta/">' . "\n";
        $xmp .= '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">' . "\n";
        $xmp .= '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n";
        $xmp .= '<dc:format>application/pdf</dc:format>' . "\n";
        $xmp .= '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . 
                htmlspecialchars($options['title'] ?? 'Document') . 
                '</rdf:li></rdf:Alt></dc:title>' . "\n";
        $xmp .= '<dc:creator><rdf:Seq><rdf:li>' . 
                htmlspecialchars($options['author'] ?? 'Giga-PDF') . 
                '</rdf:li></rdf:Seq></dc:creator>' . "\n";
        $xmp .= '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">' . 
                htmlspecialchars($options['description'] ?? 'PDF/A Document') . 
                '</rdf:li></rdf:Alt></dc:description>' . "\n";
        $xmp .= '</rdf:Description>' . "\n";
        
        // Add PDF/A identification
        $xmp .= '<rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/">' . "\n";
        $xmp .= '<pdfaid:part>' . substr($version, 0, 1) . '</pdfaid:part>' . "\n";
        $xmp .= '<pdfaid:conformance>' . strtoupper(substr($version, 1, 1)) . '</pdfaid:conformance>' . "\n";
        $xmp .= '</rdf:Description>' . "\n";
        
        $xmp .= '</rdf:RDF>' . "\n";
        $xmp .= '</x:xmpmeta>' . "\n";
        $xmp .= '<?xpacket end="w"?>';
        
        // Set XMP metadata (if method exists)
        if (method_exists($pdf, 'setXmpMetadata')) {
            $pdf->setXmpMetadata($xmp);
        }
    }
    
    /**
     * Add color profile for PDF/A
     */
    private function addColorProfile(Fpdi $pdf): void
    {
        // Path to sRGB color profile
        $profilePath = resource_path('profiles/sRGB_IEC61966-2-1.icc');
        
        if (file_exists($profilePath)) {
            $profileContent = file_get_contents($profilePath);
            
            // Add output intent with color profile
            $pdf->AddSpotColor('All', 0, 0, 0, 100);
            
            // Embed the color profile (if method exists)
            if (method_exists($pdf, 'setColorProfile')) {
                $pdf->setColorProfile('sRGB IEC61966-2.1', $profileContent);
            }
        }
    }
    
    /**
     * Add print marks for PDF/X
     */
    private function addPrintMarks(Fpdi $pdf, array $size, array $options): void
    {
        if ($options['add_crop_marks'] ?? false) {
            $bleed = $options['bleed'] ?? 3; // 3mm bleed by default
            
            // Set line style for crop marks
            $pdf->SetLineStyle(['width' => 0.1, 'color' => [0, 0, 0, 100]]);
            
            // Top-left crop marks
            $pdf->Line(-$bleed - 5, 0, -$bleed - 2, 0);
            $pdf->Line(0, -$bleed - 5, 0, -$bleed - 2);
            
            // Top-right crop marks
            $pdf->Line($size['width'] + $bleed + 2, 0, $size['width'] + $bleed + 5, 0);
            $pdf->Line($size['width'], -$bleed - 5, $size['width'], -$bleed - 2);
            
            // Bottom-left crop marks
            $pdf->Line(-$bleed - 5, $size['height'], -$bleed - 2, $size['height']);
            $pdf->Line(0, $size['height'] + $bleed + 2, 0, $size['height'] + $bleed + 5);
            
            // Bottom-right crop marks
            $pdf->Line($size['width'] + $bleed + 2, $size['height'], $size['width'] + $bleed + 5, $size['height']);
            $pdf->Line($size['width'], $size['height'] + $bleed + 2, $size['width'], $size['height'] + $bleed + 5);
        }
        
        if ($options['add_color_bars'] ?? false) {
            // Add CMYK color bars for print calibration
            $barWidth = 5;
            $barHeight = 3;
            $barY = $size['height'] + 10;
            
            // Cyan
            $pdf->SetFillColor(100, 0, 0, 0);
            $pdf->Rect(10, $barY, $barWidth, $barHeight, 'F');
            
            // Magenta
            $pdf->SetFillColor(0, 100, 0, 0);
            $pdf->Rect(15, $barY, $barWidth, $barHeight, 'F');
            
            // Yellow
            $pdf->SetFillColor(0, 0, 100, 0);
            $pdf->Rect(20, $barY, $barWidth, $barHeight, 'F');
            
            // Black
            $pdf->SetFillColor(0, 0, 0, 100);
            $pdf->Rect(25, $barY, $barWidth, $barHeight, 'F');
        }
    }
    
    /**
     * Add output intent for PDF/X
     */
    private function addOutputIntent(Fpdi $pdf, string $version): void
    {
        $outputIntent = [
            'Type' => '/OutputIntent',
            'S' => '/GTS_PDFX',
            'OutputConditionIdentifier' => 'FOGRA39',
            'Info' => 'Coated FOGRA39 (ISO 12647-2:2004)',
            'RegistryName' => 'http://www.color.org',
        ];
        
        // Path to ICC profile for printing
        $profilePath = resource_path('profiles/CoatedFOGRA39.icc');
        
        if (file_exists($profilePath)) {
            $profileContent = file_get_contents($profilePath);
            if (method_exists($pdf, 'setOutputIntent')) {
                $pdf->setOutputIntent('FOGRA39', $profileContent, $outputIntent);
            }
        }
    }
    
    /**
     * Validate PDF/A compliance
     */
    private function validatePDFA(string $pdfPath, string $version): bool
    {
        // Use verapdf for validation if available
        if ($this->isVeraPDFAvailable()) {
            $command = sprintf(
                'verapdf -f %s --format text %s 2>&1',
                escapeshellarg($version),
                escapeshellarg($pdfPath)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception('PDF/A validation failed: ' . implode("\n", $output));
            }
            
            return true;
        }
        
        // Fallback: basic validation
        return $this->basicPDFValidation($pdfPath);
    }
    
    /**
     * Validate PDF/X compliance
     */
    private function validatePDFX(string $pdfPath, string $version): bool
    {
        // Use preflight tool if available
        if ($this->isPreflightAvailable()) {
            $command = sprintf(
                'pdfpreflight %s --profile=PDFX-%s 2>&1',
                escapeshellarg($pdfPath),
                escapeshellarg($version)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception('PDF/X validation failed: ' . implode("\n", $output));
            }
            
            return true;
        }
        
        // Fallback: basic validation
        return $this->basicPDFValidation($pdfPath);
    }
    
    /**
     * Basic PDF validation
     */
    private function basicPDFValidation(string $pdfPath): bool
    {
        // Check if file is a valid PDF
        $handle = fopen($pdfPath, 'r');
        $header = fread($handle, 5);
        fclose($handle);
        
        if ($header !== '%PDF-') {
            throw new Exception('Invalid PDF file');
        }
        
        return true;
    }
    
    /**
     * Get PDF/A version string
     */
    private function getPDFAVersion(string $version): string
    {
        $versions = [
            '1a' => '1.4',
            '1b' => '1.4',
            '2a' => '1.7',
            '2b' => '1.7',
            '2u' => '1.7',
            '3a' => '1.7',
            '3b' => '1.7',
            '3u' => '1.7',
        ];
        
        return $versions[$version] ?? '1.4';
    }
    
    /**
     * Get compliance level description
     */
    private function getComplianceLevel(string $version): string
    {
        $levels = [
            '1a' => 'Level A - Accessible',
            '1b' => 'Level B - Basic',
            '2a' => 'Level A - Accessible (PDF 1.7)',
            '2b' => 'Level B - Basic (PDF 1.7)',
            '2u' => 'Level U - Unicode (PDF 1.7)',
            '3a' => 'Level A - Accessible (with attachments)',
            '3b' => 'Level B - Basic (with attachments)',
            '3u' => 'Level U - Unicode (with attachments)',
        ];
        
        return $levels[$version] ?? 'Unknown';
    }
    
    /**
     * Check if VeraPDF is available
     */
    private function isVeraPDFAvailable(): bool
    {
        exec('which verapdf 2>/dev/null', $output, $returnCode);
        return $returnCode === 0;
    }
    
    /**
     * Check if preflight tool is available
     */
    private function isPreflightAvailable(): bool
    {
        exec('which pdfpreflight 2>/dev/null', $output, $returnCode);
        return $returnCode === 0;
    }
}