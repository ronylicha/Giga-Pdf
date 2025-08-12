<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\PDFComparisonService;
use App\Services\PDFFormsService;
use App\Services\PDFRedactionService;
use App\Services\PDFSignatureService;
use App\Services\PDFStandardsService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PDFAdvancedController extends Controller
{
    protected $signatureService;
    protected $redactionService;
    protected $standardsService;
    protected $comparisonService;
    protected $formsService;

    public function __construct(
        PDFSignatureService $signatureService,
        PDFRedactionService $redactionService,
        PDFStandardsService $standardsService,
        PDFComparisonService $comparisonService,
        PDFFormsService $formsService
    ) {
        $this->signatureService = $signatureService;
        $this->redactionService = $redactionService;
        $this->standardsService = $standardsService;
        $this->comparisonService = $comparisonService;
        $this->formsService = $formsService;
    }

    /**
     * Page Methods
     */
    public function index()
    {
        return Inertia::render('PDFAdvanced/Index');
    }

    public function signaturesPage()
    {
        $documents = Document::where('tenant_id', auth()->user()->tenant_id)
            ->where('mime_type', 'application/pdf')
            ->latest()
            ->get();

        return Inertia::render('PDFAdvanced/Signatures', [
            'documents' => $documents,
        ]);
    }

    public function redactPage()
    {
        $documents = Document::where('tenant_id', auth()->user()->tenant_id)
            ->where('mime_type', 'application/pdf')
            ->latest()
            ->get();

        return Inertia::render('PDFAdvanced/Redact', [
            'documents' => $documents,
        ]);
    }

    public function standardsPage()
    {
        $documents = Document::where('tenant_id', auth()->user()->tenant_id)
            ->where('mime_type', 'application/pdf')
            ->latest()
            ->get();

        return Inertia::render('PDFAdvanced/Standards', [
            'documents' => $documents,
        ]);
    }

    public function comparePage()
    {
        $documents = Document::where('tenant_id', auth()->user()->tenant_id)
            ->where('mime_type', 'application/pdf')
            ->latest()
            ->get();

        return Inertia::render('PDFAdvanced/Compare', [
            'documents' => $documents,
        ]);
    }

    public function formsPage()
    {
        $documents = Document::where('tenant_id', auth()->user()->tenant_id)
            ->where('mime_type', 'application/pdf')
            ->latest()
            ->get();

        return Inertia::render('PDFAdvanced/Forms', [
            'documents' => $documents,
        ]);
    }

    /**
     * Digital Signature Methods
     */
    public function signDocument(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $request->validate([
            'certificate' => 'required|file|mimes:crt,pem,p12',
            'private_key' => 'required_unless:certificate_type,p12|file',
            'password' => 'required|string',
            'signer_name' => 'required|string|max:255',
            'reason' => 'required|string|max:500',
            'location' => 'nullable|string|max:255',
            'visible_signature' => 'boolean',
            'signature_image' => 'nullable|image|max:2048',
        ]);

        try {
            DB::beginTransaction();

            // Store certificate temporarily
            $certPath = $request->file('certificate')->store('temp/certificates');
            $keyPath = null;

            if ($request->hasFile('private_key')) {
                $keyPath = $request->file('private_key')->store('temp/certificates');
            } else {
                $keyPath = $certPath; // For P12 files
            }

            $options = [
                'signer_name' => $request->signer_name,
                'reason' => $request->reason,
                'location' => $request->location,
                'contact' => auth()->user()->email,
                'visible_signature' => $request->visible_signature ?? false,
                'author' => auth()->user()->name,
            ];

            if ($request->hasFile('signature_image')) {
                $options['signature_image'] = $request->file('signature_image')->store('temp/signatures');
            }

            $signedDocument = $this->signatureService->signDocument(
                $document,
                Storage::path($certPath),
                Storage::path($keyPath),
                $request->password,
                $options
            );

            // Cleanup temporary files
            Storage::delete([$certPath, $keyPath]);
            if (isset($options['signature_image'])) {
                Storage::delete($options['signature_image']);
            }

            // Log activity
            activity()
                ->performedOn($signedDocument)
                ->withProperties([
                    'action' => 'signed',
                    'signer' => $request->signer_name,
                    'reason' => $request->reason,
                ])
                ->log('Document digitally signed');

            DB::commit();

            return response()->json([
                'success' => true,
                'document' => $signedDocument->load('user'),
                'message' => 'Document signed successfully',
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to sign document: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function verifySignature(Document $document)
    {
        $this->authorize('view', $document);

        try {
            $verification = $this->signatureService->verifySignature($document);

            return response()->json([
                'success' => true,
                'verification' => $verification,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify signature: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function createSelfSignedCertificate(Request $request)
    {
        $request->validate([
            'common_name' => 'required|string|max:255',
            'organization' => 'required|string|max:255',
            'country' => 'required|string|size:2',
            'state' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'email' => 'required|email',
            'password' => 'required|string|min:8',
        ]);

        try {
            $certificate = $this->signatureService->createSelfSignedCertificate($request->all());

            return response()->json([
                'success' => true,
                'certificate' => $certificate,
                'message' => 'Self-signed certificate created successfully',
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create certificate: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Redaction Methods
     */
    public function redactDocument(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $request->validate([
            'areas' => 'required|array',
            'areas.*.page' => 'required|integer|min:1',
            'areas.*.x' => 'required|numeric',
            'areas.*.y' => 'required|numeric',
            'areas.*.width' => 'required|numeric|min:1',
            'areas.*.height' => 'required|numeric|min:1',
            'reason' => 'nullable|string|max:500',
            'redaction_text' => 'nullable|string|max:50',
        ]);

        try {
            DB::beginTransaction();

            // Group areas by page
            $areasByPage = collect($request->areas)->groupBy('page')->toArray();

            $options = [
                'reason' => $request->reason ?? 'Sensitive content removal',
                'text' => $request->redaction_text ?? 'REDACTED',
                'color' => [0, 0, 0], // Black redaction
            ];

            $redactedDocument = $this->redactionService->redactDocument(
                $document,
                $areasByPage,
                $options
            );

            // Log redaction
            $this->redactionService->logRedaction($redactedDocument, [
                'areas_count' => count($request->areas),
                'reason' => $options['reason'],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'document' => $redactedDocument->load('user'),
                'message' => 'Document redacted successfully',
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to redact document: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function redactSensitiveData(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $request->validate([
            'patterns' => 'nullable|array',
            'patterns.*' => 'in:SSN,Credit Card,Email,Phone,Account Number',
            'custom_patterns' => 'nullable|array',
            'custom_patterns.*.pattern' => 'required|string',
            'custom_patterns.*.type' => 'required|in:regex,text',
        ]);

        try {
            DB::beginTransaction();

            $options = [
                'only_patterns' => $request->patterns,
                'custom_patterns' => $request->custom_patterns,
                'reason' => 'Automatic sensitive data redaction',
            ];

            $redactedDocument = $this->redactionService->redactSensitiveData($document, $options);

            // Log redaction
            $this->redactionService->logRedaction($redactedDocument, [
                'patterns' => $request->patterns ?? [],
                'reason' => $options['reason'],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'document' => $redactedDocument->load('user'),
                'message' => 'Sensitive data redacted successfully',
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to redact sensitive data: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function redactByKeywords(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $request->validate([
            'keywords' => 'required|array|min:1',
            'keywords.*' => 'required|string|max:255',
            'case_sensitive' => 'boolean',
            'whole_word' => 'boolean',
            'redaction_color' => 'nullable|string|max:7',
            'remove_metadata' => 'boolean',
        ]);

        try {
            DB::beginTransaction();

            $options = [
                'keywords' => $request->keywords,
                'case_sensitive' => $request->case_sensitive ?? false,
                'whole_word' => $request->whole_word ?? false,
                'color' => $request->redaction_color ?? '#000000',
                'reason' => 'Keyword-based redaction',
                'remove_metadata' => $request->remove_metadata ?? true,
            ];

            $redactedDocument = $this->redactionService->redactByKeywords($document, $options);

            // Log redaction
            $this->redactionService->logRedaction($redactedDocument, [
                'keywords_count' => count($request->keywords),
                'reason' => $options['reason'],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'document' => $redactedDocument->load('user'),
                'message' => 'Keywords redacted successfully',
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to redact keywords: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PDF Standards Methods
     */
    public function convertToPDFA(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $request->validate([
            'version' => 'required|in:1a,1b,2a,2b,2u,3a,3b,3u',
            'validate' => 'boolean',
            'author' => 'nullable|string|max:255',
            'subject' => 'nullable|string|max:500',
            'keywords' => 'nullable|string|max:500',
        ]);

        try {
            DB::beginTransaction();

            $options = [
                'validate' => $request->validate ?? true,
                'author' => $request->author ?? auth()->user()->name,
                'subject' => $request->subject,
                'keywords' => $request->keywords,
                'title' => $document->original_name,
                'description' => 'PDF/A compliant document',
            ];

            $pdfaDocument = $this->standardsService->convertToPDFA(
                $document,
                $request->version,
                $options
            );

            // Log conversion
            activity()
                ->performedOn($pdfaDocument)
                ->withProperties([
                    'action' => 'converted_to_pdfa',
                    'version' => $request->version,
                ])
                ->log('Document converted to PDF/A');

            DB::commit();

            return response()->json([
                'success' => true,
                'document' => $pdfaDocument->load('user'),
                'message' => 'Document converted to PDF/A successfully',
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to convert to PDF/A: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function convertToPDFX(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $request->validate([
            'version' => 'required|in:1a,3,4',
            'validate' => 'boolean',
            'add_crop_marks' => 'boolean',
            'add_color_bars' => 'boolean',
            'bleed' => 'nullable|numeric|min:0|max:20',
        ]);

        try {
            DB::beginTransaction();

            $options = [
                'validate' => $request->validate ?? true,
                'add_crop_marks' => $request->add_crop_marks ?? false,
                'add_color_bars' => $request->add_color_bars ?? false,
                'bleed' => $request->bleed ?? 3,
                'author' => auth()->user()->name,
                'subject' => 'Print-ready PDF/X document',
            ];

            $pdfxDocument = $this->standardsService->convertToPDFX(
                $document,
                $request->version,
                $options
            );

            // Log conversion
            activity()
                ->performedOn($pdfxDocument)
                ->withProperties([
                    'action' => 'converted_to_pdfx',
                    'version' => $request->version,
                ])
                ->log('Document converted to PDF/X');

            DB::commit();

            return response()->json([
                'success' => true,
                'document' => $pdfxDocument->load('user'),
                'message' => 'Document converted to PDF/X successfully',
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to convert to PDF/X: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Comparison Methods
     */
    public function compareDocuments(Request $request)
    {
        // Si c'est une requête POST (comparaison)
        if ($request->isMethod('post')) {
            $request->validate([
                'document1_id' => 'required|exists:documents,id',
                'document2_id' => 'required|exists:documents,id',
                'threshold' => 'nullable|numeric|min:0|max:100',
                'detailed_analysis' => 'boolean',
                'generate_diff_pdf' => 'boolean',
                'create_diff_images' => 'boolean',
            ]);

            $document1 = Document::findOrFail($request->document1_id);
            $document2 = Document::findOrFail($request->document2_id);

            $this->authorize('view', $document1);
            $this->authorize('view', $document2);

            try {
                $options = [
                    'threshold' => $request->threshold ?? 95,
                    'detailed_analysis' => $request->detailed_analysis ?? false,
                    'generate_diff_pdf' => $request->generate_diff_pdf ?? false,
                    'create_diff_images' => $request->create_diff_images ?? false,
                    'highlight_differences' => true,
                    'include_diff_images' => true,
                ];

                // Check file sizes and use lightweight comparison if files are too large
                $maxSizeForVisual = 20 * 1024 * 1024; // 20MB
                $totalSize = $document1->size + $document2->size;

                if ($totalSize > $maxSizeForVisual || $request->comparison_type === 'text') {
                    // Use lightweight text-based comparison
                    $comparison = $this->comparisonService->compareLightweight(
                        $document1,
                        $document2
                    );
                } else {
                    // Use visual comparison
                    $comparison = $this->comparisonService->compareDocuments(
                        $document1,
                        $document2,
                        $options
                    );
                }

                // Log comparison
                activity()
                    ->withProperties([
                        'action' => 'compared_documents',
                        'document1' => $document1->id,
                        'document2' => $document2->id,
                        'similarity' => $comparison['similarity_percentage'],
                    ])
                    ->log('Documents compared');

                // Pour les requêtes Inertia, retourner la page avec les résultats
                if ($request->header('X-Inertia')) {
                    $documents = Document::where('tenant_id', auth()->user()->tenant_id)
                        ->where('mime_type', 'application/pdf')
                        ->latest()
                        ->get();

                    return Inertia::render('PDFAdvanced/Compare', [
                        'documents' => $documents,
                        'comparison' => $comparison,
                        'flash' => [
                            'success' => 'Documents compared successfully',
                        ],
                    ]);
                }

                // Pour les requêtes AJAX normales
                return response()->json([
                    'success' => true,
                    'comparison' => $comparison,
                    'message' => 'Documents compared successfully',
                ]);

            } catch (Exception $e) {
                if ($request->header('X-Inertia')) {
                    return back()->withErrors(['error' => 'Failed to compare documents: ' . $e->getMessage()]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to compare documents: ' . $e->getMessage(),
                ], 500);
            }
        }

        // Pour les requêtes GET, retourner la page Compare
        $documents = Document::where('tenant_id', auth()->user()->tenant_id)
            ->where('mime_type', 'application/pdf')
            ->latest()
            ->get();

        return Inertia::render('PDFAdvanced/Compare', [
            'documents' => $documents,
        ]);
    }

    public function compareText(Request $request)
    {
        $request->validate([
            'document1_id' => 'required|exists:documents,id',
            'document2_id' => 'required|exists:documents,id|different:document1_id',
            'show_additions' => 'boolean',
            'show_deletions' => 'boolean',
            'show_modifications' => 'boolean',
        ]);

        $document1 = Document::findOrFail($request->document1_id);
        $document2 = Document::findOrFail($request->document2_id);

        $this->authorize('view', $document1);
        $this->authorize('view', $document2);

        try {
            // Utiliser une vraie comparaison textuelle
            $comparison = $this->comparisonService->compareTextContent(
                $document1,
                $document2,
                [
                    'show_additions' => $request->show_additions ?? true,
                    'show_deletions' => $request->show_deletions ?? true,
                    'show_modifications' => $request->show_modifications ?? true,
                ]
            );

            return response()->json([
                'success' => true,
                'comparison' => $comparison,
                'message' => 'Text comparison completed successfully',
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to compare text: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Forms Methods
     */
    public function createForm(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $request->validate([
            'fields' => 'required|array|min:1',
            'fields.*.type' => 'required|in:text,checkbox,radio,dropdown,signature,date,textarea',
            'fields.*.name' => 'required|string|max:255',
            'fields.*.x' => 'required|numeric',
            'fields.*.y' => 'required|numeric',
            'fields.*.width' => 'nullable|numeric|min:1',
            'fields.*.height' => 'nullable|numeric|min:1',
            'fields.*.page' => 'nullable|integer|min:1',
            'fields.*.label' => 'nullable|string|max:255',
            'fields.*.required' => 'boolean',
            'fields.*.options' => 'required_if:fields.*.type,dropdown|array',
            'add_validation' => 'boolean',
        ]);

        try {
            DB::beginTransaction();

            $options = [
                'add_validation' => $request->add_validation ?? false,
            ];

            $formDocument = $this->formsService->createForm(
                $document,
                $request->fields,
                $options
            );

            // Log form creation
            activity()
                ->performedOn($formDocument)
                ->withProperties([
                    'action' => 'created_form',
                    'field_count' => count($request->fields),
                ])
                ->log('PDF form created');

            DB::commit();

            return response()->json([
                'success' => true,
                'document' => $formDocument->load('user'),
                'message' => 'Form created successfully',
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create form: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function fillForm(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $request->validate([
            'data' => 'required|array',
            'flatten' => 'boolean',
        ]);

        try {
            DB::beginTransaction();

            $options = [
                'flatten' => $request->flatten ?? false,
            ];

            $filledDocument = $this->formsService->fillForm(
                $document,
                $request->data,
                $options
            );

            // Log form filling
            activity()
                ->performedOn($filledDocument)
                ->withProperties([
                    'action' => 'filled_form',
                    'field_count' => count($request->data),
                ])
                ->log('PDF form filled');

            DB::commit();

            return response()->json([
                'success' => true,
                'document' => $filledDocument->load('user'),
                'message' => 'Form filled successfully',
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to fill form: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function extractFormData(Document $document)
    {
        $this->authorize('view', $document);

        try {
            $formData = $this->formsService->extractFormData($document);

            return response()->json([
                'success' => true,
                'data' => $formData,
                'message' => 'Form data extracted successfully',
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to extract form data: ' . $e->getMessage(),
            ], 500);
        }
    }
}
