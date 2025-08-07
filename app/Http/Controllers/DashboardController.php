<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Conversion;
use App\Models\Share;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Display the dashboard.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant;
        
        // Get statistics
        $stats = [
            'total_documents' => Document::where('user_id', $user->id)->count(),
            'total_size' => Document::where('user_id', $user->id)->sum('size'),
            'conversions_today' => Conversion::where('user_id', $user->id)
                ->whereDate('created_at', Carbon::today())
                ->count(),
            'shared_files' => Share::whereHas('document', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })->count(),
        ];
        
        // Get recent documents
        $recentDocuments = Document::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->map(function ($document) {
                return [
                    'id' => $document->id,
                    'original_name' => $document->original_name,
                    'size' => $document->size,
                    'mime_type' => $document->mime_type,
                    'extension' => $document->getExtension(),
                    'created_at' => $document->created_at,
                    'thumbnail_path' => $document->thumbnail_path,
                    'thumbnail_url' => $document->thumbnail_path 
                        ? asset('storage/' . $document->thumbnail_path) 
                        : null,
                ];
            });
        
        // Get storage usage for the user
        $storageUsage = [
            'used' => $tenant->getStorageUsed(),
            'limit' => $tenant->max_storage_gb * 1024 * 1024 * 1024, // Convert GB to bytes
        ];
        
        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentDocuments' => $recentDocuments,
            'storage' => $storageUsage,
        ]);
    }
}