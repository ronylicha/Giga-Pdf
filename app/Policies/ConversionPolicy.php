<?php

namespace App\Policies;

use App\Models\Conversion;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ConversionPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any conversions.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the conversion.
     */
    public function view(User $user, Conversion $conversion): bool
    {
        // User can view their own conversions
        if ($conversion->user_id === $user->id) {
            return true;
        }

        // User can view conversions from their tenant if they have permission
        if ($conversion->tenant_id === $user->tenant_id) {
            return $user->isTenantAdmin() || $user->hasRole('manager');
        }

        return false;
    }

    /**
     * Determine whether the user can create conversions.
     */
    public function create(User $user): bool
    {
        return true; // All authenticated users can create conversions
    }

    /**
     * Determine whether the user can update the conversion.
     */
    public function update(User $user, Conversion $conversion): bool
    {
        // Only the owner can update (retry/cancel) their conversion
        return $conversion->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the conversion.
     */
    public function delete(User $user, Conversion $conversion): bool
    {
        // Owner can delete their own conversions
        if ($conversion->user_id === $user->id) {
            return true;
        }

        // Tenant admin can delete any conversion in their tenant
        if ($conversion->tenant_id === $user->tenant_id && $user->isTenantAdmin()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can restore the conversion.
     */
    public function restore(User $user, Conversion $conversion): bool
    {
        return $this->delete($user, $conversion);
    }

    /**
     * Determine whether the user can permanently delete the conversion.
     */
    public function forceDelete(User $user, Conversion $conversion): bool
    {
        return $conversion->tenant_id === $user->tenant_id && $user->isTenantAdmin();
    }
}
