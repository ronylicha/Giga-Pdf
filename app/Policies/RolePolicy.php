<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class RolePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any roles.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('roles.view');
    }

    /**
     * Determine whether the user can view the role.
     */
    public function view(User $user, Role $role): bool
    {
        if (!$user->hasPermission('roles.view')) {
            return false;
        }
        
        // Super admin can view all roles
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        // Others can only view roles from their tenant
        return $role->tenant_id === $user->tenant_id;
    }

    /**
     * Determine whether the user can create roles.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('roles.create');
    }

    /**
     * Determine whether the user can update the role.
     */
    public function update(User $user, Role $role): bool
    {
        if (!$user->hasPermission('roles.update')) {
            return false;
        }
        
        // Check if user's role can manage this role
        $userRole = $user->role();
        if ($userRole && !$userRole->canManage($role)) {
            return false;
        }
        
        // Super admin can update all roles
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        // Others can only update roles from their tenant
        return $role->tenant_id === $user->tenant_id;
    }

    /**
     * Determine whether the user can delete the role.
     */
    public function delete(User $user, Role $role): bool
    {
        if (!$user->hasPermission('roles.delete')) {
            return false;
        }
        
        // Cannot delete system roles
        if ($role->is_system) {
            return false;
        }
        
        // Check if user's role can manage this role
        $userRole = $user->role();
        if ($userRole && !$userRole->canManage($role)) {
            return false;
        }
        
        // Super admin can delete all non-system roles
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        // Others can only delete roles from their tenant
        return $role->tenant_id === $user->tenant_id;
    }

    /**
     * Determine whether the user can restore the role.
     */
    public function restore(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.delete') && 
               ($user->isSuperAdmin() || $role->tenant_id === $user->tenant_id);
    }

    /**
     * Determine whether the user can permanently delete the role.
     */
    public function forceDelete(User $user, Role $role): bool
    {
        return $user->isSuperAdmin() && !$role->is_system;
    }

    /**
     * Determine whether the user can assign the role to users.
     */
    public function assign(User $user, Role $role): bool
    {
        if (!$user->hasPermission('users.roles')) {
            return false;
        }
        
        // Check if user's role can manage this role
        $userRole = $user->role();
        if ($userRole && !$userRole->canManage($role)) {
            return false;
        }
        
        return true;
    }
}