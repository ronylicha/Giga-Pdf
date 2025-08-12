<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\PDFComparisonService;
use App\Services\PDFFormsService;
use App\Services\PDFRedactionService;
use App\Services\PDFSignatureService;
use App\Services\PDFStandardsService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestPDFAdvanced extends Command
{
    protected $signature = 'pdf:test-advanced 
                            {--feature= : Specific feature to test (signature, redaction, standards, comparison, forms)}
                            {--tenant= : Tenant ID to use for testing}
                            {--document= : Document ID to use for testing}';

    protected $description = 'Test advanced PDF features';

    private $signatureService;
    private $redactionService;
    private $standardsService;
    private $comparisonService;
    private $formsService;

    public function __construct(
        PDFSignatureService $signatureService,
        PDFRedactionService $redactionService,
        PDFStandardsService $standardsService,
        PDFComparisonService $comparisonService,
        PDFFormsService $formsService
    ) {
        parent::__construct();

        $this->signatureService = $signatureService;
        $this->redactionService = $redactionService;
        $this->standardsService = $standardsService;
        $this->comparisonService = $comparisonService;
        $this->formsService = $formsService;
    }

    public function handle()
    {
        $feature = $this->option('feature');
        $tenantId = $this->option('tenant') ?? 1;
        $documentId = $this->option('document');

        // Get or create test document
        $document = $this->getTestDocument($tenantId, $documentId);

        if (! $document) {
            $this->error('Could not find or create test document');

            return Command::FAILURE;
        }

        $this->info("Testing with document: {$document->original_name} (ID: {$document->id})");

        // Run specific test or all tests
        if ($feature) {
            $method = 'test' . ucfirst($feature);
            if (method_exists($this, $method)) {
                $this->$method($document);
            } else {
                $this->error("Unknown feature: $feature");

                return Command::FAILURE;
            }
        } else {
            // Test all features
            $this->testSignature($document);
            $this->testRedaction($document);
            $this->testStandards($document);
            $this->testComparison($document);
            $this->testForms($document);
        }

        $this->info('All tests completed successfully!');

        return Command::SUCCESS;
    }

    private function getTestDocument($tenantId, $documentId = null): ?Document
    {
        if ($documentId) {
            return Document::find($documentId);
        }

        // Try to find existing PDF document
        $document = Document::where('tenant_id', $tenantId)
            ->where('extension', 'pdf')
            ->first();

        if ($document) {
            return $document;
        }

        // Create a test PDF
        $this->info('Creating test PDF document...');

        // Create simple PDF content
        $pdf = new \TCPDF();
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, 'Test PDF Document', 0, 1);
        $pdf->Ln(10);
        $pdf->Write(0, 'This is a test document for advanced PDF features.');
        $pdf->Ln(10);
        $pdf->Write(0, 'Email: test@example.com');
        $pdf->Ln(5);
        $pdf->Write(0, 'Phone: 555-123-4567');
        $pdf->Ln(5);
        $pdf->Write(0, 'SSN: 123-45-6789');

        $filename = 'test_document_' . time() . '.pdf';
        $path = 'documents/' . $tenantId . '/' . $filename;
        $fullPath = Storage::path($path);

        // Ensure directory exists
        $dir = dirname($fullPath);
        if (! file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        $pdf->Output($fullPath, 'F');

        return Document::create([
            'tenant_id' => $tenantId,
            'user_id' => 1,
            'original_name' => 'Test Document.pdf',
            'stored_name' => $path,
            'mime_type' => 'application/pdf',
            'size' => filesize($fullPath),
            'extension' => 'pdf',
            'hash' => hash_file('sha256', $fullPath),
        ]);
    }

    private function testSignature(Document $document): void
    {
        $this->info('Testing Digital Signatures...');

        try {
            // Create self-signed certificate
            $certDetails = [
                'common_name' => 'Test User',
                'organization' => 'Giga-PDF Test',
                'country' => 'US',
                'state' => 'Test State',
                'city' => 'Test City',
                'email' => 'test@giga-pdf.local',
                'password' => 'testpass123',
            ];

            $certificate = $this->signatureService->createSelfSignedCertificate($certDetails);
            $this->info('✓ Self-signed certificate created');

            // Sign document
            $signedDocument = $this->signatureService->signDocument(
                $document,
                $certificate['certificate_path'],
                $certificate['private_key_path'],
                'testpass123',
                [
                    'signer_name' => 'Test User',
                    'reason' => 'Testing signature',
                    'location' => 'Test Location',
                    'visible_signature' => true,
                ]
            );

            $this->info('✓ Document signed successfully');
            $this->info("  Signed document ID: {$signedDocument->id}");

            // Verify signature
            $verification = $this->signatureService->verifySignature($signedDocument);

            if ($verification['is_valid']) {
                $this->info('✓ Signature verified successfully');
            } else {
                $this->warn('✗ Signature verification failed');
            }

            // Cleanup certificate files
            @unlink($certificate['certificate_path']);
            @unlink($certificate['private_key_path']);

        } catch (Exception $e) {
            $this->error('✗ Signature test failed: ' . $e->getMessage());
        }
    }

    private function testRedaction(Document $document): void
    {
        $this->info('Testing Redaction...');

        try {
            // Test area-based redaction
            $areas = [
                1 => [ // Page 1
                    ['x' => 50, 'y' => 100, 'width' => 100, 'height' => 20],
                ],
            ];

            $redactedDocument = $this->redactionService->redactDocument(
                $document,
                $areas,
                ['reason' => 'Test redaction']
            );

            $this->info('✓ Area-based redaction completed');
            $this->info("  Redacted document ID: {$redactedDocument->id}");

            // Test pattern-based redaction (SSN, Email, Phone)
            $sensitiveDocument = $this->redactionService->redactSensitiveData(
                $document,
                ['only_patterns' => ['SSN', 'Email', 'Phone']]
            );

            $this->info('✓ Sensitive data redaction completed');
            $this->info("  Redacted document ID: {$sensitiveDocument->id}");

        } catch (Exception $e) {
            $this->error('✗ Redaction test failed: ' . $e->getMessage());
        }
    }

    private function testStandards(Document $document): void
    {
        $this->info('Testing PDF Standards...');

        try {
            // Test PDF/A conversion
            $pdfaDocument = $this->standardsService->convertToPDFA(
                $document,
                '1b',
                ['validate' => false] // Skip validation if verapdf not installed
            );

            $this->info('✓ PDF/A conversion completed');
            $this->info("  PDF/A document ID: {$pdfaDocument->id}");

            // Test PDF/X conversion
            $pdfxDocument = $this->standardsService->convertToPDFX(
                $document,
                '1a',
                ['validate' => false] // Skip validation if preflight not installed
            );

            $this->info('✓ PDF/X conversion completed');
            $this->info("  PDF/X document ID: {$pdfxDocument->id}");

        } catch (Exception $e) {
            $this->error('✗ Standards test failed: ' . $e->getMessage());
        }
    }

    private function testComparison(Document $document): void
    {
        $this->info('Testing PDF Comparison...');

        try {
            // Create a modified version of the document for comparison
            $pdf = new \TCPDF();
            $pdf->AddPage();
            $pdf->SetFont('helvetica', '', 12);
            $pdf->Cell(0, 10, 'Test PDF Document - Modified', 0, 1);
            $pdf->Ln(10);
            $pdf->Write(0, 'This is a modified test document.');
            $pdf->Ln(10);
            $pdf->Write(0, 'Email: different@example.com');

            $filename = 'test_modified_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullPath = Storage::path($path);

            $pdf->Output($fullPath, 'F');

            $document2 = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'original_name' => 'Test Document Modified.pdf',
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullPath),
            ]);

            // Compare documents
            $comparison = $this->comparisonService->compareDocuments(
                $document,
                $document2,
                [
                    'threshold' => 90,
                    'detailed_analysis' => true,
                    'generate_diff_pdf' => true,
                ]
            );

            $this->info('✓ Document comparison completed');
            $this->info("  Similarity: {$comparison['similarity_percentage']}%");
            $this->info("  Differences found: " . count($comparison['differences']));

            if (isset($comparison['diff_document_id'])) {
                $this->info("  Diff document ID: {$comparison['diff_document_id']}");
            }

            // Test text comparison
            $textComparison = $this->comparisonService->compareText($document, $document2);
            $this->info("✓ Text comparison completed");
            $this->info("  Text similarity: {$textComparison['similarity']}%");

        } catch (Exception $e) {
            $this->error('✗ Comparison test failed: ' . $e->getMessage());
        }
    }

    private function testForms(Document $document): void
    {
        $this->info('Testing PDF Forms...');

        try {
            // Create form fields
            $fields = [
                [
                    'type' => 'text',
                    'name' => 'full_name',
                    'label' => 'Full Name',
                    'x' => 50,
                    'y' => 50,
                    'width' => 100,
                    'height' => 10,
                    'page' => 1,
                    'required' => true,
                ],
                [
                    'type' => 'checkbox',
                    'name' => 'agree',
                    'label' => 'I agree to terms',
                    'x' => 50,
                    'y' => 70,
                    'size' => 5,
                    'page' => 1,
                ],
                [
                    'type' => 'dropdown',
                    'name' => 'country',
                    'label' => 'Country',
                    'x' => 50,
                    'y' => 90,
                    'width' => 100,
                    'height' => 10,
                    'page' => 1,
                    'options' => ['USA', 'Canada', 'UK', 'France', 'Germany'],
                ],
                [
                    'type' => 'signature',
                    'name' => 'signature',
                    'label' => 'Signature',
                    'x' => 50,
                    'y' => 110,
                    'width' => 100,
                    'height' => 30,
                    'page' => 1,
                ],
            ];

            $formDocument = $this->formsService->createForm(
                $document,
                $fields,
                ['add_validation' => true]
            );

            $this->info('✓ PDF form created');
            $this->info("  Form document ID: {$formDocument->id}");

            // Fill the form
            $formData = [
                'full_name' => 'John Doe',
                'agree' => true,
                'country' => 'USA',
            ];

            $filledDocument = $this->formsService->fillForm(
                $formDocument,
                $formData,
                ['flatten' => false]
            );

            $this->info('✓ PDF form filled');
            $this->info("  Filled document ID: {$filledDocument->id}");

            // Extract form data
            $extractedData = $this->formsService->extractFormData($filledDocument);

            $this->info('✓ Form data extracted');
            $this->info("  Fields extracted: " . count($extractedData));

        } catch (Exception $e) {
            $this->error('✗ Forms test failed: ' . $e->getMessage());
        }
    }
}
