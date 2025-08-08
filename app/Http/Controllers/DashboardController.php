<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Conversion;
use App\Models\Share;
use App\Models\Tenant;
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
        
        // Handle super-admin case (no tenant)
        if ($user->isSuperAdmin()) {
            return $this->superAdminDashboard($user);
        }
        
        // Regular user with tenant
        $tenant = $user->tenant;
        
        if (!$tenant) {
            // User has no tenant assigned - redirect to error or assign default
            return redirect()->route('home')->with('error', 'Aucun tenant assigné à votre compte.');
        }
        
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
    
    /**
     * Display super-admin dashboard
     */
    private function superAdminDashboard($user)
    {
        // Get global statistics
        $stats = [
            'tenants_count' => Tenant::count(),
            'total_users' => \App\Models\User::count(),
            'total_documents' => Document::count(),
            'total_conversions' => Conversion::count(),
            'active_tenants' => Tenant::where('is_active', true)->count(),
            'suspended_tenants' => Tenant::where('is_active', false)->count(),
        ];
        
        // Get recent tenants
        $recentTenants = Tenant::orderBy('created_at', 'desc')
            ->take(5)
            ->withCount(['users', 'documents'])
            ->get()
            ->map(function ($tenant) {
                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'subscription_plan' => $tenant->subscription_plan,
                    'users_count' => $tenant->users_count,
                    'documents_count' => $tenant->documents_count,
                    'is_active' => $tenant->is_active,
                    'created_at' => $tenant->created_at,
                ];
            });
        
        // Get system storage usage
        $totalStorageUsed = Document::sum('size');
        $totalStorageLimit = Tenant::sum('max_storage_gb') * 1024 * 1024 * 1024;
        
        $storageUsage = [
            'used' => $totalStorageUsed,
            'limit' => $totalStorageLimit,
        ];
        
        // Get recent activity across all tenants
        $recentActivity = \App\Models\ActivityLog::orderBy('created_at', 'desc')
            ->take(10)
            ->with(['causer', 'tenant'])
            ->get()
            ->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'user' => $activity->getCauserName(),
                    'tenant' => $activity->tenant ? $activity->tenant->name : 'Global',
                    'action' => $activity->description ?? $activity->event ?? 'Unknown',
                    'subject_type' => $activity->subject_type,
                    'created_at' => $activity->created_at,
                ];
            });
        
        return Inertia::render('SuperAdminDashboard', [
            'stats' => $stats,
            'recentTenants' => $recentTenants,
            'storage' => $storageUsage,
            'recentActivity' => $recentActivity,
        ]);
    }
}