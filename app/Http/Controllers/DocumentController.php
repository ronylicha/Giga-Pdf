<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\StorageService;
use App\Services\ConversionService;
use App\Services\ImagickService;
use App\Http\Requests\UploadDocumentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Exception;

class DocumentController extends Controller
{
    protected $storageService;
    protected $conversionService;
    protected $imagickService;
    
    public function __construct(
        StorageService $storageService,
        ConversionService $conversionService,
        ImagickService $imagickService
    ) {
        $this->storageService = $storageService;
        $this->conversionService = $conversionService;
        $this->imagickService = $imagickService;
        
        $this->middleware('auth');
        $this->middleware('tenant');
        $this->middleware('storage.quota')->only(['store', 'update']);
    }
    
    /**
     * Display listing of documents
     */
    public function index(Request $request)
    {
        $query = Document::query()
            ->with(['user'])
            ->where('parent_id', null); // Only show original documents, not conversions
        
        // Search
        if ($request->search) {
            $query->search($request->search);
        }
        
        // Filter by type
        if ($request->type) {
            $query->ofType($request->type);
        }
        
        // Filter by date
        if ($request->from_date) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        
        if ($request->to_date) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }
        
        // Sort
        $sortField = $request->sort ?? 'created_at';
        $sortDirection = $request->direction ?? 'desc';
        $query->orderBy($sortField, $sortDirection);
        
        $documents = $query->paginate(20)->withQueryString();
        
        // Get statistics
        $stats = [
            'total_documents' => Document::count(),
            'total_size' => Document::sum('size'),
            'documents_today' => Document::whereDate('created_at', today())->count(),
            'storage_used' => auth()->user()->tenant->getStorageUsage(),
            'storage_limit' => auth()->user()->tenant->max_storage_gb * 1024 * 1024 * 1024,
        ];
        
