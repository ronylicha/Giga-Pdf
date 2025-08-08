<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SuperAdminUsersController extends Controller
{
    /**
     * Display all users across all tenants
     */
    public function index(Request $request)
    {
        $query = User::with(['tenant']);

        // Search filter
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Tenant filter
        if ($request->filled('tenant_id')) {
            if ($request->input('tenant_id') === 'null') {
                $query->whereNull('tenant_id');
            } else {
                $query->where('tenant_id', $request->input('tenant_id'));
            }
        }

        // Role filter
        if ($request->filled('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->input('role'));
            });
        }

        // Status filter
        if ($request->filled('status')) {
            if ($request->input('status') === 'active') {
                $query->whereNotNull('email_verified_at');
            } elseif ($request->input('status') === 'inactive') {
                $query->whereNull('email_verified_at');
            }
        }

        $users = $query->latest()
                      ->paginate(20)
                      ->withQueryString();

        // Transform users data
        $users->through(function ($user) {
            // Set team context to get correct roles
            if ($user->tenant_id) {
                app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($user->tenant_id);
            } else {
                app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
            }
            
            // Get roles with correct team context
            $userRoles = $user->roles ? $user->roles->pluck('name')->toArray() : [];
            
            // Reset team context
            app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
            
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'tenant' => $user->tenant ? [
                    'id' => $user->tenant->id,
                    'name' => $user->tenant->name,
                ] : null,
                'roles' => $userRoles,
                'role' => $userRoles[0] ?? null, // Primary role for compatibility
                'role_display' => collect($userRoles)->map(function ($role) {
                    return ucwords(str_replace('-', ' ', $role));
                })->implode(', '),
                'email_verified_at' => $user->email_verified_at,
                'created_at' => $user->created_at,
                'last_login_at' => $user->last_login_at,
                'is_2fa_enabled' => !empty($user->two_factor_secret),
            ];
        });

        // Get statistics
        $stats = [
            'total_users' => User::count(),
            'super_admins' => User::whereHas('roles', function ($q) {
                $q->where('name', 'super-admin');
            })->count(),
            'tenant_admins' => User::whereHas('roles', function ($q) {
                $q->where('name', 'tenant-admin');
            })->count(),
            'verified_users' => User::whereNotNull('email_verified_at')->count(),
            'users_with_2fa' => User::whereNotNull('two_factor_secret')->count(),
        ];

        // Get all tenants for filter
        $tenants = Tenant::orderBy('name')->get(['id', 'name']);

        // Get all roles for filter
        $roles = Role::orderBy('name')->pluck('name');

        return Inertia::render('SuperAdmin/Users/Index', [
            'users' => $users,
            'stats' => $stats,
            'tenants' => $tenants,
            'roles' => $roles,
            'filters' => $request->only(['search', 'tenant_id', 'role', 'status']),
        ]);
    }

    /**
     * Show user details
     */
    public function show(User $user)
    {
        $user->load(['tenant', 'permissions']);
        
        // Set team context to get correct roles
        if ($user->tenant_id) {
            app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($user->tenant_id);
        } else {
            app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
        }

        // Get user activity from activity_log table (Spatie Activity Log)
        $activities = DB::table('activity_log')
            ->where('causer_id', $user->id)
            ->where('causer_type', 'App\Models\User')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        // Get user documents count
        $documentsCount = DB::table('documents')
            ->where('user_id', $user->id)
            ->count();

        // Get user conversions count
        $conversionsCount = DB::table('conversions')
            ->where('user_id', $user->id)
            ->count();

        // Get user storage usage
        $storageUsage = DB::table('documents')
            ->where('user_id', $user->id)
            ->sum('size');

        return Inertia::render('SuperAdmin/Users/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'tenant' => $user->tenant,
                'roles' => $user->roles->map(function($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'display_name' => ucwords(str_replace('-', ' ', $role->name)),
                    ];
                }),
                'permissions' => $user->permissions,
                'email_verified_at' => $user->email_verified_at,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'last_login_at' => $user->last_login_at,
                'is_2fa_enabled' => !empty($user->two_factor_secret),
                'documents_count' => $documentsCount,
                'conversions_count' => $conversionsCount,
                'storage_usage' => $storageUsage,
            ],
            'activities' => $activities,
        ]);
        
        // Reset team context
        app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
    }

    /**
     * Show form to create a new user
     */
    public function create()
    {
        $tenants = Tenant::orderBy('name')->get(['id', 'name']);
        
        // Get all unique role names
        $roleNames = Role::distinct()->pluck('name')->map(function($name) {
            return [
                'name' => $name,
                'display_name' => ucwords(str_replace('-', ' ', $name))
            ];
        });

        return Inertia::render('SuperAdmin/Users/Create', [
            'tenants' => $tenants,
            'roles' => $roleNames,
        ]);
    }

    /**
     * Store a new user
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'tenant_id' => 'nullable|exists:tenants,id',
            'role' => 'required|exists:roles,name',
            'send_welcome_email' => 'boolean',
        ]);

        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'tenant_id' => $validated['tenant_id'],
                'email_verified_at' => now(),
            ]);

            // Set team context and assign role
            if ($user->tenant_id) {
                app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($user->tenant_id);
                
                // Get the role for this specific team
                $role = Role::where('name', $validated['role'])
                           ->where('team_id', $user->tenant_id)
                           ->first();
                
                if (!$role) {
                    // Create the role for this team if it doesn't exist
                    $role = Role::create([
                        'name' => $validated['role'],
                        'guard_name' => 'web',
                        'team_id' => $user->tenant_id
                    ]);
                }
            } else {
                // Global role (super-admin)
                app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
                $role = Role::where('name', $validated['role'])
                           ->whereNull('team_id')
                           ->first();
            }
            
            if ($role) {
                $user->assignRole($role);
            }
            
            // Reset team context
            app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);

            // Send welcome email if requested
            if ($request->input('send_welcome_email', false)) {
                // TODO: Send welcome email
            }

            DB::commit();

            return redirect()->route('super-admin.users.show', $user)
                           ->with('success', 'Utilisateur créé avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Erreur lors de la création de l\'utilisateur: ' . $e->getMessage());
        }
    }

    /**
     * Show form to edit user
     */
    public function edit(User $user)
    {
        $user->load(['tenant']);
        
        // Set team context to get correct roles
        if ($user->tenant_id) {
            app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($user->tenant_id);
        } else {
            app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
        }
        
        $userRoles = $user->roles->pluck('name');
        
        // Reset team context
        app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
        
        $tenants = Tenant::orderBy('name')->get(['id', 'name']);
        
        // Get all unique role names
        $roleNames = Role::distinct()->pluck('name')->map(function($name) {
            return [
                'name' => $name,
                'display_name' => ucwords(str_replace('-', ' ', $name))
            ];
        });

        return Inertia::render('SuperAdmin/Users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'tenant_id' => $user->tenant_id,
                'roles' => $userRoles,
            ],
            'tenants' => $tenants,
            'roles' => $roleNames,
        ]);
    }

    /**
     * Update user
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8|confirmed',
            'tenant_id' => 'nullable|exists:tenants,id',
            'role' => 'required|exists:roles,name',
        ]);

        DB::beginTransaction();
        try {
            $user->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'tenant_id' => $validated['tenant_id'],
            ]);

            if (!empty($validated['password'])) {
                $user->update(['password' => Hash::make($validated['password'])]);
            }

            // Update role with correct team context
            if ($user->tenant_id) {
                app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($user->tenant_id);
                
                // Get the role for this specific team
                $role = Role::where('name', $validated['role'])
                           ->where('team_id', $user->tenant_id)
                           ->first();
                
                if (!$role) {
                    // Create the role for this team if it doesn't exist
                    $role = Role::create([
                        'name' => $validated['role'],
                        'guard_name' => 'web',
                        'team_id' => $user->tenant_id
                    ]);
                }
            } else {
                // Global role (super-admin)
                app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
                $role = Role::where('name', $validated['role'])
                           ->whereNull('team_id')
                           ->first();
            }
            
            if ($role) {
                $user->syncRoles([$role]);
            }
            
            // Reset team context
            app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);

            DB::commit();

            return redirect()->route('super-admin.users.show', $user)
                           ->with('success', 'Utilisateur mis à jour avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Erreur lors de la mise à jour: ' . $e->getMessage());
        }
    }

    /**
     * Delete user
     */
    public function destroy(User $user)
    {
        // Prevent deleting self
        if ($user->id === Auth::id()) {
            return back()->with('error', 'Vous ne pouvez pas supprimer votre propre compte');
        }

        // Prevent deleting last super admin
        if ($user->hasRole('super-admin')) {
            $superAdminCount = User::whereHas('roles', function ($q) {
                $q->where('name', 'super-admin');
            })->count();

            if ($superAdminCount <= 1) {
                return back()->with('error', 'Impossible de supprimer le dernier super admin');
            }
        }

        try {
            $user->delete();
            return redirect()->route('super-admin.users.index')
                           ->with('success', 'Utilisateur supprimé avec succès');
        } catch (\Exception $e) {
            return back()->with('error', 'Erreur lors de la suppression: ' . $e->getMessage());
        }
    }

    /**
     * Impersonate a user
     */
    public function impersonate(Request $request, User $user)
    {
        // Cannot impersonate self
        if ($user->id === Auth::id()) {
            return back()->with('error', 'Vous ne pouvez pas vous impersonner vous-même');
        }

        // Store original user ID before switching
        $impersonatorId = Auth::id();
        
        // Store impersonator ID and start time in session
        session([
            'impersonator_id' => $impersonatorId,
            'impersonation_started_at' => now(),
        ]);
        
        // Log the impersonation BEFORE switching user
        DB::table('impersonation_logs')->insert([
            'impersonator_id' => $impersonatorId,
            'impersonated_user_id' => $user->id,
            'action' => 'start',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => json_encode([
                'impersonated_user_name' => $user->name,
                'impersonated_user_email' => $user->email,
                'impersonated_user_role' => $user->roles->pluck('name')->first(),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Now login as the target user
        Auth::login($user);

        return redirect()->route('dashboard')
                       ->with('info', 'Vous êtes maintenant connecté en tant que ' . $user->name);
    }

    /**
     * Stop impersonating
     */
    public function stopImpersonation(Request $request)
    {
        if (!session()->has('impersonator_id')) {
            return redirect()->route('dashboard');
        }

        $impersonatorId = session('impersonator_id');
        $impersonatedUserId = Auth::id();

        // Login back as original user
        $originalUser = User::find($impersonatorId);
        if ($originalUser) {
            Auth::login($originalUser);
        }

        // Clear impersonation session
        session()->forget(['impersonator_id', 'impersonation_started_at']);

        // Log the end of impersonation
        DB::table('impersonation_logs')->insert([
            'impersonator_id' => $impersonatorId,
            'impersonated_user_id' => $impersonatedUserId,
            'action' => 'stop',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => json_encode([
                'session_duration' => now()->diffInMinutes(session('impersonation_started_at', now())),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Redirect to super-admin dashboard if the original user is super-admin
        if ($originalUser && $originalUser->isSuperAdmin()) {
            return redirect()->route('super-admin.dashboard')
                           ->with('success', 'Impersonation terminée');
        }
        
        // Otherwise redirect to regular dashboard
        return redirect()->route('dashboard')
                       ->with('success', 'Impersonation terminée');
    }

    /**
     * Reset user password
     */
    public function resetPassword(User $user)
    {
        $newPassword = \Str::random(12);
        
        $user->update([
            'password' => Hash::make($newPassword),
        ]);

        // TODO: Send email with new password

        return back()->with('success', 'Mot de passe réinitialisé. Nouveau mot de passe: ' . $newPassword);
    }

    /**
     * Verify user email
     */
    public function verifyEmail(User $user)
    {
        if ($user->email_verified_at) {
            return back()->with('info', 'Email déjà vérifié');
        }

        $user->update(['email_verified_at' => now()]);

        return back()->with('success', 'Email vérifié avec succès');
    }

    /**
     * Disable 2FA for user
     */
    public function disable2FA(User $user)
    {
        $user->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ]);

        return back()->with('success', '2FA désactivé pour cet utilisateur');
    }
}