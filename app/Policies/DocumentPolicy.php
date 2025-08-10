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
        // Tous les utilisateurs connectés peuvent voir les documents
        return true;
    }

    /**
     * Determine whether the user can view the document.
     */
    public function view(User $user, Document $document): bool
    {
        // User can view if they own it or are admin
        return $user->id === $document->user_id || 
               $user->hasRole(['tenant-admin', 'manager']) ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can create documents.
     */
    public function create(User $user): bool
    {
        // Tous les utilisateurs peuvent créer des documents
        return true;
    }

    /**
     * Determine whether the user can update the document.
     */
    public function update(User $user, Document $document): bool
    {
        // User can update if they own it or are admin
        return $user->id === $document->user_id ||
               $user->hasRole('tenant-admin') ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can edit the document.
     */
    public function edit(User $user, Document $document): bool
    {
        // User can edit if they own it or are admin
        return $user->id === $document->user_id ||
               $user->hasRole('tenant-admin') ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can delete the document.
     */
    public function delete(User $user, Document $document): bool
    {
        // User can delete if they own it or are admin
        return $user->id === $document->user_id ||
               $user->hasRole('tenant-admin') ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can share the document.
     */
    public function share(User $user, Document $document): bool
    {
        // User can share if they own it or are admin
        return $user->id === $document->user_id ||
               $user->hasRole(['tenant-admin', 'manager']) ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can download the document.
     */
    public function download(User $user, Document $document): bool
    {
        // User can download if they own it or have view permission
        return $user->id === $document->user_id || 
               $user->hasRole(['tenant-admin', 'manager', 'editor', 'viewer']) ||
               $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can convert the document.
     */
    public function convert(User $user, Document $document): bool
    {
        // User can convert if they own it or are admin
        return $user->id === $document->user_id ||
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