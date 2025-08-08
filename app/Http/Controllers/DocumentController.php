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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Exception;

class DocumentController extends Controller
{
    protected PDFService $pdfService;
    protected PDFEditorService $editorService;
    protected \App\Services\PDFContentService $contentService;
    
    public function __construct(
        PDFService $pdfService, 
        PDFEditorService $editorService,
        \App\Services\PDFContentService $contentService
    ) {
        $this->pdfService = $pdfService;
        $this->editorService = $editorService;
        $this->contentService = $contentService;
    }
    
    /**
     * Display listing of documents
     */
    public function index(Request $request)
    {
        $user = Auth::user();
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
        $user = Auth::user();
        
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
            'file' => 'required|file|max:' . (Auth::user()->tenant->max_file_size_mb * 1024)
        ]);
        
        try {
            DB::beginTransaction();
            
            $file = $request->file('file');
            $user = Auth::user();
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
            
            Log::error('Document upload failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
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
            'document' => 'required|file|max:' . (Auth::user()->tenant->max_file_size_mb * 1024),
            'tags' => 'nullable|array',
            'auto_convert_pdf' => 'nullable|boolean',
        ]);
        
        try {
            DB::beginTransaction();
            
            $file = $request->file('document');
            $user = Auth::user();
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
            
            Log::error('Document upload failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
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
            'can_edit' => Auth::user()->can('update', $document),
            'can_share' => Auth::user()->can('share', $document),
            'can_delete' => Auth::user()->can('delete', $document),
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
                'edited_by' => Auth::id(),
            ]);
        }
        
        $document->save();
        
        // Log activity
        activity()
            ->performedOn($document)
            ->causedBy(Auth::user())
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
            'shared_by' => Auth::id(),
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
            ->causedBy(Auth::user())
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
            ->causedBy(Auth::user())
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
                ->causedBy(Auth::user())
                ->withProperties($documentInfo)
                ->log('Document deleted');
            
            return redirect()->route('documents.index')
                ->with('success', 'Document supprimé avec succès.');
            
        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Document deletion failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            
            return back()->with('error', 'Échec de la suppression du document.');
        }
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
     * Serve document assets (images, etc.) generated during PDF conversion
     */
    public function serveAsset(Document $document, $filename)
    {
        $this->authorize('view', $document);
        
        // Security: Clean the filename to prevent directory traversal
        $filename = basename($filename);
        
        // Check multiple possible locations for the asset
        $possiblePaths = [
            // In the same directory as the document
            storage_path('app/' . dirname($document->stored_name) . '/' . $filename),
            // In a temp directory
            sys_get_temp_dir() . '/' . $filename,
            // In public storage
            storage_path('app/public/documents/' . $document->id . '/' . $filename),
            // In private storage
            storage_path('app/documents/' . $document->id . '/' . $filename),
        ];
        
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                // Determine MIME type
                $mimeType = mime_content_type($path);
                
                return response()->file($path, [
                    'Content-Type' => $mimeType,
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
        }
        
        // If not found, return a default image or 404
        Log::warning('Asset not found', [
            'document_id' => $document->id,
            'filename' => $filename,
            'checked_paths' => $possiblePaths
        ]);
        
        return response()->json(['error' => 'Asset not found'], 404);
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
                Auth::id(),
                Auth::user()->tenant_id
            );
            
            // Log activity
            activity()
                ->performedOn($mergedDocument)
                ->causedBy(Auth::user())
                ->withProperties([
                    'source_documents' => $request->document_ids,
                    'output_name' => $request->output_name,
                ])
                ->log('PDFs merged');
            
            return redirect()->route('documents.show', $mergedDocument)
                ->with('success', 'Les PDF ont été fusionnés avec succès.');
                
        } catch (Exception $e) {
            Log::error('PDF merge failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
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
            $splitDocuments = $this->pdfService->split($document, Auth::id());
            
            // Log activity
            activity()
                ->performedOn($document)
                ->causedBy(Auth::user())
                ->withProperties([
                    'pages_created' => count($splitDocuments),
                ])
                ->log('PDF split into pages');
            
            return redirect()->route('documents.index')
                ->with('success', 'Le PDF a été divisé en ' . count($splitDocuments) . ' pages.');
                
        } catch (Exception $e) {
            Log::error('PDF split failed', [
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
                ->causedBy(Auth::user())
                ->withProperties([
                    'rotation' => $request->degrees,
                    'pages' => $request->pages,
                ])
                ->log('PDF rotated');
            
            return redirect()->route('documents.show', $rotatedDocument)
                ->with('success', 'Le PDF a été pivoté avec succès.');
                
        } catch (Exception $e) {
            Log::error('PDF rotation failed', [
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
                ->causedBy(Auth::user())
                ->withProperties([
                    'pages_extracted' => $request->pages,
                ])
                ->log('Pages extracted from PDF');
            
            return redirect()->route('documents.show', $extractedDocument)
                ->with('success', 'Les pages ont été extraites avec succès.');
                
        } catch (Exception $e) {
            Log::error('Page extraction failed', [
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
                ->causedBy(Auth::user())
                ->withProperties([
                    'quality' => $request->quality ?? 'medium',
                    'original_size' => $document->size,
                    'compressed_size' => $compressedDocument->size,
                ])
                ->log('PDF compressed');
            
            return redirect()->route('documents.show', $compressedDocument)
                ->with('success', 'Le PDF a été compressé avec succès. Réduction: ' . $compressedDocument->metadata['compression_ratio']);
                
        } catch (Exception $e) {
            Log::error('PDF compression failed', [
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
                ->causedBy(Auth::user())
                ->withProperties([
                    'language' => $request->language ?? 'eng',
                    'source_document' => $document->id,
                ])
                ->log('OCR performed on document');
            
            return redirect()->route('documents.show', $ocrDocument)
                ->with('success', 'L\'OCR a été effectué avec succès. Le document est maintenant recherchable.');
                
        } catch (Exception $e) {
            Log::error('OCR processing failed', [
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
                ->causedBy(Auth::user())
                ->withProperties([
                    'watermark_text' => $request->text,
                    'options' => $options,
                ])
                ->log('Watermark added to PDF');
            
            return redirect()->route('documents.show', $watermarkedDocument)
                ->with('success', 'Le filigrane a été ajouté avec succès.');
                
        } catch (Exception $e) {
            Log::error('Watermark addition failed', [
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
                ->causedBy(Auth::user())
                ->withProperties([
                    'has_user_password' => true,
                    'has_owner_password' => !empty($request->owner_password),
                    'permissions' => $permissions,
                ])
                ->log('PDF encrypted');
            
            return redirect()->route('documents.show', $encryptedDocument)
                ->with('success', 'Le PDF a été chiffré avec succès.');
                
        } catch (Exception $e) {
            Log::error('PDF encryption failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            
            return back()->with('error', 'Erreur lors du chiffrement: ' . $e->getMessage());
        }
    }
    
    /**
     * Serve document file
     */
    public function serve(Document $document)
    {
        $this->authorize('view', $document);
        
        $path = Storage::path($document->stored_name);
        
        if (!file_exists($path)) {
            abort(404, 'Document not found');
        }
        
        return response()->file($path, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="' . $document->original_name . '"',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
    
    /**
     * Extract text content from PDF
     */
    public function extractContent(Document $document)
    {
        $this->authorize('view', $document);
        
        if ($document->mime_type !== 'application/pdf') {
            return response()->json(['error' => 'Document is not a PDF'], 400);
        }
        
        try {
            $path = Storage::path($document->stored_name);
            $textElements = $this->contentService->extractTextWithPositions($path);
            
            return response()->json([
                'success' => true,
                'content' => $textElements
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Apply a single modification to the PDF
     */
    public function applyModification(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        
        if ($document->mime_type !== 'application/pdf') {
            return response()->json(['error' => 'Document is not a PDF'], 400);
        }
        
        $request->validate([
            'modification' => 'required|array',
            'modification.type' => 'required|in:replace,add,delete',
            'modification.page' => 'required|integer|min:1',
            'modification.x' => 'required|numeric',
            'modification.y' => 'required|numeric',
            'temp_document_id' => 'nullable|exists:documents,id',
        ]);
        
        try {
            // Use temp document if exists, otherwise use original
            $sourceDocument = $request->temp_document_id 
                ? Document::findOrFail($request->temp_document_id)
                : $document;
            
            $path = Storage::path($sourceDocument->stored_name);
            
            // Use HTML-based editing for text modification
            $htmlEditor = new \App\Services\HTMLPDFEditor();
            $modifiedPath = $htmlEditor->editViaHTML($path, [$request->modification]);
            
            // Save as temporary document
            $tempStoredName = 'temp/' . $document->tenant_id . '/' . Str::random(40) . '.pdf';
            Storage::put($tempStoredName, file_get_contents($modifiedPath));
            
            // Create or update temp document
            $tempDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => Auth::id(),
                'original_name' => $document->original_name . '_temp',
                'stored_name' => $tempStoredName,
                'mime_type' => 'application/pdf',
                'size' => filesize($modifiedPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $modifiedPath),
                'metadata' => [
                    'is_temp' => true,
                    'parent_document_id' => $document->id,
                    'modifications' => [$request->modification]
                ]
            ]);
            
            // Clean up temp file
            @unlink($modifiedPath);
            
            return response()->json([
                'success' => true,
                'temp_document' => $tempDocument,
                'message' => 'Modification applied successfully'
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Save all modifications permanently
     */
    public function saveModifications(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        
        $request->validate([
            'temp_document_id' => 'required|exists:documents,id',
        ]);
        
        try {
            $tempDocument = Document::findOrFail($request->temp_document_id);
            
            // Copy temp file over original
            $tempPath = Storage::path($tempDocument->stored_name);
            $content = file_get_contents($tempPath);
            Storage::put($document->stored_name, $content);
            
            // Update document metadata
            $document->size = strlen($content);
            $document->hash = hash('sha256', $content);
            $document->save();
            
            // Delete temp document
            Storage::delete($tempDocument->stored_name);
            $tempDocument->delete();
            
            // Log activity
            activity()
                ->performedOn($document)
                ->causedBy(Auth::user())
                ->withProperties(['action' => 'save_modifications'])
                ->log('Document modifications saved');
            
            // For Inertia requests, redirect back with success message
            if ($request->header('X-Inertia')) {
                return redirect()->back()->with('success', 'Modifications sauvegardées avec succès');
            }
            
            // For AJAX requests, return JSON
            return response()->json([
                'success' => true,
                'message' => 'Modifications saved successfully'
            ]);
            
        } catch (Exception $e) {
            if ($request->header('X-Inertia')) {
                return redirect()->back()->with('error', 'Erreur lors de la sauvegarde: ' . $e->getMessage());
            }
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update PDF content (for compatibility)
     */
    public function updateContent(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        
        if ($document->mime_type !== 'application/pdf') {
            return response()->json(['error' => 'Document is not a PDF'], 400);
        }
        
        $request->validate([
            'modifications' => 'required|array',
            'modifications.*.type' => 'required|in:replace,add,delete',
            'modifications.*.page' => 'required|integer|min:1',
        ]);
        
        try {
            $path = Storage::path($document->stored_name);
            $currentPath = $path;
            
            // Group modifications by type
            $replacements = [];
            $additions = [];
            $deletions = [];
            
            foreach ($request->modifications as $mod) {
                switch ($mod['type']) {
                    case 'replace':
                        $replacements[] = $mod;
                        break;
                    case 'add':
                        $additions[] = $mod;
                        break;
                    case 'delete':
                        $deletions[] = $mod;
                        break;
                }
            }
            
            // Apply modifications using HTML editor
            if (!empty($request->modifications)) {
                $htmlEditor = new \App\Services\HTMLPDFEditor();
                $currentPath = $htmlEditor->editViaHTML($currentPath, $request->modifications);
            }
            
            // Save the modified PDF
            $content = file_get_contents($currentPath);
            Storage::put($document->stored_name, $content);
            
            // Update document metadata
            $document->size = strlen($content);
            $document->hash = hash('sha256', $content);
            $document->save();
            
            // Clean up temp file if created
            if ($currentPath !== $path) {
                @unlink($currentPath);
            }
            
            // Log activity
            activity()
                ->performedOn($document)
                ->causedBy(Auth::user())
                ->withProperties(['modifications' => $request->modifications])
                ->log('Document content updated');
            
            return response()->json([
                'success' => true,
                'message' => 'Content updated successfully'
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Show HTML editor
     */
    public function htmlEditor(Document $document)
    {
        $this->authorize('update', $document);
        
        return Inertia::render('Documents/HtmlEditor', [
            'document' => $document
        ]);
    }
    
    /**
     * Convert PDF to HTML for editing
     */
    public function convertToHtml(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        
        try {
            $path = Storage::path($document->stored_name);
            
            // Convert PDF to editable HTML using the primary service. No fallback.
            $html = $this->convertPdfToEditableHtml($request, $path, $document);
            
            return response()->json([
                'success' => true,
                'html' => $html
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Convert PDF to editable HTML using advanced table extraction
     */
    private function convertPdfToEditableHtml(Request $request, $pdfPath, Document $document)
    {
        try {
            // Use the PdfToHtmlService for structured content extraction
            $htmlService = new \App\Services\PdfToHtmlService();
            $version = $request->get('pdf_version'); // Get version from request
            $structuredContent = $htmlService->convertPdfToStructuredHtml($pdfPath, $document->id, $version);
            
            // Ensure structuredContent is an array
            if (!is_array($structuredContent)) {
                throw new \Exception('Invalid structured content returned from PdfToHtmlService');
            }
            
            // Build an advanced editable HTML document
            $html = $this->buildAdvancedEditableHtml($structuredContent, $pdfPath, $document);
            
            return $html;
            
        } catch (\Exception $e) {
            Log::error('PDF to editable HTML conversion failed: ' . $e->getMessage());
            
            // Return error message instead of fallback
            throw new \Exception('La conversion PDF a échoué. Veuillez vérifier le fichier PDF.');
        }
    }
    
    /**
     * Build advanced editable HTML with all features
     */
    private function buildAdvancedEditableHtml($content, $pdfPath, Document $document = null)
    {
        // Ensure content is an array with all required keys
        if (!is_array($content)) {
            $content = [
                'full_html' => is_string($content) ? $content : '',
                'tables' => [],
                'text' => '',
                'images' => [],
                'styles' => '',
                'fonts' => []
            ];
        }
        
        // Ensure all keys exist
        $content = array_merge([
            'full_html' => '',
            'tables' => [],
            'text' => '',
            'images' => [],
            'styles' => '',
            'fonts' => []
        ], $content);
        
        // Get page count for PDF preview generation if needed
        $pageCount = $this->getPdfPageCount($pdfPath);
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Editor</title>';
    
        // Add preserved styles from PDF (filtered and scoped)
        if (!empty($content['styles']) && is_string($content['styles'])) {
            // Filter out problematic styles
            $filteredStyles = $content['styles'];
            // Remove any body background styles that could affect the editor
            $filteredStyles = preg_replace('/body\s*{[^}]*}/i', '', $filteredStyles);
            // Don't remove background-image properties - they might be needed for PDF content
            // Just scope them properly to avoid affecting the editor UI
            
            // Scope styles to pdf-document to avoid breaking editor UI
            $filteredStyles = str_replace('.pdf-', '.pdf-document .pdf-', $filteredStyles);
            // Also scope any unscoped selectors that might interfere
            $filteredStyles = preg_replace('/^([^{}@\s][^{]*){/m', '.pdf-document $1{', $filteredStyles);
            
            $html .= '
    <style>
        /* Preserved PDF Styles (Scoped) */
        ' . $filteredStyles . '
    </style>';
        }
        
        // Add font faces from PDF
        if (!empty($content['fonts']) && is_array($content['fonts'])) {
            $html .= '
    <style>
        /* Preserved PDF Fonts */';
            foreach ($content['fonts'] as $font) {
                if (is_array($font)) {
                    // If font info is structured
                    $fontName = $font['name'] ?? 'Unknown';
                    $html .= "\n        /* Font: $fontName */";
                } else {
                    // If font is a CSS string
                    $html .= "\n        " . $font;
                }
            }
            $html .= '
    </style>';
        }
        
        $html .= '
    <style>
        /* CRITICAL: Editor UI Styles - Do not override */
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #f0f2f5 !important;
            /* Allow background images from PDF content while maintaining default background */
        }
        
        /* Toolbar - Protected with !important */
        .editor-toolbar {
            position: sticky !important;
            top: 0 !important;
            background: white !important;
            border-bottom: 1px solid #dee2e6 !important;
            padding: 12px 20px !important;
            z-index: 9999 !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            width: 100% !important;
            min-height: 60px !important;
        }
        
        .toolbar-buttons {
            display: flex !important;
            gap: 8px !important;
            flex-wrap: nowrap !important;
            align-items: center !important;
            justify-content: space-between !important;
            width: 100% !important;
        }
        
        .editor-toolbar * {
            visibility: visible !important;
            opacity: 1 !important;
        }
        
        .btn {
            padding: 6px 12px !important;
            background: #007bff !important;
            color: white !important;
            border: none !important;
            border-radius: 4px !important;
            cursor: pointer !important;
            font-size: 13px !important;
            transition: background 0.2s !important;
            display: inline-flex !important;
            align-items: center !important;
            text-decoration: none !important;
            position: relative !important;
            z-index: 10000 !important;
            white-space: nowrap !important;
            height: 36px !important;
        }
        
        .btn:hover {
            background: #0056b3 !important;
        }
        
        .btn-secondary {
            background: #6c757d !important;
        }
        
        .btn-secondary:hover {
            background: #545b62 !important;
        }
        
        .btn-success {
            background: #28a745 !important;
        }
        
        .btn-success:hover {
            background: #218838 !important;
        }
        
        /* Action buttons group */
        .action-buttons {
            display: flex !important;
            gap: 8px !important;
            align-items: center !important;
            flex: 1 !important;
        }
        
        .view-toggle {
            display: flex !important;
            gap: 0 !important;
            margin-left: 20px !important;
            flex-shrink: 0 !important;
        }
        
        .toggle-btn {
            padding: 6px 12px !important;
            background: #e9ecef !important;
            color: #495057 !important;
            border: 1px solid #dee2e6 !important;
            cursor: pointer !important;
            transition: all 0.2s !important;
            display: inline-flex !important;
            align-items: center !important;
            font-size: 13px !important;
            height: 36px !important;
            white-space: nowrap !important;
        }
        
        .toggle-btn:first-child {
            border-radius: 4px 0 0 4px !important;
        }
        
        .toggle-btn:last-child {
            border-radius: 0 4px 4px 0 !important;
        }
        
        .toggle-btn.active {
            background: #007bff !important;
            color: white !important;
            border-color: #007bff !important;
        }
        
        /* Main container */
        .pdf-editor-container {
            min-height: 100vh !important;
            padding: 40px 20px !important;
            background: #f5f5f5 !important;
            display: flex !important;
            justify-content: center !important;
            align-items: flex-start !important;
        }
        
        .pdf-content {
            background: white !important;
            padding: 40px !important;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1), 0 1px 3px rgba(0,0,0,0.08) !important;
            min-height: 1000px !important;
            position: relative !important;
            max-width: 900px !important;
            width: 100% !important;
            margin: 0 auto !important;
            border: 2px solid #dee2e6 !important;
            border-radius: 8px !important;
            transform-origin: top center !important;
            transition: transform 0.3s ease !important;
        }
        
        /* Allow background images in PDF document content */
        .pdf-document {
            position: relative;
            /* Background images from PDF are allowed here */
        }
        
        /* PDF pages can have background images */
        .pdf-page {
            position: relative;
            /* Background images from PDF pages are allowed */
        }
        
        /* View modes */
        .view-mode {
            display: none;
        }
        
        .view-mode.active {
            display: block;
        }
        
        /* Tables */
        .pdf-table {
            border-collapse: collapse;
            width: 100%;
            margin: 20px 0;
            background: white;
        }
        
        .pdf-table td, .pdf-table th {
            border: 1px solid #dee2e6;
            padding: 10px;
            text-align: left;
            position: relative;
        }
        
        .pdf-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        .pdf-table tr:hover {
            background-color: #f8f9fa;
        }
        
        .pdf-table td[contenteditable="true"]:hover {
            background-color: #e7f3ff;
            outline: 2px solid #007bff;
            outline-offset: -1px;
        }
        
        .pdf-table td[contenteditable="true"]:focus {
            background-color: white;
            outline: 2px solid #007bff;
            outline-offset: -1px;
            box-shadow: inset 0 0 0 1px #007bff;
        }
        
        /* Table container */
        .table-container {
            margin: 30px 0;
            position: relative;
        }
        
        .table-tools {
            margin-bottom: 10px;
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .table-btn {
            padding: 6px 12px;
            background: #17a2b8;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.2s;
        }
        
        .table-btn:hover {
            background: #138496;
        }
        
        .table-btn.danger {
            background: #dc3545;
        }
        
        .table-btn.danger:hover {
            background: #c82333;
        }
        
        /* Text content */
        .pdf-text {
            white-space: pre-wrap;
            margin: 20px 0;
            line-height: 1.8;
            font-size: 14px;
            color: #212529;
        }
        
        .pdf-text[contenteditable="true"] {
            display: inline-block;
            padding: 2px 5px;
            border: 1px solid transparent;
            border-radius: 3px;
            transition: all 0.2s;
        }
        
        .pdf-text[contenteditable="true"]:hover {
            background-color: #e7f3ff;
            border-color: #007bff;
        }
        
        .pdf-text[contenteditable="true"]:focus {
            background-color: white;
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }
        
        /* Images */
        .pdf-image-container {
            position: relative;
            display: inline-block;
            margin: 20px;
            cursor: move;
        }
        
        .pdf-image {
            max-width: 100%;
            height: auto;
            display: block;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .pdf-image-container.dragging {
            opacity: 0.5;
        }
        
        .image-resize-handle {
            position: absolute;
            width: 10px;
            height: 10px;
            background: #007bff;
            border: 2px solid white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }
        
        .image-resize-handle.nw { top: -5px; left: -5px; cursor: nw-resize; }
        .image-resize-handle.ne { top: -5px; right: -5px; cursor: ne-resize; }
        .image-resize-handle.sw { bottom: -5px; left: -5px; cursor: sw-resize; }
        .image-resize-handle.se { bottom: -5px; right: -5px; cursor: se-resize; }
        
        /* Status bar */
        .status-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #343a40;
            color: white;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
        }
        
        .status-message {
            color: #28a745;
        }
        
        /* Zoom controls styling */
        .zoom-controls {
            border-left: 1px solid #dee2e6;
            padding-left: 15px;
            margin-left: 15px;
        }
        
        .zoom-controls .btn-sm {
            padding: 4px 8px !important;
            font-size: 14px !important;
            border-radius: 4px !important;
            background: #f8f9fa !important;
            border: 1px solid #dee2e6 !important;
            cursor: pointer !important;
            transition: all 0.2s !important;
        }
        
        .zoom-controls .btn-sm:hover {
            background: #e9ecef !important;
            border-color: #adb5bd !important;
        }
        
        /* Responsive adjustments for toolbar */
        @media (max-width: 992px) {
            .btn {
                font-size: 12px !important;
                padding: 5px 8px !important;
            }
            
            .toggle-btn {
                font-size: 12px !important;
                padding: 5px 8px !important;
            }
            
            .zoom-controls {
                margin-left: 10px !important;
                padding-left: 10px !important;
            }
        }
        
        @media (max-width: 768px) {
            .editor-toolbar {
                padding: 10px !important;
            }
            
            .toolbar-buttons {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 10px !important;
            }
            
            .action-buttons {
                flex-wrap: wrap !important;
                justify-content: center !important;
            }
            
            .view-toggle {
                margin-left: 0 !important;
                justify-content: center !important;
                width: 100% !important;
            }
        }
        
        /* Draggable elements styling */
        [data-draggable="true"] {
            transition: opacity 0.2s, box-shadow 0.2s;
        }
        
        [data-draggable="true"]:hover {
            outline: 2px dashed #007bff;
            outline-offset: 2px;
            cursor: move;
        }
        
        .dragging {
            cursor: grabbing !important;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3) !important;
            outline: 2px solid #007bff !important;
        }
        
        /* Loading */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .loading-overlay.active {
            display: flex;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
    <style>
        /* Final override to ensure toolbar is always visible */
        .editor-toolbar {
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
        }
        
        .toolbar-buttons,
        .action-buttons,
        .view-toggle {
            display: flex !important;
            visibility: visible !important;
            opacity: 1 !important;
        }
        
        /* Ensure toolbar stays on top */
        body > .editor-toolbar {
            position: sticky !important;
            top: 0 !important;
            z-index: 999999 !important;
        }
    </style>
</head>
<body>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>
    
    <div class="editor-toolbar" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
        <div class="toolbar-buttons">
            <div class="action-buttons">
                <button class="btn btn-success" onclick="exportAsPdf()">💾 Sauvegarder comme copie</button>
                <button class="btn btn-info" onclick="toggleDragMode()" id="dragModeBtn">🔀 Mode Déplacement</button>
                <button class="btn" onclick="addNewTable()">📊 Table</button>
                <button class="btn" onclick="addTextBlock()">📝 Text</button>
                <button class="btn" onclick="insertImage()">🖼️ Image</button>
            </div>
            
            <!-- Zoom controls -->
            <div class="zoom-controls" style="display: inline-flex; align-items: center; gap: 8px;">
                <button class="btn btn-sm" onclick="zoomOut()" title="Zoom arrière">➖</button>
                <span id="zoomLevel" style="min-width: 50px; text-align: center; font-weight: 500;">100%</span>
                <button class="btn btn-sm" onclick="zoomIn()" title="Zoom avant">➕</button>
                <button class="btn btn-sm" onclick="resetZoom()" title="Réinitialiser">🔄</button>
            </div>
            
            <!-- Simplified view - only combined mode -->
            <div class="view-toggle">
                <button class="toggle-btn active" id="viewCombined">Vue Complète</button>
            </div>
        </div>
    </div>
    
    <div class="pdf-editor-container">
        <div class="pdf-content" id="pdfContent">';
        
        // Add all content - only combined view now
        $renderedContent = $this->renderEditableContent($content, 'combined');
        
        // Convert relative image paths to accessible asset URLs
        if ($document) {
            $renderedContent = $this->transformImagePaths($renderedContent, $document);
        }
        
        $html .= $renderedContent;
        
        $html .= '
        </div>
    </div>
    
    <div class="status-bar">
        <span>Page Count: ' . $pageCount . '</span>
        <span class="status-message" id="statusMessage">Ready</span>
    </div>
    
    ' . $this->getAdvancedEditingScript() . '
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Render editable content based on view mode
     */
    private function renderEditableContent($content, $mode = 'combined')
    {
        $html = '';
        
        // Ensure content is array with required keys
        if (!is_array($content)) {
            $content = [
                'tables' => [],
                'text' => '',
                'images' => [],
                'full_html' => ''
            ];
        }
        
        // If we have full_html in combined mode, use it directly as it's already formatted
        if ($mode === 'combined' && !empty($content['full_html'])) {
            // Make the full HTML editable
            $editableHtml = $content['full_html'];
            
            // Ensure we have the pdf-document wrapper for proper styling
            if (strpos($editableHtml, 'class="pdf-document"') === false) {
                $editableHtml = '<div class="pdf-document">' . $editableHtml . '</div>';
            }
            
            // Make all content editable
            $editableHtml = str_replace('<div class="pdf-document">', '<div class="pdf-document" contenteditable="true">', $editableHtml);
            
            // Ensure tables are editable
            $editableHtml = preg_replace('/<td([^>]*)>/i', '<td$1 contenteditable="true">', $editableHtml);
            $editableHtml = preg_replace('/<th([^>]*)>/i', '<th$1 contenteditable="true">', $editableHtml);
            
            return $editableHtml;
        }
        
        // Render tables
        if (($mode === 'combined' || $mode === 'tables') && !empty($content['tables']) && is_array($content['tables'])) {
            foreach ($content['tables'] as $index => $table) {
                // Preserve existing styles while making cells editable
                $editableTable = $table;
                
                // Make cells editable while preserving their styles
                $editableTable = preg_replace_callback(
                    '/<td([^>]*)>/',
                    function($matches) {
                        $attrs = $matches[1];
                        // Check if contenteditable already exists
                        if (strpos($attrs, 'contenteditable') === false) {
                            return '<td' . $attrs . ' contenteditable="true">';
                        }
                        return $matches[0];
                    },
                    $editableTable
                );
                
                // Make header cells editable too
                $editableTable = preg_replace_callback(
                    '/<th([^>]*)>/',
                    function($matches) {
                        $attrs = $matches[1];
                        if (strpos($attrs, 'contenteditable') === false) {
                            return '<th' . $attrs . ' contenteditable="true">';
                        }
                        return $matches[0];
                    },
                    $editableTable
                );
                
                // Add ID and classes while preserving existing attributes
                $editableTable = preg_replace_callback(
                    '/<table([^>]*)>/',
                    function($matches) use ($index) {
                        $attrs = $matches[1];
                        // Add our classes without removing existing ones
                        if (strpos($attrs, 'class=') !== false) {
                            $attrs = preg_replace(
                                '/class="([^"]*)"/i',
                                'class="$1 pdf-table editable-table"',
                                $attrs
                            );
                        } else {
                            $attrs .= ' class="pdf-table editable-table"';
                        }
                        // Add ID
                        if (strpos($attrs, 'id=') === false) {
                            $attrs .= ' id="table_' . $index . '"';
                        }
                        return '<table' . $attrs . '>';
                    },
                    $editableTable
                );
                
                $html .= '<div class="table-container" data-table-index="' . $index . '">';
                $html .= '<div class="table-tools">
                    <button class="table-btn" onclick="addTableRow(' . $index . ')">➕ Row</button>
                    <button class="table-btn" onclick="addTableColumn(' . $index . ')">➕ Column</button>
                    <button class="table-btn" onclick="insertRowAbove(' . $index . ')">⬆️ Insert Row</button>
                    <button class="table-btn" onclick="insertColumnLeft(' . $index . ')">⬅️ Insert Column</button>
                    <button class="table-btn danger" onclick="deleteTableRow(' . $index . ')">➖ Row</button>
                    <button class="table-btn danger" onclick="deleteTableColumn(' . $index . ')">➖ Column</button>
                    <button class="table-btn" onclick="exportTableCSV(' . $index . ')">📊 Export CSV</button>
                </div>';
                $html .= $editableTable;
                $html .= '</div>';
            }
        }
        
        // Render text
        if (($mode === 'combined' || $mode === 'text') && !empty($content['text']) && is_string($content['text'])) {
            $html .= '<div class="pdf-text" contenteditable="true" id="mainText">';
            // Don't escape HTML if it contains tags
            if (strip_tags($content['text']) !== $content['text']) {
                $html .= $content['text']; // Already contains HTML
            } else {
                $html .= nl2br(htmlspecialchars($content['text'])); // Plain text, escape it
            }
            $html .= '</div>';
        }
        
        // Render images (only in combined mode)
        if ($mode === 'combined' && !empty($content['images']) && is_array($content['images'])) {
            foreach ($content['images'] as $imgIndex => $image) {
                // Handle both string and array image formats
                if (is_array($image)) {
                    $src = $image['src'] ?? '';
                    $style = $image['style'] ?? '';
                    $position = $image['position'] ?? null;
                    
                    // Use positioned container if position info exists
                    if ($position && !empty($style)) {
                        $html .= '<div class="pdf-image-container" style="' . $style . '" draggable="true" data-image-index="' . $imgIndex . '">';
                    } else {
                        $html .= '<div class="pdf-image-container" draggable="true" data-image-index="' . $imgIndex . '">';
                    }
                    
                    $html .= '<img src="' . $src . '" class="pdf-image" id="image_' . $imgIndex . '" />';
                } else {
                    // Simple string image source
                    $html .= '<div class="pdf-image-container" draggable="true" data-image-index="' . $imgIndex . '">';
                    $html .= '<img src="' . $image . '" class="pdf-image" id="image_' . $imgIndex . '" />';
                }
                
                // Add resize handles
                $html .= '<div class="image-resize-handle nw" data-handle="nw"></div>';
                $html .= '<div class="image-resize-handle ne" data-handle="ne"></div>';
                $html .= '<div class="image-resize-handle sw" data-handle="sw"></div>';
                $html .= '<div class="image-resize-handle se" data-handle="se"></div>';
                $html .= '</div>';
            }
        }
        
        // Add placeholder if no content
        if (empty($html)) {
            $html = '<div class="pdf-text" contenteditable="true" placeholder="Start typing or paste content here...">
                <p style="color: #999;">No content extracted. Start editing here...</p>
            </div>';
        }
        
        return $html;
    }
    
    /**
     * Get PDF page count
     */
    private function getPdfPageCount($pdfPath)
    {
        $command = sprintf('pdfinfo %s | grep "Pages:" | awk \'{print $2}\'', escapeshellarg($pdfPath));
        $pageCount = trim(shell_exec($command));
        return is_numeric($pageCount) ? (int)$pageCount : 0;
    }
    
    /**
     * Get advanced editing JavaScript
     */
    private function getAdvancedEditingScript()
    {
        return '<script>
        // Global variables
        let currentView = "combined";
        let isDragging = false;
        let currentImage = null;
        let isResizing = false;
        let startX = 0;
        let startY = 0;
        let startWidth = 0;
        let startHeight = 0;
        
        // No longer need view switching - only combined view exists
        
        // Drag and drop functionality for all editable elements
        function initializeDragAndDrop() {
            // Make all contenteditable elements draggable
            const editableElements = document.querySelectorAll(\'[contenteditable="true"]\');
            editableElements.forEach(element => {
                makeDraggable(element);
            });
            
            // Make images draggable
            const images = document.querySelectorAll(\'.pdf-content img:not(.pdf-page-background)\');
            images.forEach(img => {
                makeDraggable(img);
            });
            
            // Make tables draggable
            const tables = document.querySelectorAll(\'.pdf-table\');
            tables.forEach(table => {
                makeDraggable(table);
            });
        }
        
        function makeDraggable(element) {
            // Add drag handle if not already present
            if (!element.dataset.draggable) {
                element.dataset.draggable = \'true\';
                element.style.cursor = \'move\';
                
                let isDragging = false;
                let startX = 0;
                let startY = 0;
                let initialLeft = 0;
                let initialTop = 0;
                
                // Ensure element has position absolute or relative
                if (!element.style.position || element.style.position === \'static\') {
                    element.style.position = \'absolute\';
                }
                
                // Parse existing position or set defaults
                initialLeft = parseInt(element.style.left) || element.offsetLeft || 0;
                initialTop = parseInt(element.style.top) || element.offsetTop || 0;
                element.style.left = initialLeft + \'px\';
                element.style.top = initialTop + \'px\';
                
                // Add drag start event
                element.addEventListener(\'mousedown\', function(e) {
                    // Only start drag if drag mode is active
                    if (dragModeActive) {
                        e.preventDefault();
                        e.stopPropagation();
                        isDragging = true;
                        startX = e.clientX;
                        startY = e.clientY;
                        initialLeft = parseInt(element.style.left) || 0;
                        initialTop = parseInt(element.style.top) || 0;
                        
                        // Add dragging class for visual feedback
                        element.classList.add(\'dragging\');
                        element.style.opacity = \'0.7\';
                        element.style.zIndex = \'1000\';
                        
                        // Add mousemove and mouseup listeners to document
                        document.addEventListener(\'mousemove\', handleDragMove);
                        document.addEventListener(\'mouseup\', handleDragEnd);
                    }
                });
                
                function handleDragMove(e) {
                    if (!isDragging) return;
                    
                    e.preventDefault();
                    const deltaX = e.clientX - startX;
                    const deltaY = e.clientY - startY;
                    
                    element.style.left = (initialLeft + deltaX) + \'px\';
                    element.style.top = (initialTop + deltaY) + \'px\';
                    
                    updateStatus(\'Déplacement en cours... (Relâchez pour terminer)\');
                }
                
                function handleDragEnd(e) {
                    if (!isDragging) return;
                    
                    isDragging = false;
                    element.classList.remove(\'dragging\');
                    element.style.opacity = \'1\';
                    element.style.zIndex = \'auto\';
                    
                    document.removeEventListener(\'mousemove\', handleDragMove);
                    document.removeEventListener(\'mouseup\', handleDragEnd);
                    
                    updateStatus(\'Élément déplacé\');
                }
            }
        }
        
        // Toggle drag mode
        let dragModeActive = false;
        function toggleDragMode() {
            dragModeActive = !dragModeActive;
            const btn = document.getElementById(\'dragModeBtn\');
            const body = document.body;
            
            if (dragModeActive) {
                btn.classList.add(\'active\');
                btn.style.background = \'#28a745\';
                btn.innerHTML = \'✓ Déplacement Actif\';
                body.classList.add(\'drag-mode-active\');
                updateStatus(\'Mode déplacement activé - Cliquez et déplacez les éléments\');
                
                // Change cursor for all draggable elements
                document.querySelectorAll(\'[data-draggable="true"]\').forEach(el => {
                    el.style.cursor = \'move\';
                });
            } else {
                btn.classList.remove(\'active\');
                btn.style.background = \'\';
                btn.innerHTML = \'🔀 Mode Déplacement\';
                body.classList.remove(\'drag-mode-active\');
                updateStatus(\'Mode déplacement désactivé\');
                
                // Reset cursor
                document.querySelectorAll(\'[data-draggable="true"]\').forEach(el => {
                    el.style.cursor = \'\';
                });
            }
        }
        
        // Zoom functionality
        let currentZoom = 100;
        const zoomStep = 10;
        const minZoom = 50;
        const maxZoom = 200;
        
        function updateZoomDisplay() {
            const zoomLevel = document.getElementById(\'zoomLevel\');
            if (zoomLevel) {
                zoomLevel.textContent = currentZoom + \'%\';
            }
            
            const pdfContent = document.getElementById(\'pdfContent\');
            if (pdfContent) {
                const scale = currentZoom / 100;
                pdfContent.style.transform = \'scale(\' + scale + \')\';
                
                // Adjust container height to accommodate scaled content
                const container = document.querySelector(\'.pdf-editor-container\');
                if (container) {
                    const baseHeight = 1000; // min-height of pdf-content
                    container.style.minHeight = (baseHeight * scale + 100) + \'px\';
                }
            }
        }
        
        function zoomIn() {
            if (currentZoom < maxZoom) {
                currentZoom += zoomStep;
                updateZoomDisplay();
                updateStatus(\'Zoom: \' + currentZoom + \'%\');
            }
        }
        
        function zoomOut() {
            if (currentZoom > minZoom) {
                currentZoom -= zoomStep;
                updateZoomDisplay();
                updateStatus(\'Zoom: \' + currentZoom + \'%\');
            }
        }
        
        function resetZoom() {
            currentZoom = 100;
            updateZoomDisplay();
            updateStatus(\'Zoom réinitialisé à 100%\');
        }
        
        // Keyboard shortcuts for zoom
        document.addEventListener(\'keydown\', function(e) {
            // Ctrl/Cmd + Plus for zoom in
            if ((e.ctrlKey || e.metaKey) && (e.key === \'+\' || e.key === \'=\')) {
                e.preventDefault();
                zoomIn();
            }
            // Ctrl/Cmd + Minus for zoom out
            else if ((e.ctrlKey || e.metaKey) && e.key === \'-\') {
                e.preventDefault();
                zoomOut();
            }
            // Ctrl/Cmd + 0 for reset zoom
            else if ((e.ctrlKey || e.metaKey) && e.key === \'0\') {
                e.preventDefault();
                resetZoom();
            }
        });
        
        // Initialize drag and drop when page loads
        setTimeout(function() {
            initializeDragAndDrop();
            updateZoomDisplay();
            updateStatus(\'Prêt - Activez le mode déplacement pour déplacer les éléments\');
        }, 500);
        
        // Table operations
        function addTableRow(tableIndex) {
            const table = document.getElementById("table_" + tableIndex);
            const newRow = table.insertRow(-1);
            const cellCount = table.rows[0].cells.length;
            
            for (let i = 0; i < cellCount; i++) {
                const cell = newRow.insertCell(i);
                cell.contentEditable = true;
                cell.innerHTML = "New Cell";
            }
            updateStatus("Row added");
        }
        
        function addTableColumn(tableIndex) {
            const table = document.getElementById("table_" + tableIndex);
            const rows = table.rows;
            
            for (let i = 0; i < rows.length; i++) {
                const cell = rows[i].insertCell(-1);
                cell.contentEditable = true;
                cell.innerHTML = i === 0 && rows[i].cells[0].tagName === "TH" ? "New Header" : "New Cell";
                
                if (i === 0 && rows[i].cells[0].tagName === "TH") {
                    const th = document.createElement("th");
                    th.contentEditable = true;
                    th.innerHTML = cell.innerHTML;
                    cell.parentNode.replaceChild(th, cell);
                }
            }
            updateStatus("Column added");
        }
        
        function insertRowAbove(tableIndex) {
            const table = document.getElementById("table_" + tableIndex);
            const selectedRow = getSelectedTableRow(table);
            const rowIndex = selectedRow ? selectedRow.rowIndex : 1;
            const newRow = table.insertRow(rowIndex);
            const cellCount = table.rows[0].cells.length;
            
            for (let i = 0; i < cellCount; i++) {
                const cell = newRow.insertCell(i);
                cell.contentEditable = true;
                cell.innerHTML = "New Cell";
            }
            updateStatus("Row inserted");
        }
        
        function insertColumnLeft(tableIndex) {
            const table = document.getElementById("table_" + tableIndex);
            const selectedCell = getSelectedTableCell();
            const colIndex = selectedCell ? selectedCell.cellIndex : 0;
            const rows = table.rows;
            
            for (let i = 0; i < rows.length; i++) {
                const cell = rows[i].insertCell(colIndex);
                cell.contentEditable = true;
                cell.innerHTML = i === 0 && rows[i].cells[0].tagName === "TH" ? "New Header" : "New Cell";
            }
            updateStatus("Column inserted");
        }
        
        function deleteTableRow(tableIndex) {
            const table = document.getElementById("table_" + tableIndex);
            if (table.rows.length > 1) {
                const selectedRow = getSelectedTableRow(table);
                const rowIndex = selectedRow ? selectedRow.rowIndex : table.rows.length - 1;
                table.deleteRow(rowIndex);
                updateStatus("Row deleted");
            }
        }
        
        function deleteTableColumn(tableIndex) {
            const table = document.getElementById("table_" + tableIndex);
            const rows = table.rows;
            if (rows[0].cells.length > 1) {
                const selectedCell = getSelectedTableCell();
                const colIndex = selectedCell ? selectedCell.cellIndex : rows[0].cells.length - 1;
                
                for (let i = 0; i < rows.length; i++) {
                    rows[i].deleteCell(colIndex);
                }
                updateStatus("Column deleted");
            }
        }
        
        function getSelectedTableRow(table) {
            const selection = window.getSelection();
            if (selection.rangeCount > 0) {
                let node = selection.anchorNode;
                while (node && node.nodeName !== "TR") {
                    node = node.parentNode;
                }
                return node;
            }
            return null;
        }
        
        function getSelectedTableCell() {
            const selection = window.getSelection();
            if (selection.rangeCount > 0) {
                let node = selection.anchorNode;
                while (node && node.nodeName !== "TD" && node.nodeName !== "TH") {
                    node = node.parentNode;
                }
                return node;
            }
            return null;
        }
        
        function exportTableCSV(tableIndex) {
            const table = document.getElementById("table_" + tableIndex);
            let csv = [];
            
            for (let i = 0; i < table.rows.length; i++) {
                let row = [];
                for (let j = 0; j < table.rows[i].cells.length; j++) {
                    let cellText = table.rows[i].cells[j].innerText.replace(/"/g, \'""\');
                    row.push(\'"\' + cellText + \'"\');
                }
                csv.push(row.join(","));
            }
            
            const blob = new Blob([csv.join("\n")], { type: "text/csv" });
            const url = URL.createObjectURL(blob);
            const a = document.createElement("a");
            a.href = url;
            a.download = "table_" + tableIndex + ".csv";
            a.click();
            URL.revokeObjectURL(url);
            
            updateStatus("Table exported as CSV");
        }
        
        // Add new elements
        function addNewTable() {
            const container = document.querySelector(".view-mode.active");
            const tableHtml = `
                <div class="table-container" data-table-index="new_${Date.now()}">
                    <div class="table-tools">
                        <button class="table-btn" onclick="this.parentElement.parentElement.remove()">🗑️ Delete Table</button>
                    </div>
                    <table class="pdf-table editable-table">
                        <thead>
                            <tr>
                                <th contenteditable="true">Header 1</th>
                                <th contenteditable="true">Header 2</th>
                                <th contenteditable="true">Header 3</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td contenteditable="true">Cell 1</td>
                                <td contenteditable="true">Cell 2</td>
                                <td contenteditable="true">Cell 3</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            `;
            container.insertAdjacentHTML("beforeend", tableHtml);
            updateStatus("New table added");
        }
        
        function addTextBlock() {
            const container = document.querySelector(".view-mode.active");
            const textHtml = `
                <div class="pdf-text" contenteditable="true">
                    <p>New text block. Start typing here...</p>
                </div>
            `;
            container.insertAdjacentHTML("beforeend", textHtml);
            updateStatus("Text block added");
        }
        
        function insertImage() {
            const input = document.createElement("input");
            input.type = "file";
            input.accept = "image/*";
            input.onchange = function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        const container = document.querySelector(".view-mode.active");
                        const imageHtml = `
                            <div class="pdf-image-container" draggable="true">
                                <img src="${event.target.result}" class="pdf-image" />
                                <div class="image-resize-handle nw" data-handle="nw"></div>
                                <div class="image-resize-handle ne" data-handle="ne"></div>
                                <div class="image-resize-handle sw" data-handle="sw"></div>
                                <div class="image-resize-handle se" data-handle="se"></div>
                            </div>
                        `;
                        container.insertAdjacentHTML("beforeend", imageHtml);
                        initializeImageHandlers();
                        updateStatus("Image inserted");
                    };
                    reader.readAsDataURL(file);
                }
            };
            input.click();
        }
        
        // Image drag and resize
        function initializeImageHandlers() {
            // Drag functionality
            document.querySelectorAll(".pdf-image-container").forEach(container => {
                container.addEventListener("dragstart", handleDragStart);
                container.addEventListener("dragend", handleDragEnd);
            });
            
            // Resize functionality
            document.querySelectorAll(".image-resize-handle").forEach(handle => {
                handle.addEventListener("mousedown", handleResizeStart);
            });
        }
        
        function handleDragStart(e) {
            isDragging = true;
            currentImage = e.target;
            e.target.classList.add("dragging");
            e.dataTransfer.effectAllowed = "move";
        }
        
        function handleDragEnd(e) {
            isDragging = false;
            e.target.classList.remove("dragging");
            updateStatus("Image repositioned");
        }
        
        function handleResizeStart(e) {
            e.preventDefault();
            isResizing = true;
            currentImage = e.target.parentElement.querySelector(".pdf-image");
            startX = e.clientX;
            startY = e.clientY;
            startWidth = currentImage.offsetWidth;
            startHeight = currentImage.offsetHeight;
            
            document.addEventListener("mousemove", handleResize);
            document.addEventListener("mouseup", handleResizeEnd);
        }
        
        function handleResize(e) {
            if (!isResizing) return;
            
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            
            currentImage.style.width = (startWidth + dx) + "px";
            currentImage.style.height = (startHeight + dy) + "px";
        }
        
        function handleResizeEnd(e) {
            isResizing = false;
            document.removeEventListener("mousemove", handleResize);
            document.removeEventListener("mouseup", handleResizeEnd);
            updateStatus("Image resized");
        }
        
        // Export as PDF (creates a copy)
        
        function exportAsPdf() {
            showLoading();
            
            const pdfContent = document.getElementById("pdfContent");
            if (!pdfContent) {
                alert("Erreur: Contenu éditable non trouvé.");
                hideLoading();
                return;
            }

            // Get all page containers and their exact dimensions
            const pageContainers = pdfContent.querySelectorAll(\'.pdf-page-container\');
            if (!pageContainers.length) {
                alert("Erreur: Aucune page trouvée.");
                hideLoading();
                return;
            }

            // Build HTML with exact page dimensions preserved
            let pagesHtml = \'\';
            let maxWidth = 0;
            let maxHeight = 0;
            
            pageContainers.forEach((page, index) => {
                const pageClone = page.cloneNode(true);
                
                // Get the computed style to get actual dimensions
                const style = window.getComputedStyle(page);
                const width = parseFloat(style.width);
                const height = parseFloat(style.height);
                
                maxWidth = Math.max(maxWidth, width);
                maxHeight = Math.max(maxHeight, height);
                
                // Set explicit dimensions on the cloned page
                pageClone.style.width = width + \'px\';
                pageClone.style.height = height + \'px\';
                pageClone.style.position = \'relative\';
                pageClone.style.pageBreakAfter = index < pageContainers.length - 1 ? \'always\' : \'auto\';
                pageClone.style.margin = \'0\';
                pageClone.style.padding = \'0\';
                
                pagesHtml += pageClone.outerHTML;
            });

            // Create a clean, self-contained HTML document for submission
            const htmlToSend = `
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <meta name="page-width" content="${maxWidth}">
                    <meta name="page-height" content="${maxHeight}">
                    <style>
                        * { margin: 0; padding: 0; box-sizing: border-box; }
                        body, html { 
                            margin: 0; 
                            padding: 0; 
                            width: ${maxWidth}px;
                            height: ${maxHeight}px;
                            overflow: hidden;
                        }
                        .pdf-page-container { 
                            position: relative;
                            overflow: hidden;
                            margin: 0;
                            padding: 0;
                        }
                        .pdf-element, .pdf-text, .pdf-image {
                            position: absolute;
                        }
                        @page {
                            size: ${maxWidth}px ${maxHeight}px;
                            margin: 0;
                        }
                    </style>
                </head>
                <body>
                    ${pagesHtml}
                </body>
                </html>
            `;
            
            fetch(window.location.pathname.replace("/html-editor", "/save-html-as-pdf"), {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": document.querySelector(\'meta[name="csrf-token"]\')?.content || ""
                },
                body: JSON.stringify({ html: htmlToSend })
            })
            .then(response => {
                hideLoading();
                if (response.redirected) {
                    window.location.href = response.url;
                } else {
                    response.json().then(data => {
                        if (data.success) {
                            updateStatus("Document sauvegardé avec succès");
                        } else {
                            alert("Erreur lors de la sauvegarde: " + data.error);
                            updateStatus("Erreur: " + data.error);
                        }
                    });
                }
            })
            .catch(error => {
                hideLoading();
                alert("Erreur de communication: " + error.message);
                updateStatus("Error: " + error.message);
            });
        }
        
        // Utility functions
        function updateStatus(message) {
            const statusElement = document.getElementById("statusMessage");
            statusElement.textContent = message;
            setTimeout(() => {
                statusElement.textContent = "Ready";
            }, 3000);
        }
        
        function showLoading() {
            document.getElementById("loadingOverlay").classList.add("active");
        }
        
        function hideLoading() {
            document.getElementById("loadingOverlay").classList.remove("active");
        }
        
        // Auto-save
        let saveTimeout;
        document.addEventListener("input", function() {
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(() => {
                updateStatus("Auto-saving...");
                // Implement auto-save if needed
            }, 5000);
        });
        
        // Initialize on load
        document.addEventListener("DOMContentLoaded", function() {
            initializeImageHandlers();
            updateStatus("Editor ready");
        });
        </script>';
    }
    
    /**
     * Fallback to basic pdftohtml conversion
     */
    private function fallbackPdfToHtml($pdfPath, Document $document)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_') . '.html';
        $baseFile = str_replace('.html', '', $tempFile);
        
        // Use pdftohtml with complex flag
        $command = sprintf(
            'pdftohtml -c -s -noframes %s %s 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($baseFile)
        );
        
        exec($command, $output, $returnCode);
        
        $extractedContent = '';
        
        if ($returnCode === 0 && file_exists($tempFile)) {
            $html = file_get_contents($tempFile);
            
            // Clean up temp files
            @unlink($tempFile);
            @unlink($baseFile . '-outline.html');
            
            // Clean up any generated image files
            $imageFiles = glob(dirname($tempFile) . '/' . pathinfo($baseFile, PATHINFO_FILENAME) . '*.{jpg,jpeg,png,gif}', GLOB_BRACE);
            foreach ($imageFiles as $imageFile) {
                @unlink($imageFile);
            }
            
            $extractedContent = $html;
        } else {
            // If pdftohtml fails, extract text only
            $textCommand = sprintf('pdftotext -layout %s - 2>&1', escapeshellarg($pdfPath));
            $text = shell_exec($textCommand);
            $extractedContent = '<pre>' . htmlspecialchars($text) . '</pre>';
        }
        
        // Build the complete HTML with toolbar using buildAdvancedEditableHtml
        $content = [
            'full_html' => $extractedContent,
            'tables' => [],
            'text' => '',
            'images' => [],
            'styles' => '',
            'fonts' => []
        ];
        
        return $this->buildAdvancedEditableHtml($content, $pdfPath, $document);
    }
    
    /**
     * Save HTML content (for auto-save)
     */
    public function saveHtml(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        
        $request->validate([
            'html' => 'required|string'
        ]);
        
        try {
            // Store HTML content temporarily for this session
            $sessionKey = 'html_content_' . $document->id;
            session([$sessionKey => $request->html]);
            
            return response()->json([
                'success' => true,
                'message' => 'Contenu sauvegardé temporairement'
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Save edited HTML as PDF
     */
    public function saveHtmlAsPdf(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $request->validate(['html' => 'required|string']);

        try {
            $html = $request->html;
            
            // Process and embed all images as base64
            $html = $this->processImagesForPdf($html, $document);
            
            // Extract only the page containers from the HTML
            $cleanedHtml = $this->extractPageContent($html);
            
            // Get page dimensions
            $dimensions = $this->extractPageDimensions($html);
            
            // Create final HTML document
            $finalHtml = $this->buildFinalHtml($cleanedHtml, $dimensions);
            
            // Save to temp file
            $htmlFile = tempnam(sys_get_temp_dir(), 'edited_') . '.html';
            file_put_contents($htmlFile, $finalHtml);
            
            // Generate PDF
            $pdfFile = $this->generatePdfFromHtml($htmlFile, $dimensions);
            
            if (!$pdfFile) {
                throw new Exception('Failed to generate PDF');
            }
            
            // Save the PDF as a new document
            $content = file_get_contents($pdfFile);
            $copyName = pathinfo($document->original_name, PATHINFO_FILENAME) . '_edited_' . date('Y-m-d_His') . '.pdf';
            
            $newDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => Auth::id(),
                'original_name' => $copyName,
                'stored_name' => 'documents/' . $document->tenant_id . '/' . Str::uuid() . '.pdf',
                'mime_type' => 'application/pdf',
                'size' => strlen($content),
                'hash' => hash('sha256', $content),
                'extension' => 'pdf',
                'metadata' => ['edited_from' => $document->id, 'edited_at' => now()->toIso8601String()],
            ]);
            
            Storage::put($newDocument->stored_name, $content);
            
            activity()->performedOn($newDocument)->causedBy(Auth::user())
                ->withProperties(['action' => 'html_edit_copy', 'source_document' => $document->id])
                ->log('Document copy created from HTML editor');
            
            // Clean up temp files
            @unlink($htmlFile);
            @unlink($pdfFile);
            
            return redirect()->route('documents.show', $newDocument)
                ->with('success', 'Document sauvegardé avec succès.');
                
        } catch (Exception $e) {
            Log::error('HTML to PDF save failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }
    
    private function processImagesForPdf($html, $document)
    {
        return preg_replace_callback(
            '/<img([^>]*?)src=["\']?(.*?)["\']?([^>]*)>/i',
            function($matches) use ($document) {
                $beforeSrc = $matches[1];
                $src = $matches[2];
                $afterSrc = $matches[3];
                
                // Skip if already a data URI
                if (strpos($src, 'data:') === 0) {
                    return $matches[0];
                }
                
                // Try multiple locations for the image
                $imageData = null;
                $mimeType = 'image/png';
                
                // Extract filename from URL
                $filename = basename(parse_url($src, PHP_URL_PATH));
                
                // Try document-specific folder first
                $paths = [
                    storage_path('app/documents/' . $document->id . '/' . $filename),
                    storage_path('app/temp/' . $filename),
                    public_path($src),
                ];
                
                foreach ($paths as $path) {
                    if (file_exists($path) && is_file($path)) {
                        $imageData = file_get_contents($path);
                        $mimeType = mime_content_type($path);
                        break;
                    }
                }
                
                if ($imageData) {
                    $base64 = base64_encode($imageData);
                    return "<img{$beforeSrc}src=\"data:{$mimeType};base64,{$base64}\"{$afterSrc}>";
                }
                
                // Return original if image not found
                return $matches[0];
            },
            $html
        );
    }
    
    private function extractPageContent($html)
    {
        // Use a more robust regex to capture nested divs
        // This will capture the entire page container including all nested elements
        $pattern = '/<div[^>]*class=["\'][^"\']*pdf-page-container[^"\']*["\'][^>]*>(?:[^<]|<(?!\/div>)|<div[^>]*>(?:[^<]|<(?!\/div>))*<\/div>)*<\/div>/is';
        
        preg_match_all($pattern, $html, $matches);
        
        if (!empty($matches[0])) {
            return implode("\n", $matches[0]);
        }
        
        // Alternative: Use DOM parsing for more reliable extraction
        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new \DOMXPath($dom);
            
            // Find all elements with class pdf-page-container
            $pageContainers = $xpath->query("//div[contains(@class, 'pdf-page-container')]");
            
            if ($pageContainers->length > 0) {
                $result = '';
                foreach ($pageContainers as $container) {
                    $result .= $dom->saveHTML($container) . "\n";
                }
                return $result;
            }
        } catch (\Exception $e) {
            // Fall back to regex if DOM parsing fails
        }
        
        // If no page containers found, extract body content
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $bodyMatch)) {
            return $bodyMatch[1];
        }
        
        return $html;
    }
    
    private function extractPageDimensions($html)
    {
        $width = 210; // Default A4 width in mm
        $height = 297; // Default A4 height in mm
        
        // Try to get from meta tags
        if (preg_match('/<meta name="page-width" content="(\d+\.?\d*)"/', $html, $widthMatch)) {
            $widthPx = floatval($widthMatch[1]);
            $width = round($widthPx * 25.4 / 96);
        }
        
        if (preg_match('/<meta name="page-height" content="(\d+\.?\d*)"/', $html, $heightMatch)) {
            $heightPx = floatval($heightMatch[1]);
            $height = round($heightPx * 25.4 / 96);
        }
        
        // Fallback: try first page container
        if (preg_match('/<div[^>]*class=["\'][^"\']*pdf-page-container[^"\']*["\'][^>]*style="[^"]*width:\s*(\d+\.?\d*)px/', $html, $widthMatch)) {
            $width = round(floatval($widthMatch[1]) * 25.4 / 96);
        }
        
        if (preg_match('/<div[^>]*class=["\'][^"\']*pdf-page-container[^"\']*["\'][^>]*style="[^"]*height:\s*(\d+\.?\d*)px/', $html, $heightMatch)) {
            $height = round(floatval($heightMatch[1]) * 25.4 / 96);
        }
        
        return ['width' => $width, 'height' => $height];
    }
    
    private function buildFinalHtml($content, $dimensions)
    {
        $width = $dimensions['width'];
        $height = $dimensions['height'];
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            margin: 0;
            padding: 0;
            width: {$width}mm;
            height: {$height}mm;
        }
        
        .pdf-page-container {
            width: {$width}mm;
            height: {$height}mm;
            position: relative;
            page-break-after: always;
            overflow: hidden;
            margin: 0;
            padding: 0;
        }
        
        .pdf-page-container:last-child {
            page-break-after: auto;
        }
        
        .pdf-element, .pdf-text, .pdf-image {
            position: absolute;
        }
        
        img {
            max-width: 100%;
            object-fit: contain;
        }
        
        @page {
            size: {$width}mm {$height}mm;
            margin: 0;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>
{$content}
</body>
</html>
HTML;
    }
    
    private function generatePdfFromHtml($htmlFile, $dimensions)
    {
        $pdfFile = tempnam(sys_get_temp_dir(), 'output_') . '.pdf';
        
        $width = $dimensions['width'];
        $height = $dimensions['height'];
        
        // Use wkhtmltopdf with precise settings
        $command = sprintf(
            'wkhtmltopdf ' .
            '--page-width %dmm --page-height %dmm ' .
            '--margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 0 ' .
            '--disable-smart-shrinking ' .
            '--print-media-type ' .
            '--enable-local-file-access ' .
            '--load-error-handling ignore ' .
            '--encoding UTF-8 ' .
            '--dpi 96 ' .
            '--image-quality 100 ' .
            '--no-background ' .
            '%s %s 2>&1',
            $width,
            $height,
            escapeshellarg($htmlFile),
            escapeshellarg($pdfFile)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($pdfFile) && filesize($pdfFile) > 0) {
            return $pdfFile;
        }
        
        Log::error('wkhtmltopdf failed', ['command' => $command, 'output' => $output, 'code' => $returnCode]);
        return null;
    }
    
/**
     * Finds image URLs in HTML and replaces them with base64 data URIs for embedding.
     */
    private function embedImagesAsBase64($html, Document $document)
    {
        return preg_replace_callback(
            '/<img([^>]*?)src="([^"]+)"/i',
            function($matches) use ($document) {
                $attributes = $matches[1];
                $src = $matches[2];
                $appUrl = config('app.url');

                // Process only local asset URLs, skip data URIs and external images
                if (strpos($src, 'data:') === 0 || strpos($src, $appUrl) === false) {
                    return $matches[0];
                }

                $path_parts = explode('/', parse_url($src, PHP_URL_PATH));
                $filename = end($path_parts);
                
                if (!$filename) {
                    return $matches[0];
                }

                // Images are stored in storage/app/documents/{id}/{filename} by the Python script
                $imagePath = storage_path('app/documents/' . $document->id . '/' . $filename);

                if (file_exists($imagePath)) {
                    $imageData = file_get_contents($imagePath);
                    $mimeType = mime_content_type($imagePath);
                    $base64 = base64_encode($imageData);
                    $dataUrl = "data:{$mimeType};base64,{$base64}";
                    
                    return "<img{$attributes}src=\"{$dataUrl}\"";
                }

                return $matches[0];
            },
            $html
        );
    }
    /**
     * Transform relative image paths in HTML to accessible asset URLs.
     */
    private function transformImagePaths($html, Document $document)
    {
        // Regex to find src attributes with relative paths (e.g., "image.png", not "/path/image.png" or "http://...")
        $html = preg_replace_callback(
            '/<img([^>]*?)src="([^"\/][^"]+\.(png|jpg|jpeg|gif|bmp))"([^>]*?)>/i',
            function($matches) use ($document) {
                $preAttributes = $matches[1];
                $filename = $matches[2];
                $postAttributes = $matches[4];
                
                // The python script saves images in storage/app/documents/{id}
                $imagePath = storage_path('app/documents/' . $document->id . '/' . $filename);

                if (file_exists($imagePath)) {
                    $url = route('documents.serve-asset', [
                        'document' => $document->id,
                        'filename' => $filename
                    ]);
                    return "<img{$preAttributes}src=\"{$url}\"{$postAttributes}>";
                }

                // If file doesn't exist for some reason, return the original tag to avoid breaking the layout
                return $matches[0];
            },
            $html
        );

        // Also handle background-image styles
        $html = preg_replace_callback(
            '/background-image:\s*url\(["\']?([^"\'\/][^"\')]+\.(png|jpg|jpeg|gif|bmp))["\']?\)/i',
            function($matches) use ($document) {
                $filename = $matches[1];
                
                $imagePath = storage_path('app/documents/' . $document->id . '/' . $filename);

                if (file_exists($imagePath)) {
                    $url = route('documents.serve-asset', [
                        'document' => $document->id,
                        'filename' => $filename
                    ]);
                    return "background-image: url('{$url}')";
                }
                
                return $matches[0];
            },
            $html
        );

        return $html;
    }
    
    /**
     * Prepare HTML for editing (clean up pdftohtml output)
     */
    private function prepareHtmlForEditing($html)
    {
        // Extract styles from head if present
        $styles = '';
        if (preg_match('/<style[^>]*>(.*?)<\/style>/is', $html, $styleMatches)) {
            $styles = '<style>' . $styleMatches[1] . '</style>';
        }
        
        // Extract body content
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches)) {
            $html = $matches[1];
        }
        
        // Remove page navigation links but keep other elements
        $html = preg_replace('/<a[^>]*href="#[^"]*"[^>]*>\s*<\/a>/is', '', $html);
        
        // Preserve original styling but make text editable
        $html = str_replace('readonly', '', $html);
        $html = str_replace('disabled', '', $html);
        
        // Improve absolute positioning for editing while preserving layout
        // Convert divs with absolute positioning to relative positioning with margins
        $html = preg_replace_callback(
            '/<div[^>]*style="([^"]*position:\s*absolute[^"]*)"[^>]*>/i',
            function($matches) {
                $style = $matches[1];
                // Extract position values
                preg_match('/top:\s*([\d.]+)px/i', $style, $top);
                preg_match('/left:\s*([\d.]+)px/i', $style, $left);
                
                $marginTop = isset($top[1]) ? 'margin-top: ' . ($top[1] / 2) . 'px;' : '';
                $marginLeft = isset($left[1]) ? 'margin-left: ' . ($left[1] / 2) . 'px;' : '';
                
                // Replace absolute with relative positioning
                $style = preg_replace('/position:\s*absolute;?/i', 'position: relative; display: inline-block;', $style);
                $style = preg_replace('/top:\s*[\d.]+px;?/i', $marginTop, $style);
                $style = preg_replace('/left:\s*[\d.]+px;?/i', $marginLeft, $style);
                
                return '<div style="' . $style . '">';
            },
            $html
        );
        
        // Preserve fonts and colors
        $html = preg_replace_callback(
            '/<p[^>]*style="([^"]*)"[^>]*>/i',
            function($matches) {
                $style = $matches[1] . '; padding: 2px; margin: 2px;';
                return '<p style="' . $style . '" contenteditable="true">';
            },
            $html
        );
        
        // Return HTML with preserved styles
        return $styles . "\n" . $html;
    }
    
    /**
     * Extract complete HTML with all styles preserved for 1:1 representation
     */
    private function extractCompleteHtml($html)
    {
        // Extract all styles from head
        $styles = '';
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $styleMatches)) {
            foreach ($styleMatches[1] as $style) {
                $styles .= $style . "\n";
            }
        }
        
        // Extract body content with all attributes preserved
        $bodyContent = $html;
        $bodyBgColor = 'white';
        
        // Extract body with its background color if present
        if (preg_match('/<body[^>]*bgcolor="([^"]+)"[^>]*>(.*?)<\/body>/is', $html, $matches)) {
            $bodyBgColor = $matches[1];
            $bodyContent = $matches[2];
        } elseif (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches)) {
            $bodyContent = $matches[1];
        }
        
        // Remove only navigation links that pdftohtml adds
        $bodyContent = preg_replace('/<a[^>]*href="#[^"]*"[^>]*>\s*<\/a>/is', '', $bodyContent);
        
        // Process tables to ensure borders are visible
        $bodyContent = $this->enhanceTableBorders($bodyContent);
        
        // Wrap in a container div that preserves the exact layout
        // Do NOT set a background color here - let the PDF content define it
        $fullHtml = '<div style="position: relative; width: 100%; min-height: 100vh;">';
        
        // Add enhanced styles inline
        $fullHtml .= '<style>';
        if ($styles) {
            $fullHtml .= $styles . "\n";
        }
        // Add table border styles to ensure they're visible
        $fullHtml .= '
        table { border-collapse: collapse; }
        table td, table th { 
            border: 1px solid #000 !important;
            padding: 4px;
        }
        /* Preserve background colors on specific elements only */
        div[style*="background"] {
            background-color: inherit !important;
        }
        /* Ensure images with backgrounds show properly */
        img {
            background-color: transparent !important;
        }
        ';
        $fullHtml .= '</style>';
        
        // Add the body content AS-IS
        $fullHtml .= $bodyContent;
        
        $fullHtml .= '</div>';
        
        return $fullHtml;
    }
    
    /**
     * Enhance table borders to ensure they're visible
     */
    private function enhanceTableBorders($html)
    {
        // Add border styles to tables if not present
        $html = preg_replace_callback(
            '/<table([^>]*)>/i',
            function($matches) {
                $attrs = $matches[1];
                // Check if style attribute exists
                if (strpos($attrs, 'style=') === false) {
                    return '<table' . $attrs . ' style="border-collapse: collapse; border: 1px solid #000;">';
                } else {
                    // Add border styles to existing style
                    $attrs = preg_replace(
                        '/style="([^"]*)"/i',
                        'style="$1; border-collapse: collapse; border: 1px solid #000;"',
                        $attrs
                    );
                    return '<table' . $attrs . '>';
                }
            },
            $html
        );
        
        // Add border styles to td and th elements
        $html = preg_replace_callback(
            '/<(td|th)([^>]*)>/i',
            function($matches) {
                $tag = $matches[1];
                $attrs = $matches[2];
                // Check if style attribute exists
                if (strpos($attrs, 'style=') === false) {
                    return '<' . $tag . $attrs . ' style="border: 1px solid #000; padding: 4px;">';
                } else {
                    // Add border styles to existing style
                    $attrs = preg_replace(
                        '/style="([^"]*)"/i',
                        'style="$1; border: 1px solid #000; padding: 4px;"',
                        $attrs
                    );
                    return '<' . $tag . $attrs . '>';
                }
            },
            $html
        );
        
        return $html;
    }
    
    /**
     * Bulk delete documents
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'document_ids' => 'required|array',
            'document_ids.*' => 'integer|exists:documents,id'
        ]);
        
        $deletedCount = 0;
        $errors = [];
        
        foreach ($request->document_ids as $documentId) {
            try {
                $document = Document::findOrFail($documentId);
                
                // Check authorization for each document
                if (!Auth::user()->can('delete', $document)) {
                    $errors[] = "Non autorisé à supprimer: {$document->original_name}";
                    continue;
                }
                
                // Delete physical file
                if (Storage::exists($document->stored_name)) {
                    Storage::delete($document->stored_name);
                }
                
                // Delete thumbnail if exists
                if ($document->thumbnail_path && Storage::exists($document->thumbnail_path)) {
                    Storage::delete($document->thumbnail_path);
                }
                
                // Log activity
                activity()
                    ->causedBy(Auth::user())
                    ->withProperties([
                        'original_name' => $document->original_name,
                        'bulk_operation' => true
                    ])
                    ->log('Document deleted (bulk operation)');
                
                // Delete record
                $document->delete();
                $deletedCount++;
                
            } catch (Exception $e) {
                $errors[] = "Erreur lors de la suppression du document ID {$documentId}";
                Log::error('Bulk delete error', [
                    'document_id' => $documentId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        if ($errors) {
            return back()->with('warning', "Supprimé {$deletedCount} document(s). Erreurs: " . implode(', ', $errors));
        }
        
        return back()->with('success', "{$deletedCount} document(s) supprimé(s) avec succès.");
    }
    
    /**
     * Bulk download documents (create ZIP)
     */
    public function bulkDownload(Request $request)
    {
        $request->validate([
            'document_ids' => 'required|array',
            'document_ids.*' => 'integer|exists:documents,id'
        ]);
        
        $zipName = 'documents_' . date('Y-m-d_His') . '.zip';
        $zipPath = storage_path('app/temp/' . $zipName);
        
        // Ensure temp directory exists
        if (!file_exists(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }
        
        $zip = new \ZipArchive();
        
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== TRUE) {
            return back()->with('error', 'Impossible de créer l\'archive ZIP');
        }
        
        foreach ($request->document_ids as $documentId) {
            try {
                $document = Document::findOrFail($documentId);
                
                // Check authorization
                if (!Auth::user()->can('download', $document)) {
                    continue;
                }
                
                $filePath = Storage::path($document->stored_name);
                
                if (file_exists($filePath)) {
                    $zip->addFile($filePath, $document->original_name);
                    
                    // Log download
                    activity()
                        ->performedOn($document)
                        ->causedBy(Auth::user())
                        ->withProperties(['bulk_operation' => true])
                        ->log('Document downloaded (bulk operation)');
                }
            } catch (Exception $e) {
                Log::error('Bulk download error', [
                    'document_id' => $documentId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $zip->close();
        
        // Send the ZIP file
        return response()->download($zipPath, $zipName)->deleteFileAfterSend(true);
    }
    
    /**
     * Wrap HTML content with proper structure for PDF conversion
     */
    private function wrapHtmlForPdf($content)
    {
        // Check if content already has styles
        $hasStyles = strpos($content, '<style>') !== false;
        $styles = '';
        $bodyContent = $content;
        
        // Extract existing styles if present
        if ($hasStyles) {
            if (preg_match('/<style[^>]*>(.*?)<\/style>/is', $content, $styleMatches)) {
                $styles = $styleMatches[1];
                $bodyContent = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content);
            }
        }
        
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @page {
            size: A4;
            margin: 0;
        }
        @media print {
            body {
                margin: 0;
            }
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            padding: 0;
            background: white;
            position: relative;
        }
        /* Preserve absolute positioning */
        div[style*="position: absolute"],
        div[style*="position:absolute"] {
            position: absolute !important;
        }
        /* Preserve all original styles with higher priority */
        ' . $styles . '
        /* Override only for better rendering */
        img {
            max-width: none !important;
            height: auto;
        }
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
' . $bodyContent . '
</body>
</html>';
    }
}