<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Tcpdf\Fpdi;
use TCPDF;
use Exception;

class PDFFormsService  
{
    /**
     * Create fillable PDF form
     */
    public function createForm(
        Document $document,
        array $fields,
        array $options = []
    ): Document {
        try {
            $sourcePath = Storage::path($document->stored_name);
            
            // Generate output filename
            $filename = Str::slug(pathinfo($document->original_name, PATHINFO_FILENAME)) 
                . '_form_' . time() . '.pdf';
            $outputPath = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullOutputPath = Storage::path($outputPath);
            
            // Ensure directory exists
            $dir = dirname($fullOutputPath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            // Create new PDF with form fields
            $pdf = new Fpdi();
            
            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Import existing PDF pages
            $pageCount = $pdf->setSourceFile($sourcePath);
            
            // Track field names for validation
            $fieldNames = [];
            
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                
                // Add a page with same orientation and size
                $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                
                // Use the imported page
                $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height'], true);
                
                // Add form fields for this page
                foreach ($fields as $field) {
                    if (($field['page'] ?? 1) === $pageNo) {
                        $this->addFormField($pdf, $field, $fieldNames);
                    }
                }
            }
            
            // Add JavaScript for form validation if requested
            if ($options['add_validation'] ?? false) {
                $this->addFormValidation($pdf, $fields);
            }
            
            // Output the form PDF
            $pdf->Output($fullOutputPath, 'F');
            
            // Create new document record
            $formDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => str_replace('.pdf', '_form.pdf', $document->original_name),
                'stored_name' => $outputPath,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullOutputPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullOutputPath),
                'metadata' => array_merge($document->metadata ?? [], [
                    'type' => 'form',
                    'source_document' => $document->id,
                    'form_fields' => $fieldNames,
                    'field_count' => count($fieldNames),
                    'created_at' => now()->toIso8601String(),
                ]),
            ]);
            
            return $formDocument;
            
        } catch (Exception $e) {
            throw new Exception('Error creating PDF form: ' . $e->getMessage());
        }
    }
    
    /**
     * Add form field to PDF
     */
    private function addFormField(Fpdi $pdf, array $field, array &$fieldNames): void
    {
        $fieldName = $field['name'] ?? 'field_' . count($fieldNames);
        $fieldNames[] = $fieldName;
        
        switch ($field['type']) {
            case 'text':
                $this->addTextField($pdf, $field, $fieldName);
                break;
                
            case 'checkbox':
                $this->addCheckbox($pdf, $field, $fieldName);
                break;
                
            case 'radio':
                $this->addRadioButton($pdf, $field, $fieldName);
                break;
                
            case 'dropdown':
                $this->addDropdown($pdf, $field, $fieldName);
                break;
                
            case 'signature':
                $this->addSignatureField($pdf, $field, $fieldName);
                break;
                
            case 'date':
                $this->addDateField($pdf, $field, $fieldName);
                break;
                
            case 'textarea':
                $this->addTextArea($pdf, $field, $fieldName);
                break;
                
            default:
                throw new Exception("Unknown field type: {$field['type']}");
        }
    }
    
    /**
     * Add text field
     */
    private function addTextField(Fpdi $pdf, array $field, string $fieldName): void
    {
        $x = $field['x'];
        $y = $field['y'];
        $width = $field['width'] ?? 50;
        $height = $field['height'] ?? 5;
        
        // Set field properties
        $properties = [
            'borderStyle' => 'solid',
            'borderWidth' => 1,
            'borderColor' => [0, 0, 0],
            'fillColor' => [255, 255, 255],
            'textColor' => [0, 0, 0],
        ];
        
        if (isset($field['maxlength'])) {
            $properties['maxlen'] = $field['maxlength'];
        }
        
        if ($field['required'] ?? false) {
            $properties['ff'] = ['Required'];
        }
        
        // Add text field
        $pdf->TextField(
            $fieldName,
            $width,
            $height,
            $properties,
            ['v' => $field['default'] ?? '', 'dv' => $field['default'] ?? ''],
            $x,
            $y
        );
        
        // Add label if provided
        if (isset($field['label'])) {
            $pdf->SetXY($x, $y - 5);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell($width, 4, $field['label'], 0, 0);
        }
    }
    
    /**
     * Add checkbox
     */
    private function addCheckbox(Fpdi $pdf, array $field, string $fieldName): void
    {
        $x = $field['x'];
        $y = $field['y'];
        $size = $field['size'] ?? 4;
        
        $properties = [
            'borderStyle' => 'solid',
            'borderWidth' => 1,
            'borderColor' => [0, 0, 0],
            'fillColor' => [255, 255, 255],
        ];
        
        $pdf->CheckBox(
            $fieldName,
            $size,
            $field['checked'] ?? false,
            $properties,
            [],
            $field['onvalue'] ?? 'Yes',
            $x,
            $y
        );
        
        // Add label if provided
        if (isset($field['label'])) {
            $pdf->SetXY($x + $size + 2, $y);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(0, $size, $field['label'], 0, 0);
        }
    }
    
    /**
     * Add radio button
     */
    private function addRadioButton(Fpdi $pdf, array $field, string $fieldName): void
    {
        $x = $field['x'];
        $y = $field['y'];
        $size = $field['size'] ?? 4;
        $groupName = $field['group'] ?? $fieldName;
        
        $properties = [
            'borderStyle' => 'solid',
            'borderWidth' => 1,
            'borderColor' => [0, 0, 0],
            'fillColor' => [255, 255, 255],
        ];
        
        $pdf->RadioButton(
            $groupName,
            $size,
            $properties,
            [],
            $field['value'] ?? $fieldName,
            $field['checked'] ?? false,
            $x,
            $y
        );
        
        // Add label if provided
        if (isset($field['label'])) {
            $pdf->SetXY($x + $size + 2, $y);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(0, $size, $field['label'], 0, 0);
        }
    }
    
    /**
     * Add dropdown/select field
     */
    private function addDropdown(Fpdi $pdf, array $field, string $fieldName): void
    {
        $x = $field['x'];
        $y = $field['y'];
        $width = $field['width'] ?? 50;
        $height = $field['height'] ?? 5;
        
        $properties = [
            'borderStyle' => 'solid',
            'borderWidth' => 1,
            'borderColor' => [0, 0, 0],
            'fillColor' => [255, 255, 255],
            'textColor' => [0, 0, 0],
        ];
        
        $options = $field['options'] ?? [];
        $default = $field['default'] ?? '';
        
        $pdf->ComboBox(
            $fieldName,
            $width,
            $height,
            $options,
            $properties,
            ['v' => $default, 'dv' => $default],
            $x,
            $y
        );
        
        // Add label if provided
        if (isset($field['label'])) {
            $pdf->SetXY($x, $y - 5);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell($width, 4, $field['label'], 0, 0);
        }
    }
    
    /**
     * Add signature field
     */
    private function addSignatureField(Fpdi $pdf, array $field, string $fieldName): void
    {
        $x = $field['x'];
        $y = $field['y'];
        $width = $field['width'] ?? 60;
        $height = $field['height'] ?? 20;
        
        // Draw signature box
        $pdf->SetLineStyle(['width' => 0.5, 'color' => [128, 128, 128]]);
        $pdf->Rect($x, $y, $width, $height);
        
        // Add signature line
        $pdf->Line($x + 5, $y + $height - 5, $x + $width - 5, $y + $height - 5);
        
        // Add signature text
        $pdf->SetXY($x, $y + $height - 3);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell($width, 3, 'Signature', 0, 0, 'C');
        
        // Add signature field (for digital signatures)
        $pdf->setSignatureAppearance($x, $y, $width, $height);
        
        // Add label if provided
        if (isset($field['label'])) {
            $pdf->SetXY($x, $y - 5);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell($width, 4, $field['label'], 0, 0);
        }
    }
    
    /**
     * Add date field
     */
    private function addDateField(Fpdi $pdf, array $field, string $fieldName): void
    {
        $x = $field['x'];
        $y = $field['y'];
        $width = $field['width'] ?? 30;
        $height = $field['height'] ?? 5;
        
        $properties = [
            'borderStyle' => 'solid',
            'borderWidth' => 1,
            'borderColor' => [0, 0, 0],
            'fillColor' => [255, 255, 255],
            'textColor' => [0, 0, 0],
        ];
        
        // Add with date format validation
        $jsFormat = $field['format'] ?? 'mm/dd/yyyy';
        $properties['js'] = "AFDate_FormatEx('$jsFormat');";
        
        $pdf->TextField(
            $fieldName,
            $width,
            $height,
            $properties,
            ['v' => $field['default'] ?? '', 'dv' => $field['default'] ?? ''],
            $x,
            $y
        );
        
        // Add label if provided
        if (isset($field['label'])) {
            $pdf->SetXY($x, $y - 5);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell($width, 4, $field['label'], 0, 0);
        }
    }
    
    /**
     * Add text area
     */
    private function addTextArea(Fpdi $pdf, array $field, string $fieldName): void
    {
        $x = $field['x'];
        $y = $field['y'];
        $width = $field['width'] ?? 80;
        $height = $field['height'] ?? 20;
        
        $properties = [
            'borderStyle' => 'solid',
            'borderWidth' => 1,
            'borderColor' => [0, 0, 0],
            'fillColor' => [255, 255, 255],
            'textColor' => [0, 0, 0],
            'multiline' => true,
        ];
        
        if (isset($field['maxlength'])) {
            $properties['maxlen'] = $field['maxlength'];
        }
        
        $pdf->TextField(
            $fieldName,
            $width,
            $height,
            $properties,
            ['v' => $field['default'] ?? '', 'dv' => $field['default'] ?? ''],
            $x,
            $y
        );
        
        // Add label if provided
        if (isset($field['label'])) {
            $pdf->SetXY($x, $y - 5);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell($width, 4, $field['label'], 0, 0);
        }
    }
    
    /**
     * Add JavaScript validation to form
     */
    private function addFormValidation(Fpdi $pdf, array $fields): void
    {
        $js = "function validateForm() {\n";
        $js .= "  var errors = [];\n";
        
        foreach ($fields as $field) {
            if ($field['required'] ?? false) {
                $fieldName = $field['name'] ?? '';
                $label = $field['label'] ?? $fieldName;
                
                $js .= "  var field_{$fieldName} = this.getField('{$fieldName}');\n";
                $js .= "  if (!field_{$fieldName}.value || field_{$fieldName}.value.trim() === '') {\n";
                $js .= "    errors.push('{$label} is required');\n";
                $js .= "  }\n";
            }
            
            // Add custom validation if provided
            if (isset($field['validation'])) {
                $js .= $field['validation'] . "\n";
            }
        }
        
        $js .= "  if (errors.length > 0) {\n";
        $js .= "    app.alert('Please correct the following errors:\\n' + errors.join('\\n'));\n";
        $js .= "    return false;\n";
        $js .= "  }\n";
        $js .= "  return true;\n";
        $js .= "}\n";
        
        // Add validation on submit
        $js .= "this.submitForm = function() {\n";
        $js .= "  if (validateForm()) {\n";
        $js .= "    this.submitForm();\n";
        $js .= "  }\n";
        $js .= "};\n";
        
        $pdf->IncludeJS($js);
    }
    
    /**
     * Fill PDF form with data
     */
    public function fillForm(
        Document $document,
        array $data,
        array $options = []
    ): Document {
        try {
            $sourcePath = Storage::path($document->stored_name);
            
            // Generate output filename
            $filename = Str::slug(pathinfo($document->original_name, PATHINFO_FILENAME)) 
                . '_filled_' . time() . '.pdf';
            $outputPath = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullOutputPath = Storage::path($outputPath);
            
            // Ensure directory exists
            $dir = dirname($fullOutputPath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            
            // Use pdftk to fill form if available
            if ($this->isPdftkAvailable()) {
                $this->fillFormWithPdftk($sourcePath, $fullOutputPath, $data, $options);
            } else {
                // Fallback to TCPDF
                $this->fillFormWithTCPDF($sourcePath, $fullOutputPath, $data, $options);
            }
            
            // Create new document record
            $filledDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => str_replace('.pdf', '_filled.pdf', $document->original_name),
                'stored_name' => $outputPath,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullOutputPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullOutputPath),
                'metadata' => array_merge($document->metadata ?? [], [
                    'type' => 'filled_form',
                    'source_document' => $document->id,
                    'filled_at' => now()->toIso8601String(),
                    'filled_by' => auth()->id(),
                    'flatten' => $options['flatten'] ?? false,
                ]),
            ]);
            
            return $filledDocument;
            
        } catch (Exception $e) {
            throw new Exception('Error filling PDF form: ' . $e->getMessage());
        }
    }
    
    /**
     * Fill form using pdftk
     */
    private function fillFormWithPdftk(
        string $sourcePath,
        string $outputPath,
        array $data,
        array $options
    ): void {
        // Create FDF file with form data
        $fdfPath = tempnam(sys_get_temp_dir(), 'form_data_') . '.fdf';
        $this->createFDF($data, $sourcePath, $fdfPath);
        
        // Build pdftk command
        $command = sprintf(
            'pdftk %s fill_form %s output %s',
            escapeshellarg($sourcePath),
            escapeshellarg($fdfPath),
            escapeshellarg($outputPath)
        );
        
        // Add flatten option if requested
        if ($options['flatten'] ?? false) {
            $command .= ' flatten';
        }
        
        exec($command, $output, $returnCode);
        
        // Cleanup
        unlink($fdfPath);
        
        if ($returnCode !== 0) {
            throw new Exception('Failed to fill form with pdftk: ' . implode("\n", $output));
        }
    }
    
    /**
     * Fill form using TCPDF
     */
    private function fillFormWithTCPDF(
        string $sourcePath,
        string $outputPath,
        array $data,
        array $options
    ): void {
        $pdf = new Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        $pageCount = $pdf->setSourceFile($sourcePath);
        
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);
            
            $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height'], true);
        }
        
        // Fill form fields
        foreach ($data as $fieldName => $value) {
            $pdf->setFormDefaultProp(['value' => $value]);
        }
        
        $pdf->Output($outputPath, 'F');
    }
    
    /**
     * Create FDF file for form data
     */
    private function createFDF(array $data, string $pdfPath, string $fdfPath): void
    {
        $fdf = "%FDF-1.2\n";
        $fdf .= "1 0 obj\n";
        $fdf .= "<< /FDF << /Fields [\n";
        
        foreach ($data as $field => $value) {
            $fdf .= "<< /T ($field) /V ($value) >>\n";
        }
        
        $fdf .= "] /F ($pdfPath) >> >>\n";
        $fdf .= "endobj\n";
        $fdf .= "trailer\n";
        $fdf .= "<< /Root 1 0 R >>\n";
        $fdf .= "%%EOF";
        
        file_put_contents($fdfPath, $fdf);
    }
    
    /**
     * Extract form data from filled PDF
     */
    public function extractFormData(Document $document): array
    {
        try {
            $pdfPath = Storage::path($document->stored_name);
            
            if ($this->isPdftkAvailable()) {
                // Use pdftk to extract form data
                $command = sprintf(
                    'pdftk %s dump_data_fields 2>&1',
                    escapeshellarg($pdfPath)
                );
                
                exec($command, $output, $returnCode);
                
                if ($returnCode === 0) {
                    return $this->parsePdftkOutput($output);
                }
            }
            
            // Fallback: extract using TCPDF
            return $this->extractFormDataWithTCPDF($pdfPath);
            
        } catch (Exception $e) {
            throw new Exception('Error extracting form data: ' . $e->getMessage());
        }
    }
    
    /**
     * Parse pdftk output
     */
    private function parsePdftkOutput(array $output): array
    {
        $fields = [];
        $currentField = null;
        
        foreach ($output as $line) {
            if (strpos($line, 'FieldName:') === 0) {
                $currentField = trim(substr($line, 10));
            } elseif (strpos($line, 'FieldValue:') === 0 && $currentField) {
                $fields[$currentField] = trim(substr($line, 11));
                $currentField = null;
            }
        }
        
        return $fields;
    }
    
    /**
     * Extract form data using TCPDF
     */
    private function extractFormDataWithTCPDF(string $pdfPath): array
    {
        // This is a simplified extraction
        // In practice, you'd need to parse the PDF structure
        $fields = [];
        
        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($pdfPath);
        
        // Extract annotations which contain form data
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            // This would require parsing PDF annotations
            // which is complex without pdftk
        }
        
        return $fields;
    }
    
    /**
     * Check if pdftk is available
     */
    private function isPdftkAvailable(): bool
    {
        exec('which pdftk 2>/dev/null', $output, $returnCode);
        return $returnCode === 0;
    }
}