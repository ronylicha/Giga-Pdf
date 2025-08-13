<?php

namespace App\Http\Controllers;

use App\Models\Conversion;
use App\Models\Document;
use App\Services\ConversionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ConversionController extends Controller
{
    protected $conversionService;

    public function __construct(ConversionService $conversionService)
    {
        $this->conversionService = $conversionService;
        $this->middleware('auth');
        $this->middleware('tenant');
    }

    /**
     * Display conversion page
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $tenant = $user->tenant;

        // Get user's documents for conversion
        $userDocuments = Document::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->select('id', 'original_name', 'size', 'mime_type', 'extension')
            ->orderBy('created_at', 'desc')
            ->get();

        // Get recent conversions
        $recentConversions = Conversion::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->with('document', 'resultDocument')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Get selected document if provided
        $selectedDocument = null;
        if ($request->has('document_id')) {
            $selectedDocument = Document::where('tenant_id', $tenant->id)
                ->where('id', $request->document_id)
                ->first();
        }

        return Inertia::render('Documents/Convert', [
            'userDocuments' => $userDocuments,
            'recentConversions' => $recentConversions,
            'selectedDocumentId' => $selectedDocument ? $selectedDocument->id : null,
        ]);
    }

    /**
     * Create a new conversion
     */
    public function create(Request $request)
    {
        $validated = $request->validate([
            'document_id' => 'required_without:file|exists:documents,id',
            'file' => 'required_without:document_id|file|max:102400', // 100MB max
            'output_format' => 'required|string|in:pdf,docx,doc,xlsx,xls,pptx,ppt,jpg,jpeg,png,gif,bmp,tiff,html,txt,md,word,excel,powerpoint,image,text,markdown',
            'options' => 'nullable|json',
        ]);

        $user = auth()->user();
        $tenant = $user->tenant;

        // Map format names to actual extensions
        $outputFormat = $this->mapFormatToExtension($validated['output_format']);

        try {
            DB::beginTransaction();

            // Get or create document
            if (isset($validated['document_id'])) {
                $document = Document::where('tenant_id', $tenant->id)
                    ->where('id', $validated['document_id'])
                    ->firstOrFail();
            } else {
                // Upload new file for conversion
                $file = $request->file('file');
                $document = Document::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'extension' => strtolower($file->getClientOriginalExtension()),
                ]);

                $path = $file->store('documents/' . $tenant->id, 'local');
                $document->update([
                    'stored_name' => $path,
                    'hash' => hash_file('sha256', Storage::path($path)),
                ]);
            }

            // Create conversion record
            $conversion = Conversion::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'document_id' => $document->id,
                'from_format' => $document->extension,
                'to_format' => $outputFormat,
                'status' => 'pending',
                'options' => json_decode($validated['options'] ?? '{}', true),
            ]);

            // Dispatch conversion job
            dispatch(new \App\Jobs\ProcessConversion($conversion));

            DB::commit();

            // Poll for completion (in production, use websockets)
            $maxAttempts = 30; // 30 seconds max
            $attempts = 0;

            while ($attempts < $maxAttempts) {
                sleep(1);
                $conversion->refresh();

                if ($conversion->status === 'completed') {
                    return response()->json([
                        'success' => true,
                        'conversion_id' => $conversion->id,
                        'message' => 'Conversion terminée avec succès',
                        'download_url' => route('conversions.download', $conversion),
                        'result_document_id' => $conversion->result_document_id,
                    ]);
                } elseif ($conversion->status === 'failed') {
                    return response()->json([
                        'success' => false,
                        'message' => 'La conversion a échoué: ' . $conversion->error_message,
                    ], 500);
                }

                $attempts++;
            }

            return response()->json([
                'success' => true,
                'conversion_id' => $conversion->id,
                'message' => 'Conversion en cours de traitement',
                'check_status_url' => route('conversions.show', $conversion),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la conversion: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get conversion status
     */
    public function show(Conversion $conversion)
    {
        $this->authorize('view', $conversion);

        return response()->json([
            'conversion' => $conversion->load('document', 'resultDocument'),
        ]);
    }

    /**
     * Retry failed conversion
     */
    public function retry(Conversion $conversion)
    {
        $this->authorize('update', $conversion);

        if ($conversion->status !== 'failed') {
            return response()->json([
                'message' => 'Cette conversion ne peut pas être relancée',
            ], 400);
        }

        $conversion->update([
            'status' => 'pending',
            'error_message' => null,
            'progress' => 0,
        ]);

        dispatch(new \App\Jobs\ProcessConversion($conversion));

        return response()->json([
            'message' => 'Conversion relancée avec succès',
        ]);
    }

    /**
     * Delete conversion
     */
    public function destroy(Conversion $conversion)
    {
        $this->authorize('delete', $conversion);

        // Delete result document if exists
        if ($conversion->result_document_id) {
            $resultDocument = Document::find($conversion->result_document_id);
            if ($resultDocument) {
                Storage::delete($resultDocument->stored_name);
                $resultDocument->delete();
            }
        }

        $conversion->delete();

        return redirect()->route('conversions.index')
            ->with('success', 'Conversion supprimée avec succès');
    }

    /**
     * Download converted file
     */
    public function download(Conversion $conversion)
    {
        $this->authorize('view', $conversion);

        if ($conversion->status !== 'completed' || ! $conversion->result_document_id) {
            return response()->json([
                'message' => 'Le fichier converti n\'est pas disponible',
            ], 404);
        }

        $document = Document::find($conversion->result_document_id);

        if (! $document || ! Storage::exists($document->stored_name)) {
            return response()->json([
                'message' => 'Le fichier converti est introuvable',
            ], 404);
        }

        return Storage::download($document->stored_name, $document->original_name);
    }

    /**
     * Cancel a pending conversion
     */
    public function cancel(Conversion $conversion)
    {
        $this->authorize('update', $conversion);

        if (! in_array($conversion->status, ['pending', 'processing'])) {
            return response()->json([
                'message' => 'Cette conversion ne peut pas être annulée',
            ], 400);
        }

        $conversion->update([
            'status' => 'cancelled',
            'completed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Conversion annulée avec succès',
        ]);
    }

    /**
     * Batch conversion for multiple documents
     */
    public function batch(Request $request)
    {
        $validated = $request->validate([
            'document_ids' => 'required|array|min:1',
            'document_ids.*' => 'exists:documents,id',
            'output_format' => 'required|string|in:pdf,docx,doc,xlsx,xls,pptx,ppt,jpg,jpeg,png,gif,bmp,tiff,html,txt,md',
        ]);

        $user = auth()->user();
        $tenant = $user->tenant;
        $conversions = [];

        foreach ($validated['document_ids'] as $documentId) {
            $document = Document::where('tenant_id', $tenant->id)
                ->where('id', $documentId)
                ->first();

            if (! $document) {
                continue;
            }

            $conversion = Conversion::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'document_id' => $document->id,
                'from_format' => $document->extension,
                'to_format' => $validated['output_format'],
                'status' => 'pending',
            ]);

            dispatch(new \App\Jobs\ProcessConversion($conversion));
            $conversions[] = $conversion;
        }

        return response()->json([
            'success' => true,
            'conversions' => $conversions,
            'message' => count($conversions) . ' conversions démarrées',
        ]);
    }

    /**
     * Map format names to actual file extensions
     */
    protected function mapFormatToExtension(string $format): string
    {
        return match($format) {
            'word' => 'docx',
            'excel' => 'xlsx',
            'powerpoint' => 'pptx',
            'image' => 'jpg',
            'text' => 'txt',
            'markdown' => 'md',
            default => $format
        };
    }
}
