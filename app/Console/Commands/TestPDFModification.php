<?php

namespace App\Console\Commands;

use App\Services\HTMLPDFEditor;
use App\Services\PDFContentService;
use Illuminate\Console\Command;

class TestPDFModification extends Command
{
    protected $signature = 'pdf:test {pdf?}';
    protected $description = 'Test PDF modification via HTML conversion';

    public function handle()
    {
        $pdfPath = $this->argument('pdf') ?: storage_path('app/private/test.pdf');

        if (! file_exists($pdfPath)) {
            $this->error("PDF not found: $pdfPath");
            $this->info("Creating test PDF...");

            // Create test PDF
            $pdf = new \TCPDF();
            $pdf->AddPage();
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->Cell(0, 10, 'Test Document', 0, 1, 'C');
            $pdf->SetFont('helvetica', '', 12);
            $pdf->Ln(10);
            $pdf->Cell(0, 10, 'First line of text', 0, 1);
            $pdf->Cell(0, 10, 'This text will be replaced', 0, 1);
            $pdf->Cell(0, 10, 'Last line of text', 0, 1);
            $pdf->Output($pdfPath, 'F');
            $this->info("✓ Test PDF created");
        }

        // Extract text
        $this->info("\nExtracting text...");
        $contentService = new PDFContentService();
        $elements = $contentService->extractTextWithPositions($pdfPath);

        foreach ($elements as $i => $element) {
            $this->line(sprintf(
                "[%d] '%s' at (%d,%d)",
                $i + 1,
                $element['text'],
                $element['x'] ?? 0,
                $element['y'] ?? 0
            ));
        }

        // Find text to replace
        $target = null;
        foreach ($elements as $element) {
            if (strpos($element['text'], 'will be replaced') !== false) {
                $target = $element;

                break;
            }
        }

        if (! $target) {
            $this->error("Target text not found");

            return 1;
        }

        $this->info("\nReplacing: '" . $target['text'] . "'");

        // Modify
        $modifications = [[
            'type' => 'replace',
            'page' => $target['page'] ?? 1,
            'x' => $target['x'] ?? 0,
            'y' => $target['y'] ?? 0,
            'oldText' => $target['text'],
            'newText' => 'TEXT REPLACED SUCCESSFULLY',
            'color' => '#FF0000',
        ]];

        $this->info("\nModifying PDF...");
        $htmlEditor = new HTMLPDFEditor();
        $modifiedPath = $htmlEditor->editViaHTML($pdfPath, $modifications);

        $outputPath = storage_path('app/private/test_modified.pdf');
        copy($modifiedPath, $outputPath);
        @unlink($modifiedPath);

        $this->info("✓ Modified PDF saved to: $outputPath");

        // Verify
        $this->info("\nVerifying modification...");
        $newElements = $contentService->extractTextWithPositions($outputPath);

        $found = false;
        foreach ($newElements as $element) {
            if (strpos($element['text'], 'REPLACED') !== false) {
                $found = true;
                $this->info("✓ Success! Found: '" . $element['text'] . "'");

                break;
            }
        }

        if (! $found) {
            $this->warn("⚠ Warning: Replacement text not found");
        }

        return 0;
    }
}
