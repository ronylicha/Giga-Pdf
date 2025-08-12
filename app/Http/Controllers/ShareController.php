<?php

namespace App\Http\Controllers;

use App\Models\Share;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ShareController extends Controller
{
    /**
     * Display a listing of shares created by the authenticated user
     */
    public function index(Request $request)
    {
        $shares = Share::with(['document', 'recipient'])
            ->where('shared_by', Auth::id())
            ->when($request->get('type'), function ($query, $type) {
                $query->where('type', $type);
            })
            ->when($request->get('active') !== null, function ($query) use ($request) {
                if ($request->get('active') === 'true') {
                    $query->active();
                } else {
                    $query->where('is_active', false)->orWhere('expires_at', '<', now());
                }
            })
            ->when($request->get('search'), function ($query, $search) {
                $query->whereHas('document', function ($q) use ($search) {
                    $q->where('original_name', 'like', "%{$search}%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return Inertia::render('Shares/Index', [
            'shares' => $shares,
            'filters' => $request->only(['type', 'active', 'search'])
        ]);
    }

    /**
     * Display the shared document
     */
    public function show(Request $request, $token)
    {
        $share = Share::where('token', $token)
            ->with(['document', 'sharer'])
            ->firstOrFail();

        // Check if share is active and not expired
        if (!$share->isActive()) {
            abort(403, 'This share link has expired or been revoked.');
        }

        // Check if password protected and not verified
        if ($share->isProtected()) {
            $sessionKey = 'share_access_' . $token;
            
            if (!session()->has($sessionKey)) {
                return Inertia::render('Shares/PasswordPrompt', [
                    'token' => $token,
                    'documentName' => $share->document->original_name
                ]);
            }
        }

        // Record access
        $share->recordAccess($request->ip(), $request->userAgent());

        // Check if user can view
        if (!$share->canView()) {
            abort(403, 'You do not have permission to view this document.');
        }

        // Get document URL for preview
        $documentUrl = $this->getDocumentUrl($share->document);

        return Inertia::render('Shares/View', [
            'share' => $share,
            'document' => $share->document,
            'documentUrl' => $documentUrl,
            'canDownload' => $share->canDownload(),
            'canComment' => $share->canComment(),
            'sharedBy' => $share->sharer->name
        ]);
    }

    /**
     * Verify password for protected share
     */
    public function verify(Request $request, $token)
    {
        // Rate limiting to prevent brute force
        $key = 'share-verify:' . $token . ':' . $request->ip();
        
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            abort(429, "Too many attempts. Please try again in {$seconds} seconds.");
        }

        $request->validate([
            'password' => 'required|string'
        ]);

        $share = Share::where('token', $token)->firstOrFail();

        if (!$share->isActive()) {
            abort(403, 'This share link has expired or been revoked.');
        }

        if (!$share->isProtected()) {
            return redirect()->route('share.show', $token);
        }

        // Verify password
        if (!Hash::check($request->password, $share->password)) {
            RateLimiter::hit($key, 60);
            
            return back()->withErrors([
                'password' => 'The password is incorrect.'
            ]);
        }

        // Clear rate limiter
        RateLimiter::clear($key);

        // Store in session
        session(['share_access_' . $token => true]);
        session()->save();

        // Log successful access
        activity()
            ->performedOn($share)
            ->withProperties([
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ])
            ->log('Password verified for protected share');

        return redirect()->route('share.show', $token);
    }

    /**
     * Download the shared document
     */
    public function download(Request $request, $token)
    {
        $share = Share::where('token', $token)
            ->with('document')
            ->firstOrFail();

        // Check if share is active
        if (!$share->isActive()) {
            abort(403, 'This share link has expired or been revoked.');
        }

        // Check if password protected
        if ($share->isProtected() && !session()->has('share_access_' . $token)) {
            return redirect()->route('share.show', $token);
        }

        // Check download permission
        if (!$share->canDownload()) {
            abort(403, 'You do not have permission to download this document.');
        }

        // Record download
        $share->recordDownload($request->ip());

        // Log download
        activity()
            ->performedOn($share->document)
            ->withProperties([
                'share_id' => $share->id,
                'ip' => $request->ip()
            ])
            ->log('Document downloaded via share link');

        // Return file download
        $path = $share->document->stored_name;
        
        if (!Storage::exists($path)) {
            abort(404, 'File not found.');
        }

        return Storage::download(
            $path,
            $share->document->original_name,
            [
                'Content-Type' => $share->document->mime_type,
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0'
            ]
        );
    }

    /**
     * Update share settings
     */
    public function update(Request $request, Share $share)
    {
        // Check authorization
        if ($share->shared_by !== Auth::id()) {
            abort(403, 'You are not authorized to modify this share.');
        }

        $validated = $request->validate([
            'permissions' => 'nullable|array',
            'permissions.*' => 'in:view,download,edit,comment',
            'expires_at' => 'nullable|date|after:now',
            'password' => 'nullable|string|min:6',
            'message' => 'nullable|string|max:500'
        ]);

        // Update permissions
        if (isset($validated['permissions'])) {
            $share->updatePermissions($validated['permissions']);
        }

        // Update expiration
        if (isset($validated['expires_at'])) {
            $share->expires_at = $validated['expires_at'];
        }

        // Update password
        if (isset($validated['password'])) {
            $share->password = Hash::make($validated['password']);
            $share->type = Share::TYPE_PROTECTED;
        }

        // Update message
        if (array_key_exists('message', $validated)) {
            $share->message = $validated['message'];
        }

        $share->save();

        // Log update
        activity()
            ->performedOn($share)
            ->causedBy(Auth::user())
            ->withProperties($validated)
            ->log('Share settings updated');

        return back()->with('success', 'Share settings updated successfully.');
    }

    /**
     * Revoke a share
     */
    public function destroy(Share $share)
    {
        // Check authorization
        if ($share->shared_by !== Auth::id()) {
            abort(403, 'You are not authorized to revoke this share.');
        }

        // Revoke the share
        $share->revoke();

        // Log revocation
        activity()
            ->performedOn($share)
            ->causedBy(Auth::user())
            ->log('Share revoked');

        return back()->with('success', 'Share has been revoked successfully.');
    }

    /**
     * Get share statistics
     */
    public function stats(Share $share)
    {
        // Check authorization
        if ($share->shared_by !== Auth::id()) {
            abort(403, 'You are not authorized to view these statistics.');
        }

        $stats = [
            'views_count' => $share->views_count,
            'downloads_count' => $share->downloads_count,
            'last_accessed_at' => $share->last_accessed_at,
            'last_accessed_ip' => $share->last_accessed_ip,
            'access_log' => collect($share->access_log ?? [])->take(50),
            'created_at' => $share->created_at,
            'expires_at' => $share->expires_at,
            'is_active' => $share->is_active,
            'type' => $share->type
        ];

        return response()->json($stats);
    }

    /**
     * Extend share expiration
     */
    public function extend(Request $request, Share $share)
    {
        // Check authorization
        if ($share->shared_by !== Auth::id()) {
            abort(403, 'You are not authorized to extend this share.');
        }

        $validated = $request->validate([
            'days' => 'required|integer|min:1|max:365'
        ]);

        $share->extendExpiration($validated['days']);

        // Log extension
        activity()
            ->performedOn($share)
            ->causedBy(Auth::user())
            ->withProperties(['days' => $validated['days']])
            ->log('Share expiration extended');

        return back()->with('success', 'Share expiration extended by ' . $validated['days'] . ' days.');
    }

    /**
     * Create a new share (alternative to DocumentController::share)
     */
    public function store(Request $request, Document $document)
    {
        // Check if user can share this document
        if ($document->user_id !== Auth::id() && $document->tenant_id !== Auth::user()->tenant_id) {
            abort(403, 'You are not authorized to share this document.');
        }

        $validated = $request->validate([
            'type' => 'required|in:internal,public,protected',
            'shared_with' => 'nullable|exists:users,id|required_if:type,internal',
            'password' => 'nullable|string|min:6|required_if:type,protected',
            'expires_at' => 'nullable|date|after:now',
            'permissions' => 'nullable|array',
            'permissions.*' => 'in:view,download,edit,comment',
            'message' => 'nullable|string|max:500'
        ]);

        // Set default permissions
        if (!isset($validated['permissions'])) {
            $validated['permissions'] = ['view', 'download'];
        }

        // Create share
        $share = Share::create([
            'document_id' => $document->id,
            'shared_by' => Auth::id(),
            'shared_with' => $validated['shared_with'] ?? null,
            'type' => $validated['type'],
            'permissions' => $validated['permissions'],
            'password' => isset($validated['password']) ? Hash::make($validated['password']) : null,
            'expires_at' => $validated['expires_at'] ?? null,
            'message' => $validated['message'] ?? null
        ]);

        // Log share creation
        activity()
            ->performedOn($document)
            ->causedBy(Auth::user())
            ->withProperties([
                'share_id' => $share->id,
                'type' => $share->type
            ])
            ->log('Document shared');

        // Send notification if internal share
        if ($share->isInternal() && $share->recipient) {
            // TODO: Send notification to recipient
        }

        return response()->json([
            'share' => $share,
            'url' => $share->getShareUrl(),
            'message' => 'Document shared successfully.'
        ]);
    }

    /**
     * Get document URL for preview
     */
    private function getDocumentUrl(Document $document): string
    {
        // Generate a temporary signed URL for the document
        return Storage::temporaryUrl(
            $document->stored_name,
            now()->addHours(1),
            [
                'ResponseContentDisposition' => 'inline; filename="' . $document->original_name . '"'
            ]
        );
    }
}