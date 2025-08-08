<?php

namespace App\Policies;

use Spatie\Permission\Models\Role;
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
        return $user->can('view roles');
    }

    /**
     * Determine whether the user can view the role.
     */
    public function view(User $user, Role $role): bool
    {
        if (!$user->can('view roles')) {
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
        return $user->can('create roles');
    }

    /**
     * Determine whether the user can update the role.
     */
    public function update(User $user, Role $role): bool
    {
        if (!$user->can('edit roles')) {
            return false;
        }
        
        // Super admin can update all roles
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        // Prevent editing system roles
        if (in_array($role->name, ['super-admin', 'tenant-admin', 'manager', 'editor', 'viewer'])) {
            return false;
        }
        
        // Others can only update roles from their tenant
        return $role->tenant_id === $user->tenant_id;
    }

    /**
     * Determine whether the user can delete the role.
     */
    public function delete(User $user, Role $role): bool
    {
        if (!$user->can('delete roles')) {
            return false;
        }
        
        // Cannot delete system roles
        if (in_array($role->name, ['super-admin', 'tenant-admin', 'manager', 'editor', 'viewer'])) {
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
        return $user->can('delete roles') && 
               ($user->isSuperAdmin() || $role->tenant_id === $user->tenant_id);
    }

    /**
     * Determine whether the user can permanently delete the role.
     */
    public function forceDelete(User $user, Role $role): bool
    {
        return $user->isSuperAdmin() && 
               !in_array($role->name, ['super-admin', 'tenant-admin', 'manager', 'editor', 'viewer']);
    }

    /**
     * Determine whether the user can assign the role to users.
     */
    public function assign(User $user, Role $role): bool
    {
        if (!$user->can('assign roles')) {
            return false;
        }
        
        // Super admin can assign any role
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        // Tenant admin can only assign roles from their tenant
        if ($user->isTenantAdmin()) {
            return $role->tenant_id === $user->tenant_id;
        }
        
        return false;
    }
}