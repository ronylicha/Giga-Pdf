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
    public function index()
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

        return Inertia::render('Documents/Convert', [
            'userDocuments' => $userDocuments,
            'recentConversions' => $recentConversions,
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

            return response()->json([
                'success' => true,
                'conversion_id' => $conversion->id,
                'message' => 'Conversion démarrée avec succès',
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