        return Inertia::render('Documents/Index', [
            'documents' => $documents,
            'filters' => $request->only(['search', 'type', 'from_date', 'to_date', 'sort', 'direction']),
            'stats' => $stats,
        ]);
    }
    
    /**
     * Show upload form
     */
    public function create()
    {
        $tenant = auth()->user()->tenant;
        
        return Inertia::render('Documents/Upload', [
            'max_file_size' => $tenant->max_file_size_mb,
            'allowed_types' => $tenant->getSetting('allowed_file_types', [
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff',
                'txt', 'rtf', 'odt', 'ods', 'odp'
            ]),
            'storage_available' => $tenant->getAvailableStorage(),
        ]);
    }
    
    /**
     * Handle file upload via AJAX
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:' . (auth()->user()->tenant->max_file_size_mb * 1024)
        ]);
        
        try {
            DB::beginTransaction();
            
            $file = $request->file('file');
            $user = auth()->user();
            $tenant = $user->tenant;
            
            // Check storage quota
            if ($tenant->getStorageUsed() + $file->getSize() > $tenant->max_storage_gb * 1024 * 1024 * 1024) {
                return response()->json([
                    'message' => 'Espace de stockage insuffisant'
                ], 422);
            }
            
            // Create document record
            $document = Document::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'extension' => strtolower($file->getClientOriginalExtension()),
                'metadata' => [
                    'uploaded_at' => now()->toIso8601String(),
                    'ip_address' => $request->ip(),
                ],
            ]);
            
            // Store file
            $path = $file->store('documents/' . $tenant->id, 'local');
            $document->update([
                'stored_name' => $path,
                'hash' => hash_file('sha256', Storage::path($path)),
            ]);
            
            // Generate thumbnail in background
            dispatch(new \App\Jobs\GenerateThumbnail($document));
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'document' => $document,
                'message' => 'Document téléchargé avec succès'
            ]);
            
        } catch (Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Erreur lors du téléchargement: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Store uploaded document
     */
    public function store(UploadDocumentRequest $request)
    {
        try {
            DB::beginTransaction();
            
            $file = $request->file('document');
            $user = auth()->user();
            
            // Create document record
            $document = Document::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'tags' => $request->tags ?? [],
                'metadata' => [
                    'uploaded_at' => now()->toIso8601String(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            ]);
            
            // Store file
            $storedPath = $this->storageService->storeUpload($file, $document);
            $document->update([
                'stored_name' => $storedPath,
                'hash' => hash_file('sha256', $this->storageService->getPath($document)),
            ]);
            
            // Generate thumbnail
            dispatch(new \App\Jobs\GenerateDocumentThumbnail($document));
            
            // Index content for search
            dispatch(new \App\Jobs\IndexDocumentContent($document));
            
            // Auto-convert to PDF if requested
            if ($request->auto_convert_pdf && !$document->isPdf()) {
                dispatch(new \App\Jobs\ProcessDocumentConversion($document, 'pdf'));
            }
            
            DB::commit();
            
            // Log activity
            activity()
                ->performedOn($document)
                ->causedBy($user)
                ->withProperties([
                    'original_name' => $document->original_name,
                    'size' => $document->size,
                    'mime_type' => $document->mime_type,
                ])
                ->log('Document uploaded');
            
            return redirect()->route('documents.show', $document)
                ->with('success', 'Document uploaded successfully.');
            
        } catch (Exception $e) {
            DB::rollBack();
            
            \Log::error('Document upload failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);
            
            return back()
                ->with('error', 'Failed to upload document: ' . $e->getMessage())
                ->withInput();
        }
    }
    
    /**
     * Display document details
     */
    public function show(Document $document)
    {
        $this->authorize('view', $document);
        
        $document->load(['user', 'children', 'shares.recipient']);
        $document->markAsAccessed();
        
        // Get conversions
        $conversions = $document->conversions()
            ->with('resultDocument')
            ->latest()
            ->get();
        
        // Get activity log
        $activities = \App\Models\ActivityLog::forSubject($document)
            ->with('causer')
            ->latest()
            ->limit(20)
            ->get();
        
        return Inertia::render('Documents/Show', [
            'document' => $document,
            'conversions' => $conversions,
            'activities' => $activities,
            'can_edit' => $document->canBeEditedBy(auth()->user()),
            'can_share' => auth()->user()->can('share', $document),
            'can_delete' => auth()->user()->can('delete', $document),
        ]);
    }
    
    /**
     * Download document
     */
    public function download(Document $document)
    {
        $this->authorize('download', $document);
        
        $path = $this->storageService->getPath($document);
        
        // Log download
        activity()
            ->performedOn($document)
            ->causedBy(auth()->user())
            ->log('Document downloaded');
        
        return response()->download($path, $document->original_name, [
            'Content-Type' => $document->mime_type,
        ]);
    }
    
    /**
     * Preview document
     */
    public function preview(Document $document)
    {
        $this->authorize('view', $document);
        
        $path = $this->storageService->getPath($document);
        
        // For PDFs and images, return inline
        if ($document->isPdf() || $document->isImage()) {
            return response()->file($path, [
                'Content-Type' => $document->mime_type,
                'Content-Disposition' => 'inline; filename="' . $document->original_name . '"',
            ]);
        }
        
        // For other files, redirect to viewer
        return redirect()->route('documents.show', $document);
    }
    
    /**
     * Show edit form
     */
    public function edit(Document $document)
    {
        $this->authorize('update', $document);
        
        return Inertia::render('Documents/Edit', [
            'document' => $document,
        ]);
    }
    
    /**
     * Update document metadata
     */
    public function update(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        
        $request->validate([
            'original_name' => 'sometimes|string|max:255',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:50',
        ]);
        
        $document->update($request->only(['original_name', 'tags']));
        
        // Log activity
        activity()
            ->performedOn($document)
            ->causedBy(auth()->user())
            ->withProperties($request->only(['original_name', 'tags']))
            ->log('Document updated');
        
        return redirect()->route('documents.show', $document)
            ->with('success', 'Document updated successfully.');
    }
    
    /**
     * Delete document
     */
    public function destroy(Document $document)
    {
        $this->authorize('delete', $document);
        
        try {
            DB::beginTransaction();
            
            // Store info for logging
            $documentInfo = [
                'original_name' => $document->original_name,
                'size' => $document->size,
                'mime_type' => $document->mime_type,
            ];
            
            // Delete physical files
            $this->storageService->delete($document);
            
            // Delete record (will cascade to children, shares, etc.)
            $document->delete();
            
            DB::commit();
            
            // Log activity
            activity()
                ->causedBy(auth()->user())
                ->withProperties($documentInfo)
                ->log('Document deleted');
            
            return redirect()->route('documents.index')
                ->with('success', 'Document deleted successfully.');
            
        } catch (Exception $e) {
            DB::rollBack();
            
            \Log::error('Document deletion failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            
            return back()->with('error', 'Failed to delete document.');
        }
    }
    
    /**
     * Bulk delete documents
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'document_ids' => 'required|array',
            'document_ids.*' => 'exists:documents,id',
        ]);
        
        $documents = Document::whereIn('id', $request->document_ids)->get();
        $deletedCount = 0;
        
        foreach ($documents as $document) {
            if (auth()->user()->can('delete', $document)) {
                try {
                    $this->storageService->delete($document);
                    $document->delete();
                    $deletedCount++;
                } catch (Exception $e) {
                    \Log::error('Failed to delete document in bulk operation', [
                        'document_id' => $document->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        return back()->with('success', "Deleted {$deletedCount} documents.");
    }
    
    /**
     * Search documents
     */
    public function search(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:2',
        ]);
        
        $documents = Document::search($request->q)
            ->with(['user'])
            ->limit(20)
            ->get();
        
        if ($request->expectsJson()) {
            return response()->json([
                'documents' => $documents,
            ]);
        }
        
        return Inertia::render('Documents/Search', [
            'query' => $request->q,
            'documents' => $documents,
        ]);
    }
    
    /**
     * Get document thumbnail
     */
    public function thumbnail(Document $document)
    {
        $this->authorize('view', $document);
        
        if (!$document->thumbnail_path) {
            // Return default thumbnail
            return response()->file(public_path('images/default-thumbnail.png'));
        }
        
        $path = Storage::disk('local')->path($document->thumbnail_path);
        
        if (!file_exists($path)) {
            // Generate thumbnail on the fly
            dispatch(new \App\Jobs\GenerateDocumentThumbnail($document))->onQueue('high');
            return response()->file(public_path('images/default-thumbnail.png'));
        }
        
        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}