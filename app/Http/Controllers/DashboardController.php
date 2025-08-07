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
        
        // Get statistics (tenant-scoped)
        $stats = [
            'documents_count' => Document::where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->count(),
            'conversions_count' => Conversion::where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->count(),
            'shares_count' => Share::whereHas('document', function ($query) use ($tenant, $user) {
                $query->where('tenant_id', $tenant->id)
                      ->where('user_id', $user->id);
            })->count(),
        ];
        
        // Get recent documents (tenant-scoped)
        $recentDocuments = Document::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(function ($document) {
                return [
                    'id' => $document->id,
                    'original_name' => $document->original_name,
                    'size' => $document->size,
                    'mime_type' => $document->mime_type,
                    'extension' => $document->extension ?? pathinfo($document->original_name, PATHINFO_EXTENSION),
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