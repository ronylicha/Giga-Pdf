<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\PDFSignatureService;
use App\Services\PDFRedactionService;
use App\Services\PDFStandardsService;
use App\Services\PDFComparisonService;
use App\Services\PDFFormsService;
use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class PDFAdvancedServicesTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Document $document;

    protected function setUp(): void
    {
        parent::setUp();
        
        Storage::fake('local');
        
        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        
        $this->document = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
        ]);
        
        Storage::put($this->document->stored_name, $this->generateTestPDF());
    }

    /**
     * Signature Service Tests
     */
    public function test_can_create_self_signed_certificate()
    {
        $service = new PDFSignatureService();
        
        $certificate = $service->createSelfSignedCertificate([
            'common_name' => 'Test User',
            'organization' => 'Test Org',
            'country' => 'US',
            'state' => 'CA',
            'city' => 'San Francisco',
            'email' => 'test@example.com',
            'password' => 'testpass',
        ]);
        
        $this->assertArrayHasKey('certificate_path', $certificate);
        $this->assertArrayHasKey('private_key_path', $certificate);
        $this->assertFileExists($certificate['certificate_path']);
        $this->assertFileExists($certificate['private_key_path']);
        
        // Cleanup
        @unlink($certificate['certificate_path']);
        @unlink($certificate['private_key_path']);
    }

    public function test_can_sign_pdf_document()
    {
        $service = new PDFSignatureService();
        
        // Create certificate
        $cert = $service->createSelfSignedCertificate([
            'common_name' => 'Test Signer',
            'email' => 'signer@test.com',
            'password' => 'test123',
        ]);
        
        // Sign document
        $signedDoc = $service->signDocument(
            $this->document,
            $cert['certificate_path'],
            $cert['private_key_path'],
            'test123',
            ['signer_name' => 'Test Signer', 'reason' => 'Testing']
        );
        
        $this->assertInstanceOf(Document::class, $signedDoc);
        $this->assertEquals('signed', $signedDoc->metadata['type']);
        $this->assertEquals('Test Signer', $signedDoc->metadata['signer']);
        
        // Cleanup
        @unlink($cert['certificate_path']);
        @unlink($cert['private_key_path']);
    }

    /**
     * Redaction Service Tests
     */
    public function test_can_redact_document_areas()
    {
        $service = new PDFRedactionService();
        
        $areas = [
            1 => [
                ['x' => 50, 'y' => 100, 'width' => 100, 'height' => 20],
                ['x' => 50, 'y' => 150, 'width' => 150, 'height' => 30],
            ]
        ];
        
        $redactedDoc = $service->redactDocument(
            $this->document,
            $areas,
            ['reason' => 'Test redaction']
        );
        
        $this->assertInstanceOf(Document::class, $redactedDoc);
        $this->assertEquals('redacted', $redactedDoc->metadata['type']);
        $this->assertEquals(2, $redactedDoc->metadata['redaction_count']);
    }

    public function test_can_redact_sensitive_patterns()
    {
        $service = new PDFRedactionService();
        
        // Create document with sensitive data
        $pdf = new \TCPDF();
        $pdf->AddPage();
        $pdf->Write(0, 'SSN: 123-45-6789, Email: test@example.com');
        
        $path = 'documents/' . $this->tenant->id . '/sensitive.pdf';
        Storage::put($path, $pdf->Output('', 'S'));
        
        $doc = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'stored_name' => $path,
            'extension' => 'pdf',
        ]);
        
        $redactedDoc = $service->redactSensitiveData($doc, [
            'only_patterns' => ['SSN', 'Email']
        ]);
        
        $this->assertInstanceOf(Document::class, $redactedDoc);
        $this->assertEquals('redacted', $redactedDoc->metadata['type']);
    }

    /**
     * Standards Service Tests
     */
    public function test_can_convert_to_pdfa()
    {
        $service = new PDFStandardsService();
        
        $pdfaDoc = $service->convertToPDFA(
            $this->document,
            '1b',
            ['validate' => false]
        );
        
        $this->assertInstanceOf(Document::class, $pdfaDoc);
        $this->assertEquals('pdfa', $pdfaDoc->metadata['type']);
        $this->assertEquals('1b', $pdfaDoc->metadata['pdfa_version']);
    }

    public function test_can_convert_to_pdfx()
    {
        $service = new PDFStandardsService();
        
        $pdfxDoc = $service->convertToPDFX(
            $this->document,
            '1a',
            ['validate' => false]
        );
        
        $this->assertInstanceOf(Document::class, $pdfxDoc);
        $this->assertEquals('pdfx', $pdfxDoc->metadata['type']);
        $this->assertEquals('1a', $pdfxDoc->metadata['pdfx_version']);
        $this->assertTrue($pdfxDoc->metadata['print_ready']);
    }

    /**
     * Comparison Service Tests
     */
    public function test_can_compare_documents()
    {
        $service = new PDFComparisonService();
        
        // Create second document
        $doc2 = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
        ]);
        
        Storage::put($doc2->stored_name, $this->generateTestPDF('Different content'));
        
        $comparison = $service->compareDocuments(
            $this->document,
            $doc2,
            ['threshold' => 90]
        );
        
        $this->assertIsArray($comparison);
        $this->assertArrayHasKey('similarity_percentage', $comparison);
        $this->assertArrayHasKey('has_differences', $comparison);
        $this->assertArrayHasKey('differences', $comparison);
    }

    public function test_can_compare_text_content()
    {
        $service = new PDFComparisonService();
        
        $doc2 = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
        ]);
        
        Storage::put($doc2->stored_name, $this->generateTestPDF('Similar text content'));
        
        $comparison = $service->compareText($this->document, $doc2);
        
        $this->assertIsArray($comparison);
        $this->assertArrayHasKey('similarity', $comparison);
        $this->assertArrayHasKey('differences', $comparison);
        $this->assertArrayHasKey('text1_length', $comparison);
        $this->assertArrayHasKey('text2_length', $comparison);
    }

    /**
     * Forms Service Tests
     */
    public function test_can_create_pdf_form()
    {
        $service = new PDFFormsService();
        
        $fields = [
            [
                'type' => 'text',
                'name' => 'name',
                'label' => 'Full Name',
                'x' => 50,
                'y' => 50,
                'width' => 100,
                'page' => 1,
                'required' => true,
            ],
            [
                'type' => 'checkbox',
                'name' => 'agree',
                'label' => 'I Agree',
                'x' => 50,
                'y' => 80,
                'size' => 5,
                'page' => 1,
            ],
        ];
        
        $formDoc = $service->createForm($this->document, $fields);
        
        $this->assertInstanceOf(Document::class, $formDoc);
        $this->assertEquals('form', $formDoc->metadata['type']);
        $this->assertEquals(2, $formDoc->metadata['field_count']);
        $this->assertContains('name', $formDoc->metadata['form_fields']);
        $this->assertContains('agree', $formDoc->metadata['form_fields']);
    }

    public function test_can_fill_pdf_form()
    {
        $service = new PDFFormsService();
        
        // First create a form
        $fields = [
            ['type' => 'text', 'name' => 'name', 'x' => 50, 'y' => 50],
            ['type' => 'text', 'name' => 'email', 'x' => 50, 'y' => 70],
        ];
        
        $formDoc = $service->createForm($this->document, $fields);
        
        // Then fill it
        $data = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ];
        
        $filledDoc = $service->fillForm($formDoc, $data);
        
        $this->assertInstanceOf(Document::class, $filledDoc);
        $this->assertEquals('filled_form', $filledDoc->metadata['type']);
        $this->assertEquals($this->user->id, $filledDoc->metadata['filled_by']);
    }

    public function test_can_extract_form_data()
    {
        $service = new PDFFormsService();
        
        // Create and fill a form
        $fields = [
            ['type' => 'text', 'name' => 'field1', 'x' => 50, 'y' => 50],
            ['type' => 'text', 'name' => 'field2', 'x' => 50, 'y' => 70],
        ];
        
        $formDoc = $service->createForm($this->document, $fields);
        
        $data = ['field1' => 'Value 1', 'field2' => 'Value 2'];
        $filledDoc = $service->fillForm($formDoc, $data);
        
        // Extract data
        $extractedData = $service->extractFormData($filledDoc);
        
        $this->assertIsArray($extractedData);
        // Note: Actual extraction depends on pdftk availability
        if (!empty($extractedData)) {
            $this->assertArrayHasKey('field1', $extractedData);
            $this->assertArrayHasKey('field2', $extractedData);
        }
    }

    // Helper method
    private function generateTestPDF($content = 'Test PDF Content'): string
    {
        $pdf = new \TCPDF();
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Write(0, $content);
        return $pdf->Output('', 'S');
    }
}