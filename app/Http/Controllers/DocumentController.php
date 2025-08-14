<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Share;
use App\Models\User;
use App\Services\OCRService;
use App\Services\PDFEditorService;
use App\Services\PDFService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;

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
            $query->where(function ($q) use ($request) {
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

        if (! $user) {
            return redirect()->route('login');
        }

        $tenant = $user->tenant;

        if (! $tenant) {
            return back()->with('error', 'Aucun tenant associé à votre compte. Veuillez contacter l\'administrateur.');
        }

        return Inertia::render('Documents/Upload', [
            'max_file_size' => $tenant->max_file_size_mb ?? 50,
            'allowed_types' => [
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff',
                'txt', 'rtf', 'odt', 'ods', 'odp',
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
            'file' => 'required|file|max:' . (Auth::user()->tenant->max_file_size_mb * 1024),
        ]);

        try {
            DB::beginTransaction();

            $file = $request->file('file');
            $user = Auth::user();
            $tenant = $user->tenant;

            // Check storage quota
            if ($tenant->getStorageUsed() + $file->getSize() > $tenant->max_storage_gb * 1024 * 1024 * 1024) {
                return response()->json([
                    'message' => 'Espace de stockage insuffisant',
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
                'message' => 'Document téléchargé avec succès',
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Document upload failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'message' => 'Erreur lors du téléchargement: ' . $e->getMessage(),
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
            ]),
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
     * Serve document thumbnail
     */
    public function thumbnail(Document $document)
    {
        $this->authorize('view', $document);

        // Check if thumbnail exists in public disk
        if (!$document->thumbnail_path || !Storage::disk('public')->exists($document->thumbnail_path)) {
            // Generate thumbnail on the fly if missing
            dispatch(new \App\Jobs\GenerateThumbnail($document))->onQueue('high');
            
            // Return placeholder image
            return response()->file(public_path('images/pdf-placeholder.png'), [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'no-cache',
            ]);
        }

        $path = Storage::disk('public')->path($document->thumbnail_path);

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400', // Cache for 1 day
        ]);
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
            'type' => 'required|in:link,email',
            'password' => 'nullable|string|min:6',
            'expires_at' => 'nullable|date|after:now',
            'permissions' => 'nullable|array',
            'email' => 'nullable|email|required_if:type,email',
        ]);

        // Map frontend types to model types
        $shareType = Share::TYPE_PUBLIC;
        if ($validated['type'] === 'email' && isset($validated['email'])) {
            $shareType = Share::TYPE_INTERNAL;
        } elseif (isset($validated['password']) && !empty($validated['password'])) {
            $shareType = Share::TYPE_PROTECTED;
        }

        $share = Share::create([
            'document_id' => $document->id,
            'shared_by' => Auth::id(),
            'type' => $shareType,
            'token' => Str::random(32),
            'password' => isset($validated['password']) && !empty($validated['password']) ? Hash::make($validated['password']) : null,
            'expires_at' => $validated['expires_at'] ?? null,
            'permissions' => $validated['permissions'] ?? ['view', 'download'],
        ]);

        // If sharing with specific user
        if ($shareType === Share::TYPE_INTERNAL && isset($validated['email'])) {
            $recipient = User::where('email', $validated['email'])->first();
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
     * Upload an image for the HTML editor
     */
    public function uploadEditorImage(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        
        $request->validate([
            'image' => 'required|image|max:10240', // Max 10MB
        ]);
        
        try {
            $file = $request->file('image');
            
            // Get file content and convert to base64
            $fileContent = file_get_contents($file->getRealPath());
            $mimeType = $file->getMimeType();
            $base64 = base64_encode($fileContent);
            $dataUrl = 'data:' . $mimeType . ';base64,' . $base64;
            
            // Also save the file for later use if needed
            $filename = 'editor_' . time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
            $path = 'editor-images/' . $document->id . '/' . $filename;
            Storage::disk('local')->put($path, $fileContent);
            
            // Return the data URL directly
            return response()->json([
                'success' => true,
                'url' => $dataUrl,
                'filename' => $filename,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to upload editor image', [
                'error' => $e->getMessage(),
                'document_id' => $document->id,
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to upload image: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Get an editor image
     */
    public function getEditorImage(Document $document, $filename)
    {
        // Security: Clean the filename
        $filename = basename($filename);
        
        // Check for token-based access first
        $token = request()->query('token');
        if ($token) {
            $cachedToken = Cache::get('editor_image_token_' . $document->id . '_' . $filename);
            if ($token !== $cachedToken) {
                abort(403, 'Invalid token');
            }
        } else {
            // Fall back to regular authorization
            try {
                $this->authorize('view', $document);
            } catch (\Exception $e) {
                abort(403, 'Unauthorized');
            }
        }
        
        $path = 'editor-images/' . $document->id . '/' . $filename;
        
        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'Image not found');
        }
        
        $file = Storage::disk('local')->get($path);
        $mimeType = Storage::disk('local')->mimeType($path);
        
        // Add CORS headers for iframe access
        return response($file, 200)
            ->header('Content-Type', $mimeType)
            ->header('Cache-Control', 'public, max-age=3600')
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET');
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
            'checked_paths' => $possiblePaths,
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
            if ($document->mime_type !== 'application/pdf' && ! str_starts_with($document->mime_type, 'image/')) {
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
                    'has_owner_password' => ! empty($request->owner_password),
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
     * Decrypt PDF (remove password)
     */
    public function decrypt(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        if ($document->mime_type !== 'application/pdf') {
            return back()->with('error', 'Seuls les fichiers PDF peuvent être déverrouillés.');
        }

        $request->validate([
            'password' => 'nullable|string',
            'force_remove' => 'nullable|boolean',
        ]);

        try {
            // Create password removal service instance
            $passwordService = app(\App\Services\PDFPasswordRemovalService::class);

            // Check if document has password
            if (! $passwordService->hasPassword($document)) {
                return back()->with('warning', 'Ce document n\'est pas protégé par mot de passe.');
            }

            // Remove password
            $unlockedDocument = $passwordService->removePassword(
                $document,
                $request->password,
                $request->boolean('force_remove')
            );

            // Log activity
            activity()
                ->performedOn($unlockedDocument)
                ->causedBy(Auth::user())
                ->withProperties([
                    'force_removal' => $request->boolean('force_remove'),
                    'source_document' => $document->id,
                ])
                ->log('PDF password removed');

            return redirect()->route('documents.show', $unlockedDocument)
                ->with('success', 'Le mot de passe a été supprimé avec succès.');

        } catch (Exception $e) {
            Log::error('PDF decryption failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Erreur lors du déverrouillage: ' . $e->getMessage());
        }
    }

    /**
     * Serve document file
     */
    public function serve(Document $document)
    {
        $this->authorize('view', $document);

        $path = Storage::path($document->stored_name);

        if (! file_exists($path)) {
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
                'content' => $textElements,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
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
                    'modifications' => [$request->modification],
                ],
            ]);

            // Clean up temp file
            @unlink($modifiedPath);

            return response()->json([
                'success' => true,
                'temp_document' => $tempDocument,
                'message' => 'Modification applied successfully',
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
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
                'message' => 'Modifications saved successfully',
            ]);

        } catch (Exception $e) {
            if ($request->header('X-Inertia')) {
                return redirect()->back()->with('error', 'Erreur lors de la sauvegarde: ' . $e->getMessage());
            }

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
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
            if (! empty($request->modifications)) {
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
                'message' => 'Content updated successfully',
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
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
            'document' => $document,
        ]);
    }

    /**
     * Convert PDF to HTML for editing
     */
    public function convertToHtml(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        try {
            // ALWAYS clear ALL cache to ensure fresh conversion
            $cacheKey = 'pdf_html_' . $document->id;
            Cache::forget($cacheKey);
            Cache::forget('pdf_conversion_' . $document->id);
            Cache::forget('document_html_' . $document->id);
            
            Log::info('Starting FRESH HTML conversion (all cache cleared)', [
                'document_id' => $document->id,
                'original_name' => $document->original_name
            ]);
            
            $path = Storage::path($document->stored_name);

            // Convert PDF to editable HTML using the primary service. No fallback.
            $html = $this->convertPdfToEditableHtml($request, $path, $document);
            
            // Log sample content for debugging
            $sampleText = '';
            if (preg_match('/<div[^>]*class="pdf-text"[^>]*>(.*?)<\/div>/s', $html, $matches)) {
                $sampleText = substr(strip_tags($matches[1]), 0, 100);
            }
            
            Log::info('HTML conversion complete', [
                'document_id' => $document->id,
                'html_length' => strlen($html),
                'sample_text' => $sampleText,
                'timestamp' => time()
            ]);

            return response()->json([
                'success' => true,
                'html' => $html,
                'timestamp' => time() // Force browser to recognize fresh data
            ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
              ->header('Pragma', 'no-cache')
              ->header('Expires', '0');

        } catch (Exception $e) {
            Log::error('Error in convertToHtml', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Convert PDF to editable HTML using Universal converter ONLY
     */
    private function convertPdfToEditableHtml(Request $request, $pdfPath, Document $document)
    {
        try {
            // ONLY USE Universal converter - NO FALLBACKS
            $universalConverter = new \App\Services\UniversalPDFConverter();
            $html = $universalConverter->convertToHTML($pdfPath);
            
            if (empty($html)) {
                throw new \Exception('Universal converter returned empty HTML');
            }
            
            // Debug logging
            $pageCount = substr_count($html, 'data-page="');
            $textElements = substr_count($html, 'class="pdf-text"');
            $editableElements = substr_count($html, 'contenteditable');
            
            Log::info('Successfully converted PDF with Universal converter', [
                'document_id' => $document->id,
                'html_size' => strlen($html),
                'pages' => $pageCount,
                'text_elements' => $textElements,
                'editable_elements' => $editableElements,
                'has_page_1' => strpos($html, 'data-page="1"') !== false,
                'has_page_2' => strpos($html, 'data-page="2"') !== false
            ]);
            
            return $html;

        } catch (\Exception $e) {
            Log::error('PDF to HTML conversion failed with Universal converter: ' . $e->getMessage());
            
            // Return detailed error HTML
            return '
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erreur de conversion</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        .error-container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 100%;
        }
        h2 {
            color: #e53e3e;
            margin: 0 0 20px 0;
            font-size: 24px;
        }
        .error-message {
            background: #fff5f5;
            border-left: 4px solid #e53e3e;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .error-message code {
            color: #c53030;
            font-size: 14px;
            word-break: break-all;
        }
        .suggestions {
            color: #666;
            line-height: 1.6;
            margin-top: 20px;
        }
        .suggestions ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        .btn-retry {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.3s;
        }
        .btn-retry:hover {
            background: #5a67d8;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h2>⚠️ Erreur de conversion PDF</h2>
        <p>Impossible de convertir le document PDF en HTML éditable.</p>
        <div class="error-message">
            <code>' . htmlspecialchars($e->getMessage()) . '</code>
        </div>
        <div class="suggestions">
            <strong>Suggestions :</strong>
            <ul>
                <li>Vérifiez que le fichier PDF n\'est pas corrompu</li>
                <li>Assurez-vous que le PDF n\'est pas protégé par mot de passe</li>
                <li>Essayez avec un fichier PDF différent</li>
                <li>Contactez le support si le problème persiste</li>
            </ul>
        </div>
        <a href="javascript:history.back()" class="btn-retry">← Retour</a>
    </div>
</body>
</html>';
        }
    }

    /**
     * Build advanced editable HTML with all features
     */
    private function buildAdvancedEditableHtml($content, $pdfPath, Document $document = null)
    {
        // Ensure content is an array with all required keys
        if (! is_array($content)) {
            $content = [
                'full_html' => is_string($content) ? $content : '',
                'tables' => [],
                'text' => '',
                'images' => [],
                'styles' => '',
                'fonts' => [],
            ];
        }

        // Ensure all keys exist
        $content = array_merge([
            'full_html' => '',
            'tables' => [],
            'text' => '',
            'images' => [],
            'styles' => '',
            'fonts' => [],
        ], $content);

        // Get page count for PDF preview generation if needed
        $pageCount = $this->getPdfPageCount($pdfPath);

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="' . csrf_token() . '">
    <meta name="document-id" content="' . $document->id . '">
    <title>PDF Editor</title>';

        // Add preserved styles from PDF (filtered and scoped)
        if (! empty($content['styles']) && is_string($content['styles'])) {
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
        if (! empty($content['fonts']) && is_array($content['fonts'])) {
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
            z-index: 1 !important;
            transition: transform 0.3s ease !important;
            display: inline-block !important;
        }
        
        /* PDF page container - exact size */
        .pdf-page-container {
            position: relative !important;
            margin: 20px auto !important;
            padding: 0 !important;  /* No padding to preserve absolute positions */
            page-break-after: always !important;
            background: white !important;
            overflow: visible !important;  /* Allow elements to extend if needed */
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
            margin: 0;
            padding: 0;
            line-height: inherit;
            font-size: inherit;
            color: inherit;
            position: absolute !important;
            z-index: 100 !important;
            display: inline-block !important;
            width: fit-content !important;
            height: fit-content !important;
        }
        
        .pdf-text[contenteditable="true"] {
            display: inline-block;
            padding: 0;
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
            position: absolute;  /* Keep absolute for proper PDF positioning */
            cursor: move;
            transition: all 0.3s ease;
            visibility: visible !important;
            opacity: 1 !important;
            z-index: 50 !important;  /* Lower than text but above background */
            box-sizing: border-box;
            width: auto !important;
            height: auto !important;
        }
        
        .pdf-image-container:hover {
            outline: 2px solid #007bff;
            outline-offset: 0;
        }
        
        .pdf-image {
            display: block !important;
            user-select: none;
            visibility: visible !important;
            opacity: 1 !important;
            object-fit: contain;  /* Preserve aspect ratio */
            max-width: 100%;
            max-height: 100%;
        }
        
        .pdf-image-container.dragging {
            opacity: 0.7;
            z-index: 99999 !important;
        }
        
        /* Ensure elements being dragged stay on top */
        .dragging {
            z-index: 99999 !important;
        }
        
        /* Draggable elements */
        .draggable-element {
            position: absolute !important;
            cursor: move;
        }
        
        .draggable-element:hover {
            outline: 1px dashed #007bff;
            outline-offset: 0;
        }
        
        /* Element controls */
        .element-controls {
            position: absolute;
            top: -30px;
            right: 0;
            display: none;
            background: rgba(255,255,255,0.95);
            padding: 3px;
            border-radius: 3px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        
        .draggable-element:hover .element-controls {
            display: block !important;
        }
        
        .pdf-image-container.drag-over {
            border: 2px dashed #007bff;
            background: rgba(0,123,255,0.1);
        }
        
        /* Image controls overlay */
        .image-controls {
            position: absolute;
            top: 5px;
            right: 5px;
            display: none;
            flex-direction: row;
            gap: 5px;
            z-index: 100;
            background: rgba(255,255,255,0.95);
            padding: 5px;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            pointer-events: all !important;
        }
        
        .pdf-image-container:hover .image-controls {
            display: flex !important;
            pointer-events: all !important;
        }
        
        .image-control-btn {
            padding: 5px 10px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer !important;
            font-size: 12px;
            transition: background 0.2s;
            pointer-events: all !important;
            position: relative;
            z-index: 101;
        }
        
        .image-control-btn:hover {
            background: #0056b3;
        }
        
        .image-control-btn.delete {
            background: #dc3545;
        }
        
        .image-control-btn.delete:hover {
            background: #c82333;
        }
        
        .image-resize-handle {
            position: absolute;
            width: 10px;
            height: 10px;
            background: #007bff;
            border: 2px solid white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
            opacity: 0;
            transition: opacity 0.2s;
        }
        
        .pdf-image-container:hover .image-resize-handle {
            opacity: 1;
        }
        
        .image-resize-handle.nw { top: -5px; left: -5px; cursor: nw-resize; }
        .image-resize-handle.ne { top: -5px; right: -5px; cursor: ne-resize; }
        .image-resize-handle.sw { bottom: -5px; left: -5px; cursor: sw-resize; }
        .image-resize-handle.se { bottom: -5px; right: -5px; cursor: se-resize; }
        

        /* Fix for drag mode - prevent elements from disappearing */
        body.drag-mode-active .pdf-text {
            z-index: 100 !important;
            position: absolute !important;
        }
        
        body.drag-mode-active .pdf-image-container {
            z-index: 50 !important;
            position: absolute !important;
        }
        
        body.drag-mode-active .pdf-page-container {
            z-index: 1 !important;
        }
        
        /* PDF elements proper layering */
        .pdf-page-container {
            position: relative !important;
            z-index: 1 !important;
        }
        
        .pdf-image {
            pointer-events: none; /* Let container handle events */
        }
        
        .pdf-image-container {
            pointer-events: all !important;
        }

        /* === HOVER AREA FIXES === */
        /* Limit hover areas to exact element bounds */
        .pdf-text {
            display: inline-block !important;
            width: fit-content !important;
            height: fit-content !important;
            max-width: none !important;
            overflow: visible !important;
        }
        
        .pdf-image-container {
            display: inline-block !important;
            width: auto !important;
            height: auto !important;
            overflow: visible !important;
        }
        
        .draggable-element {
            display: inline-block !important;
            width: fit-content !important;
            height: fit-content !important;
            overflow: visible !important;
        }
        
        /* Hover states with precise boundaries */
        .pdf-text:hover {
            background: rgba(0, 123, 255, 0.05) !important;
            outline: 1px solid rgba(0, 123, 255, 0.3);
            outline-offset: 0;
        }
        
        .pdf-image-container:hover {
            outline: 2px solid #007bff;
            outline-offset: 0;
        }
        
        .draggable-element:hover {
            outline: 1px dashed #007bff;
            outline-offset: 0;
        }
        
        /* Keep image controls inside the container */
        .image-controls {
            position: absolute;
            top: 5px !important;
            right: 5px !important;
            display: none;
            background: rgba(255, 255, 255, 0.98);
            padding: 5px 8px;
            gap: 5px;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            pointer-events: all !important;
            white-space: nowrap;
            z-index: 1001;
        }
        
        .element-controls {
            position: absolute;
            top: -35px !important;
            right: 0;
            display: none;
            background: rgba(255, 255, 255, 0.98);
            padding: 5px 8px;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 1001;
            pointer-events: auto;
            white-space: nowrap;
        }
        
        /* Show controls on hover without expanding element */
        .pdf-image-container:hover .image-controls,
        .draggable-element:hover .element-controls,
        .pdf-text:hover .element-controls {
            display: flex !important;
        }
        
        /* Drag mode hover states */
        body.drag-mode-active .pdf-text:hover,
        body.drag-mode-active .pdf-image-container:hover,
        body.drag-mode-active .draggable-element:hover {
            outline: 2px dashed #3498db;
            outline-offset: 0;
        }
        
        /* Remove any margin/padding that could expand hover area */
        .pdf-text[contenteditable="true"] {
            margin: 0 !important;
            padding: 1px 2px !important;
        }
        
        /* Ensure images do not have extra space */
        .pdf-image {
            display: block;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        /* === END HOVER AREA FIXES === */
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
        if (! is_array($content)) {
            $content = [
                'tables' => [],
                'text' => '',
                'images' => [],
                'full_html' => '',
            ];
        }

        // If we have full_html in combined mode, use it but process images properly
        if ($mode === 'combined' && ! empty($content['full_html'])) {
            // Make the full HTML editable
            $editableHtml = $content['full_html'];

            // Ensure we have the pdf-document wrapper for proper styling
            if (strpos($editableHtml, 'class="pdf-document"') === false) {
                $editableHtml = '<div class="pdf-document">' . $editableHtml . '</div>';
            }

            // Process images to add proper containers and controls
            $imageIndex = 0;
            $editableHtml = preg_replace_callback(
                '/<img([^>]*?)class="pdf-image"([^>]*?)\/?>/',
                function ($matches) use (&$imageIndex) {
                    $imgTag = $matches[0];
                    $attributes1 = $matches[1];
                    $attributes2 = $matches[2];
                    
                    // Extract positioning from style attribute
                    $containerStyle = '';
                    $imgStyle = '';
                    if (preg_match('/style="([^"]*)"/', $attributes1 . $attributes2, $styleMatch)) {
                        $fullStyle = $styleMatch[1];
                        
                        // Extract position-related styles for container
                        if (preg_match('/left:\s*([^;]+);/', $fullStyle, $leftMatch)) {
                            $containerStyle .= 'left:' . $leftMatch[1] . ';';
                        }
                        if (preg_match('/top:\s*([^;]+);/', $fullStyle, $topMatch)) {
                            $containerStyle .= 'top:' . $topMatch[1] . ';';
                        }
                        if (preg_match('/width:\s*([^;]+);/', $fullStyle, $widthMatch)) {
                            $containerStyle .= 'width:' . $widthMatch[1] . ';';
                            $imgStyle .= 'width:100%;';
                        }
                        if (preg_match('/height:\s*([^;]+);/', $fullStyle, $heightMatch)) {
                            $containerStyle .= 'height:' . $heightMatch[1] . ';';
                            $imgStyle .= 'height:100%;';
                        }
                    }
                    
                    // Build the wrapped image with container preserving exact position
                    $wrappedImg = '<div class="pdf-image-container" style="' . $containerStyle . '" draggable="true" data-image-index="' . $imageIndex . '">';
                    
                    // Keep the img tag simple with just the source
                    $imgTagClean = preg_replace('/style="[^"]*"/', '', $imgTag);
                    $imgTagClean = str_replace('class="pdf-image"', 'class="pdf-image" id="image_' . $imageIndex . '" style="' . $imgStyle . 'display:block;"', $imgTagClean);
                    
                    $wrappedImg .= $imgTagClean;
                    
                    // Add controls
                    $wrappedImg .= '<div class="image-controls">';
                    $wrappedImg .= '<button class="image-control-btn" onclick="replaceImage(' . $imageIndex . ')">🔄 Remplacer</button>';
                    $wrappedImg .= '<button class="image-control-btn delete" onclick="deleteImage(' . $imageIndex . ')">🗑️ Supprimer</button>';
                    $wrappedImg .= '</div>';
                    
                    // Add resize handles
                    $wrappedImg .= '<div class="image-resize-handle nw" data-handle="nw"></div>';
                    $wrappedImg .= '<div class="image-resize-handle ne" data-handle="ne"></div>';
                    $wrappedImg .= '<div class="image-resize-handle sw" data-handle="sw"></div>';
                    $wrappedImg .= '<div class="image-resize-handle se" data-handle="se"></div>';
                    $wrappedImg .= '</div>';
                    
                    $imageIndex++;
                    return $wrappedImg;
                },
                $editableHtml
            );

            // Make text content editable
            $editableHtml = preg_replace('/<span([^>]*?)class="pdf-text"([^>]*?)>/', '<span$1class="pdf-text"$2 contenteditable="true">', $editableHtml);

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
        if (($mode === 'combined' || $mode === 'tables') && ! empty($content['tables']) && is_array($content['tables'])) {
            foreach ($content['tables'] as $index => $table) {
                // Preserve existing styles while making cells editable
                $editableTable = $table;

                // Make cells editable while preserving their styles
                $editableTable = preg_replace_callback(
                    '/<td([^>]*)>/',
                    function ($matches) {
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
                    function ($matches) {
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
                    function ($matches) use ($index) {
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
        if (($mode === 'combined' || $mode === 'text') && ! empty($content['text']) && is_string($content['text'])) {
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
        if ($mode === 'combined' && ! empty($content['images']) && is_array($content['images'])) {
            foreach ($content['images'] as $imgIndex => $image) {
                // Handle both string and array image formats
                if (is_array($image)) {
                    $src = $image['src'] ?? '';
                    $style = $image['style'] ?? '';
                    $position = $image['position'] ?? null;

                    // Use positioned container if position info exists
                    if ($position && ! empty($style)) {
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

                // Add image controls
                $html .= '<div class="image-controls">';
                $html .= '<button class="image-control-btn" onclick="replaceImage(' . $imgIndex . ')">🔄 Remplacer</button>';
                $html .= '<button class="image-control-btn delete" onclick="deleteImage(' . $imgIndex . ')">🗑️ Supprimer</button>';
                $html .= '</div>';

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
                        'height_px' => round($heightPts * 96 / 72),
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
            'height_px' => 1122,
        ];
    }

    /**
     * Get advanced editing JavaScript
     */
    private function getAdvancedEditingScript()
    {
        return '<script>
        // Make functions globally accessible
        function insertImage() {
            if (window.insertImage) {
                window.insertImage();
            }
        }
        
        function addTextBlock() {
            if (window.addTextBlock) {
                window.addTextBlock();
            }
        }
        
        function addNewTable() {
            if (window.addNewTable) {
                window.addNewTable();
            }
        }
        
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
            // Add controls to existing text elements without them
            const textElements = document.querySelectorAll(\'.pdf-text:not([data-has-controls])\');
            textElements.forEach(element => {
                if (!element.querySelector(\'.element-controls\')) {
                    const controls = document.createElement(\'div\');
                    controls.className = \'element-controls\';
                    controls.style.cssText = \'position: absolute; top: -30px; right: 0; display: none; background: rgba(255,255,255,0.95); padding: 3px; border-radius: 3px; box-shadow: 0 2px 5px rgba(0,0,0,0.2); z-index: 1000;\';
                    controls.innerHTML = \'<button class="control-btn" onclick="deleteElement(this.parentElement.parentElement)" style="background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; font-size: 12px;">🗑️ Supprimer</button>\';
                    element.appendChild(controls);
                    element.setAttribute(\'data-has-controls\', \'true\');
                    element.classList.add(\'draggable-element\');
                    initializeElementControls(element);
                }
                makeDraggable(element);
            });
            
            // Make all contenteditable elements draggable
            const editableElements = document.querySelectorAll(\'[contenteditable="true"]\');
            editableElements.forEach(element => {
                if (!element.classList.contains(\'pdf-text\')) {
                    makeDraggable(element);
                }
            });
            
            // Make image containers draggable
            const imageContainers = document.querySelectorAll(\'.pdf-image-container\');
            imageContainers.forEach(container => {
                container.classList.add(\'draggable-element\');
                makeDraggable(container);
            });
            
            // Make tables draggable
            const tables = document.querySelectorAll(\'.pdf-table\');
            tables.forEach(table => {
                const parent = table.parentElement;
                if (parent && !parent.classList.contains(\'table-container\')) {
                    // Wrap table if not already wrapped
                    const wrapper = document.createElement(\'div\');
                    wrapper.className = \'table-container draggable-element\';
                    wrapper.style.cssText = \'position: absolute;\';
                    table.parentNode.insertBefore(wrapper, table);
                    wrapper.appendChild(table);
                    
                    // Add controls
                    const controls = document.createElement(\'div\');
                    controls.className = \'element-controls\';
                    controls.style.cssText = \'position: absolute; top: -30px; right: 0; display: none; background: rgba(255,255,255,0.95); padding: 3px; border-radius: 3px; box-shadow: 0 2px 5px rgba(0,0,0,0.2); z-index: 1000;\';
                    controls.innerHTML = \'<button class="control-btn" onclick="deleteElement(this.parentElement.parentElement)" style="background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; font-size: 12px;">🗑️ Supprimer</button>\';
                    wrapper.appendChild(controls);
                    
                    initializeElementControls(wrapper);
                    makeDraggable(wrapper);
                } else if (parent && parent.classList.contains(\'table-container\')) {
                    parent.classList.add(\'draggable-element\');
                    makeDraggable(parent);
                }
            });
        }
        
        function makeDraggable(element) {
            // Add drag handle if not already present
            if (!element.dataset.draggable) {
                element.dataset.draggable = \'true\';
                
                // For images, only make cursor move when hovering over the image itself, not controls
                if (element.classList.contains(\'pdf-image-container\')) {
                    const img = element.querySelector(\'.pdf-image\');
                    if (img) {
                        img.style.cursor = \'move\';
                    }
                    // Images should use absolute positioning
                    if (!element.style.position || element.style.position === \'static\') {
                        element.style.position = \'absolute\';
                    }
                } else if (element.classList.contains(\'pdf-table\')) {
                    element.style.cursor = \'move\';
                    // Tables can use relative positioning
                    if (!element.style.position || element.style.position === \'static\') {
                        element.style.position = \'relative\';
                    }
                } else {
                    // For text elements, use relative positioning to avoid z-index issues
                    element.style.cursor = \'move\';
                    if (!element.style.position || element.style.position === \'static\') {
                        element.style.position = \'relative\';
                    }
                }
                
                let isDragging = false;
                let startX = 0;
                let startY = 0;
                let initialLeft = 0;
                let initialTop = 0;
                let initialTransform = \'\';
                
                // Parse existing position or use transform for relative elements
                if (element.style.position === \'absolute\') {
                    initialLeft = parseInt(element.style.left) || element.offsetLeft || 0;
                    initialTop = parseInt(element.style.top) || element.offsetTop || 0;
                    element.style.left = initialLeft + \'px\';
                    element.style.top = initialTop + \'px\';
                } else {
                    // Use transform for relative positioned elements
                    initialTransform = element.style.transform || \'\';
                    const match = initialTransform.match(/translate\((-?\d+)px,\s*(-?\d+)px\)/);
                    if (match) {
                        initialLeft = parseInt(match[1]) || 0;
                        initialTop = parseInt(match[2]) || 0;
                    } else {
                        initialLeft = 0;
                        initialTop = 0;
                    }
                }
                
                // Add drag start event
                const dragTarget = element.classList.contains(\'pdf-image-container\') ? 
                    element.querySelector(\'.pdf-image\') : element;
                
                if (dragTarget) {
                    dragTarget.addEventListener(\'mousedown\', function(e) {
                        // Only start drag if drag mode is active
                        // For images, also check that we\'re not clicking on controls
                        if (dragModeActive && !e.target.classList.contains(\'image-control-btn\') 
                            && !e.target.classList.contains(\'image-resize-handle\')) {
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
                            element.style.zIndex = \'10000\';  // Very high z-index during drag
                            
                            // Add mousemove and mouseup listeners to document
                            document.addEventListener(\'mousemove\', handleDragMove);
                            document.addEventListener(\'mouseup\', handleDragEnd);
                        }
                    });
                }
                
                function handleDragMove(e) {
                    if (!isDragging) return;
                    
                    e.preventDefault();
                    const deltaX = e.clientX - startX;
                    const deltaY = e.clientY - startY;
                    
                    if (element.style.position === \'absolute\') {
                        element.style.left = (initialLeft + deltaX) + \'px\';
                        element.style.top = (initialTop + deltaY) + \'px\';
                    } else {
                        // Use transform for relative positioned elements
                        element.style.transform = \'translate(\' + (initialLeft + deltaX) + \'px, \' + (initialTop + deltaY) + \'px)\';
                    }
                    
                    updateStatus(\'Déplacement en cours... (Relâchez pour terminer)\');
                }
                
                function handleDragEnd(e) {
                    if (!isDragging) return;
                    
                    isDragging = false;
                    element.classList.remove(\'dragging\');
                    element.style.opacity = \'1\';
                    element.style.zIndex = \'\';
                    
                    // Update stored position for next drag
                    if (element.style.position === \'absolute\') {
                        initialLeft = parseInt(element.style.left) || 0;
                        initialTop = parseInt(element.style.top) || 0;
                    } else {
                        const transform = element.style.transform;
                        const match = transform.match(/translate\((-?\d+)px,\s*(-?\d+)px\)/);
                        if (match) {
                            initialLeft = parseInt(match[1]) || 0;
                            initialTop = parseInt(match[2]) || 0;
                        }
                    }
                    
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
        // Use a longer delay to ensure all images are loaded
        window.addEventListener(\'load\', function() {
            setTimeout(function() {
                initializeDragAndDrop();
                initializeImageHandlers();
                updateZoomDisplay();
                updateStatus(\'Prêt - Activez le mode déplacement pour déplacer les éléments\');
            }, 100);
        });
        
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
                    let cellText = table.rows[i].cells[j].innerText.replace(/"/g, \'\\"\');
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
        
        // Add new elements - make global for onclick handlers
        window.addNewTable = function() {
            // Try to find the first PDF page container
            let container = document.querySelector(".pdf-page-container");
            
            // If no page container, try the main content area
            if (!container) {
                container = document.getElementById("pdfContent");
            }
            
            if (!container) {
                console.error("No suitable container found for adding table");
                return;
            }
            
            const tableId = \'table_\' + Date.now();
            const tableHtml = \'<div class="table-container draggable-element" style="position: absolute; left: 50px; top: 100px; background: white; z-index: 10;" data-table-index="\' + tableId + \'" data-element-type="table">\' +
                \'<div class="element-controls" style="position: absolute; top: -30px; right: 0; display: none; background: rgba(255,255,255,0.95); padding: 3px; border-radius: 3px; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">\' +
                \'<button class="control-btn" onclick="deleteElement(this.parentElement.parentElement)" style="background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; font-size: 12px;">🗑️ Supprimer</button>\' +
                \'</div>\' +
                \'<div class="table-tools">\' +
                \'<button class="table-btn" onclick="this.parentElement.parentElement.remove()">🗑️ Delete Table</button>\' +
                \'</div>\' +
                \'<table class="pdf-table editable-table">\' +
                \'<thead><tr>\' +
                \'<th contenteditable="true">Header 1</th>\' +
                \'<th contenteditable="true">Header 2</th>\' +
                \'<th contenteditable="true">Header 3</th>\' +
                \'</tr></thead>\' +
                \'<tbody><tr>\' +
                \'<td contenteditable="true">Cell 1</td>\' +
                \'<td contenteditable="true">Cell 2</td>\' +
                \'<td contenteditable="true">Cell 3</td>\' +
                \'</tr></tbody>\' +
                \'</table>\' +
                \'</div>\';
            container.insertAdjacentHTML("beforeend", tableHtml);
            
            // Make the new table draggable
            const newTable = container.querySelector(\'[data-table-index="\' + tableId + \'"]\');
            if (newTable) {
                makeDraggable(newTable);
                initializeElementControls(newTable);
            }
            
            updateStatus("Nouvelle table ajoutée - Activez le mode déplacement pour la positionner");
        };
        
        window.addTextBlock = function() {
            // Try to find the first PDF page container
            let container = document.querySelector(".pdf-page-container");
            
            // If no page container, try the main content area
            if (!container) {
                container = document.getElementById("pdfContent");
            }
            
            if (!container) {
                console.error("No suitable container found for adding text");
                return;
            }
            
            const textId = \'text_\' + Date.now();
            const textHtml = \'<div class="pdf-text draggable-element" contenteditable="true" style="position: absolute; left: 50px; top: 50px; padding: 10px; background: rgba(255,255,255,0.95); border: 1px dashed #007bff; min-width: 200px; z-index: 10;" data-text-id="\' + textId + \'" data-element-type="text">\' +
                \'<div class="element-controls" style="position: absolute; top: -30px; right: 0; display: none; background: rgba(255,255,255,0.95); padding: 3px; border-radius: 3px; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">\' +
                \'<button class="control-btn" onclick="deleteElement(this.parentElement.parentElement)" style="background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; font-size: 12px;">🗑️ Supprimer</button>\' +
                \'</div>\' +
                \'<p>Nouveau bloc de texte. Cliquez pour éditer...</p>\' +
                \'</div>\';
            container.insertAdjacentHTML("beforeend", textHtml);
            
            // Make the new text block draggable
            const newText = container.querySelector(\'[data-text-id="\' + textId + \'"]\');
            if (newText) {
                makeDraggable(newText);
                initializeElementControls(newText);
            }
            
            updateStatus("Nouveau texte ajouté - Activez le mode déplacement pour le positionner");
        };
        
        // Initialize when document is ready
        document.addEventListener("DOMContentLoaded", function() {
            // Ensure all global functions are available
            if (!window.insertImage) {
                console.error("window.insertImage not initialized");
            }
            if (!window.replaceImage) {
                console.error("window.replaceImage not initialized");
            }
            if (!window.deleteImage) {
                console.error("window.deleteImage not initialized");
            }
        });
        
        // Simple insertImage function with server upload
        window.insertImage = async function() {
            console.log("insertImage called - using server upload");
            const input = document.createElement("input");
            input.type = "file";
            input.accept = "image/*";
            
            input.addEventListener("change", async function(e) {
                console.log("File input change event fired");
                console.log("Files:", e.target.files);
                const file = e.target.files && e.target.files[0];
                
                if (file) {
                    console.log("Processing file:", file.name, file.type, file.size);
                    
                    try {
                        // Show loading indicator
                        const loadingDiv = document.createElement(\'div\');
                        loadingDiv.innerHTML = \'Téléchargement de l\\\'image...\';
                        loadingDiv.style.cssText = \'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:20px;border:2px solid #007bff;border-radius:5px;z-index:10000;\';
                        document.body.appendChild(loadingDiv);
                        
                        let imageUrl;
                        
                        // Get document ID from meta tag
                        const documentId = document.querySelector(\'meta[name="document-id"]\')?.content;
                        
                        if (documentId) {
                            console.log("Document ID found:", documentId);
                            
                            // Upload image to server
                            const formData = new FormData();
                            formData.append(\'image\', file);
                            
                            const csrfToken = document.querySelector(\'meta[name="csrf-token"]\')?.content || \'\';
                            const uploadUrl = `/documents/${documentId}/upload-editor-image`;
                            
                            console.log("Uploading to:", uploadUrl);
                            
                            const response = await fetch(uploadUrl, {
                                method: \'POST\',
                                headers: {
                                    \'X-CSRF-TOKEN\': csrfToken,
                                    \'Accept\': \'application/json\'
                                },
                                body: formData
                            });
                            
                            const data = await response.json();
                            console.log("Upload response:", data);
                            
                            if (data.success) {
                                imageUrl = data.url;
                                console.log("Image uploaded, URL type:", imageUrl.substring(0, 50));
                                console.log("Full URL length:", imageUrl.length);
                            } else {
                                throw new Error(data.error || \'Upload failed\');
                            }
                        } else {
                            console.log("No document ID, using base64");
                            // Fallback to base64
                            imageUrl = await new Promise((resolve, reject) => {
                                const reader = new FileReader();
                                reader.onload = e => resolve(e.target.result);
                                reader.onerror = reject;
                                reader.readAsDataURL(file);
                            });
                        }
                        
                        console.log("Image ready, inserting into document");
                        
                        // Try to find the first PDF page container
                        let container = document.querySelector(".pdf-page-container");
                        
                        // If no page container, try the main content area
                        if (!container) {
                            container = document.getElementById("pdfContent");
                        }
                        
                        // Also try pdf-document
                        if (!container) {
                            container = document.querySelector(".pdf-document");
                        }
                        
                        // Last resort - any container with pdf-content class
                        if (!container) {
                            const pdfContent = document.querySelector(".pdf-content");
                            if (pdfContent) {
                                container = pdfContent.querySelector("div");
                            }
                        }
                        
                        if (!container) {
                            console.error("No suitable container found for adding image");
                            return;
                        }
                        
                        const imageIndex = document.querySelectorAll(".pdf-image-container").length;
                        
                        // Create a temporary image to get dimensions
                        const tempImg = new Image();
                        tempImg.onload = function() {
                            const width = Math.min(this.naturalWidth, 500);
                            const height = this.naturalHeight * (width / this.naturalWidth);
                            
                            const imageHtml = \'<div class="pdf-image-container draggable-element" style="position: absolute; left: 100px; top: 150px; width: \' + width + \'px; height: \' + height + \'px; z-index: 150;" draggable="true" data-image-index="\' + imageIndex + \'" data-element-type="image">\' +
                                \'<img src="\' + imageUrl + \'" class="pdf-image" id="image_\' + imageIndex + \'" style="width: 100%; height: 100%; display: block;" />\' +
                                \'<div class="image-controls">\' +
                                \'<button class="image-control-btn" onclick="window.replaceImage(\' + imageIndex + \')">🔄 Remplacer</button>\' +
                                \'<button class="image-control-btn delete" onclick="window.deleteImage(\' + imageIndex + \')">🗑️ Supprimer</button>\' +
                                \'</div>\' +
                                \'<div class="image-resize-handle nw" data-handle="nw"></div>\' +
                                \'<div class="image-resize-handle ne" data-handle="ne"></div>\' +
                                \'<div class="image-resize-handle sw" data-handle="sw"></div>\' +
                                \'<div class="image-resize-handle se" data-handle="se"></div>\' +
                                \'</div>\';
                            
                            container.insertAdjacentHTML("beforeend", imageHtml);
                            
                            // Make the new image draggable and initialize controls
                            const newImageContainer = container.querySelector(\'[data-image-index="\' + imageIndex + \'"]\');
                            if (newImageContainer) {
                                console.log("New image container inserted with dimensions:", width, "x", height);
                                
                                makeDraggable(newImageContainer);
                                initializeElementControls(newImageContainer);
                                initializeImageHandlers();
                                
                                updateStatus("Image insérée - Activez le mode déplacement pour la positionner");
                            } else {
                                console.error("Could not find newly inserted image with index:", imageIndex);
                            }
                            
                            // Remove loading indicator
                            loadingDiv.remove();
                        };
                        
                        tempImg.onerror = function() {
                            console.error("Failed to load image");
                            alert("Erreur lors du chargement de l\'image");
                            loadingDiv.remove();
                        };
                        
                        tempImg.src = imageUrl;
                        
                    } catch (error) {
                        console.error("Error processing image:", error);
                        alert("Erreur lors du traitement de l\'image: " + error.message);
                        if (loadingDiv) loadingDiv.remove();
                    }
                } else {
                    console.log("No file selected");
                }
            });
            
            // Trigger click on the input
            console.log("Triggering file dialog...");
            input.click();
        };
        
        // Delete any element function
        window.deleteElement = function(element) {
            if (confirm("Êtes-vous sûr de vouloir supprimer cet élément ?")) {
                element.remove();
                updateStatus("Élément supprimé");
            }
        };
        
        // Initialize controls for elements
        function initializeElementControls(element) {
            // For image containers, keep existing dimensions
            if (element.classList.contains(\'pdf-image-container\')) {
                // Keep the existing width and height that were set
                // Do not override them
            } else {
                // For text elements, use fit-content
                if (element.style.width === \'\' || element.style.width === \'auto\') {
                    element.style.width = \'fit-content\';
                }
                if (element.style.height === \'\' || element.style.height === \'auto\') {
                    element.style.height = \'fit-content\';
                }
            }
            
            // Show controls on hover
            element.addEventListener(\'mouseenter\', function(e) {
                // Only show controls if hovering over the actual element
                const controls = this.querySelector(\'.element-controls\');
                const imageControls = this.querySelector(\'.image-controls\');
                
                if (controls) {
                    controls.style.display = \'block\';
                }
                if (imageControls) {
                    imageControls.style.display = \'flex\';
                }
            });
            
            element.addEventListener(\'mouseleave\', function(e) {
                const controls = this.querySelector(\'.element-controls\');
                const imageControls = this.querySelector(\'.image-controls\');
                
                // Check if we\'re moving to a child element (like controls)
                const relatedTarget = e.relatedTarget;
                
                // Check if the related target is the controls themselves or their children
                if (relatedTarget) {
                    if (this.contains(relatedTarget)) {
                        return; // Don\'t hide if moving within the element
                    }
                    if (controls && controls.contains(relatedTarget)) {
                        return; // Don\'t hide if moving to controls
                    }
                    if (imageControls && imageControls.contains(relatedTarget)) {
                        return; // Don\'t hide if moving to image controls
                    }
                }
                
                // Add a small delay to allow clicking on buttons
                setTimeout(() => {
                    if (controls && !controls.matches(\':hover\')) {
                        controls.style.display = \'none\';
                    }
                    if (imageControls && !imageControls.matches(\':hover\')) {
                        imageControls.style.display = \'none\';
                    }
                }, 100);
            });
        }
        
        // Replace image function - make it global for onclick handlers
        window.replaceImage = function(imageIndex) {
            console.log("Replacing image with index:", imageIndex);
            const input = document.createElement("input");
            input.type = "file";
            input.accept = "image/*";
            input.onchange = function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        // Find image by container data-image-index or by ID
                        let imgElement = null;
                        const container = document.querySelector(\'[data-image-index="\' + imageIndex + \'"]\');
                        if (container) {
                            imgElement = container.querySelector(\'img\');
                        } else {
                            imgElement = document.getElementById("image_" + imageIndex);
                        }
                        
                        if (imgElement) {
                            // Store current dimensions
                            const currentWidth = imgElement.offsetWidth;
                            const currentHeight = imgElement.offsetHeight;
                            
                            // Replace the source
                            imgElement.src = event.target.result;
                            
                            // Restore dimensions
                            imgElement.style.width = currentWidth + "px";
                            imgElement.style.height = currentHeight + "px";
                            
                            updateStatus("Image remplacée");
                        }
                    };
                    reader.onerror = function(error) {
                        console.error("Error reading file:", error);
                        alert("Erreur lors de la lecture du fichier image");
                    };
                    reader.readAsDataURL(file);
                } else {
                    console.log("No file selected");
                }
            };
            
            // Trigger click on the input
            console.log("Triggering file dialog...");
            input.click();
        };
        
        // Delete image function - make it global for onclick handlers
        window.deleteImage = function(imageIndex) {
            console.log("Deleting image with index:", imageIndex);
            if (confirm("Êtes-vous sûr de vouloir supprimer cette image ?")) {
                const container = document.querySelector(\'[data-image-index="\' + imageIndex + \'"]\');
                if (container) {
                    container.remove();
                    updateStatus("Image supprimée");
                }
            }
        };
        
        // Image drag and resize
        function initializeImageHandlers() {
            // Enhanced drag functionality for images
            document.querySelectorAll(".pdf-image-container").forEach(container => {
                // Remove old listeners first
                container.removeEventListener("dragstart", handleDragStart);
                container.removeEventListener("dragend", handleDragEnd);
                
                // Add new enhanced listeners
                container.addEventListener("dragstart", handleDragStart);
                container.addEventListener("dragend", handleDragEnd);
                container.addEventListener("dragover", handleDragOver);
                container.addEventListener("drop", handleDrop);
                
                // Double-click to replace
                container.addEventListener("dblclick", function(e) {
                    const imageIndex = this.dataset.imageIndex;
                    if (imageIndex !== undefined) {
                        replaceImage(imageIndex);
                    }
                });
            });
            
            // Resize functionality
            document.querySelectorAll(".image-resize-handle").forEach(handle => {
                handle.removeEventListener("mousedown", handleResizeStart);
                handle.addEventListener("mousedown", handleResizeStart);
            });
            
            // Make the document area accept drops
            const pdfContent = document.getElementById("pdfContent");
            if (pdfContent) {
                pdfContent.addEventListener("dragover", function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = "move";
                });
                
                pdfContent.addEventListener("drop", function(e) {
                    e.preventDefault();
                    if (draggedElement && draggedElement.classList.contains("pdf-image-container")) {
                        // Calculate new position
                        const rect = pdfContent.getBoundingClientRect();
                        const x = e.clientX - rect.left;
                        const y = e.clientY - rect.top;
                        
                        // Apply absolute positioning if not already
                        if (!draggedElement.style.position || draggedElement.style.position === "static") {
                            draggedElement.style.position = "absolute";
                        }
                        
                        // Set new position
                        draggedElement.style.left = (x - draggedElement.offsetWidth / 2) + "px";
                        draggedElement.style.top = (y - draggedElement.offsetHeight / 2) + "px";
                        
                        updateStatus("Image déplacée");
                    }
                });
            }
        }
        
        let draggedElement = null;
        
        function handleDragStart(e) {
            isDragging = true;
            draggedElement = e.currentTarget;
            e.currentTarget.classList.add("dragging");
            e.dataTransfer.effectAllowed = "move";
            e.dataTransfer.setData("text/html", e.currentTarget.innerHTML);
        }
        
        function handleDragEnd(e) {
            isDragging = false;
            e.currentTarget.classList.remove("dragging");
            draggedElement = null;
            updateStatus("Image repositionnée");
        }
        
        function handleDragOver(e) {
            if (e.preventDefault) {
                e.preventDefault();
            }
            e.dataTransfer.dropEffect = "move";
            return false;
        }
        
        function handleDrop(e) {
            if (e.stopPropagation) {
                e.stopPropagation();
            }
            return false;
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
            .then(async response => {
                hideLoading();
                if (response.redirected) {
                    window.location.href = response.url;
                } else if (!response.ok) {
                    // Si erreur, afficher la vue d\'erreur dans une modal
                    const errorHtml = await response.text();
                    showErrorModal(errorHtml);
                } else {
                    try {
                        const data = await response.json();
                        if (data.success) {
                            updateStatus("Document sauvegardé avec succès");
                            showSuccessNotification("Le PDF a été généré avec succès!");
                        } else {
                            showErrorNotification("Erreur: " + (data.error || "Erreur inconnue"));
                            updateStatus("Erreur: " + data.error);
                        }
                    } catch (e) {
                        // Si ce n\'est pas du JSON, c\'est probablement une redirection ou du HTML
                        const text = await response.text();
                        if (text.includes("<!DOCTYPE") || text.includes("<html")) {
                            showErrorModal(text);
                        } else {
                            showErrorNotification("Réponse inattendue du serveur");
                        }
                    }
                }
            })
            .catch(error => {
                hideLoading();
                showErrorNotification("Erreur de connexion: " + error.message);
                updateStatus("Erreur de connexion");
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
        
        // Notification functions
        function showSuccessNotification(message) {
            const notification = createNotification(message, "success");
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 5000);
        }
        
        function showErrorNotification(message) {
            const notification = createNotification(message, "error");
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 8000);
        }
        
        function createNotification(message, type) {
            const notification = document.createElement("div");
            notification.className = `notification notification-${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                max-width: 400px;
                padding: 16px 20px;
                background: ${type === "success" ? "#10b981" : "#ef4444"};
                color: white;
                border-radius: 8px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.2);
                z-index: 10000;
                animation: slideIn 0.3s ease-out;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            `;
            notification.innerHTML = `
                <div style="display: flex; align-items: center; gap: 12px;">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                        ${type === "success" 
                            ? \'<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>\' 
                            : \'<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>\'
                        }
                    </svg>
                    <div style="flex: 1;">${message}</div>
                    <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; color: white; cursor: pointer; padding: 4px;">
                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                </div>
            `;
            return notification;
        }
        
        // Error modal function
        function showErrorModal(htmlContent) {
            // Create modal container
            const modal = document.createElement("div");
            modal.id = "errorModal";
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 10000;
                animation: fadeIn 0.3s ease-out;
            `;
            
            // Create modal content
            const modalContent = document.createElement("div");
            modalContent.style.cssText = `
                background: white;
                border-radius: 16px;
                max-width: 90%;
                max-height: 90%;
                overflow: auto;
                position: relative;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            `;
            
            // Add close button
            const closeButton = document.createElement("button");
            closeButton.style.cssText = `
                position: absolute;
                top: 16px;
                right: 16px;
                background: #f3f4f6;
                border: none;
                border-radius: 50%;
                width: 32px;
                height: 32px;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 10;
            `;
            closeButton.innerHTML = \'<svg width="20" height="20" fill="#6b7280" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>\';
            closeButton.onclick = () => modal.remove();
            
            // Create iframe for error content
            const iframe = document.createElement("iframe");
            iframe.style.cssText = `
                width: 100%;
                min-height: 500px;
                border: none;
                border-radius: 16px;
            `;
            iframe.srcdoc = htmlContent;
            
            modalContent.appendChild(closeButton);
            modalContent.appendChild(iframe);
            modal.appendChild(modalContent);
            document.body.appendChild(modal);
            
            // Close on backdrop click
            modal.onclick = (e) => {
                if (e.target === modal) {
                    modal.remove();
                }
            };
            
            // Add CSS animation
            const style = document.createElement("style");
            style.textContent = `
                @keyframes fadeIn {
                    from { opacity: 0; }
                    to { opacity: 1; }
                }
                @keyframes slideIn {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
            `;
            document.head.appendChild(style);
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
        
        // Context menu for images
        function initializeContextMenu() {
            // Remove existing context menu if any
            const existingMenu = document.getElementById("imageContextMenu");
            if (existingMenu) {
                existingMenu.remove();
            }
            
            // Create context menu
            const contextMenu = document.createElement("div");
            contextMenu.id = "imageContextMenu";
            contextMenu.style.cssText = \'position: fixed; background: white; border: 1px solid #ccc; border-radius: 4px; padding: 5px 0; box-shadow: 0 2px 10px rgba(0,0,0,0.2); z-index: 10000; display: none;\';
            contextMenu.innerHTML = \'<div class="context-menu-item" onclick="contextMenuReplace()" style="padding: 8px 20px; cursor: pointer; hover: background: #f0f0f0;">🔄 Remplacer image</div>\' +
                \'<div class="context-menu-item" onclick="contextMenuDelete()" style="padding: 8px 20px; cursor: pointer; hover: background: #f0f0f0;">🗑️ Supprimer image</div>\' +
                \'<div class="context-menu-item" onclick="contextMenuDuplicate()" style="padding: 8px 20px; cursor: pointer; hover: background: #f0f0f0;">📋 Dupliquer image</div>\';
            document.body.appendChild(contextMenu);
            
            let currentImageIndex = null;
            
            // Add right-click handler to images
            document.addEventListener("contextmenu", function(e) {
                const imageContainer = e.target.closest(".pdf-image-container");
                if (imageContainer) {
                    e.preventDefault();
                    currentImageIndex = imageContainer.dataset.imageIndex;
                    contextMenu.style.left = e.clientX + "px";
                    contextMenu.style.top = e.clientY + "px";
                    contextMenu.style.display = "block";
                }
            });
            
            // Hide context menu on click elsewhere
            document.addEventListener("click", function() {
                contextMenu.style.display = "none";
            });
            
            // Context menu functions
            window.contextMenuReplace = function() {
                if (currentImageIndex !== null) {
                    replaceImage(currentImageIndex);
                }
                contextMenu.style.display = "none";
            };
            
            window.contextMenuDelete = function() {
                if (currentImageIndex !== null) {
                    deleteImage(currentImageIndex);
                }
                contextMenu.style.display = "none";
            };
            
            window.contextMenuDuplicate = function() {
                if (currentImageIndex !== null) {
                    const original = document.querySelector(\'[data-image-index="\' + currentImageIndex + \'"]\');
                    if (original) {
                        const clone = original.cloneNode(true);
                        const newIndex = document.querySelectorAll(".pdf-image-container").length;
                        clone.dataset.imageIndex = newIndex;
                        const img = clone.querySelector(".pdf-image");
                        if (img) img.id = "image_" + newIndex;
                        // Update button onclick attributes
                        const buttons = clone.querySelectorAll(".image-control-btn");
                        buttons[0].setAttribute("onclick", "replaceImage(" + newIndex + ")");
                        buttons[1].setAttribute("onclick", "deleteImage(" + newIndex + ")");
                        // Offset position slightly
                        clone.style.left = (parseInt(clone.style.left || 0) + 20) + "px";
                        clone.style.top = (parseInt(clone.style.top || 0) + 20) + "px";
                        document.getElementById("pdfContent").appendChild(clone);
                        initializeImageHandlers();
                        updateStatus("Image dupliquée");
                    }
                }
                contextMenu.style.display = "none";
            };
        }
        
        // Initialize on load
        document.addEventListener("DOMContentLoaded", function() {
            initializeImageHandlers();
            initializeContextMenu();
            updateStatus("Éditeur prêt - Double-cliquez sur une image pour la remplacer");
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
            'fonts' => [],
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
            'html' => 'required|string',
        ]);

        try {
            // Store HTML content temporarily for this session
            $sessionKey = 'html_content_' . $document->id;
            session([$sessionKey => $request->html]);
            
            // Also save HTML as a new document for permanent storage
            $html = $request->html;
            
            // Create a unique filename
            $htmlFilename = pathinfo($document->original_name, PATHINFO_FILENAME) . '_saved_' . date('Y-m-d_His') . '.html';
            $storedName = 'documents/' . Auth::id() . '/' . uniqid() . '.html';
            
            // Save HTML file
            Storage::put($storedName, $html);
            
            // Create a new document record with all required fields
            $savedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => Auth::id(),
                'original_name' => $htmlFilename,
                'stored_name' => $storedName,
                'mime_type' => 'text/html',
                'extension' => 'html',
                'size' => strlen($html),
                'hash' => hash('sha256', $html),
                'metadata' => json_encode([
                    'source_document_id' => $document->id,
                    'saved_at' => now()->toIso8601String(),
                ]),
                'is_public' => false,
                'status' => 'active',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Document sauvegardé avec succès',
                'document_id' => $savedDocument->id,
            ]);

        } catch (Exception $e) {
            Log::error('Error saving HTML', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
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
                'has_images' => strpos($html, '<img') !== false,
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

            // Use original dimensions if available, otherwise use extracted dimensions
            // Do NOT use content bounds as this causes the page to shrink
            $pdfDimensions = $dimensions;
            
            // If we have original PDF dimensions, use those to maintain exact size
            if ($originalDimensions) {
                $pdfDimensions = [
                    'width' => $originalDimensions['width_mm'],
                    'height' => $originalDimensions['height_mm']
                ];
            }

            Log::info('Using PDF dimensions', $pdfDimensions);

            // Generate PDF with page dimensions to maintain proper size
            $pdfFile = $this->generatePdfFromHtmlImproved($htmlFile, $pdfDimensions);

            if (! $pdfFile) {
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
            Log::error('HTML to PDF save failed', [
                'document_id' => $document->id, 
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Pour les requêtes AJAX, retourner une vue HTML d'erreur au lieu de JSON
            if ($request->ajax() || $request->wantsJson()) {
                return response()->view('errors.pdf-conversion', [
                    'error' => $e->getMessage(),
                    'document' => $document,
                    'supportEmail' => config('mail.support_email', 'support@gigapdf.com')
                ], 500);
            }

            // Pour les requêtes normales, rediriger avec message d'erreur
            return redirect()->back()
                ->with('error', 'Impossible de convertir le document en PDF. ' . $e->getMessage())
                ->withInput();
        }
    }

    private function processImagesForPdf($html, $document)
    {
        return preg_replace_callback(
            '/<img([^>]*?)src=["\']?(.*?)["\']?([^>]*)>/i',
            function ($matches) use ($document) {
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
                        'paths_checked' => $paths,
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
                        if (! preg_match('/(editor-toolbar|loading-overlay|status-bar)/i', $classes)) {
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

        // Try to extract from data attributes first (these are set by the editor)
        if (preg_match('/data-width="([^"]+)"/', $html, $widthMatch)) {
            $widthValue = $widthMatch[1];
            if (preg_match('/(\d+\.?\d*)px/', $widthValue, $pxMatch)) {
                $width = round(floatval($pxMatch[1]) * 25.4 / 96);
            }
        }

        if (preg_match('/data-height="([^"]+)"/', $html, $heightMatch)) {
            $heightValue = $heightMatch[1];
            if (preg_match('/(\d+\.?\d*)px/', $heightValue, $pxMatch)) {
                $height = round(floatval($pxMatch[1]) * 25.4 / 96);
            }
        }

        // Fallback: try first page container
        if (preg_match('/<div[^>]*class=["\'][^"\']*pdf-page-container[^"\']*["\'][^>]*style="[^"]*width:\s*(\d+\.?\d*)px/', $html, $widthMatch)) {
            $width = round(floatval($widthMatch[1]) * 25.4 / 96);
        }

        if (preg_match('/<div[^>]*class=["\'][^"\']*pdf-page-container[^"\']*["\'][^>]*style="[^"]*height:\s*(\d+\.?\d*)px/', $html, $heightMatch)) {
            $height = round(floatval($heightMatch[1]) * 25.4 / 96);
        }

        Log::info('Extracted page dimensions', ['width_mm' => $width, 'height_mm' => $height]);

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
            '--margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 6.6mm ' .
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
            'height' => $height,
        ];
    }

    /**
         * Finds image URLs in HTML and replaces them with base64 data URIs for embedding.
         */
    private function embedImagesAsBase64($html, Document $document)
    {
        return preg_replace_callback(
            '/<img([^>]*?)src="([^"]+)"/i',
            function ($matches) use ($document) {
                $attributes = $matches[1];
                $src = $matches[2];
                $appUrl = config('app.url');

                // Process only local asset URLs, skip data URIs and external images
                if (strpos($src, 'data:') === 0 || strpos($src, $appUrl) === false) {
                    return $matches[0];
                }

                $path_parts = explode('/', parse_url($src, PHP_URL_PATH));
                $filename = end($path_parts);

                if (! $filename) {
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

            // Priority 1: Find all pdf-page-wrapper elements (multi-page support)
            $pageWrappers = $xpath->query("//div[contains(@class, 'pdf-page-wrapper')]");
            if ($pageWrappers->length > 0) {
                $content = '';
                foreach ($pageWrappers as $wrapper) {
                    $content .= $dom->saveHTML($wrapper);
                }
                Log::info('Extracted multiple page wrappers', ['count' => $pageWrappers->length]);
                return $this->fixEmptyImageSources($content);
            }

            // Priority 2: Find all pdf-page elements
            $pdfPages = $xpath->query("//div[contains(@class, 'pdf-page')]");
            if ($pdfPages->length > 0) {
                $content = '';
                foreach ($pdfPages as $page) {
                    $content .= $dom->saveHTML($page);
                }
                Log::info('Extracted multiple PDF pages', ['count' => $pdfPages->length]);
                return $this->fixEmptyImageSources($content);
            }

            // Priority 3: Find the pdfContent div directly
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

            // Priority 4: Find all page containers
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
                if ($pageContainers->length == 1 || ! $isMultiPage) {
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
                    'is_multi_page' => $isMultiPage,
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
            function ($matches) {
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
            function ($matches) {
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
            function ($matches) {
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
            function ($matches) use ($document) {
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
            function ($matches) use ($document) {
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
                                'user_agent' => 'Mozilla/5.0 (Compatible; PDF Converter)',
                            ],
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
                                'mime' => $mimeType,
                            ]);

                            break;
                        }
                    }

                    if (! $imageData) {
                        Log::warning('Image not found after checking all paths', [
                            'src' => $src,
                            'filename' => $filename,
                            'document_id' => $document->id,
                            'paths_checked' => count($possiblePaths),
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
        // Use the provided dimensions (original PDF dimensions) if available
        // Do NOT use content bounds as this shrinks the page
        $width = $dimensions['width'] ?? 210; // mm
        $height = $dimensions['height'] ?? 297; // mm
        
        // Convert mm to pixels for CSS (at 96 DPI)
        $contentWidthPx = round($width * 96 / 25.4);
        $contentHeightPx = round($height * 96 / 25.4);
        
        Log::info('Building final HTML with dimensions', [
            'width_mm' => $width,
            'height_mm' => $height,
            'width_px' => $contentWidthPx,
            'height_px' => $contentHeightPx
        ]);

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
            width: 100%;
            height: 100%;
            margin: 0 !important;
            padding: 0 !important;
            overflow: visible;
            font-family: Arial, sans-serif;
            background: white;
            border: none;
            box-shadow: none;
            position: relative;
        }
        
        /* Remove page markers from export */
        .page-marker {
            display: none !important;
        }
        
        /* Page break handling for multiple pages */
        .pdf-page-wrapper,
        .pdf-page {
            page-break-after: always;
            page-break-inside: avoid;
            position: relative;
            width: {$contentWidthPx}px;
            height: {$contentHeightPx}px;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }
        
        /* Last page should not have page break */
        .pdf-page-wrapper:last-child,
        .pdf-page:last-child {
            page-break-after: auto;
        }
        
        /* Page containers - use actual content dimensions and remove ALL margins */
        .pdf-page-container {
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            width: {$contentWidthPx}px !important;
            height: {$contentHeightPx}px !important;
            page-break-inside: avoid;
            overflow: visible;
            margin: 0 !important;
            padding: 0 !important;
            background: white;
            border: none;
            box-shadow: none;
            transform: none !important;
        }
        
        /* Ensure no wrapper margins */
        #pdfContent {
            margin: 0 !important;
            padding: 0 !important;
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            width: {$contentWidthPx}px !important;
            height: {$contentHeightPx}px !important;
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
        .pdf-text, .pdf-element, .pdf-image, .pdf-vector, .pdf-line,
        div[style*="position: absolute"],
        img[style*="position: absolute"],
        span[style*="position: absolute"] {
            /* Let inline styles handle positioning */
        }
        
        /* Vector elements and lines */
        .pdf-vector {
            position: absolute !important;
            z-index: 2;
        }
        
        .pdf-line {
            position: absolute !important;
            z-index: 3;
            pointer-events: none;
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
            max-width: none !important;
            height: auto;
        }
        
        /* Vector images - ensure they are visible */
        .pdf-vector, img.pdf-vector {
            position: absolute !important;
            z-index: 2 !important;
            display: block !important;
            opacity: 1 !important;
            visibility: visible !important;
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
                width: 100%;
                height: 100%;
                margin: 0 !important;
                padding: 0 !important;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            
            .pdf-page-container {
                position: absolute !important;
                top: 0 !important;
                left: 0 !important;
                width: {$contentWidthPx}px !important;
                height: {$contentHeightPx}px !important;
                margin: 0 !important;
                padding: 0 !important;
            }
        }
    </style>
</head>
<body>
{$content}

<script>
    // Signal to wkhtmltopdf that the page is ready
    if (typeof window !== 'undefined') {
        window.status = 'ready';
    }
</script>
</body>
</html>
HTML;
    }

    /**
     * Analyze content to find actual bounds
     */
    private function analyzeContentBounds($html)
    {
        // Default A4 dimensions in pixels at 96 DPI
        $defaultWidth = 794; // A4 width 
        $defaultHeight = 1123; // A4 height
        
        // Check if this is a multi-page document
        $pageWrapperCount = substr_count($html, 'class="pdf-page-wrapper"');
        $pdfPageCount = substr_count($html, 'class="pdf-page"');
        $totalPages = max($pageWrapperCount, $pdfPageCount);
        
        // For multi-page documents, always use standard page dimensions
        if ($totalPages > 1) {
            Log::info('Multi-page document detected, using standard dimensions', [
                'pages' => $totalPages,
                'width' => $defaultWidth,
                'height' => $defaultHeight
            ]);
            return [
                'width' => $defaultWidth,
                'height' => $defaultHeight,
            ];
        }

        $maxWidth = $defaultWidth;
        $maxHeight = $defaultHeight;

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

        // For single page, check for absolutely positioned elements to find actual content bounds
        if ($totalPages <= 1) {
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
                // Use actual content bounds or default, whichever is more appropriate
                $maxWidth = min(max($actualMaxX, $defaultWidth), 1190);
                $maxHeight = min(max($actualMaxY, $defaultHeight), 1684);
            }
        }

        // Cap dimensions to reasonable maximum (A3 size)
        $maxWidth = min($maxWidth, 1190); // A3 width at 96 DPI
        $maxHeight = min($maxHeight, 1684); // A3 height at 96 DPI

        return [
            'width' => $maxWidth,
            'height' => $maxHeight,
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

        // Calculate pixel dimensions for viewport - no scaling
        $widthPx = round($width * 96 / 25.4);
        $heightPx = round($height * 96 / 25.4);

        // wkhtmltopdf command optimized for multiple pages without zoom or offset
        $command = sprintf(
            'wkhtmltopdf ' .
            '--page-width %dmm --page-height %dmm ' .
            '--margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 6.6mm ' .
            '--disable-smart-shrinking ' .
            '--print-media-type ' .
            '--enable-local-file-access ' .
            '--load-error-handling ignore ' .
            '--load-media-error-handling ignore ' .
            '--encoding UTF-8 ' .
            '--dpi 96 ' . // Screen DPI for 1:1 mapping
            '--image-quality 100 ' .
            '--image-dpi 96 ' .
            '--javascript-delay 500 ' . // Reduced delay
            '--no-stop-slow-scripts ' .
            '--enable-javascript ' .
            '--window-status ready ' . // Wait for window.status = "ready"
            '--no-outline ' . // Disable outline/border
            '--background ' . // Enable HTML background
            '--enable-forms ' . // Enable form elements
            '--zoom 1.0 ' . // Explicit 1:1 zoom
            '--disable-external-links ' . // No external resources
            '%s %s 2>&1',
            $width,
            $height,
            escapeshellarg($htmlFile),
            escapeshellarg($pdfFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode === 0 && file_exists($pdfFile) && filesize($pdfFile) > 0) {
            Log::info('PDF generated successfully', [
                'size' => filesize($pdfFile),
                'dimensions' => $dimensions,
                'viewport' => $widthPx . 'x' . $heightPx,
            ]);

            return $pdfFile;
        }

        // Log detailed error information
        Log::error('wkhtmltopdf failed', [
            'command' => $command,
            'output' => implode("\n", $output),
            'return_code' => $returnCode,
            'html_file_exists' => file_exists($htmlFile),
            'html_file_size' => file_exists($htmlFile) ? filesize($htmlFile) : 0,
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
            function ($matches) use ($document) {
                $preAttributes = $matches[1];
                $filename = $matches[2];
                $postAttributes = $matches[4];

                // The python script saves images in storage/app/documents/{id}
                $imagePath = storage_path('app/documents/' . $document->id . '/' . $filename);

                if (file_exists($imagePath)) {
                    $url = route('documents.serve-asset', [
                        'document' => $document->id,
                        'filename' => $filename,
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
            function ($matches) use ($document) {
                $filename = $matches[1];

                $imagePath = storage_path('app/documents/' . $document->id . '/' . $filename);

                if (file_exists($imagePath)) {
                    $url = route('documents.serve-asset', [
                        'document' => $document->id,
                        'filename' => $filename,
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
            function ($matches) {
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
            function ($matches) {
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
            function ($matches) {
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
            function ($matches) {
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
            'document_ids.*' => 'integer|exists:documents,id',
        ]);

        $deletedCount = 0;
        $errors = [];

        foreach ($request->document_ids as $documentId) {
            try {
                $document = Document::findOrFail($documentId);

                // Check authorization for each document
                if (! Auth::user()->can('delete', $document)) {
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
                        'bulk_operation' => true,
                    ])
                    ->log('Document deleted (bulk operation)');

                // Delete record
                $document->delete();
                $deletedCount++;

            } catch (Exception $e) {
                $errors[] = "Erreur lors de la suppression du document ID {$documentId}";
                Log::error('Bulk delete error', [
                    'document_id' => $documentId,
                    'error' => $e->getMessage(),
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
            'document_ids.*' => 'integer|exists:documents,id',
        ]);

        $zipName = 'documents_' . date('Y-m-d_His') . '.zip';
        $zipPath = storage_path('app/temp/' . $zipName);

        // Ensure temp directory exists
        if (! file_exists(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $zip = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            return back()->with('error', 'Impossible de créer l\'archive ZIP');
        }

        foreach ($request->document_ids as $documentId) {
            try {
                $document = Document::findOrFail($documentId);

                // Check authorization
                if (! Auth::user()->can('download', $document)) {
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
                    'error' => $e->getMessage(),
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
