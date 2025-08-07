<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Share;
use App\Models\User;
use App\Services\PDFService;
use App\Services\PDFEditorService;
use App\Services\OCRService;
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
    protected PDFService $pdfService;
    protected PDFEditorService $editorService;
    
    public function __construct(PDFService $pdfService, PDFEditorService $editorService)
    {
        $this->pdfService = $pdfService;
        $this->editorService = $editorService;
    }
    
    /**
     * Display listing of documents
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $tenant = $user->tenant;
        
        $query = Document::query()
            ->with(['user'])
            ->where('tenant_id', $tenant->id) // Filter by tenant
            ->where('user_id', $user->id); // Filter by user - show ALL user's documents including conversions
        
        // Search
        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('original_name', 'like', '%' . $request->search . '%')
                  ->orWhere('tags', 'like', '%' . $request->search . '%');
            });
        }
        
        // Filter by type
        if ($request->type) {
            switch ($request->type) {
                case 'pdf':
                    $query->where('extension', 'pdf');
                    break;
                case 'doc':
                    $query->whereIn('extension', ['doc', 'docx']);
                    break;
                case 'xls':
                    $query->whereIn('extension', ['xls', 'xlsx']);
                    break;
                case 'ppt':
                    $query->whereIn('extension', ['ppt', 'pptx']);
                    break;
                case 'image':
                    $query->whereIn('extension', ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp']);
                    break;
                case 'other':
                    $query->whereNotIn('extension', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp']);
                    break;
            }
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
        
        // Get statistics (filtered by tenant and user - all documents)
        $stats = [
            'total_documents' => Document::where('tenant_id', $tenant->id)->where('user_id', $user->id)->count(),
            'total_size' => Document::where('tenant_id', $tenant->id)->where('user_id', $user->id)->sum('size'),
            'documents_today' => Document::where('tenant_id', $tenant->id)->where('user_id', $user->id)->whereDate('created_at', today())->count(),
            'storage_used' => $tenant->getStorageUsage(),
            'storage_limit' => $tenant->max_storage_gb * 1024 * 1024 * 1024,
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
        $user = auth()->user();
        
        if (!$user) {
            return redirect()->route('login');
        }
        
        $tenant = $user->tenant;
        
        if (!$tenant) {
            return back()->with('error', 'Aucun tenant associé à votre compte. Veuillez contacter l\'administrateur.');
        }
        
        return Inertia::render('Documents/Upload', [
            'max_file_size' => $tenant->max_file_size_mb ?? 100,
            'allowed_types' => [
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff',
                'txt', 'rtf', 'odt', 'ods', 'odp'
            ],
            'storage_available' => $tenant->max_storage_gb ? 
                (($tenant->max_storage_gb * 1024 * 1024 * 1024) - ($tenant->getStorageUsage() ?? 0)) : 
                (100 * 1024 * 1024 * 1024),
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
            
            // Store file first
            $path = $file->store('documents/' . $tenant->id, 'local');
            
            // Create document record with stored_name
            $document = Document::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'original_name' => $file->getClientOriginalName(),
                'stored_name' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'extension' => strtolower($file->getClientOriginalExtension()),
                'hash' => hash_file('sha256', Storage::path($path)),
                'metadata' => [
                    'uploaded_at' => now()->toIso8601String(),
                    'ip_address' => $request->ip(),
                ],
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
    public function store(Request $request)
    {
        $request->validate([
            'document' => 'required|file|max:' . (auth()->user()->tenant->max_file_size_mb * 1024),
            'tags' => 'nullable|array',
            'auto_convert_pdf' => 'nullable|boolean',
        ]);
        
        try {
            DB::beginTransaction();
            
            $file = $request->file('document');
            $user = auth()->user();
            $tenant = $user->tenant;
            
            // Check storage quota
            if ($tenant->getStorageUsed() + $file->getSize() > $tenant->max_storage_gb * 1024 * 1024 * 1024) {
                return back()->with('error', 'Espace de stockage insuffisant');
            }
            
            // Store file first
            $path = $file->store('documents/' . $tenant->id, 'local');
            
            // Create document record with stored_name
            $document = Document::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'original_name' => $file->getClientOriginalName(),
                'stored_name' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'extension' => strtolower($file->getClientOriginalExtension()),
                'hash' => hash_file('sha256', Storage::path($path)),
                'tags' => $request->tags ?? [],
                'metadata' => [
                    'uploaded_at' => now()->toIso8601String(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            ]);
            
            // Generate thumbnail
            dispatch(new \App\Jobs\GenerateThumbnail($document));
            
            // Index content for search
            dispatch(new \App\Jobs\IndexDocumentContent($document));
            
            // Auto-convert to PDF if requested
            if ($request->auto_convert_pdf && $document->mime_type !== 'application/pdf') {
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
        
        $document->load(['user']);
        
        // Mark as accessed
        $document->update([
            'metadata' => array_merge($document->metadata ?? [], [
                'last_accessed' => now()->toIso8601String(),
                'access_count' => ($document->metadata['access_count'] ?? 0) + 1,
            ])
        ]);
        
        // Get shares
        $shares = Share::where('document_id', $document->id)->get();
        
        // Get activity log
        $activities = \App\Models\ActivityLog::where('subject_type', Document::class)
            ->where('subject_id', $document->id)
            ->latest()
            ->limit(20)
            ->get();
        
        return Inertia::render('Documents/Show', [
            'document' => $document,
            'shares' => $shares,
            'activities' => $activities,
            'can_edit' => auth()->user()->can('update', $document),
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
        
        $documents = Document::where('original_name', 'like', '%' . $request->q . '%')
            ->orWhere('tags', 'like', '%' . $request->q . '%')
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
        
        try {
            $documents = Document::whereIn('id', $request->document_ids)->get();
            
            // Verify all documents are PDFs and user has access
            foreach ($documents as $document) {
                $this->authorize('view', $document);
                if ($document->mime_type !== 'application/pdf') {
                    return back()->with('error', 'Tous les documents doivent être des PDF.');
                }
            }
            
            // Merge PDFs
            $mergedDocument = $this->pdfService->merge(
                $documents->all(),
                $request->output_name,
                auth()->id(),
                auth()->user()->tenant_id
            );
            
            // Log activity
            activity()
                ->performedOn($mergedDocument)
                ->causedBy(auth()->user())
                ->withProperties([
                    'source_documents' => $request->document_ids,
                    'output_name' => $request->output_name,
                ])
                ->log('PDFs merged');
            
            return redirect()->route('documents.show', $mergedDocument)
                ->with('success', 'Les PDF ont été fusionnés avec succès.');
                
        } catch (Exception $e) {
            \Log::error('PDF merge failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);
            
            return back()->with('error', 'Erreur lors de la fusion: ' . $e->getMessage());
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
        
        try {
            $splitDocuments = $this->pdfService->split($document, auth()->id());
            
            // Log activity
            activity()
                ->performedOn($document)
                ->causedBy(auth()->user())
                ->withProperties([
                    'pages_created' => count($splitDocuments),
                ])
                ->log('PDF split into pages');
            
            return redirect()->route('documents.index')
                ->with('success', 'Le PDF a été divisé en ' . count($splitDocuments) . ' pages.');
                
        } catch (Exception $e) {
            \Log::error('PDF split failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            
            return back()->with('error', 'Erreur lors de la division: ' . $e->getMessage());
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
            'degrees' => 'required|integer|in:90,180,270,-90,-180,-270',
            'pages' => 'nullable|array',
            'pages.*' => 'integer|min:1',
        ]);
        
        try {
            $rotatedDocument = $this->pdfService->rotate(
                $document,
                $request->degrees,
                $request->pages
            );
            
            // Log activity
            activity()
                ->performedOn($rotatedDocument)
                ->causedBy(auth()->user())
                ->withProperties([
                    'rotation' => $request->degrees,
                    'pages' => $request->pages,
                ])
                ->log('PDF rotated');
            
            return redirect()->route('documents.show', $rotatedDocument)
                ->with('success', 'Le PDF a été pivoté avec succès.');
                
        } catch (Exception $e) {
            \Log::error('PDF rotation failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            
            return back()->with('error', 'Erreur lors de la rotation: ' . $e->getMessage());
        }
    }
    
    /**
     * Extract pages from PDF
     */
    public function extractPages(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        
        if ($document->mime_type !== 'application/pdf') {
            return back()->with('error', 'Seuls les fichiers PDF peuvent être traités.');
        }
        
        $request->validate([
            'pages' => 'required|array|min:1',
            'pages.*' => 'integer|min:1',
            'output_name' => 'nullable|string|max:255',
        ]);
        
        try {
            $extractedDocument = $this->pdfService->extractPages(
                $document,
                $request->pages,
                $request->output_name
            );
            
            // Log activity
            activity()
                ->performedOn($extractedDocument)
                ->causedBy(auth()->user())
                ->withProperties([
                    'pages_extracted' => $request->pages,
                ])
                ->log('Pages extracted from PDF');
            
            return redirect()->route('documents.show', $extractedDocument)
                ->with('success', 'Les pages ont été extraites avec succès.');
                
        } catch (Exception $e) {
            \Log::error('Page extraction failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            
            return back()->with('error', 'Erreur lors de l\'extraction: ' . $e->getMessage());
        }
    }
    
    /**
     * Compress PDF
     */
    public function compress(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        
        if ($document->mime_type !== 'application/pdf') {
            return back()->with('error', 'Seuls les fichiers PDF peuvent être compressés.');
        }
        
        $request->validate([
            'quality' => 'nullable|string|in:low,medium,high',
        ]);
        
        try {
            $compressedDocument = $this->pdfService->compress(
                $document,
                $request->quality ?? 'medium'
            );
            
            // Log activity
            activity()
                ->performedOn($compressedDocument)
                ->causedBy(auth()->user())
                ->withProperties([
                    'quality' => $request->quality ?? 'medium',
                    'original_size' => $document->size,
                    'compressed_size' => $compressedDocument->size,
                ])
                ->log('PDF compressed');
            
            return redirect()->route('documents.show', $compressedDocument)
                ->with('success', 'Le PDF a été compressé avec succès. Réduction: ' . $compressedDocument->metadata['compression_ratio']);
                
        } catch (Exception $e) {
            \Log::error('PDF compression failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            
            return back()->with('error', 'Erreur lors de la compression: ' . $e->getMessage());
        }
    }
    
    /**
     * OCR processing
     */
    public function ocr(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        
        $request->validate([
            'language' => 'nullable|string|max:10',
        ]);
        
        try {
            // Check if document is a PDF or image
            if ($document->mime_type !== 'application/pdf' && !str_starts_with($document->mime_type, 'image/')) {
                return back()->with('error', 'L\'OCR ne peut être effectué que sur des PDF ou des images.');
            }
            
            // Create OCR service instance
            $ocrService = app(OCRService::class);
            
            // Process OCR
            $ocrDocument = $ocrService->processDocument(
                $document,
                $request->language ?? 'eng'
            );
            
            // Log activity
            activity()
                ->performedOn($ocrDocument)
                ->causedBy(auth()->user())
                ->withProperties([
                    'language' => $request->language ?? 'eng',
                    'source_document' => $document->id,
                ])
                ->log('OCR performed on document');
            
            return redirect()->route('documents.show', $ocrDocument)
                ->with('success', 'L\'OCR a été effectué avec succès. Le document est maintenant recherchable.');
                
        } catch (Exception $e) {
            \Log::error('OCR processing failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            
            return back()->with('error', 'Erreur lors de l\'OCR: ' . $e->getMessage());
        }
    }
    
    /**
     * Add watermark
     */
    public function addWatermark(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        
        if ($document->mime_type !== 'application/pdf') {
            return back()->with('error', 'Seuls les fichiers PDF peuvent recevoir un filigrane.');
        }
        
        $request->validate([
            'text' => 'required|string|max:255',
            'fontSize' => 'nullable|integer|min:10|max:100',
            'opacity' => 'nullable|numeric|min:0.1|max:1',
            'angle' => 'nullable|integer|min:-180|max:180',
            'color' => 'nullable|string',
            'position' => 'nullable|string|in:center,top-left,top-right,bottom-left,bottom-right',
        ]);
        
        try {
            $options = array_filter([
                'fontSize' => $request->fontSize,
                'opacity' => $request->opacity,
                'angle' => $request->angle,
                'color' => $request->color,
                'position' => $request->position,
            ]);
            
            $watermarkedDocument = $this->pdfService->addWatermark(
                $document,
                $request->text,
                $options
            );
            
            // Log activity
            activity()
                ->performedOn($watermarkedDocument)
                ->causedBy(auth()->user())
                ->withProperties([
                    'watermark_text' => $request->text,
                    'options' => $options,
                ])
                ->log('Watermark added to PDF');
            
            return redirect()->route('documents.show', $watermarkedDocument)
                ->with('success', 'Le filigrane a été ajouté avec succès.');
                
        } catch (Exception $e) {
            \Log::error('Watermark addition failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            
            return back()->with('error', 'Erreur lors de l\'ajout du filigrane: ' . $e->getMessage());
        }
    }
    
    /**
     * Encrypt PDF
     */
    public function encrypt(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        
        if ($document->mime_type !== 'application/pdf') {
            return back()->with('error', 'Seuls les fichiers PDF peuvent être chiffrés.');
        }
        
        $request->validate([
            'user_password' => 'required|string|min:6',
            'owner_password' => 'nullable|string|min:6',
            'permissions' => 'nullable|array',
            'permissions.print' => 'nullable|boolean',
            'permissions.copy' => 'nullable|boolean',
            'permissions.modify' => 'nullable|boolean',
            'permissions.annot_forms' => 'nullable|boolean',
        ]);
        
        try {
            $permissions = [];
            if ($request->has('permissions')) {
                $permissions = [
                    'print' => $request->input('permissions.print', true),
                    'copy' => $request->input('permissions.copy', false),
                    'modify' => $request->input('permissions.modify', false),
                    'annot-forms' => $request->input('permissions.annot_forms', false),
                ];
            }
            
            $encryptedDocument = $this->pdfService->encrypt(
                $document,
                $request->user_password,
                $request->owner_password,
                $permissions
            );
            
            // Log activity
            activity()
                ->performedOn($encryptedDocument)
                ->causedBy(auth()->user())
                ->withProperties([
                    'has_user_password' => true,
                    'has_owner_password' => !empty($request->owner_password),
                    'permissions' => $permissions,
                ])
                ->log('PDF encrypted');
            
            return redirect()->route('documents.show', $encryptedDocument)
                ->with('success', 'Le PDF a été chiffré avec succès.');
                
        } catch (Exception $e) {
            \Log::error('PDF encryption failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            
            return back()->with('error', 'Erreur lors du chiffrement: ' . $e->getMessage());
        }
    }
}