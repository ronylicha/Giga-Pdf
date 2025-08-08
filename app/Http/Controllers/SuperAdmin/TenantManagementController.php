<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class TenantManagementController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:super_admin']);
    }

    /**
     * Display listing of all tenants
     */
    public function index(Request $request)
    {
        $query = Tenant::query();
        
        // Search
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('domain', 'like', "%{$request->search}%")
                  ->orWhere('slug', 'like', "%{$request->search}%");
            });
        }
        
        // Filter by plan
        if ($request->plan) {
            $query->where('subscription_plan', $request->plan);
        }
        
        // Filter by status
        if ($request->status === 'active') {
            $query->where(function ($q) {
                $q->whereNull('subscription_expires_at')
                  ->orWhere('subscription_expires_at', '>', now());
            });
        } elseif ($request->status === 'expired') {
            $query->where('subscription_expires_at', '<=', now());
        }
        
        // Sort
        $sortField = $request->sort_by ?? 'created_at';
        $sortDirection = $request->sort_dir ?? 'desc';
        $query->orderBy($sortField, $sortDirection);
        
        $tenants = $query->withCount(['users', 'documents'])
            ->paginate(15)
            ->withQueryString();
        
        // Calculate global statistics
        $stats = [
            'total_tenants' => Tenant::count(),
            'active_tenants' => Tenant::where(function ($q) {
                $q->whereNull('subscription_expires_at')
                  ->orWhere('subscription_expires_at', '>', now());
            })->count(),
            'total_users' => User::count(),
            'total_documents' => DB::table('documents')->count(),
            'total_storage' => DB::table('documents')->sum('size'),
        ];
        
        return Inertia::render('SuperAdmin/Tenants/Index', [
            'tenants' => $tenants,
            'stats' => $stats,
            'filters' => $request->only(['search', 'plan', 'status', 'sort_by', 'sort_dir']),
            'plans' => [
                'free' => 'Gratuit',
                'starter' => 'Starter',
                'professional' => 'Professionnel',
                'enterprise' => 'Entreprise',
            ],
        ]);
    }

    /**
     * Show tenant details
     */
    public function show(Tenant $tenant)
    {
        $tenant->load(['users' => function ($query) {
            $query->withCount(['documents', 'conversions']);
        }]);
        
        // Get tenant statistics
        $stats = [
            'users_count' => $tenant->users()->count(),
            'documents_count' => $tenant->documents()->count(),
            'conversions_count' => DB::table('conversions')
                ->where('tenant_id', $tenant->id)
                ->count(),
            'storage_used' => $tenant->getStorageUsed(),
            'storage_limit' => $tenant->max_storage_gb * 1024 * 1024 * 1024,
            'last_activity' => DB::table('activity_log')
                ->where('tenant_id', $tenant->id)
                ->latest('created_at')
                ->first()?->created_at,
        ];
        
        // Get recent activity
        $activities = DB::table('activity_log')
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->limit(20)
            ->get();
        
        // Get subscription history
        $subscriptionHistory = DB::table('subscription_history')
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->get();
        
        return Inertia::render('SuperAdmin/Tenants/Show', [
            'tenant' => $tenant,
            'stats' => $stats,
            'activities' => $activities,
            'subscriptionHistory' => $subscriptionHistory,
        ]);
    }

    /**
     * Show form to create new tenant
     */
    public function create()
    {
        return Inertia::render('SuperAdmin/Tenants/Create', [
            'plans' => [
                'free' => [
                    'name' => 'Gratuit',
                    'max_users' => 3,
                    'max_storage_gb' => 1,
                    'max_file_size_mb' => 10,
                ],
                'starter' => [
                    'name' => 'Starter',
                    'max_users' => 10,
                    'max_storage_gb' => 10,
                    'max_file_size_mb' => 50,
                ],
                'professional' => [
                    'name' => 'Professionnel',
                    'max_users' => 50,
                    'max_storage_gb' => 100,
                    'max_file_size_mb' => 100,
                ],
                'enterprise' => [
                    'name' => 'Entreprise',
                    'max_users' => -1, // unlimited
                    'max_storage_gb' => 1000,
                    'max_file_size_mb' => 500,
                ],
            ],
        ]);
    }

    /**
     * Store new tenant
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:tenants,slug|regex:/^[a-z0-9-]+$/',
            'domain' => 'nullable|string|max:255|unique:tenants,domain',
            'subscription_plan' => 'required|in:free,starter,professional,enterprise',
            'max_users' => 'required|integer|min:-1',
            'max_storage_gb' => 'required|numeric|min:0.1',
            'max_file_size_mb' => 'required|numeric|min:1',
            'subscription_expires_at' => 'nullable|date|after:today',
            // Admin user details
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => ['required', Password::defaults()],
        ]);
        
        DB::beginTransaction();
        
        try {
            // Create tenant
            $tenant = Tenant::create([
                'name' => $validated['name'],
                'slug' => $validated['slug'],
                'domain' => $validated['domain'],
                'subscription_plan' => $validated['subscription_plan'],
                'max_users' => $validated['max_users'],
                'max_storage_gb' => $validated['max_storage_gb'],
                'max_file_size_mb' => $validated['max_file_size_mb'],
                'subscription_expires_at' => $validated['subscription_expires_at'],
                'settings' => [
                    'allow_public_shares' => true,
                    'require_2fa' => false,
                    'default_language' => 'fr',
                ],
                'features' => $this->getFeaturesForPlan($validated['subscription_plan']),
            ]);
            
            // Create admin user for the tenant
            $adminUser = User::create([
                'tenant_id' => $tenant->id,
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'password' => Hash::make($validated['admin_password']),
                'email_verified_at' => now(),
            ]);
            
            // Assign tenant_admin role
            $adminUser->assignRole('tenant_admin');
            
            // Create default directories structure
            $this->createDefaultDirectories($tenant);
            
            DB::commit();
            
            return redirect()->route('super-admin.tenants.show', $tenant)
                ->with('success', "Tenant '{$tenant->name}' créé avec succès.");
                
        } catch (\Exception $e) {
            DB::rollBack();
            
            return back()->withInput()
                ->with('error', 'Erreur lors de la création du tenant: ' . $e->getMessage());
        }
    }

    /**
     * Show edit form
     */
    public function edit(Tenant $tenant)
    {
        return Inertia::render('SuperAdmin/Tenants/Edit', [
            'tenant' => $tenant,
            'plans' => [
                'free' => [
                    'name' => 'Gratuit',
                    'max_users' => 3,
                    'max_storage_gb' => 1,
                    'max_file_size_mb' => 10,
                ],
                'starter' => [
                    'name' => 'Starter',
                    'max_users' => 10,
                    'max_storage_gb' => 10,
                    'max_file_size_mb' => 50,
                ],
                'professional' => [
                    'name' => 'Professionnel',
                    'max_users' => 50,
                    'max_storage_gb' => 100,
                    'max_file_size_mb' => 100,
                ],
                'enterprise' => [
                    'name' => 'Entreprise',
                    'max_users' => -1,
                    'max_storage_gb' => 1000,
                    'max_file_size_mb' => 500,
                ],
            ],
        ]);
    }

    /**
     * Update tenant
     */
    public function update(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:255|unique:tenants,domain,' . $tenant->id,
            'subscription_plan' => 'required|in:free,starter,professional,enterprise',
            'max_users' => 'required|integer|min:-1',
            'max_storage_gb' => 'required|numeric|min:0.1',
            'max_file_size_mb' => 'required|numeric|min:1',
            'subscription_expires_at' => 'nullable|date',
            'is_active' => 'boolean',
        ]);
        
        // Check if reducing limits would violate current usage
        if ($validated['max_users'] > 0 && $tenant->users()->count() > $validated['max_users']) {
            return back()->with('error', 'Impossible de réduire la limite d\'utilisateurs en dessous du nombre actuel.');
        }
        
        $currentStorageGB = $tenant->getStorageUsed() / (1024 * 1024 * 1024);
        if ($currentStorageGB > $validated['max_storage_gb']) {
            return back()->with('error', 'Impossible de réduire la limite de stockage en dessous de l\'utilisation actuelle.');
        }
        
        $tenant->update([
            'name' => $validated['name'],
            'domain' => $validated['domain'],
            'subscription_plan' => $validated['subscription_plan'],
            'max_users' => $validated['max_users'],
            'max_storage_gb' => $validated['max_storage_gb'],
            'max_file_size_mb' => $validated['max_file_size_mb'],
            'subscription_expires_at' => $validated['subscription_expires_at'],
            'features' => $this->getFeaturesForPlan($validated['subscription_plan']),
        ]);
        
        // Handle activation/deactivation
        if (isset($validated['is_active'])) {
            if (!$validated['is_active']) {
                // Deactivate all users of this tenant
                $tenant->users()->update(['is_active' => false]);
            }
        }
        
        return redirect()->route('super-admin.tenants.show', $tenant)
            ->with('success', 'Tenant mis à jour avec succès.');
    }

    /**
     * Delete tenant
     */
    public function destroy(Tenant $tenant)
    {
        // Check if tenant has data
        if ($tenant->documents()->count() > 0) {
            return back()->with('error', 'Impossible de supprimer un tenant avec des documents. Archivez d\'abord les documents.');
        }
        
        DB::beginTransaction();
        
        try {
            // Delete all tenant data
            $tenant->users()->delete();
            $tenant->invitations()->delete();
            
            // Delete storage directories
            $this->deleteStorageDirectories($tenant);
            
            // Delete tenant
            $tenant->delete();
            
            DB::commit();
            
            return redirect()->route('super-admin.tenants.index')
                ->with('success', 'Tenant supprimé avec succès.');
                
        } catch (\Exception $e) {
            DB::rollBack();
            
            return back()->with('error', 'Erreur lors de la suppression: ' . $e->getMessage());
        }
    }

    /**
     * Suspend tenant
     */
    public function suspend(Tenant $tenant)
    {
        $tenant->update([
            'is_suspended' => true,
            'suspended_at' => now(),
            'suspended_reason' => request('reason'),
        ]);
        
        // Deactivate all users
        $tenant->users()->update(['is_active' => false]);
        
        return back()->with('success', 'Tenant suspendu.');
    }

    /**
     * Reactivate tenant
     */
    public function reactivate(Tenant $tenant)
    {
        $tenant->update([
            'is_suspended' => false,
            'suspended_at' => null,
            'suspended_reason' => null,
        ]);
        
        // Reactivate users
        $tenant->users()->update(['is_active' => true]);
        
        return back()->with('success', 'Tenant réactivé.');
    }

    /**
     * Export tenant data
     */
    public function export(Tenant $tenant)
    {
        // Generate export file
        $exportData = [
            'tenant' => $tenant->toArray(),
            'users' => $tenant->users()->get()->toArray(),
            'documents' => $tenant->documents()->get()->toArray(),
            'statistics' => [
                'total_conversions' => DB::table('conversions')
                    ->where('tenant_id', $tenant->id)
                    ->count(),
                'storage_used' => $tenant->getStorageUsed(),
            ],
        ];
        
        $json = json_encode($exportData, JSON_PRETTY_PRINT);
        
        return response($json, 200)
            ->header('Content-Type', 'application/json')
            ->header('Content-Disposition', 'attachment; filename="tenant-' . $tenant->slug . '-export.json"');
    }

    /**
     * Get features for subscription plan
     */
    protected function getFeaturesForPlan(string $plan): array
    {
        $features = [
            'free' => [
                'pdf_conversion' => true,
                'pdf_editing' => false,
                'ocr' => false,
                'api_access' => false,
                'priority_processing' => false,
                'custom_branding' => false,
                'advanced_security' => false,
                'bulk_operations' => false,
            ],
            'starter' => [
                'pdf_conversion' => true,
                'pdf_editing' => true,
                'ocr' => true,
                'api_access' => false,
                'priority_processing' => false,
                'custom_branding' => false,
                'advanced_security' => true,
                'bulk_operations' => true,
            ],
            'professional' => [
                'pdf_conversion' => true,
                'pdf_editing' => true,
                'ocr' => true,
                'api_access' => true,
                'priority_processing' => true,
                'custom_branding' => true,
                'advanced_security' => true,
                'bulk_operations' => true,
            ],
            'enterprise' => [
                'pdf_conversion' => true,
                'pdf_editing' => true,
                'ocr' => true,
                'api_access' => true,
                'priority_processing' => true,
                'custom_branding' => true,
                'advanced_security' => true,
                'bulk_operations' => true,
                'sso' => true,
                'audit_logs' => true,
                'dedicated_support' => true,
            ],
        ];
        
        return $features[$plan] ?? $features['free'];
    }

    /**
     * Create default directories for tenant
     */
    protected function createDefaultDirectories(Tenant $tenant): void
    {
        $directories = [
            "documents/{$tenant->id}",
            "thumbnails/{$tenant->id}",
            "temp/{$tenant->id}",
            "exports/{$tenant->id}",
        ];
        
        foreach ($directories as $dir) {
            \Storage::makeDirectory($dir);
        }
    }

    /**
     * Delete storage directories for tenant
     */
    protected function deleteStorageDirectories(Tenant $tenant): void
    {
        $directories = [
            "documents/{$tenant->id}",
            "thumbnails/{$tenant->id}",
            "temp/{$tenant->id}",
            "exports/{$tenant->id}",
        ];
        
        foreach ($directories as $dir) {
            \Storage::deleteDirectory($dir);
        }
    }
}
