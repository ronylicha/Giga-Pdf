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
        return $user->hasPermission('documents.view');
    }

    /**
     * Determine whether the user can view the document.
     */
    public function view(User $user, Document $document): bool
    {
        // User can view if they own it or have permission
        return $user->id === $document->user_id || 
               $user->hasPermission('documents.view');
    }

    /**
     * Determine whether the user can create documents.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('documents.create');
    }

    /**
     * Determine whether the user can update the document.
     */
    public function update(User $user, Document $document): bool
    {
        // User can update if they own it and have permission, or are admin
        return ($user->id === $document->user_id && $user->hasPermission('documents.update')) ||
               $user->hasRole('tenant_admin') ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can delete the document.
     */
    public function delete(User $user, Document $document): bool
    {
        // User can delete if they own it and have permission, or are admin
        return ($user->id === $document->user_id && $user->hasPermission('documents.delete')) ||
               $user->hasRole('tenant_admin') ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can share the document.
     */
    public function share(User $user, Document $document): bool
    {
        // User can share if they own it and have permission
        return $user->id === $document->user_id && 
               $user->hasPermission('documents.share');
    }

    /**
     * Determine whether the user can download the document.
     */
    public function download(User $user, Document $document): bool
    {
        // User can download if they own it or have permission
        return $user->id === $document->user_id || 
               $user->hasPermission('documents.download');
    }

    /**
     * Determine whether the user can convert the document.
     */
    public function convert(User $user, Document $document): bool
    {
        // User can convert if they own it and have permission
        return $user->id === $document->user_id && 
               $user->hasPermission('documents.convert');
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