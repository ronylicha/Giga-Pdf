<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Share;
use App\Models\User;
use App\Services\StorageService;
use App\Services\ConversionService;
use App\Services\ImagickService;
use App\Http\Requests\UploadDocumentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
        $this->middleware('storage.quota')->only(['store', 'upload']);
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
            
            // Index content for search
            dispatch(new \App\Jobs\IndexDocumentContent($document));
            
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
            
            return response()->json([
                'success' => true,
                'document' => $document,
                'message' => 'Document téléchargé avec succès'
            ]);
            
        } catch (Exception $e) {
            DB::rollBack();
            
            \Log::error('Document upload failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);
            
            return response()->json([
                'message' => 'Erreur lors du téléchargement: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Store uploaded document (for form submission)
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
                'extension' => strtolower($file->getClientOriginalExtension()),
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
            dispatch(new \App\Jobs\GenerateThumbnail($document));
            
            // Index content for search
            dispatch(new \App\Jobs\IndexDocumentContent($document));
            
            // Auto-convert to PDF if requested
            if ($request->auto_convert_pdf && !$document->isPdf()) {
                dispatch(new \App\Jobs\ProcessConversion($document, 'pdf'));
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
                ->with('success', 'Document téléchargé avec succès.');
            
        } catch (Exception $e) {
            DB::rollBack();
            
            \Log::error('Document upload failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);
            
            return back()
                ->with('error', 'Échec du téléchargement: ' . $e->getMessage())
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
     * Show document editor
     */
    public function edit(Document $document)
    {
        $this->authorize('update', $document);
        
        return Inertia::render('Documents/Editor', [
            'document' => $document->load('user'),
        ]);
    }
    
    /**
     * Preview document in browser
     */
    public function preview(Document $document)
    {
        $this->authorize('view', $document);
        
        $path = Storage::path($document->stored_name);
        
        // For PDFs and images, serve directly
        if ($document->mime_type === 'application/pdf' || str_starts_with($document->mime_type, 'image/')) {
            return response()->file($path, [
                'Content-Type' => $document->mime_type,
                'Content-Disposition' => 'inline; filename="' . $document->original_name . '"',
                'Cache-Control' => 'public, max-age=3600',
            ]);
        }
        
        // For other files, redirect to show page
        return redirect()->route('documents.show', $document);
    }
    
    /**
     * Update document (metadata and annotations)
     */
    public function update(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        
        $validated = $request->validate([
            'original_name' => 'nullable|string|max:255',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'annotations' => 'nullable|array',
        ]);
        
        // Update basic fields
        if (isset($validated['original_name'])) {
            $document->original_name = $validated['original_name'];
        }
        
        if (isset($validated['tags'])) {
            $document->tags = $validated['tags'];
        }
        
        // Update annotations in metadata
        if (isset($validated['annotations'])) {
            $document->metadata = array_merge($document->metadata ?? [], [
                'annotations' => $validated['annotations'],
                'last_edited' => now()->toIso8601String(),
                'edited_by' => auth()->id(),
            ]);
        }
        
        $document->save();
        
        // Log activity
        activity()
            ->performedOn($document)
            ->causedBy(auth()->user())
            ->withProperties($validated)
            ->log('Document updated');
        
        return back()->with('success', 'Document mis à jour avec succès.');
    }
    
    /**
     * Share document
     */
    public function share(Request $request, Document $document)
    {
        $this->authorize('share', $document);
        
        $validated = $request->validate([
            'type' => 'required|in:public,password,user',
            'password' => 'nullable|string|min:6|required_if:type,password',
            'expires_at' => 'nullable|date|after:now',
            'permissions' => 'nullable|array',
            'user_email' => 'nullable|email|required_if:type,user',
        ]);
        
        $share = Share::create([
            'document_id' => $document->id,
            'shared_by' => auth()->id(),
            'type' => $validated['type'],
            'token' => Str::random(32),
            'password' => $validated['password'] ? Hash::make($validated['password']) : null,
            'expires_at' => $validated['expires_at'] ?? null,
            'permissions' => $validated['permissions'] ?? ['view', 'download'],
        ]);
        
        // If sharing with specific user
        if ($validated['type'] === 'user' && $validated['user_email']) {
            $recipient = User::where('email', $validated['user_email'])->first();
            if ($recipient) {
                $share->shared_with = $recipient->id;
                $share->save();
                
                // Send notification email
                // Mail::to($recipient)->send(new DocumentShared($share));
            }
        }
        
        // Log activity
        activity()
            ->performedOn($document)
            ->causedBy(auth()->user())
            ->withProperties(['share_type' => $validated['type']])
            ->log('Document shared');
        
        return response()->json([
            'share' => $share,
            'url' => route('share.show', $share->token),
        ]);
    }
    
    /**
     * Download document
     */
    public function download(Document $document)
    {
        $this->authorize('download', $document);
        
        $path = Storage::path($document->stored_name);
        
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
            
            // Delete physical file
            if (Storage::exists($document->stored_name)) {
                Storage::delete($document->stored_name);
            }
            
            // Delete thumbnail if exists
            if ($document->thumbnail_path && Storage::exists($document->thumbnail_path)) {
                Storage::delete($document->thumbnail_path);
            }
            
            // Delete record (will cascade to children, shares, etc.)
            $document->delete();
            
            DB::commit();
            
            // Log activity
            activity()
                ->causedBy(auth()->user())
                ->withProperties($documentInfo)
                ->log('Document deleted');
            
            return redirect()->route('documents.index')
                ->with('success', 'Document supprimé avec succès.');
            
        } catch (Exception $e) {
            DB::rollBack();
            
            \Log::error('Document deletion failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            
            return back()->with('error', 'Échec de la suppression du document.');
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
                    // Delete files
                    if (Storage::exists($document->stored_name)) {
                        Storage::delete($document->stored_name);
                    }
                    if ($document->thumbnail_path && Storage::exists($document->thumbnail_path)) {
                        Storage::delete($document->thumbnail_path);
                    }
                    
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
        
        return back()->with('success', "{$deletedCount} documents supprimés.");
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
            $defaultPath = public_path('images/default-thumbnail.png');
            if (file_exists($defaultPath)) {
                return response()->file($defaultPath);
            }
            
            // Generate thumbnail on the fly
            dispatch(new \App\Jobs\GenerateThumbnail($document))->onQueue('high');
            return response()->json(['message' => 'Thumbnail generation in progress'], 202);
        }
        
        $path = Storage::path($document->thumbnail_path);
        
        if (!file_exists($path)) {
            // Generate thumbnail on the fly
            dispatch(new \App\Jobs\GenerateThumbnail($document))->onQueue('high');
            
            $defaultPath = public_path('images/default-thumbnail.png');
            if (file_exists($defaultPath)) {
                return response()->file($defaultPath);
            }
            
            return response()->json(['message' => 'Thumbnail not found'], 404);
        }
        
        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
    
    /**
     * Merge multiple PDFs
     */
    public function merge(Request $request)
    {
        $request->validate([
            'document_ids' => 'required|array|min:2',
            'document_ids.*' => 'exists:documents,id',
            'output_name' => 'required|string|max:255',
        ]);
        
        $documents = Document::whereIn('id', $request->document_ids)
            ->where('mime_type', 'application/pdf')
            ->get();
        
        if ($documents->count() < 2) {
            return back()->with('error', 'Au moins 2 documents PDF sont nécessaires pour la fusion.');
        }
        
        // Check authorization for all documents
        foreach ($documents as $document) {
            $this->authorize('view', $document);
        }
        
        try {
            $mergedPath = $this->imagickService->mergePdfs($documents, $request->output_name);
            
            // Create new document record for merged PDF
            $mergedDocument = Document::create([
                'tenant_id' => auth()->user()->tenant_id,
                'user_id' => auth()->id(),
                'original_name' => $request->output_name . '.pdf',
                'stored_name' => $mergedPath,
                'mime_type' => 'application/pdf',
                'extension' => 'pdf',
                'size' => Storage::size($mergedPath),
                'hash' => hash_file('sha256', Storage::path($mergedPath)),
                'metadata' => [
                    'merged_from' => $documents->pluck('id')->toArray(),
                    'merged_at' => now()->toIso8601String(),
                ],
            ]);
            
            // Generate thumbnail
            dispatch(new \App\Jobs\GenerateThumbnail($mergedDocument));
            
            return redirect()->route('documents.show', $mergedDocument)
                ->with('success', 'Documents fusionnés avec succès.');
                
        } catch (Exception $e) {
            \Log::error('PDF merge failed', [
                'error' => $e->getMessage(),
                'document_ids' => $request->document_ids,
            ]);
            
            return back()->with('error', 'Échec de la fusion des documents.');
        }
    }
    
    /**
     * Split PDF into pages
     */
    public function split(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        
        if ($document->mime_type !== 'application/pdf') {
            return back()->with('error', 'Seuls les fichiers PDF peuvent être divisés.');
        }
        
        $request->validate([
            'pages' => 'required|array',
            'pages.*' => 'integer|min:1',
        ]);
        
        try {
            $splitDocuments = $this->imagickService->splitPdf($document, $request->pages);
            
            return redirect()->route('documents.index')
                ->with('success', count($splitDocuments) . ' documents créés à partir de la division.');
                
        } catch (Exception $e) {
            \Log::error('PDF split failed', [
                'error' => $e->getMessage(),
                'document_id' => $document->id,
            ]);
            
            return back()->with('error', 'Échec de la division du document.');
        }
    }
    
    /**
     * Rotate PDF pages
     */
    public function rotate(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        
        if ($document->mime_type !== 'application/pdf') {
            return back()->with('error', 'Seuls les fichiers PDF peuvent être pivotés.');
        }
        
        $request->validate([
            'angle' => 'required|in:90,180,270',
            'pages' => 'nullable|array',
            'pages.*' => 'integer|min:1',
        ]);
        
        try {
            $this->imagickService->rotatePdf($document, $request->angle, $request->pages);
            
            // Update document hash
            $document->update([
                'hash' => hash_file('sha256', Storage::path($document->stored_name)),
            ]);
            
            // Regenerate thumbnail
            dispatch(new \App\Jobs\GenerateThumbnail($document));
            
            return back()->with('success', 'Document pivoté avec succès.');
            
        } catch (Exception $e) {
            \Log::error('PDF rotation failed', [
                'error' => $e->getMessage(),
                'document_id' => $document->id,
            ]);
            
            return back()->with('error', 'Échec de la rotation du document.');
        }
    }
}