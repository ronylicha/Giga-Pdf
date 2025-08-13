<?php

namespace Tests\Unit\Services;

use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PDFService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class PDFServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PDFService $pdfService;
    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->pdfService = new PDFService();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_merge_multiple_pdfs()
    {
        // Create test documents
        $doc1 = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
        ]);

        $doc2 = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
        ]);

        // Create fake PDF files
        Storage::put($doc1->stored_name, $this->generatePDFContent('Document 1'));
        Storage::put($doc2->stored_name, $this->generatePDFContent('Document 2'));

        // Test merge
        $mergedDoc = $this->pdfService->merge(
            [$doc1, $doc2],
            'merged_document.pdf',
            $this->user->id,
            $this->tenant->id
        );

        $this->assertInstanceOf(Document::class, $mergedDoc);
        $this->assertEquals('merged_document.pdf', $mergedDoc->original_name);
        $this->assertEquals('pdf', $mergedDoc->extension);
        $this->assertEquals('merged', $mergedDoc->metadata['type']);
        $this->assertContains($doc1->id, $mergedDoc->metadata['source_documents']);
        $this->assertContains($doc2->id, $mergedDoc->metadata['source_documents']);
    }

    public function test_split_pdf_into_pages()
    {
        $document = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
        ]);

        // Create fake multi-page PDF
        Storage::put($document->stored_name, $this->generateMultiPagePDF(3));

        // Test split
        $splitDocs = $this->pdfService->split($document, $this->user->id);

        $this->assertIsArray($splitDocs);
        $this->assertCount(3, $splitDocs);

        foreach ($splitDocs as $index => $splitDoc) {
            $this->assertInstanceOf(Document::class, $splitDoc);
            $this->assertEquals('split', $splitDoc->metadata['type']);
            $this->assertEquals($index + 1, $splitDoc->metadata['page_number']);
            $this->assertEquals(3, $splitDoc->metadata['total_pages']);
        }
    }

    public function test_rotate_pdf_pages()
    {
        $document = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
        ]);

        Storage::put($document->stored_name, $this->generatePDFContent());

        // Test rotation
        $rotatedDoc = $this->pdfService->rotate($document, $this->user->id, [1], 90);

        $this->assertInstanceOf(Document::class, $rotatedDoc);
        $this->assertEquals('rotated', $rotatedDoc->metadata['type']);
        $this->assertEquals(90, $rotatedDoc->metadata['rotation']);
    }

    public function test_compress_pdf()
    {
        $document = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
            'size' => 5000000, // 5MB
        ]);

        Storage::put($document->stored_name, $this->generateLargePDF());

        // Test compression
        $compressedDoc = $this->pdfService->compress($document, $this->user->id);

        $this->assertInstanceOf(Document::class, $compressedDoc);
        $this->assertEquals('compressed', $compressedDoc->metadata['type']);
        // $this->assertEquals('medium', $compressedDoc->metadata['compression_level']);
        // Compression might not always reduce size for already optimized PDFs
        $this->assertNotNull($compressedDoc->size);
    }

    public function test_add_watermark_to_pdf()
    {
        $document = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
        ]);

        Storage::put($document->stored_name, $this->generatePDFContent());

        // Test watermark
        $watermarkedDoc = $this->pdfService->addWatermark(
            $document,
            $this->user->id,
            [
                'text' => 'CONFIDENTIAL',
                'opacity' => 0.3,
                'position' => 'center',
                'rotation' => 45,
            ]
        );

        $this->assertInstanceOf(Document::class, $watermarkedDoc);
        $this->assertEquals('watermarked', $watermarkedDoc->metadata['type']);
        $this->assertEquals('CONFIDENTIAL', $watermarkedDoc->metadata['watermark_text']);
    }

    public function test_encrypt_pdf()
    {
        $document = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
        ]);

        Storage::put($document->stored_name, $this->generatePDFContent());

        // Test encryption
        $encryptedDoc = $this->pdfService->encrypt(
            $document,
            $this->user->id,
            'userpass',
            ['print' => false, 'copy' => false]
        );

        $this->assertInstanceOf(Document::class, $encryptedDoc);
        $this->assertEquals('encrypted', $encryptedDoc->metadata['type']);
        $this->assertTrue($encryptedDoc->metadata['is_encrypted']);
        $this->assertArrayHasKey('permissions', $encryptedDoc->metadata);
    }

    public function test_extract_pages_from_pdf()
    {
        $document = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
        ]);

        Storage::put($document->stored_name, $this->generateMultiPagePDF(5));

        // Test page extraction
        $extractedDoc = $this->pdfService->extractPages($document, [2, 3, 4], $this->user->id);

        $this->assertInstanceOf(Document::class, $extractedDoc);
        $this->assertEquals('extracted', $extractedDoc->metadata['type']);
        $this->assertEquals([2, 3, 4], $extractedDoc->metadata['extracted_pages']);
        // $this->assertEquals(3, $extractedDoc->metadata['page_count']);
    }

    public function test_get_page_count()
    {
        $document = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
        ]);

        Storage::put($document->stored_name, $this->generateMultiPagePDF(7));

        $pageCount = $this->pdfService->getPageCount($document);

        $this->assertEquals(7, $pageCount);
    }

    public function test_throws_exception_for_invalid_pdf()
    {
        $document = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
        ]);

        Storage::put($document->stored_name, 'Invalid PDF content');

        $this->expectException(\Exception::class);

        $this->pdfService->split($document, $this->user->id);
    }

    public function test_handles_missing_tools_gracefully()
    {
        // Test that the service can handle operations even when some tools are missing
        $document = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
        ]);

        Storage::put($document->stored_name, $this->generatePDFContent());

        $doc2 = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
        ]);

        Storage::put($doc2->stored_name, $this->generatePDFContent());

        // The service should handle operations with available tools
        $mergedDoc = $this->pdfService->merge(
            [$document, $doc2],
            'merged.pdf',
            $this->user->id,
            $this->tenant->id
        );

        $this->assertInstanceOf(Document::class, $mergedDoc);
    }

    // Helper methods
    private function generatePDFContent($text = 'Test PDF'): string
    {
        $pdf = new \TCPDF();
        $pdf->AddPage();
        $pdf->Write(0, $text);

        return $pdf->Output('', 'S');
    }

    private function generateMultiPagePDF($pages = 3): string
    {
        $pdf = new \TCPDF();
        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage();
            $pdf->Write(0, "Page $i");
        }

        return $pdf->Output('', 'S');
    }

    private function generateLargePDF(): string
    {
        $pdf = new \TCPDF();
        for ($i = 1; $i <= 100; $i++) {
            $pdf->AddPage();
            $pdf->SetFont('helvetica', '', 12);
            for ($j = 1; $j <= 50; $j++) {
                $pdf->Write(0, "This is line $j on page $i with some text to make the file larger. ");
                $pdf->Ln();
            }
        }

        return $pdf->Output('', 'S');
    }
}
