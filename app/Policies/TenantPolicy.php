<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TenantPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any tenants.
     */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can view the tenant.
     */
    public function view(User $user, Tenant $tenant): bool
    {
        // User can view their own tenant
        if ($user->tenant_id === $tenant->id) {
            return true;
        }
        
        // Super admin can view all tenants
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can create tenants.
     */
    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can update the tenant.
     */
    public function update(User $user, Tenant $tenant): bool
    {
        // Tenant admin can update some settings
        if ($user->tenant_id === $tenant->id && $user->isTenantAdmin()) {
            return true;
        }
        
        // Super admin can update all tenants
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can delete the tenant.
     */
    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can restore the tenant.
     */
    public function restore(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can permanently delete the tenant.
     */
    public function forceDelete(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can suspend the tenant.
     */
    public function suspend(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can reactivate the tenant.
     */
    public function reactivate(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can manage tenant settings.
     */
    public function manageSettings(User $user, Tenant $tenant): bool
    {
        // Must be in the same tenant and have settings permission
        if ($user->tenant_id !== $tenant->id) {
            return false;
        }
        
        return $user->hasPermissionTo('manage tenant settings') || $user->isTenantAdmin();
    }

    /**
     * Determine whether the user can view tenant billing.
     */
    public function viewBilling(User $user, Tenant $tenant): bool
    {
        // Must be in the same tenant and have billing permission
        if ($user->tenant_id !== $tenant->id) {
            return false;
        }
        
        return $user->hasPermissionTo('view tenant statistics') || $user->isTenantAdmin();
    }
}