<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use TCPDF;

class CreateTestPDF extends Command
{
    protected $signature = 'create:test-pdf {path}';
    protected $description = 'Create a test PDF file';

    public function handle()
    {
        $path = $this->argument('path');

        // Create new PDF document
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set document information
        $pdf->SetCreator('Giga-PDF');
        $pdf->SetAuthor('Test');
        $pdf->SetTitle('Test Document');
        $pdf->SetSubject('Test PDF');

        // Add a page
        $pdf->AddPage();

        // Set font
        $pdf->SetFont('helvetica', '', 12);

        // Add some content
        $pdf->Cell(0, 10, 'Test Document for Giga-PDF', 0, 1, 'C');
        $pdf->Ln(10);

        $pdf->Write(0, 'This is a test document created to verify PDF editing functionality.');
        $pdf->Ln(10);

        $pdf->Write(0, 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.');
        $pdf->Ln(10);

        // Add more content
        for ($i = 1; $i <= 5; $i++) {
            $pdf->Write(0, "Line $i: This is sample text that can be edited or replaced.");
            $pdf->Ln(5);
        }

        // Save PDF
        $pdf->Output($path, 'F');

        $this->info("Test PDF created at: $path");
        $this->info("File size: " . filesize($path) . " bytes");

        return 0;
    }
}
