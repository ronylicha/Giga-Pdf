<?php

namespace App\Traits;

use App\Exceptions\StorageQuotaExceededException;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToTenant
{
    /**
     * Boot the trait
     */
    protected static function bootBelongsToTenant(): void
    {
        // Ajouter le scope global pour filtrer automatiquement par tenant
        static::addGlobalScope(new TenantScope());

        // Lors de la création, assigner automatiquement le tenant_id
        static::creating(function ($model) {
            if (! $model->tenant_id && Auth::check() && Auth::user()->tenant_id) {
                $model->tenant_id = Auth::user()->tenant_id;
            }

            // Si c'est un document, vérifier le quota de stockage
            if ($model instanceof \App\Models\Document && isset($model->size)) {
                $tenant = Tenant::find($model->tenant_id);

                if (! $tenant) {
                    throw new \Exception('Tenant not found');
                }

                // Calculer l'utilisation actuelle
                $currentUsage = $tenant->documents()->sum('size');
                $maxStorage = $tenant->max_storage_gb * 1024 * 1024 * 1024; // Convertir GB en bytes

                // Vérifier si le nouveau fichier dépasse le quota
                if (($currentUsage + $model->size) > $maxStorage) {
                    throw new StorageQuotaExceededException(
                        sprintf(
                            "Quota de stockage dépassé. Utilisé: %s / Maximum: %s",
                            $this->formatBytes($currentUsage),
                            $this->formatBytes($maxStorage)
                        )
                    );
                }

                // Vérifier la taille maximale par fichier
                $maxFileSize = $tenant->max_file_size_mb * 1024 * 1024; // Convertir MB en bytes
                if ($model->size > $maxFileSize) {
                    throw new \Exception(
                        sprintf(
                            "Fichier trop volumineux. Taille: %s / Maximum: %s",
                            $this->formatBytes($model->size),
                            $this->formatBytes($maxFileSize)
                        )
                    );
                }
            }
        });
    }

    /**
     * Relation avec le tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Vérifier si le modèle appartient au tenant actuel
     */
    public function belongsToCurrentTenant(): bool
    {
        if (! Auth::check() || ! Auth::user()->tenant_id) {
            return false;
        }

        return $this->tenant_id === Auth::user()->tenant_id;
    }

    /**
     * Scope pour récupérer uniquement les enregistrements du tenant spécifié
     */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope pour récupérer uniquement les enregistrements du tenant actuel
     */
    public function scopeCurrentTenant($query)
    {
        if (Auth::check() && Auth::user()->tenant_id) {
            return $query->where('tenant_id', Auth::user()->tenant_id);
        }

        return $query->whereRaw('1 = 0'); // Retourner une requête vide si pas de tenant
    }

    /**
     * Formater les bytes en format lisible
     */
    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
