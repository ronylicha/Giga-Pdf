<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        // User can view themselves
        if ($user->id === $model->id) {
            return true;
        }
        
        // Must have permission and be in same tenant
        return $user->hasPermission('users.view') && 
               $user->tenant_id === $model->tenant_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('users.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        // User can update themselves (limited fields)
        if ($user->id === $model->id) {
            return true;
        }
        
        // Check if user can manage target user
        return $user->canManageUser($model);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        // Cannot delete yourself
        if ($user->id === $model->id) {
            return false;
        }
        
        // Must have permission and be able to manage the user
        return $user->hasPermission('users.delete') && 
               $user->canManageUser($model);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return $user->hasPermission('users.delete') && 
               $user->canManageUser($model);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can manage roles for the model.
     */
    public function manageRoles(User $user, User $model): bool
    {
        // Cannot manage own roles
        if ($user->id === $model->id) {
            return false;
        }
        
        return $user->hasPermission('users.roles') && 
               $user->canManageUser($model);
    }

    /**
     * Determine whether the user can reset password for the model.
     */
    public function resetPassword(User $user, User $model): bool
    {
        // Cannot reset own password through admin panel
        if ($user->id === $model->id) {
            return false;
        }
        
        return $user->hasPermission('users.update') && 
               $user->canManageUser($model);
    }

    /**
     * Determine whether the user can toggle 2FA for the model.
     */
    public function toggle2FA(User $user, User $model): bool
    {
        // Cannot toggle own 2FA through admin panel
        if ($user->id === $model->id) {
            return false;
        }
        
        return $user->hasPermission('users.update') && 
               $user->canManageUser($model);
    }

    /**
     * Determine whether the user can impersonate the model.
     */
    public function impersonate(User $user, User $model): bool
    {
        // Only super admin can impersonate
        if (!$user->isSuperAdmin()) {
            return false;
        }
        
        // Cannot impersonate yourself
        if ($user->id === $model->id) {
            return false;
        }
        
        // Cannot impersonate another super admin
        if ($model->isSuperAdmin()) {
            return false;
        }
        
        return true;
    }
}