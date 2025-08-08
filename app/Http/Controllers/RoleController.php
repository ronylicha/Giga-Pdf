<?php

namespace App\Http\Controllers;

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    /**
     * Display a listing of roles
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        
        // Get roles based on user permissions
        $query = Role::query();
        
        if (!$user->isSuperAdmin()) {
            // Non-super admins can only see roles from their tenant
            $query->where('tenant_id', $user->tenant_id);
        } else {
            // Super admin can see all roles including global ones
            $query->whereNull('tenant_id')->orWhere('tenant_id', '>', 0);
        }
        
        $roles = $query->with('users')
            ->orderBy('level')
            ->paginate(20);
        
        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'canCreate' => $user->hasPermissionTo('create roles') || $user->isTenantAdmin(),
            'canEdit' => $user->hasPermissionTo('edit roles') || $user->isTenantAdmin(),
            'canDelete' => $user->hasPermissionTo('delete roles') || $user->isTenantAdmin(),
        ]);
    }
    
    /**
     * Show the form for creating a new role
     */
    public function create()
    {
        $user = auth()->user();
        
        if (!$user->hasPermissionTo('create roles') && !$user->isTenantAdmin()) {
            abort(403);
        }
        
        return Inertia::render('Roles/Create', [
            'permissions' => Permission::getGroupedPermissions(),
        ]);
    }
    
    /**
     * Store a newly created role
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        
        if (!$user->hasPermissionTo('create roles') && !$user->isTenantAdmin()) {
            abort(403);
        }
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles')->where(function ($query) use ($user) {
                    return $query->where('tenant_id', $user->tenant_id);
                }),
            ],
            'description' => 'nullable|string|max:500',
            'permissions' => 'array',
            'permissions.*' => 'string|exists:permissions,slug',
            'level' => 'required|integer|min:0',
        ]);
        
        DB::transaction(function () use ($validated, $user) {
            $role = Role::create([
                'tenant_id' => $user->isSuperAdmin() ? null : $user->tenant_id,
                'name' => $validated['name'],
                'slug' => $validated['slug'],
                'description' => $validated['description'],
                'permissions' => $validated['permissions'] ?? [],
                'is_system' => false,
                'level' => $validated['level'],
            ]);
        });
        
        return redirect()
            ->route('roles.index')
            ->with('success', 'Rôle créé avec succès.');
    }
    
    /**
     * Show the form for editing a role
     */
    public function edit(Role $role)
    {
        $user = auth()->user();
        
        if (!$user->hasPermissionTo('edit roles') && !$user->isTenantAdmin()) {
            abort(403);
        }
        
        // Check if user can manage this role
        if (!$user->isSuperAdmin() && !$user->isTenantAdmin()) {
            abort(403, 'Vous ne pouvez pas modifier ce rôle.');
        }
        
        return Inertia::render('Roles/Edit', [
            'role' => $role,
            'permissions' => Permission::getGroupedPermissions(),
            'isSystemRole' => $role->is_system,
        ]);
    }
    
    /**
     * Update the specified role
     */
    public function update(Request $request, Role $role)
    {
        $user = auth()->user();
        
        if (!$user->hasPermissionTo('edit roles') && !$user->isTenantAdmin()) {
            abort(403);
        }
        
        // Check if user can manage this role
        if (!$user->isSuperAdmin() && !$user->isTenantAdmin()) {
            abort(403, 'Vous ne pouvez pas modifier ce rôle.');
        }
        
        // System roles have limited editability
        if ($role->is_system) {
            $validated = $request->validate([
                'permissions' => 'array',
                'permissions.*' => 'string|exists:permissions,slug',
            ]);
            
            $role->update([
                'permissions' => $validated['permissions'] ?? [],
            ]);
        } else {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:500',
                'permissions' => 'array',
                'permissions.*' => 'string|exists:permissions,slug',
                'level' => 'required|integer|min:0',
            ]);
            
            $role->update($validated);
        }
        
        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        
        return redirect()
            ->route('roles.index')
            ->with('success', 'Rôle mis à jour avec succès.');
    }
    
    /**
     * Remove the specified role
     */
    public function destroy(Role $role)
    {
        $user = auth()->user();
        
        if (!$user->hasPermissionTo('delete roles') && !$user->isTenantAdmin()) {
            abort(403);
        }
        
        if ($role->is_system) {
            return back()->with('error', 'Les rôles système ne peuvent pas être supprimés.');
        }
        
        // Check if role has users
        if ($role->users()->count() > 0) {
            return back()->with('error', 'Ce rôle ne peut pas être supprimé car il est assigné à des utilisateurs.');
        }
        
        $role->delete();
        
        return redirect()
            ->route('roles.index')
            ->with('success', 'Rôle supprimé avec succès.');
    }
    
    /**
     * Show users with a specific role
     */
    public function users(Role $role)
    {
        $user = auth()->user();
        
        if (!($user->hasPermissionTo('view roles') || $user->isTenantAdmin()) || 
            !($user->hasPermissionTo('view users') || $user->isTenantAdmin())) {
            abort(403);
        }
        
        $users = $role->users()
            ->where('tenant_id', $user->tenant_id)
            ->paginate(20);
        
        return Inertia::render('Roles/Users', [
            'role' => $role,
            'users' => $users,
        ]);
    }
    
    /**
     * Assign role to users
     */
    public function assignUsers(Request $request, Role $role)
    {
        $user = auth()->user();
        
        if (!$user->hasPermissionTo('assign roles') && !$user->isTenantAdmin()) {
            abort(403);
        }
        
        $validated = $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);
        
        DB::transaction(function () use ($validated, $role, $user) {
            foreach ($validated['user_ids'] as $userId) {
                $targetUser = User::find($userId);
                
                // Verify user can manage target user
                if ($user->canManageUser($targetUser)) {
                    $targetUser->assignRole($role);
                }
            }
        });
        
        return back()->with('success', 'Rôle assigné aux utilisateurs sélectionnés.');
    }
    
    /**
     * Remove role from user
     */
    public function removeUser(Role $role, User $targetUser)
    {
        $user = auth()->user();
        
        if (!$user->hasPermissionTo('assign roles') && !$user->isTenantAdmin()) {
            abort(403);
        }
        
        if (!$user->canManageUser($targetUser)) {
            abort(403, 'Vous ne pouvez pas gérer cet utilisateur.');
        }
        
        $targetUser->removeRole($role);
        
        return back()->with('success', 'Rôle retiré de l\'utilisateur.');
    }
}