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
    <meta name="csrf-token" content="' . csrf_token() . '">
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
            padding: 20px !important;
            background: #f5f5f5 !important;
            display: flex !important;
            justify-content: center !important;
            align-items: flex-start !important;
            text-align: center !important;
        }
        
        .pdf-content {
            background: white !important;
            padding: 0 !important;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1), 0 1px 3px rgba(0,0,0,0.08) !important;
            position: relative !important;
            width: fit-content !important;
            max-width: 100% !important;
            margin: 0 auto !important;
            border: 1px solid #dee2e6 !important;
            border-radius: 4px !important;
            transform-origin: top center !important;
            transition: transform 0.3s ease !important;
            display: inline-block !important;
        }
        
        /* PDF page container - exact size */
        .pdf-page-container {
            position: relative !important;
            margin: 0 !important;
            padding: 20px !important;
            page-break-after: always !important;
            background: white !important;
            width: fit-content !important;
            min-width: 600px !important;
            box-sizing: border-box !important;
            text-align: left !important;
        }
        
        .pdf-page-container:last-child {
            page-break-after: auto !important;
            margin-bottom: 0 !important;
        }
        
        /* Allow background images in PDF document content */
        .pdf-document {
            position: relative;
            margin: 0 !important;
            padding: 0 !important;
            width: fit-content !important;
            box-sizing: border-box !important;
            display: inline-block !important;
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
            min-height: 400px;
        }
        
        /* Ensure content is always visible */
        #pdfContent:empty::before {
            content: "Cliquez ici pour commencer à éditer...";
            color: #999;
            font-style: italic;
            padding: 20px;
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
            padding: 10px;
            line-height: 1.8;
            font-size: 14px;
            color: #212529;
            min-height: 30px;
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
        
        // If no content, provide a minimal structure
        if (empty($content['full_html']) && empty($content['text']) && empty($content['tables'])) {
            return '<div class="pdf-document" contenteditable="true">
                <div class="pdf-page-container">
                    <p>Commencez à éditer votre document ici...</p>
                </div>
            </div>';
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
     * Get actual PDF dimensions from the original file
     */
    private function getOriginalPdfDimensions($pdfPath)
    {
        try {
            // Use pdfinfo to get page dimensions
            $command = sprintf('pdfinfo %s 2>/dev/null', escapeshellarg($pdfPath));
            $output = shell_exec($command);
            
            if ($output) {
                // Parse page size (e.g., "Page size:       595 x 842 pts (A4)")
                if (preg_match('/Page size:\s+(\d+\.?\d*)\s+x\s+(\d+\.?\d*)\s+pts/i', $output, $matches)) {
                    $widthPts = floatval($matches[1]);
                    $heightPts = floatval($matches[2]);
                    
                    // Convert points to mm (1 pt = 0.352778 mm)
                    $widthMm = round($widthPts * 0.352778);
                    $heightMm = round($heightPts * 0.352778);
                    
                    return [
                        'width_pts' => $widthPts,
                        'height_pts' => $heightPts,
                        'width_mm' => $widthMm,
                        'height_mm' => $heightMm,
                        'width_px' => round($widthPts * 96 / 72), // Convert to pixels at 96 DPI
                        'height_px' => round($heightPts * 96 / 72)
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning('Could not determine PDF dimensions', ['error' => $e->getMessage()]);
        }
        
        // Return A4 as default
        return [
            'width_pts' => 595,
            'height_pts' => 842,
            'width_mm' => 210,
            'height_mm' => 297,
            'width_px' => 793,
            'height_px' => 1122
        ];
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
                
                // Adjust container to fit content
                const container = document.querySelector(\'.pdf-editor-container\');
                if (container && pdfContent) {
                    // Get actual content height
                    const contentHeight = pdfContent.scrollHeight || pdfContent.offsetHeight;
                    container.style.minHeight = (contentHeight * scale + 100) + \'px\';
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
            let pageContainers = pdfContent.querySelectorAll(\'.pdf-page-container\');
            
            // If no page containers, treat the entire content as one page
            if (!pageContainers.length) {
                // Create a wrapper for all content
                const wrapper = document.createElement(\'div\');
                wrapper.className = \'pdf-page-container\';
                wrapper.innerHTML = pdfContent.innerHTML;
                pageContainers = [wrapper];
            }

            // Build HTML with exact page dimensions preserved
            let pagesHtml = \'\';
            let maxWidth = 0;
            let maxHeight = 0;
            
            pageContainers.forEach((page, index) => {
                const pageClone = page.cloneNode(true);
                
                // Convert all images to base64 if not already
                const images = pageClone.querySelectorAll(\'img\');
                images.forEach(img => {
                    // If image src is not already a data URI, keep it as is
                    // The server will handle the conversion
                    if (img.src && !img.src.startsWith(\'data:\')) {
                        // Ensure the src attribute is preserved
                        img.setAttribute(\'src\', img.src);
                    }
                });
                
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
            
            // Log for debugging
            Log::info('Starting HTML to PDF conversion', [
                'document_id' => $document->id,
                'html_length' => strlen($html),
                'has_images' => strpos($html, '<img') !== false
            ]);
            
            // Extract only the page content (without toolbar, etc.)
            $cleanedHtml = $this->extractPageContentImproved($html);
            
            // Remove any CSS transforms that could cause scaling issues
            $cleanedHtml = $this->removeTransformations($cleanedHtml);
            
            // Process and embed all images as base64 AFTER extraction
            $processedHtml = $this->processImagesForPdfImproved($cleanedHtml, $document);
            
            // Get original PDF dimensions if available
            $originalPdfPath = Storage::path($document->stored_name);
            $originalDimensions = null;
            if (file_exists($originalPdfPath) && $document->mime_type === 'application/pdf') {
                $originalDimensions = $this->getOriginalPdfDimensions($originalPdfPath);
                Log::info('Using original PDF dimensions', $originalDimensions);
            }
            
            // Get page dimensions - use original if available, otherwise extract from HTML
            $dimensions = $originalDimensions ? 
                ['width' => $originalDimensions['width_mm'], 'height' => $originalDimensions['height_mm']] :
                $this->extractPageDimensions($html);
            
            // Create final HTML document with improved structure
            // Note: buildFinalHtmlImproved now analyzes and uses actual content dimensions
            $finalHtml = $this->buildFinalHtmlImproved($processedHtml, $dimensions);
            
            // Save to temp file
            $htmlFile = tempnam(sys_get_temp_dir(), 'edited_') . '.html';
            file_put_contents($htmlFile, $finalHtml);
            
            // Extract actual dimensions from the final HTML for PDF generation
            $contentBounds = $this->analyzeContentBounds($finalHtml);
            $actualDimensions = [
                'width' => round($contentBounds['width'] * 25.4 / 96),
                'height' => round($contentBounds['height'] * 25.4 / 96)
            ];
            
            // Generate PDF with actual content dimensions to avoid white bands
            $pdfFile = $this->generatePdfFromHtmlImproved($htmlFile, $actualDimensions);
            
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
                
                // Handle different types of image URLs
                if (strpos($src, 'http') === 0 || strpos($src, '//') === 0) {
                    // External URL - try to fetch it
                    try {
                        $imageData = @file_get_contents($src);
                        if ($imageData) {
                            $mimeType = 'image/png'; // Default, will be overridden if we can detect
                        }
                    } catch (\Exception $e) {
                        // Ignore fetch errors
                    }
                } else {
                    // Local file - extract filename from URL
                    $filename = basename(parse_url($src, PHP_URL_PATH));
                    
                    // Check if it's a route-based URL (like /documents/123/assets/filename.png)
                    if (preg_match('/\/documents\/\d+\/assets\/(.+)/', $src, $urlMatch)) {
                        $filename = $urlMatch[1];
                    }
                    
                    // Try document-specific folders and various locations
                    $paths = [
                        storage_path('app/documents/' . $document->id . '/' . $filename),
                        storage_path('app/documents/' . $document->tenant_id . '/' . $filename),
                        storage_path('app/' . dirname($document->stored_name) . '/' . $filename),
                        storage_path('app/temp/' . $filename),
                        storage_path('app/public/' . $filename),
                        storage_path('app/public/documents/' . $document->id . '/' . $filename),
                        public_path($filename),
                        public_path('storage/' . $filename),
                        public_path('documents/' . $document->id . '/' . $filename),
                        sys_get_temp_dir() . '/' . $filename,
                    ];
                    
                    // If src starts with /, it's absolute from public
                    if (strpos($src, '/') === 0) {
                        array_unshift($paths, public_path(ltrim($src, '/')));
                    }
                    
                    // Log paths being checked for debugging
                    Log::debug('Searching for image', [
                        'src' => $src,
                        'filename' => $filename,
                        'document_id' => $document->id,
                        'paths_checked' => $paths
                    ]);
                    
                    foreach ($paths as $path) {
                        if (file_exists($path) && is_file($path)) {
                            $imageData = file_get_contents($path);
                            $mimeType = mime_content_type($path) ?: 'image/png';
                            Log::info('Image found and embedded', ['path' => $path, 'size' => strlen($imageData)]);
                            break;
                        }
                    }
                }
                
                if ($imageData) {
                    $base64 = base64_encode($imageData);
                    return "<img{$beforeSrc}src=\"data:{$mimeType};base64,{$base64}\"{$afterSrc}>";
                }
                
                // Log missing image for debugging
                Log::warning('Image not found for PDF export', ['src' => $src, 'document_id' => $document->id]);
                
                // Return original if image not found
                return $matches[0];
            },
            $html
        );
    }
    
    private function extractPageContent($html)
    {
        // Use DOM parsing for reliable extraction
        try {
            $dom = new \DOMDocument();
            // Suppress warnings for HTML5 tags
            libxml_use_internal_errors(true);
            
            // Load HTML with UTF-8 encoding
            $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
            $dom->loadHTML($html);
            libxml_clear_errors();
            
            $xpath = new \DOMXPath($dom);
            
            // Find all elements with class pdf-page-container
            $pageContainers = $xpath->query("//div[contains(@class, 'pdf-page-container')]");
            
            if ($pageContainers->length > 0) {
                $result = '';
                foreach ($pageContainers as $container) {
                    // Save the full HTML including all child elements
                    $result .= $dom->saveHTML($container) . "\n";
                }
                return $result;
            }
            
            // Fallback: look for pdf-content div
            $pdfContent = $xpath->query("//div[@id='pdfContent']");
            if ($pdfContent->length > 0) {
                // Get all children of pdfContent
                $content = '';
                foreach ($pdfContent->item(0)->childNodes as $child) {
                    if ($child->nodeType === XML_ELEMENT_NODE) {
                        $content .= $dom->saveHTML($child) . "\n";
                    }
                }
                return $content;
            }
            
            // Last fallback: get body content excluding toolbar and status bar
            $body = $xpath->query("//body")->item(0);
            if ($body) {
                $content = '';
                foreach ($body->childNodes as $child) {
                    if ($child->nodeType === XML_ELEMENT_NODE) {
                        $classes = $child->getAttribute('class');
                        // Skip toolbar, loading overlay, and status bar
                        if (!preg_match('/(editor-toolbar|loading-overlay|status-bar)/i', $classes)) {
                            // For pdf-editor-container, get the inner pdf-content
                            if (strpos($classes, 'pdf-editor-container') !== false) {
                                $innerContent = $xpath->query(".//div[@class='pdf-content' or contains(@class, 'pdf-content')]", $child);
                                if ($innerContent->length > 0) {
                                    foreach ($innerContent->item(0)->childNodes as $innerChild) {
                                        if ($innerChild->nodeType === XML_ELEMENT_NODE) {
                                            $content .= $dom->saveHTML($innerChild) . "\n";
                                        }
                                    }
                                }
                            } else {
                                $content .= $dom->saveHTML($child) . "\n";
                            }
                        }
                    }
                }
                return $content;
            }
        } catch (\Exception $e) {
            Log::error('DOM parsing failed in extractPageContent', ['error' => $e->getMessage()]);
        }
        
        // Final fallback: extract body content with regex
        if (preg_match('/<div[^>]*id=["\']pdfContent["\'][^>]*>(.*?)<\/div>\s*<\/div>\s*<div[^>]*class=["\']status-bar/is', $html, $match)) {
            return $match[1];
        }
        
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $bodyMatch)) {
            // Remove toolbar and status bar
            $body = preg_replace('/<div[^>]*class=["\'][^"\']*(editor-toolbar|status-bar|loading-overlay)[^"\']["\'][^>]*>.*?<\/div>/is', '', $bodyMatch[1]);
            return $body;
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
        
        // Analyser le contenu pour obtenir les dimensions réelles du contenu
        $contentDimensions = $this->analyzeContentDimensions($content);
        
        // Calculer le facteur d'échelle pour adapter le contenu à 100% de la page
        $scaleX = 1;
        $scaleY = 1;
        if ($contentDimensions['width'] > 0 && $contentDimensions['height'] > 0) {
            // Convertir les dimensions de contenu de px vers mm
            $contentWidthMm = $contentDimensions['width'] * 25.4 / 96;
            $contentHeightMm = $contentDimensions['height'] * 25.4 / 96;
            
            // Calculer les facteurs d'échelle
            $scaleX = $width / $contentWidthMm;
            $scaleY = $height / $contentHeightMm;
            
            // Utiliser le facteur d'échelle qui préserve les proportions
            // tout en maximisant l'utilisation de l'espace
            $scale = min($scaleX, $scaleY);
            
            // Appliquer un facteur de 0.95 pour avoir une petite marge
            $scale = $scale * 0.95;
        } else {
            $scale = 1;
        }
        
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
        
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }
        
        body {
            width: {$width}mm;
            height: {$height}mm;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Conteneur principal qui sera mis à l'échelle */
        .pdf-scaling-wrapper {
            transform: scale({$scale});
            transform-origin: center center;
            width: {$contentDimensions['width']}px;
            height: {$contentDimensions['height']}px;
            position: relative;
        }
        
        .pdf-page-container {
            width: 100%;
            height: 100%;
            position: relative;
            page-break-after: always;
            overflow: hidden;
            margin: 0;
            padding: 0;
        }
        
        .pdf-page-container:last-child {
            page-break-after: auto;
        }
        
        /* Pour les éléments avec positionnement absolu */
        .pdf-element, .pdf-text {
            position: absolute;
        }
        
        /* Images adaptatives */
        img {
            max-width: 100%;
            height: auto;
            object-fit: contain;
            display: block;
        }
        
        .pdf-image {
            max-width: 100%;
            height: auto;
        }
        
        .pdf-image-container {
            display: inline-block;
            position: relative;
        }
        
        /* Tables et texte adaptifs */
        .pdf-table {
            width: 100%;
            table-layout: fixed;
        }
        
        .pdf-table td, .pdf-table th {
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        .pdf-text {
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        @page {
            size: {$width}mm {$height}mm;
            margin: 0;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
                width: {$width}mm;
                height: {$height}mm;
            }
            
            .pdf-scaling-wrapper {
                transform: scale({$scale}) !important;
            }
        }
    </style>
</head>
<body>
    <div class="pdf-scaling-wrapper">
        {$content}
    </div>
</body>
</html>
HTML;
    }
    
    private function generatePdfFromHtml($htmlFile, $dimensions)
    {
        $pdfFile = tempnam(sys_get_temp_dir(), 'output_') . '.pdf';
        
        $width = $dimensions['width'];
        $height = $dimensions['height'];
        
        // Use wkhtmltopdf with optimized settings for full-page utilization
        $command = sprintf(
            'wkhtmltopdf ' .
            '--page-width %dmm --page-height %dmm ' .
            '--margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 0 ' .
            '--disable-smart-shrinking ' .
            '--print-media-type ' .
            '--enable-local-file-access ' .
            '--load-error-handling ignore ' .
            '--encoding UTF-8 ' .
            '--dpi 96 ' . // Standard screen DPI for better scaling
            '--image-quality 90 ' . // Good image quality
            '--image-dpi 150 ' . // Optimize image resolution
            '--javascript-delay 1000 ' . // Allow time for transformations
            '--zoom 1.0 ' . // No additional zoom
            '--enable-javascript ' . // Enable JS for transformations
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
     * Analyser les dimensions réelles du contenu HTML
     */
    private function analyzeContentDimensions($html)
    {
        $width = 595; // Default A4 width in pixels (@ 72 DPI)
        $height = 842; // Default A4 height in pixels (@ 72 DPI)
        
        try {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
            libxml_clear_errors();
            
            $xpath = new \DOMXPath($dom);
            
            // Chercher les dimensions dans les conteneurs de page
            $pageContainers = $xpath->query("//div[contains(@class, 'pdf-page-container')]");
            if ($pageContainers->length > 0) {
                $firstContainer = $pageContainers->item(0);
                $style = $firstContainer->getAttribute('style');
                
                // Extraire largeur et hauteur du style
                if (preg_match('/width:\s*(\d+\.?\d*)px/i', $style, $widthMatch)) {
                    $width = floatval($widthMatch[1]);
                }
                if (preg_match('/height:\s*(\d+\.?\d*)px/i', $style, $heightMatch)) {
                    $height = floatval($heightMatch[1]);
                }
            }
            
            // Si pas de conteneur de page, analyser le contenu pour trouver les limites
            if ($pageContainers->length == 0) {
                $maxX = 0;
                $maxY = 0;
                
                // Chercher tous les éléments avec position absolue
                $absoluteElements = $xpath->query("//*[@style and contains(@style, 'position:') and contains(@style, 'absolute')]");
                foreach ($absoluteElements as $element) {
                    $style = $element->getAttribute('style');
                    
                    $left = 0;
                    $top = 0;
                    $elemWidth = 0;
                    $elemHeight = 0;
                    
                    if (preg_match('/left:\s*(\d+\.?\d*)px/i', $style, $match)) {
                        $left = floatval($match[1]);
                    }
                    if (preg_match('/top:\s*(\d+\.?\d*)px/i', $style, $match)) {
                        $top = floatval($match[1]);
                    }
                    if (preg_match('/width:\s*(\d+\.?\d*)px/i', $style, $match)) {
                        $elemWidth = floatval($match[1]);
                    }
                    if (preg_match('/height:\s*(\d+\.?\d*)px/i', $style, $match)) {
                        $elemHeight = floatval($match[1]);
                    }
                    
                    $maxX = max($maxX, $left + $elemWidth);
                    $maxY = max($maxY, $top + $elemHeight);
                }
                
                if ($maxX > 0 && $maxY > 0) {
                    // Ajouter une marge de 20px
                    $width = $maxX + 20;
                    $height = $maxY + 20;
                }
            }
            
        } catch (\Exception $e) {
            Log::warning('Failed to analyze content dimensions', ['error' => $e->getMessage()]);
        }
        
        return [
            'width' => $width,
            'height' => $height
        ];
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
     * Improved method to extract only page content from HTML
     */
    private function extractPageContentImproved($html)
    {
        try {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            
            // Load HTML with UTF-8 encoding, preserving entities
            $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
            $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            
            $xpath = new \DOMXPath($dom);
            
            // First priority: Find the pdfContent div directly
            $pdfContent = $xpath->query("//div[@id='pdfContent']");
            if ($pdfContent->length > 0) {
                // Get inner HTML of pdfContent, preserving all attributes
                $content = '';
                foreach ($pdfContent->item(0)->childNodes as $child) {
                    $content .= $dom->saveHTML($child);
                }
                
                // Post-process to fix image tags with empty src
                $content = $this->fixEmptyImageSources($content);
                
                Log::info('Extracted content from pdfContent div', ['length' => strlen($content)]);
                return $content;
            }
            
            // Second priority: Find all page containers
            $pageContainers = $xpath->query("//div[contains(@class, 'pdf-page-container')]");
            if ($pageContainers->length > 0) {
                $content = '';
                
                // Check if this is genuinely a multi-page document
                // by looking at the position or explicit page numbers
                $isMultiPage = false;
                if ($pageContainers->length > 1) {
                    // Check if pages have different top positions (indicating separate pages)
                    foreach ($pageContainers as $container) {
                        $style = $container->getAttribute('style');
                        if (preg_match('/top:\s*(\d+)px/i', $style, $match)) {
                            $top = intval($match[1]);
                            if ($top > 1200) { // If top position > typical page height
                                $isMultiPage = true;
                                break;
                            }
                        }
                    }
                }
                
                // If it's a single page document, merge containers to avoid breaks
                if ($pageContainers->length == 1 || !$isMultiPage) {
                    $content = '<div class="pdf-page-container" style="position: relative;">';
                    foreach ($pageContainers as $container) {
                        // Extract only the inner content
                        foreach ($container->childNodes as $child) {
                            $content .= $dom->saveHTML($child);
                        }
                    }
                    $content .= '</div>';
                } else {
                    // For multi-page documents, preserve the page structure
                    foreach ($pageContainers as $container) {
                        $content .= $dom->saveHTML($container) . "\n";
                    }
                }
                
                // Post-process to fix image tags
                $content = $this->fixEmptyImageSources($content);
                
                Log::info('Extracted content from page containers', [
                    'pages' => $pageContainers->length,
                    'is_multi_page' => $isMultiPage
                ]);
                return $content;
            }
            
            // Fallback: Get body content but exclude toolbar and status bar
            $body = $xpath->query("//body")->item(0);
            if ($body) {
                $content = '';
                foreach ($body->childNodes as $child) {
                    if ($child->nodeType === XML_ELEMENT_NODE) {
                        $id = $child->getAttribute('id');
                        $class = $child->getAttribute('class');
                        
                        // Skip toolbar, status bar, and other non-content elements
                        if ($id === 'editorFrame' || 
                            strpos($class, 'editor-toolbar') !== false || 
                            strpos($class, 'status-bar') !== false ||
                            strpos($class, 'loading-overlay') !== false) {
                            continue;
                        }
                        
                        $content .= $dom->saveHTML($child);
                    }
                }
                
                // Post-process to fix image tags
                $content = $this->fixEmptyImageSources($content);
                
                return $content;
            }
            
        } catch (\Exception $e) {
            Log::error('DOM parsing failed in extractPageContentImproved', ['error' => $e->getMessage()]);
        }
        
        // Last resort: return cleaned HTML
        $cleanedHtml = preg_replace('/<div[^>]*class=["\'][^"\']*(?:editor-toolbar|status-bar|loading-overlay)[^"\']*["\'][^>]*>.*?<\/div>/is', '', $html);
        return $this->fixEmptyImageSources($cleanedHtml);
    }
    
    /**
     * Remove CSS transformations that can cause scaling issues in PDF
     */
    private function removeTransformations($html)
    {
        // Remove transform properties from style attributes
        $html = preg_replace_callback(
            '/style="([^"]+)"/i',
            function($matches) {
                $style = $matches[1];
                
                // Remove transform properties
                $style = preg_replace('/transform\s*:\s*[^;]+;?/i', '', $style);
                $style = preg_replace('/transform-origin\s*:\s*[^;]+;?/i', '', $style);
                $style = preg_replace('/zoom\s*:\s*[^;]+;?/i', '', $style);
                $style = preg_replace('/-webkit-transform\s*:\s*[^;]+;?/i', '', $style);
                $style = preg_replace('/-moz-transform\s*:\s*[^;]+;?/i', '', $style);
                $style = preg_replace('/-ms-transform\s*:\s*[^;]+;?/i', '', $style);
                $style = preg_replace('/-o-transform\s*:\s*[^;]+;?/i', '', $style);
                
                // Clean up multiple semicolons and spaces
                $style = preg_replace('/;\s*;+/', ';', $style);
                $style = preg_replace('/^\s*;|;\s*$/', '', $style);
                $style = trim($style);
                
                return 'style="' . $style . '"';
            },
            $html
        );
        
        // For images with scale(0.5), we need to adjust their dimensions
        $html = preg_replace_callback(
            '/<img([^>]*)style="([^"]*)"([^>]*)>/i',
            function($matches) {
                $beforeStyle = $matches[1];
                $style = $matches[2];
                $afterStyle = $matches[3];
                
                // Check if there was a scale transform
                if (preg_match('/width:\s*(\d+(?:\.\d+)?)px/i', $style, $widthMatch) &&
                    preg_match('/height:\s*(\d+(?:\.\d+)?)px/i', $style, $heightMatch)) {
                    
                    // Adjust dimensions if they seem to be scaled (too large)
                    $width = floatval($widthMatch[1]);
                    $height = floatval($heightMatch[1]);
                    
                    // If dimensions are too large (likely scaled), reduce them
                    if ($width > 800 || $height > 1100) {
                        $width = $width * 0.5;
                        $height = $height * 0.5;
                        
                        $style = preg_replace('/width:\s*\d+(?:\.\d+)?px/i', 'width:' . $width . 'px', $style);
                        $style = preg_replace('/height:\s*\d+(?:\.\d+)?px/i', 'height:' . $height . 'px', $style);
                    }
                }
                
                return '<img' . $beforeStyle . 'style="' . $style . '"' . $afterStyle . '>';
            },
            $html
        );
        
        return $html;
    }
    
    /**
     * Fix image tags with empty src attributes by looking for data attributes or class hints
     */
    private function fixEmptyImageSources($html)
    {
        // Look for images with empty src and try to infer the correct source
        return preg_replace_callback(
            '/<img([^>]*?)src=["\']\s*["\']([^>]*)>/i',
            function($matches) {
                $attributes = $matches[1] . $matches[2];
                
                // Try to extract image identifier from class or id
                if (preg_match('/(?:class|id)=["\'][^"\']*?(p\d+_vec\d+)[^"\']*["\']/', $attributes, $idMatch)) {
                    $imageId = $idMatch[1];
                    return '<img' . $matches[1] . 'src="' . $imageId . '.png"' . $matches[2] . '>';
                }
                
                // Check for data-original or data-src attributes
                if (preg_match('/data-(?:src|original)=["\']([^"\']+)["\']/', $attributes, $dataSrcMatch)) {
                    return '<img' . $matches[1] . 'src="' . $dataSrcMatch[1] . '"' . $matches[2] . '>';
                }
                
                // Return original if no fix needed
                return $matches[0];
            },
            $html
        );
    }
    
    /**
     * Improved method to process images for PDF with better path resolution
     */
    private function processImagesForPdfImproved($html, $document)
    {
        // First, handle images with empty src attributes (common in vector graphics)
        $html = preg_replace_callback(
            '/<img([^>]*?)src=["\']?\s*["\']?([^>]*)>/i',
            function($matches) use ($document) {
                $attributes = $matches[1] . $matches[2];
                
                // Check if there's a data-src or other attribute that might contain the actual source
                if (preg_match('/data-src=["\']([^"\']+)["\']/', $attributes, $dataSrcMatch)) {
                    return '<img' . $matches[1] . 'src="' . $dataSrcMatch[1] . '"' . $matches[2] . '>';
                }
                
                // Check for class names that might indicate vector images
                if (preg_match('/class=["\'][^"\']*vec[^"\']*["\']/', $attributes)) {
                    // Try to find corresponding vector file
                    if (preg_match('/p\d+_vec\d+/', $attributes, $vecMatch)) {
                        $vecFilename = $vecMatch[0] . '.png';
                        $vecPath = storage_path('app/documents/' . $document->id . '/' . $vecFilename);
                        if (file_exists($vecPath)) {
                            $imageData = file_get_contents($vecPath);
                            $base64 = base64_encode($imageData);
                            return '<img' . $matches[1] . 'src="data:image/png;base64,' . $base64 . '"' . $matches[2] . '>';
                        }
                    }
                }
                
                // Return original if no modifications needed
                return $matches[0];
            },
            $html
        );
        
        // Then handle normal images with src attributes
        return preg_replace_callback(
            '/<img([^>]*?)src=["\']?([^"\'>\s]+)["\']?([^>]*)>/i',
            function($matches) use ($document) {
                $beforeSrc = $matches[1];
                $src = trim($matches[2]);
                $afterSrc = $matches[3];
                
                // Skip if already a data URI
                if (strpos($src, 'data:') === 0) {
                    return $matches[0];
                }
                
                // Skip if src is empty or just whitespace
                if (empty($src)) {
                    return $matches[0];
                }
                
                $imageData = null;
                $mimeType = 'image/png';
                
                // Try to fetch external images
                if (preg_match('/^https?:\/\//i', $src)) {
                    try {
                        $context = stream_context_create([
                            'http' => [
                                'timeout' => 5,
                                'user_agent' => 'Mozilla/5.0 (Compatible; PDF Converter)'
                            ]
                        ]);
                        $imageData = @file_get_contents($src, false, $context);
                    } catch (\Exception $e) {
                        Log::warning('Could not fetch external image', ['src' => $src, 'error' => $e->getMessage()]);
                    }
                } else {
                    // Handle local images
                    $filename = basename(parse_url($src, PHP_URL_PATH));
                    
                    // If filename is empty, try to extract from the src
                    if (empty($filename) && preg_match('/([^\/]+\.(png|jpg|jpeg|gif|svg|webp))$/i', $src, $fileMatch)) {
                        $filename = $fileMatch[1];
                    }
                    
                    // Build comprehensive list of paths to check
                    $possiblePaths = [
                        // Document-specific directories
                        storage_path('app/documents/' . $document->id . '/' . $filename),
                        storage_path('app/documents/' . $document->tenant_id . '/' . $document->id . '/' . $filename),
                        storage_path('app/' . dirname($document->stored_name) . '/' . $filename),
                        
                        // Temp directories
                        sys_get_temp_dir() . '/' . $filename,
                        sys_get_temp_dir() . '/pdf_images/' . $filename,
                        storage_path('app/temp/' . $filename),
                        
                        // Public directories
                        public_path('storage/documents/' . $document->id . '/' . $filename),
                        public_path('documents/' . $document->id . '/' . $filename),
                        public_path('storage/' . $filename),
                        public_path($filename),
                    ];
                    
                    // Check for vector images with pattern p#_vec#.png
                    if (preg_match('/p\d+_vec\d+/', $filename)) {
                        array_unshift($possiblePaths, storage_path('app/documents/' . $document->id . '/' . $filename));
                    }
                    
                    // If src contains a path, also try the full path
                    if (strpos($src, '/') !== false) {
                        $cleanPath = ltrim($src, '/');
                        array_unshift($possiblePaths, public_path($cleanPath));
                        array_unshift($possiblePaths, storage_path('app/' . $cleanPath));
                        
                        // If it's a route URL, extract the filename
                        if (preg_match('/documents\/\d+\/assets\/(.+)$/', $src, $urlMatch)) {
                            $assetFilename = $urlMatch[1];
                            array_unshift($possiblePaths, storage_path('app/documents/' . $document->id . '/' . $assetFilename));
                        }
                    }
                    
                    // Remove duplicates and check each path
                    $possiblePaths = array_unique($possiblePaths);
                    
                    foreach ($possiblePaths as $path) {
                        if (file_exists($path) && is_readable($path)) {
                            $imageData = file_get_contents($path);
                            $mimeType = mime_content_type($path) ?: 'image/png';
                            Log::info('Image found and embedded', [
                                'src' => $src,
                                'path' => $path,
                                'size' => strlen($imageData),
                                'mime' => $mimeType
                            ]);
                            break;
                        }
                    }
                    
                    if (!$imageData) {
                        Log::warning('Image not found after checking all paths', [
                            'src' => $src,
                            'filename' => $filename,
                            'document_id' => $document->id,
                            'paths_checked' => count($possiblePaths)
                        ]);
                    }
                }
                
                // If we have image data, convert to base64
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
    
    /**
     * Improved method to build final HTML with better structure
     */
    private function buildFinalHtmlImproved($content, $dimensions)
    {
        // Analyser le contenu pour obtenir les dimensions réelles
        $contentBounds = $this->analyzeContentBounds($content);
        
        // Utiliser les dimensions du contenu réel au lieu des dimensions par défaut
        // Cela élimine les bandes blanches de 25%
        $contentWidthPx = $contentBounds['width'];
        $contentHeightPx = $contentBounds['height'];
        
        // Convertir en mm pour wkhtmltopdf
        $width = round($contentWidthPx * 25.4 / 96);
        $height = round($contentHeightPx * 25.4 / 96);
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Export</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        @page {
            size: {$width}mm {$height}mm;
            margin: 0;
        }
        
        html, body {
            width: {$contentWidthPx}px;
            height: {$contentHeightPx}px;
            margin: 0;
            padding: 0;
            overflow: hidden;
            font-family: Arial, sans-serif;
            background: white;
            border: none;
            box-shadow: none;
        }
        
        /* Page containers - use actual content dimensions */
        .pdf-page-container {
            width: {$contentWidthPx}px;
            height: {$contentHeightPx}px;
            position: relative;
            page-break-inside: avoid;
            overflow: hidden;
            margin: 0;
            padding: 0;
            background: white;
            border: none;
            box-shadow: none;
        }
        
        /* For single page documents - avoid all breaks */
        .pdf-page-container:only-child {
            page-break-after: avoid;
            page-break-before: avoid;
        }
        
        /* For multi-page documents - allow page breaks between containers */
        .pdf-page-container:not(:only-child) {
            page-break-after: always;
        }
        
        .pdf-page-container:not(:only-child):last-child {
            page-break-after: auto;
        }
        
        /* Content inside containers should never break */
        .pdf-content, #pdfContent {
            page-break-inside: avoid;
        }
        
        /* Preserve ALL absolute positioning exactly as is */
        .pdf-text, .pdf-element, .pdf-image, 
        div[style*="position: absolute"],
        img[style*="position: absolute"],
        span[style*="position: absolute"] {
            /* Let inline styles handle positioning */
        }
        
        /* Images - keep original positioning */
        img {
            display: block;
            page-break-inside: avoid;
            max-width: 100%;
            height: auto;
        }
        
        /* For absolutely positioned images, preserve their dimensions */
        img[style*="position: absolute"] {
            max-width: none;
            height: auto;
        }
        
        /* Tables */
        table {
            border-collapse: collapse;
            page-break-inside: avoid;
        }
        
        /* Override any scaling that might come from the HTML */
        * {
            transform: none !important;
            zoom: 1 !important;
            box-shadow: none !important;
            text-shadow: none !important;
            filter: none !important;
            -webkit-box-shadow: none !important;
            -moz-box-shadow: none !important;
            outline: none !important;
        }
        
        /* Remove any shadows from images and divs */
        img, div {
            box-shadow: none !important;
            border: none !important;
            outline: none !important;
        }
        
        /* Print-specific styles */
        @media print {
            html, body {
                width: {$contentWidthPx}px;
                height: {$contentHeightPx}px;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            
            .pdf-page-container {
                width: {$contentWidthPx}px;
                height: {$contentHeightPx}px;
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
    
    /**
     * Analyze content to find actual bounds
     */
    private function analyzeContentBounds($html)
    {
        $maxWidth = 794; // Default A4 width in pixels at 96 DPI
        $maxHeight = 1123; // Default A4 height in pixels at 96 DPI
        
        // Try to find page container dimensions first
        if (preg_match('/<div[^>]*class=["\'][^"\']*pdf-page-container[^"\']*["\'][^>]*style=["\']([^"\']+)["\']/', $html, $match)) {
            $style = $match[1];
            
            if (preg_match('/width:\s*(\d+(?:\.\d+)?)px/', $style, $widthMatch)) {
                $maxWidth = floatval($widthMatch[1]);
            }
            if (preg_match('/height:\s*(\d+(?:\.\d+)?)px/', $style, $heightMatch)) {
                $maxHeight = floatval($heightMatch[1]);
            }
        }
        
        // Also check for specific background divs with fixed dimensions
        if (preg_match_all('/<div[^>]*class=["\'][^"\']*p\d+_[^"\']*["\'][^>]*style=["\']([^"\']+)["\']/', $html, $matches)) {
            foreach ($matches[1] as $style) {
                if (preg_match('/width:\s*(\d+(?:\.\d+)?)px/', $style, $m)) {
                    $width = floatval($m[1]);
                    $maxWidth = max($maxWidth, $width);
                }
                if (preg_match('/height:\s*(\d+(?:\.\d+)?)px/', $style, $m)) {
                    $height = floatval($m[1]);
                    $maxHeight = max($maxHeight, $height);
                }
            }
        }
        
        // Check for absolutely positioned elements to find actual content bounds
        $actualMaxX = 0;
        $actualMaxY = 0;
        $hasAbsoluteElements = false;
        
        if (preg_match_all('/style=["\']([^"\']*)["\']/', $html, $matches)) {
            foreach ($matches[1] as $style) {
                // Only process styles with absolute positioning
                if (strpos($style, 'position: absolute') === false && 
                    strpos($style, 'position:absolute') === false) {
                    continue;
                }
                
                $hasAbsoluteElements = true;
                $left = 0;
                $top = 0;
                $width = 0;
                $height = 0;
                
                if (preg_match('/left:\s*(\d+(?:\.\d+)?)px/', $style, $m)) {
                    $left = floatval($m[1]);
                }
                if (preg_match('/top:\s*(\d+(?:\.\d+)?)px/', $style, $m)) {
                    $top = floatval($m[1]);
                }
                if (preg_match('/width:\s*(\d+(?:\.\d+)?)px/', $style, $m)) {
                    $width = floatval($m[1]);
                }
                if (preg_match('/height:\s*(\d+(?:\.\d+)?)px/', $style, $m)) {
                    $height = floatval($m[1]);
                }
                
                // Track the furthest extent of content
                if ($width > 0 || $height > 0) {
                    $actualMaxX = max($actualMaxX, $left + $width);
                    $actualMaxY = max($actualMaxY, $top + $height);
                }
            }
        }
        
        // If we found absolute positioned elements, use their bounds
        if ($hasAbsoluteElements && $actualMaxX > 0 && $actualMaxY > 0) {
            // Use actual content bounds WITHOUT any margin to eliminate shadows
            // No extra pixels = no shadows
            $maxWidth = $actualMaxX;
            $maxHeight = $actualMaxY;
        }
        
        // Cap dimensions to reasonable maximum (A3 size)
        $maxWidth = min($maxWidth, 1190); // A3 width at 96 DPI
        $maxHeight = min($maxHeight, 1684); // A3 height at 96 DPI
        
        return [
            'width' => $maxWidth,
            'height' => $maxHeight
        ];
    }
    
    /**
     * Improved PDF generation with better wkhtmltopdf settings
     */
    private function generatePdfFromHtmlImproved($htmlFile, $dimensions)
    {
        $pdfFile = tempnam(sys_get_temp_dir(), 'output_') . '.pdf';
        
        $width = $dimensions['width'];
        $height = $dimensions['height'];
        
        // Calculate pixel dimensions for viewport
        $widthPx = round($width * 96 / 25.4);
        $heightPx = round($height * 96 / 25.4);
        
        // wkhtmltopdf command optimized for exact positioning without shadows
        $command = sprintf(
            'wkhtmltopdf ' .
            '--page-width %dmm --page-height %dmm ' .
            '--margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 0 ' .
            '--disable-smart-shrinking ' .
            '--print-media-type ' .
            '--enable-local-file-access ' .
            '--load-error-handling ignore ' .
            '--load-media-error-handling ignore ' .
            '--encoding UTF-8 ' .
            '--dpi 96 ' . // Screen DPI for 1:1 mapping
            '--image-quality 100 ' .
            '--image-dpi 96 ' .
            '--javascript-delay 1000 ' . // 1 second delay for images to load
            '--no-stop-slow-scripts ' .
            '--enable-javascript ' .
            '--viewport-size %dx%d ' . // Exact viewport size
            '--no-outline ' . // Disable outline/border
            '--no-background ' . // Disable page background (we set our own)
            '--background ' . // But enable HTML background
            '%s %s 2>&1',
            $width,
            $height,
            $widthPx,
            $heightPx,
            escapeshellarg($htmlFile),
            escapeshellarg($pdfFile)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($pdfFile) && filesize($pdfFile) > 0) {
            Log::info('PDF generated successfully', [
                'size' => filesize($pdfFile),
                'dimensions' => $dimensions,
                'viewport' => $widthPx . 'x' . $heightPx
            ]);
            return $pdfFile;
        }
        
        // Log detailed error information
        Log::error('wkhtmltopdf failed', [
            'command' => $command,
            'output' => implode("\n", $output),
            'return_code' => $returnCode,
            'html_file_exists' => file_exists($htmlFile),
            'html_file_size' => file_exists($htmlFile) ? filesize($htmlFile) : 0
        ]);
        
        return null;
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