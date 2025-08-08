<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DocumentPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any documents.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view documents') || 
               $user->hasRole(['tenant-admin', 'manager', 'editor', 'viewer']) ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can view the document.
     */
    public function view(User $user, Document $document): bool
    {
        // User can view if they own it or have permission
        return $user->id === $document->user_id || 
               $user->hasPermissionTo('view documents') ||
               $user->hasRole(['tenant-admin', 'manager']) ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can create documents.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create documents') ||
               $user->hasRole(['tenant-admin', 'manager', 'editor']) ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can update the document.
     */
    public function update(User $user, Document $document): bool
    {
        // User can update if they own it and have permission, or are admin
        return ($user->id === $document->user_id && ($user->hasPermissionTo('edit documents') || $user->hasRole(['editor', 'manager']))) ||
               $user->hasRole('tenant-admin') ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can edit the document.
     */
    public function edit(User $user, Document $document): bool
    {
        // User can edit if they own it and have permission, or are admin
        return ($user->id === $document->user_id && ($user->hasPermissionTo('edit documents') || $user->hasRole(['editor', 'manager']))) ||
               $user->hasRole('tenant-admin') ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can delete the document.
     */
    public function delete(User $user, Document $document): bool
    {
        // User can delete if they own it and have permission, or are admin
        return ($user->id === $document->user_id && ($user->hasPermissionTo('delete documents') || $user->hasRole(['editor', 'manager']))) ||
               $user->hasRole('tenant-admin') ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can share the document.
     */
    public function share(User $user, Document $document): bool
    {
        // User can share if they own it and have permission
        return ($user->id === $document->user_id && $user->hasPermissionTo('share documents')) ||
               $user->hasRole(['tenant-admin', 'manager']) ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can download the document.
     */
    public function download(User $user, Document $document): bool
    {
        // User can download if they own it or have permission  
        // Note: download permission doesn't exist, using view permission
        return $user->id === $document->user_id || 
               $user->hasPermissionTo('view documents') ||
               $user->hasRole(['tenant-admin', 'manager', 'editor', 'viewer']) ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can convert the document.
     */
    public function convert(User $user, Document $document): bool
    {
        // User can convert if they own it and have permission
        return ($user->id === $document->user_id && $user->hasPermissionTo('convert documents')) ||
               $user->hasRole(['tenant-admin', 'manager', 'editor']) ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can restore the document.
     */
    public function restore(User $user, Document $document): bool
    {
        return $user->hasRole('tenant_admin') || $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can permanently delete the document.
     */
    public function forceDelete(User $user, Document $document): bool
    {
        return $user->isSuperAdmin();
    }
}