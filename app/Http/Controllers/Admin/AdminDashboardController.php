<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\User;
use App\Models\Conversion;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class AdminDashboardController extends Controller
{
    /**
     * Display admin dashboard
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $tenant = $user->tenant;
        
        if (!$tenant) {
            return redirect()->route('dashboard')
                ->with('error', 'Aucun tenant associé');
        }
        
        // Statistics
        $stats = [
            'total_users' => User::where('tenant_id', $tenant->id)->count(),
            'total_documents' => Document::where('tenant_id', $tenant->id)->count(),
            'total_conversions' => Conversion::where('tenant_id', $tenant->id)->count(),
            'storage_used' => $tenant->getStorageUsage(),
            'storage_limit' => $tenant->max_storage_gb * 1024 * 1024 * 1024,
            'active_users_today' => User::where('tenant_id', $tenant->id)
                ->whereDate('last_login_at', today())
                ->count(),
        ];
        
        // Recent activity
        $recentActivity = ActivityLog::where('tenant_id', $tenant->id)
            ->with('user')
            ->latest()
            ->limit(10)
            ->get();
        
        // Top users by storage
        $topUsers = User::where('tenant_id', $tenant->id)
            ->withCount('documents')
            ->withSum('documents', 'size')
            ->orderByDesc('documents_sum_size')
            ->limit(5)
            ->get();
        
        return Inertia::render('Admin/Dashboard', [
            'stats' => $stats,
            'recentActivity' => $recentActivity,
            'topUsers' => $topUsers,
            'tenant' => $tenant,
        ]);
    }
    
    /**
     * Display tenant settings
     */
    public function settings(Request $request)
    {
        $user = auth()->user();
        $tenant = $user->tenant;
        
        if (!$tenant) {
            return redirect()->route('dashboard')
                ->with('error', 'Aucun tenant associé');
        }
        
        return Inertia::render('Admin/Settings', [
            'tenant' => $tenant,
            'features' => $tenant->features ?? [],
            'settings' => $tenant->settings ?? [],
        ]);
    }
    
    /**
     * Update tenant settings
     */
    public function updateSettings(Request $request)
    {
        $user = auth()->user();
        $tenant = $user->tenant;
        
        if (!$tenant) {
            return back()->with('error', 'Aucun tenant associé');
        }
        
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'max_storage_gb' => 'nullable|integer|min:1',
            'max_users' => 'nullable|integer|min:1',
            'max_file_size_mb' => 'nullable|integer|min:1',
            'features' => 'nullable|array',
            'settings' => 'nullable|array',
        ]);
        
        if (isset($validated['name'])) {
            $tenant->name = $validated['name'];
        }
        
        if (isset($validated['max_storage_gb']) && $user->hasRole('super-admin')) {
            $tenant->max_storage_gb = $validated['max_storage_gb'];
        }
        
        if (isset($validated['max_users']) && $user->hasRole('super-admin')) {
            $tenant->max_users = $validated['max_users'];
        }
        
        if (isset($validated['max_file_size_mb'])) {
            $tenant->max_file_size_mb = $validated['max_file_size_mb'];
        }
        
        if (isset($validated['features'])) {
            $tenant->features = $validated['features'];
        }
        
        if (isset($validated['settings'])) {
            $tenant->settings = array_merge($tenant->settings ?? [], $validated['settings']);
        }
        
        $tenant->save();
        
        return back()->with('success', 'Paramètres mis à jour avec succès');
    }
    
    /**
     * Display activity logs
     */
    public function activity(Request $request)
    {
        $user = auth()->user();
        $tenant = $user->tenant;
        
        if (!$tenant) {
            return redirect()->route('dashboard')
                ->with('error', 'Aucun tenant associé');
        }
        
        $query = ActivityLog::where('tenant_id', $tenant->id)
            ->with(['user']);
        
        // Filter by user
        if ($request->user_id) {
            $query->where('user_id', $request->user_id);
        }
        
        // Filter by action
        if ($request->action) {
            $query->where('action', $request->action);
        }
        
        // Filter by date range
        if ($request->from_date) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        
        if ($request->to_date) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }
        
        $activities = $query->orderBy('created_at', 'desc')
            ->paginate(50);
        
        return Inertia::render('Admin/Activity', [
            'activities' => $activities,
            'filters' => $request->only(['user_id', 'action', 'from_date', 'to_date']),
            'users' => User::where('tenant_id', $tenant->id)
                ->select('id', 'name', 'email')
                ->get(),
        ]);
    }
    
    /**
     * Display storage management
     */
    public function storage(Request $request)
    {
        $user = auth()->user();
        $tenant = $user->tenant;
        
        if (!$tenant) {
            return redirect()->route('dashboard')
                ->with('error', 'Aucun tenant associé');
        }
        
        // Storage statistics
        $storageStats = [
            'total_used' => $tenant->getStorageUsage(),
            'total_limit' => $tenant->max_storage_gb * 1024 * 1024 * 1024,
            'percentage_used' => ($tenant->getStorageUsage() / ($tenant->max_storage_gb * 1024 * 1024 * 1024)) * 100,
        ];
        
        // Storage by user
        $userStorage = User::where('tenant_id', $tenant->id)
            ->withSum('documents', 'size')
            ->orderByDesc('documents_sum_size')
            ->get();
        
        // Storage by file type
        $typeStorage = Document::where('tenant_id', $tenant->id)
            ->select('extension', DB::raw('COUNT(*) as count'), DB::raw('SUM(size) as total_size'))
            ->groupBy('extension')
            ->orderByDesc('total_size')
            ->get();
        
        // Large files
        $largeFiles = Document::where('tenant_id', $tenant->id)
            ->with('user')
            ->orderByDesc('size')
            ->limit(20)
            ->get();
        
        return Inertia::render('Admin/Storage', [
            'storageStats' => $storageStats,
            'userStorage' => $userStorage,
            'typeStorage' => $typeStorage,
            'largeFiles' => $largeFiles,
        ]);
    }
    
    /**
     * Cleanup unused storage
     */
    public function cleanupStorage(Request $request)
    {
        $user = auth()->user();
        $tenant = $user->tenant;
        
        if (!$tenant) {
            return back()->with('error', 'Aucun tenant associé');
        }
        
        // Delete orphaned files
        $deletedCount = 0;
        
        // Find documents without physical files
        $documents = Document::where('tenant_id', $tenant->id)->get();
        
        foreach ($documents as $document) {
            if (!Storage::exists($document->stored_name)) {
                $document->delete();
                $deletedCount++;
            }
        }
        
        // Clean temporary files
        $tempPath = storage_path('app/temp/' . $tenant->id);
        if (is_dir($tempPath)) {
            $files = glob($tempPath . '/*');
            foreach ($files as $file) {
                if (filemtime($file) < time() - 86400) { // Older than 24 hours
                    unlink($file);
                    $deletedCount++;
                }
            }
        }
        
        return back()->with('success', "Nettoyage terminé. {$deletedCount} fichiers supprimés.");
    }
}