<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantPermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Validation\Rules\Password;

class TenantController extends Controller
{
    protected TenantPermissionService $permissionService;

    public function __construct(TenantPermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
        
        // Only super-admin can access tenant management
        $this->middleware('super.admin');
    }

    /**
     * Display a listing of tenants
     */
    public function index(Request $request)
    {
        $tenants = Tenant::query()
            ->withCount(['users', 'documents'])
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('domain', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            })
            ->when($request->plan, function ($query, $plan) {
                $query->where('subscription_plan', $plan);
            })
            ->when($request->status, function ($query, $status) {
                $query->where('is_active', $status === 'active');
            })
            ->orderBy($request->sort_by ?? 'created_at', $request->sort_order ?? 'desc')
            ->paginate($request->per_page ?? 15)
            ->withQueryString();

        return Inertia::render('Tenants/Index', [
            'tenants' => $tenants,
            'filters' => $request->only(['search', 'plan', 'status', 'sort_by', 'sort_order']),
            'plans' => ['basic', 'professional', 'enterprise'],
        ]);
    }

    /**
     * Show the form for creating a new tenant
     */
    public function create()
    {
        return Inertia::render('Tenants/Create', [
            'plans' => $this->getPlansWithDetails(),
        ]);
    }

    /**
     * Store a newly created tenant
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:255|unique:tenants',
            'subscription_plan' => 'required|in:basic,professional,enterprise',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => ['required', Password::defaults()],
        ]);

        try {
            // Create tenant
            $tenant = Tenant::create([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']),
                'domain' => $validated['domain'],
                'subscription_plan' => $validated['subscription_plan'],
                'settings' => $this->getDefaultSettings($validated['subscription_plan']),
                'features' => $this->getFeaturesByPlan($validated['subscription_plan']),
                'max_storage_gb' => $this->getStorageByPlan($validated['subscription_plan']),
                'max_users' => $this->getUsersByPlan($validated['subscription_plan']),
                'max_file_size_mb' => $this->getFileSizeByPlan($validated['subscription_plan']),
                'is_active' => true,
            ]);

            // Create roles and permissions for tenant
            $this->permissionService->createTenantRolesAndPermissions($tenant);

            // Create admin user
            $adminUser = User::create([
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'password' => Hash::make($validated['admin_password']),
                'tenant_id' => $tenant->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            // Assign tenant-admin role
            $this->permissionService->assignRoleToUser($adminUser, 'tenant-admin', $tenant->id);

            return redirect()->route('tenants.index')
                ->with('success', "Tenant '{$tenant->name}' créé avec succès");
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Erreur lors de la création du tenant: ' . $e->getMessage()]);
        }
    }

    /**
     * Display the specified tenant
     */
    public function show(Tenant $tenant)
    {
        $tenant->load(['users.roles', 'documents']);
        
        // Get tenant statistics
        $stats = [
            'storage_used' => $tenant->documents()->sum('size') / (1024 * 1024 * 1024), // Convert to GB
            'users_count' => $tenant->users()->count(),
            'documents_count' => $tenant->documents()->count(),
            'conversions_count' => $tenant->conversions()->count(),
            'last_activity' => $tenant->activityLogs()->latest()->first()?->created_at,
        ];

        return Inertia::render('Tenants/Show', [
            'tenant' => $tenant,
            'stats' => $stats,
            'plans' => $this->getPlansWithDetails(),
        ]);
    }

    /**
     * Show the form for editing the tenant
     */
    public function edit(Tenant $tenant)
    {
        return Inertia::render('Tenants/Edit', [
            'tenant' => $tenant,
            'plans' => $this->getPlansWithDetails(),
        ]);
    }

    /**
     * Update the specified tenant
     */
    public function update(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:255|unique:tenants,domain,' . $tenant->id,
            'subscription_plan' => 'required|in:basic,professional,enterprise',
            'is_active' => 'required|boolean',
            'max_storage_gb' => 'nullable|integer|min:1',
            'max_users' => 'nullable|integer|min:1',
            'max_file_size_mb' => 'nullable|integer|min:1',
        ]);

        // Update slug if name changed
        if ($tenant->name !== $validated['name']) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        // Update features and settings if plan changed
        if ($tenant->subscription_plan !== $validated['subscription_plan']) {
            $validated['features'] = $this->getFeaturesByPlan($validated['subscription_plan']);
            $validated['settings'] = array_merge(
                $tenant->settings ?? [],
                $this->getDefaultSettings($validated['subscription_plan'])
            );
            
            // Use custom values or plan defaults
            $validated['max_storage_gb'] = $validated['max_storage_gb'] ?? $this->getStorageByPlan($validated['subscription_plan']);
            $validated['max_users'] = $validated['max_users'] ?? $this->getUsersByPlan($validated['subscription_plan']);
            $validated['max_file_size_mb'] = $validated['max_file_size_mb'] ?? $this->getFileSizeByPlan($validated['subscription_plan']);
        }

        $tenant->update($validated);

        return redirect()->route('tenants.show', $tenant)
            ->with('success', 'Tenant mis à jour avec succès');
    }

    /**
     * Remove the specified tenant
     */
    public function destroy(Tenant $tenant)
    {
        try {
            // Check if tenant has data
            if ($tenant->users()->count() > 0 || $tenant->documents()->count() > 0) {
                return back()->withErrors(['error' => 'Impossible de supprimer un tenant avec des données. Veuillez d\'abord supprimer tous les utilisateurs et documents.']);
            }

            $tenant->delete();

            return redirect()->route('tenants.index')
                ->with('success', "Tenant '{$tenant->name}' supprimé avec succès");
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Erreur lors de la suppression: ' . $e->getMessage()]);
        }
    }

    /**
     * Suspend or activate a tenant
     */
    public function toggleStatus(Tenant $tenant)
    {
        $tenant->update(['is_active' => !$tenant->is_active]);
        
        $status = $tenant->is_active ? 'activé' : 'suspendu';
        
        return back()->with('success', "Tenant '{$tenant->name}' {$status} avec succès");
    }

    /**
     * Get plans with detailed information
     */
    private function getPlansWithDetails()
    {
        return [
            'basic' => [
                'name' => 'Basic',
                'price' => 9.99,
                'storage' => 10,
                'users' => 5,
                'file_size' => 100,
                'features' => ['basic_editor', 'basic_conversions', 'basic_sharing', 'email_support'],
            ],
            'professional' => [
                'name' => 'Professional',
                'price' => 29.99,
                'storage' => 100,
                'users' => 50,
                'file_size' => 200,
                'features' => ['api_access', 'priority_support', 'custom_domain', 'audit_logs', 'digital_signatures', 'ocr', 'redaction', 'collaboration', 'advanced_editor'],
            ],
            'enterprise' => [
                'name' => 'Enterprise',
                'price' => 99.99,
                'storage' => 1000,
                'users' => 999999,
                'file_size' => 500,
                'features' => ['unlimited_users', 'unlimited_storage', 'api_access', 'white_label', 'priority_support', 'advanced_security', 'custom_domain', 'sso', 'audit_logs', 'digital_signatures', 'ocr', 'redaction', 'collaboration', 'advanced_editor', 'batch_processing', 'webhooks', 'custom_integrations'],
            ],
        ];
    }

    /**
     * Get default settings based on plan
     */
    private function getDefaultSettings(string $plan): array
    {
        return [
            'theme' => 'light',
            'language' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'time_format' => 'H:i',
            'allow_registration' => false,
            'require_email_verification' => true,
            'require_2fa' => $plan === 'enterprise',
            'session_lifetime' => $plan === 'enterprise' ? 60 : 120,
            'password_expires_days' => $plan === 'enterprise' ? 90 : null,
        ];
    }

    /**
     * Get features based on plan
     */
    private function getFeaturesByPlan(string $plan): array
    {
        return match($plan) {
            'enterprise' => [
                'unlimited_users', 'unlimited_storage', 'api_access', 'white_label',
                'priority_support', 'advanced_security', 'custom_domain', 'sso',
                'audit_logs', 'digital_signatures', 'ocr', 'redaction',
                'collaboration', 'advanced_editor', 'batch_processing', 'webhooks',
                'custom_integrations', 'dedicated_support', 'sla_guarantee'
            ],
            'professional' => [
                'api_access', 'priority_support', 'custom_domain', 'audit_logs',
                'digital_signatures', 'ocr', 'redaction', 'collaboration',
                'advanced_editor', 'batch_processing', 'email_support'
            ],
            'basic' => [
                'basic_editor', 'basic_conversions', 'basic_sharing',
                'email_support', 'standard_security'
            ],
            default => ['basic_conversions']
        };
    }

    /**
     * Get storage limit based on plan
     */
    private function getStorageByPlan(string $plan): int
    {
        return match($plan) {
            'enterprise' => 1000,
            'professional' => 100,
            'basic' => 10,
            default => 5
        };
    }

    /**
     * Get user limit based on plan
     */
    private function getUsersByPlan(string $plan): int
    {
        return match($plan) {
            'enterprise' => 999999,
            'professional' => 50,
            'basic' => 5,
            default => 3
        };
    }

    /**
     * Get file size limit based on plan
     */
    private function getFileSizeByPlan(string $plan): int
    {
        return match($plan) {
            'enterprise' => 500,
            'professional' => 200,
            'basic' => 100,
            default => 50
        };
    }
}