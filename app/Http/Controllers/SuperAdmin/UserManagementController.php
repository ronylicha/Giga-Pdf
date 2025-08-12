<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class UserManagementController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:super_admin']);
    }

    /**
     * Display listing of all users across all tenants
     */
    public function index(Request $request)
    {
        $query = User::with(['tenant', 'roles']);

        // Search
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
                  ->orWhereHas('tenant', function ($tq) use ($request) {
                      $tq->where('name', 'like', "%{$request->search}%");
                  });
            });
        }

        // Filter by tenant
        if ($request->tenant_id) {
            $query->where('tenant_id', $request->tenant_id);
        }

        // Filter by role
        if ($request->role) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }

        // Filter by status
        if ($request->status === 'active') {
            $query->whereNotNull('email_verified_at')
                  ->where('is_active', true);
        } elseif ($request->status === 'inactive') {
            $query->where(function ($q) {
                $q->whereNull('email_verified_at')
                  ->orWhere('is_active', false);
            });
        } elseif ($request->status === '2fa_enabled') {
            $query->whereNotNull('two_factor_secret');
        }

        // Sort
        $sortField = $request->sort_by ?? 'created_at';
        $sortDirection = $request->sort_dir ?? 'desc';

        if ($sortField === 'tenant') {
            $query->leftJoin('tenants', 'users.tenant_id', '=', 'tenants.id')
                  ->orderBy('tenants.name', $sortDirection)
                  ->select('users.*');
        } else {
            $query->orderBy($sortField, $sortDirection);
        }

        $users = $query->withCount(['documents', 'conversions'])
                       ->paginate(20)
                       ->withQueryString();

        // Get statistics
        $stats = [
            'total_users' => User::count(),
            'active_users' => User::whereNotNull('email_verified_at')
                                 ->where('is_active', true)
                                 ->count(),
            'users_with_2fa' => User::whereNotNull('two_factor_secret')->count(),
            'super_admins' => User::whereHas('roles', function ($q) {
                $q->where('name', 'super_admin');
            })->count(),
            'total_documents' => DB::table('documents')->count(),
            'total_conversions' => DB::table('conversions')->count(),
        ];

        // Get tenants for filter
        $tenants = Tenant::orderBy('name')->get(['id', 'name']);

        return Inertia::render('SuperAdmin/Users/Index', [
            'users' => $users,
            'stats' => $stats,
            'tenants' => $tenants,
            'filters' => $request->only(['search', 'tenant_id', 'role', 'status', 'sort_by', 'sort_dir']),
            'roles' => [
                'user' => 'Utilisateur',
                'editor' => 'Éditeur',
                'manager' => 'Manager',
                'tenant_admin' => 'Admin Tenant',
                'super_admin' => 'Super Admin',
            ],
        ]);
    }

    /**
     * Show user details
     */
    public function show(User $user)
    {
        $user->load(['tenant', 'roles', 'documents', 'conversions']);

        // Get user activity
        $activities = DB::table('activity_log')
            ->where('causer_id', $user->id)
            ->where('causer_type', User::class)
            ->latest('created_at')
            ->limit(50)
            ->get();

        // Get user statistics
        $stats = [
            'documents_count' => $user->documents()->count(),
            'conversions_count' => $user->conversions()->count(),
            'shares_count' => DB::table('shares')
                ->where('shared_by', $user->id)
                ->count(),
            'storage_used' => $user->documents()->sum('size'),
            'last_login' => $user->last_login_at,
            'login_count' => DB::table('activity_log')
                ->where('causer_id', $user->id)
                ->where('event', 'login')
                ->count(),
        ];

        // Get related invitations
        $invitations = Invitation::where('email', $user->email)
            ->orWhere('invited_by', $user->id)
            ->with(['tenant', 'invitedBy'])
            ->latest()
            ->get();

        return Inertia::render('SuperAdmin/Users/Show', [
            'user' => $user,
            'activities' => $activities,
            'stats' => $stats,
            'invitations' => $invitations,
        ]);
    }

    /**
     * Show form to create new user
     */
    public function create()
    {
        $tenants = Tenant::orderBy('name')->get(['id', 'name']);

        return Inertia::render('SuperAdmin/Users/Create', [
            'tenants' => $tenants,
            'roles' => [
                'user' => 'Utilisateur',
                'editor' => 'Éditeur',
                'manager' => 'Manager',
                'tenant_admin' => 'Admin Tenant',
                'super_admin' => 'Super Admin',
            ],
        ]);
    }

    /**
     * Store new user
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', Password::defaults()],
            'role' => 'required|in:user,editor,manager,tenant_admin,super_admin',
            'send_welcome_email' => 'boolean',
            'email_verified' => 'boolean',
            'require_2fa' => 'boolean',
        ]);

        $tenant = Tenant::findOrFail($validated['tenant_id']);

        // Check tenant user limit
        if ($tenant->max_users > 0 && $tenant->users()->count() >= $tenant->max_users) {
            return back()->with('error', 'Ce tenant a atteint sa limite d\'utilisateurs.');
        }

        DB::beginTransaction();

        try {
            $user = User::create([
                'tenant_id' => $validated['tenant_id'],
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'email_verified_at' => $validated['email_verified'] ?? false ? now() : null,
                'is_active' => true,
            ]);

            // Assign role
            $user->assignRole($validated['role']);

            // Setup 2FA if required
            if ($validated['require_2fa'] ?? false) {
                $user->update([
                    'two_factor_required' => true,
                ]);
            }

            // Send welcome email if requested
            if ($validated['send_welcome_email'] ?? false) {
                // Mail::to($user->email)->send(new WelcomeEmail($user, $validated['password']));
            }

            DB::commit();

            return redirect()->route('super-admin.users.show', $user)
                ->with('success', 'Utilisateur créé avec succès.');

        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()
                ->with('error', 'Erreur lors de la création: ' . $e->getMessage());
        }
    }

    /**
     * Show edit form
     */
    public function edit(User $user)
    {
        $user->load(['tenant', 'roles']);
        $tenants = Tenant::orderBy('name')->get(['id', 'name']);

        return Inertia::render('SuperAdmin/Users/Edit', [
            'user' => $user,
            'tenants' => $tenants,
            'roles' => [
                'user' => 'Utilisateur',
                'editor' => 'Éditeur',
                'manager' => 'Manager',
                'tenant_admin' => 'Admin Tenant',
                'super_admin' => 'Super Admin',
            ],
        ]);
    }

    /**
     * Update user
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role' => 'required|in:user,editor,manager,tenant_admin,super_admin',
            'is_active' => 'boolean',
            'email_verified' => 'boolean',
        ]);

        // Check if changing tenant
        if ($user->tenant_id !== $validated['tenant_id']) {
            $newTenant = Tenant::findOrFail($validated['tenant_id']);

            // Check new tenant user limit
            if ($newTenant->max_users > 0 && $newTenant->users()->count() >= $newTenant->max_users) {
                return back()->with('error', 'Le nouveau tenant a atteint sa limite d\'utilisateurs.');
            }
        }

        $user->update([
            'tenant_id' => $validated['tenant_id'],
            'name' => $validated['name'],
            'email' => $validated['email'],
            'is_active' => $validated['is_active'] ?? true,
            'email_verified_at' => $validated['email_verified'] ?? false
                ? ($user->email_verified_at ?? now())
                : null,
        ]);

        // Update role
        $user->syncRoles([$validated['role']]);

        // Deactivate sessions if user is deactivated
        if (! ($validated['is_active'] ?? true)) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();
        }

        return redirect()->route('super-admin.users.show', $user)
            ->with('success', 'Utilisateur mis à jour avec succès.');
    }

    /**
     * Reset user password
     */
    public function resetPassword(User $user)
    {
        $password = Str::random(12);

        $user->update([
            'password' => Hash::make($password),
            'password_changed_at' => now(),
        ]);

        // Invalidate sessions
        DB::table('sessions')->where('user_id', $user->id)->delete();

        // Send email with new password
        // Mail::to($user->email)->send(new PasswordReset($user, $password));

        return back()->with('success', 'Mot de passe réinitialisé. Nouveau mot de passe: ' . $password);
    }

    /**
     * Toggle 2FA for user
     */
    public function toggle2FA(User $user)
    {
        if ($user->two_factor_secret) {
            $user->update([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ]);
            $message = '2FA désactivée pour cet utilisateur.';
        } else {
            $user->update([
                'two_factor_required' => true,
            ]);
            $message = 'L\'utilisateur devra configurer 2FA à sa prochaine connexion.';
        }

        return back()->with('success', $message);
    }

    /**
     * Impersonate user
     */
    public function impersonate(User $user)
    {
        // Prevent impersonating other super admins
        if ($user->hasRole('super_admin')) {
            return back()->with('error', 'Impossible d\'emprunter l\'identité d\'un autre super admin.');
        }

        // Store original user ID in session
        session(['impersonator_id' => auth()->id()]);

        // Login as the target user
        auth()->login($user);

        return redirect()->route('dashboard')
            ->with('info', 'Vous empruntez maintenant l\'identité de ' . $user->name);
    }

    /**
     * Stop impersonation
     */
    public function stopImpersonation()
    {
        if (! session()->has('impersonator_id')) {
            return redirect()->route('dashboard');
        }

        $originalUser = User::findOrFail(session('impersonator_id'));
        session()->forget('impersonator_id');

        auth()->login($originalUser);

        return redirect()->route('super-admin.users.index')
            ->with('success', 'Retour à votre compte.');
    }

    /**
     * Delete user
     */
    public function destroy(User $user)
    {
        // Prevent deleting super admins
        if ($user->hasRole('super_admin')) {
            return back()->with('error', 'Impossible de supprimer un super admin.');
        }

        // Prevent self-deletion
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Vous ne pouvez pas supprimer votre propre compte.');
        }

        DB::beginTransaction();

        try {
            // Transfer or delete user's documents
            $tenant = $user->tenant;
            $replacement = $tenant->users()
                ->where('id', '!=', $user->id)
                ->whereHas('roles', function ($q) {
                    $q->where('name', 'tenant_admin');
                })
                ->first();

            if ($replacement) {
                $user->documents()->update(['user_id' => $replacement->id]);
                $user->conversions()->update(['user_id' => $replacement->id]);
            } else {
                // Delete documents and their files
                foreach ($user->documents as $document) {
                    \Storage::delete($document->stored_name);
                    if ($document->thumbnail_path) {
                        \Storage::delete($document->thumbnail_path);
                    }
                }
                $user->documents()->delete();
                $user->conversions()->delete();
            }

            // Delete user
            $user->delete();

            DB::commit();

            return redirect()->route('super-admin.users.index')
                ->with('success', 'Utilisateur supprimé avec succès.');

        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with('error', 'Erreur lors de la suppression: ' . $e->getMessage());
        }
    }

    /**
     * Export users data
     */
    public function export(Request $request)
    {
        $query = User::with(['tenant', 'roles']);

        // Apply filters (same as index)
        if ($request->tenant_id) {
            $query->where('tenant_id', $request->tenant_id);
        }

        if ($request->role) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }

        $users = $query->get();

        $exportData = $users->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'tenant' => $user->tenant->name,
                'role' => $user->roles->first()?->name,
                'is_active' => $user->is_active,
                'email_verified' => $user->email_verified_at !== null,
                '2fa_enabled' => $user->two_factor_secret !== null,
                'documents_count' => $user->documents()->count(),
                'storage_used_mb' => round($user->documents()->sum('size') / 1048576, 2),
                'created_at' => $user->created_at->format('Y-m-d H:i:s'),
                'last_login' => $user->last_login_at?->format('Y-m-d H:i:s'),
            ];
        });

        $csv = "ID,Name,Email,Tenant,Role,Active,Email Verified,2FA Enabled,Documents,Storage (MB),Created,Last Login\n";

        foreach ($exportData as $row) {
            $csv .= implode(',', array_map(function ($value) {
                return '"' . str_replace('"', '""', $value ?? '') . '"';
            }, $row)) . "\n";
        }

        return response($csv, 200)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="users-export-' . date('Y-m-d') . '.csv"');
    }

    /**
     * Bulk actions on users
     */
    public function bulkAction(Request $request)
    {
        $validated = $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
            'action' => 'required|in:activate,deactivate,delete,reset_password,enable_2fa,disable_2fa',
        ]);

        $users = User::whereIn('id', $validated['user_ids'])->get();
        $count = 0;

        foreach ($users as $user) {
            // Skip super admins for certain actions
            if ($user->hasRole('super_admin') && in_array($validated['action'], ['delete', 'deactivate'])) {
                continue;
            }

            switch ($validated['action']) {
                case 'activate':
                    $user->update(['is_active' => true]);
                    $count++;

                    break;

                case 'deactivate':
                    $user->update(['is_active' => false]);
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                    $count++;

                    break;

                case 'delete':
                    if ($user->id !== auth()->id()) {
                        $user->delete();
                        $count++;
                    }

                    break;

                case 'reset_password':
                    $password = Str::random(12);
                    $user->update(['password' => Hash::make($password)]);
                    // Send email...
                    $count++;

                    break;

                case 'enable_2fa':
                    $user->update(['two_factor_required' => true]);
                    $count++;

                    break;

                case 'disable_2fa':
                    $user->update([
                        'two_factor_secret' => null,
                        'two_factor_recovery_codes' => null,
                        'two_factor_required' => false,
                    ]);
                    $count++;

                    break;
            }
        }

        return back()->with('success', "Action effectuée sur {$count} utilisateur(s).");
    }
}
