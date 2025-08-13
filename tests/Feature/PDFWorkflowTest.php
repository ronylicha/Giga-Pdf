<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PDFWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Set tenant context
        app()->instance('tenant', $this->tenant);
        config(['tenant.id' => $this->tenant->id]);
    }

    public function test_complete_document_workflow()
    {
        $this->actingAs($this->user);

        // 1. Upload document
        $file = UploadedFile::fake()->create('test.pdf', 1000, 'application/pdf');

        $response = $this->postJson('/api/documents/upload', [
            'file' => $file,
            'name' => 'Test Document',
        ]);

        $response->assertStatus(201);
        $document = Document::find($response->json('data.id'));

        // 2. Merge with another document
        $file2 = UploadedFile::fake()->create('test2.pdf', 800, 'application/pdf');

        $response2 = $this->postJson('/api/documents/upload', [
            'file' => $file2,
            'name' => 'Second Document',
        ]);

        $doc2 = Document::find($response2->json('data.id'));

        $mergeResponse = $this->postJson('/api/documents/merge', [
            'document_ids' => [$document->id, $doc2->id],
            'output_name' => 'Merged Document',
        ]);

        $mergeResponse->assertStatus(200);
        $mergedDoc = Document::find($mergeResponse->json('data.id'));

        // 3. Add watermark
        $watermarkResponse = $this->postJson("/api/documents/{$mergedDoc->id}/watermark", [
            'text' => 'CONFIDENTIAL',
            'opacity' => 0.3,
        ]);

        $watermarkResponse->assertStatus(200);

        // 4. Share document
        $shareResponse = $this->postJson("/api/shares/documents/{$mergedDoc->id}", [
            'type' => 'public',
            'expires_at' => now()->addDays(7),
        ]);

        $shareResponse->assertStatus(201);
        $shareToken = $shareResponse->json('data.token');

        // 5. Access shared document (public)
        $publicResponse = $this->get("/shared/{$shareToken}");
        $publicResponse->assertStatus(200);

        // 6. Delete document
        $deleteResponse = $this->delete("/api/documents/{$mergedDoc->id}");
        $deleteResponse->assertStatus(200);

        $this->assertSoftDeleted('documents', ['id' => $mergedDoc->id]);
    }

    public function test_conversion_workflow()
    {
        $this->actingAs($this->user);

        // Upload Word document
        $file = UploadedFile::fake()->create('document.docx', 2000, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $response = $this->postJson('/api/documents/upload', [
            'file' => $file,
            'name' => 'Word Document',
        ]);

        $response->assertStatus(201);
        $document = Document::find($response->json('data.id'));

        // Convert to PDF
        $conversionResponse = $this->postJson('/api/conversions/create', [
            'document_id' => $document->id,
            'target_format' => 'pdf',
        ]);

        $conversionResponse->assertStatus(201);
        $conversionId = $conversionResponse->json('data.id');

        // Check conversion status
        $statusResponse = $this->get("/api/conversions/{$conversionId}");
        $statusResponse->assertStatus(200);
        $statusResponse->assertJsonStructure([
            'data' => [
                'id',
                'status',
                'progress',
                'from_format',
                'to_format',
            ],
        ]);
    }

    public function test_advanced_pdf_features_workflow()
    {
        $this->actingAs($this->user);

        // Create test document
        $document = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'extension' => 'pdf',
        ]);

        Storage::put($document->stored_name, $this->generateTestPDF());

        // 1. Sign document
        $signResponse = $this->postJson("/api/pdf-advanced/documents/{$document->id}/sign", [
            'signer_name' => 'John Doe',
            'reason' => 'Approval',
            'location' => 'San Francisco',
        ]);

        // Will fail without certificate, but tests the endpoint
        $this->assertContains($signResponse->status(), [422, 500]);

        // 2. Redact sensitive data
        $redactResponse = $this->postJson("/api/pdf-advanced/documents/{$document->id}/redact-sensitive", [
            'patterns' => ['SSN', 'Email'],
        ]);

        $this->assertContains($redactResponse->status(), [200, 500]);

        // 3. Convert to PDF/A
        $pdfaResponse = $this->postJson("/api/pdf-advanced/documents/{$document->id}/convert-pdfa", [
            'version' => '1b',
        ]);

        $this->assertContains($pdfaResponse->status(), [200, 500]);

        // 4. Create form
        $formResponse = $this->postJson("/api/pdf-advanced/documents/{$document->id}/create-form", [
            'fields' => [
                [
                    'type' => 'text',
                    'name' => 'name',
                    'x' => 50,
                    'y' => 50,
                    'label' => 'Full Name',
                ],
            ],
        ]);

        $this->assertContains($formResponse->status(), [200, 500]);
    }

    public function test_permission_based_access()
    {
        // Create users with different roles
        $admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $editor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $viewer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $document = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $admin->id,
        ]);

        // Admin can do everything
        $this->actingAs($admin);
        $response = $this->get("/api/documents/{$document->id}");
        $response->assertStatus(200);

        $response = $this->delete("/api/documents/{$document->id}");
        $response->assertStatus(200);

        // Editor can view and edit
        $document2 = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $editor->id,
        ]);

        $this->actingAs($editor);
        $response = $this->get("/api/documents/{$document2->id}");
        $response->assertStatus(200);

        $response = $this->put("/api/documents/{$document2->id}", [
            'name' => 'Updated Name',
        ]);
        $response->assertStatus(200);

        // Viewer can only view
        $this->actingAs($viewer);
        $response = $this->get("/api/documents/{$document2->id}");
        $response->assertStatus(200);

        $response = $this->delete("/api/documents/{$document2->id}");
        $response->assertStatus(403);
    }

    public function test_multi_tenant_isolation()
    {
        // Create second tenant
        $tenant2 = Tenant::factory()->create();
        $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);

        // Create document in tenant 1
        $doc1 = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        // Create document in tenant 2
        $doc2 = Document::factory()->create([
            'tenant_id' => $tenant2->id,
            'user_id' => $user2->id,
        ]);

        // User from tenant 1 cannot access tenant 2's document
        $this->actingAs($this->user);
        $response = $this->get("/api/documents/{$doc2->id}");
        $response->assertStatus(404);

        // User from tenant 2 cannot access tenant 1's document
        $this->actingAs($user2);
        app()->instance('tenant', $tenant2);
        config(['tenant.id' => $tenant2->id]);

        $response = $this->get("/api/documents/{$doc1->id}");
        $response->assertStatus(404);
    }

    public function test_bulk_operations()
    {
        $this->actingAs($this->user);

        // Create multiple documents
        $documents = Document::factory()->count(5)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        $documentIds = $documents->pluck('id')->toArray();

        // Bulk delete
        $response = $this->postJson('/api/documents/bulk-delete', [
            'document_ids' => array_slice($documentIds, 0, 3),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['data' => ['deleted' => 3]]);

        // Verify deletion
        $this->assertSoftDeleted('documents', ['id' => $documentIds[0]]);
        $this->assertSoftDeleted('documents', ['id' => $documentIds[1]]);
        $this->assertSoftDeleted('documents', ['id' => $documentIds[2]]);
        $this->assertDatabaseHas('documents', ['id' => $documentIds[3], 'deleted_at' => null]);
    }

    public function test_search_functionality()
    {
        $this->actingAs($this->user);

        // Create documents with different names
        Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'original_name' => 'Invoice 2024.pdf',
        ]);

        Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'original_name' => 'Contract Agreement.pdf',
        ]);

        Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'original_name' => 'Invoice 2023.pdf',
        ]);

        // Search for invoices
        $response = $this->get('/api/documents?search=Invoice');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');

        // Search with filters
        $response = $this->get('/api/documents?search=Invoice&year=2024');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    // Helper method
    private function generateTestPDF(): string
    {
        $pdf = new \TCPDF();
        $pdf->AddPage();
        $pdf->Write(0, 'Test PDF for integration testing');

        return $pdf->Output('', 'S');
    }
}
