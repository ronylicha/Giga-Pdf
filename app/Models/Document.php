<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    use HasFactory;
    use SoftDeletes;
    use BelongsToTenant;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'original_name',
        'stored_name',
        'mime_type',
        'size',
        'hash',
        'metadata',
        'is_public',
        'parent_id',
        'search_content',
        'thumbnail_path',
        'page_count',
        'status',
        'tags',
        'last_accessed_at',
        'access_count',
        'extension',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'metadata' => 'array',
        'tags' => 'array',
        'is_public' => 'boolean',
        'size' => 'integer',
        'page_count' => 'integer',
        'access_count' => 'integer',
        'last_accessed_at' => 'datetime',
    ];

    /**
     * Default attributes
     */
    protected $attributes = [
        'is_public' => false,
        'status' => 'active',
        'access_count' => 0,
        'metadata' => '{}',
        'tags' => '[]',
    ];

    /**
     * Boot method
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($document) {
            // Générer un hash si non fourni
            if (empty($document->hash) && $document->stored_name) {
                $path = Storage::path($document->stored_name);
                if (file_exists($path)) {
                    $document->hash = hash_file('sha256', $path);
                }
            }
        });

        static::deleting(function ($document) {
            // Supprimer le fichier physique lors de la suppression
            if ($document->stored_name && Storage::exists($document->stored_name)) {
                Storage::delete($document->stored_name);
            }

            // Supprimer le thumbnail
            if ($document->thumbnail_path && Storage::exists($document->thumbnail_path)) {
                Storage::delete($document->thumbnail_path);
            }

            // Supprimer les documents enfants
            $document->children()->delete();

            // Supprimer les partages associés
            $document->shares()->delete();

            // Supprimer les conversions associées
            $document->conversions()->delete();
        });
    }

    /**
     * Get user relationship
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get parent document relationship
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'parent_id');
    }

    /**
     * Get child documents relationship
     */
    public function children(): HasMany
    {
        return $this->hasMany(Document::class, 'parent_id');
    }

    /**
     * Get conversions relationship
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(Conversion::class);
    }

    /**
     * Get shares relationship
     */
    public function shares(): HasMany
    {
        return $this->hasMany(Share::class);
    }

    /**
     * Check if document is a PDF
     */
    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    /**
     * Check if document is an image
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Check if document is a text file
     */
    public function isText(): bool
    {
        return in_array($this->mime_type, [
            'text/plain',
            'text/html',
            'text/markdown',
            'text/csv',
            'application/json',
            'application/xml',
        ]);
    }

    /**
     * Check if document is an office document
     */
    public function isOfficeDocument(): bool
    {
        return in_array($this->mime_type, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-powerpoint',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
        ]);
    }

    /**
     * Get file extension
     */
    public function getExtension(): string
    {
        return pathinfo($this->original_name, PATHINFO_EXTENSION);
    }

    /**
     * Get file name without extension
     */
    public function getNameWithoutExtension(): string
    {
        return pathinfo($this->original_name, PATHINFO_FILENAME);
    }

    /**
     * Get formatted file size
     */
    public function getFormattedSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = $this->size;

        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Get document URL
     */
    public function getUrl(): string
    {
        if ($this->is_public) {
            return route('documents.public', $this->id);
        }

        return route('documents.show', $this->id);
    }

    /**
     * Get download URL
     */
    public function getDownloadUrl(): string
    {
        return route('documents.download', $this->id);
    }

    /**
     * Get thumbnail URL
     */
    public function getThumbnailUrl(): ?string
    {
        if (! $this->thumbnail_path) {
            return null;
        }

        return Storage::url($this->thumbnail_path);
    }

    /**
     * Get storage path
     */
    public function getStoragePath(): string
    {
        return Storage::path($this->stored_name);
    }

    /**
     * Check if document exists on disk
     */
    public function existsOnDisk(): bool
    {
        return Storage::exists($this->stored_name);
    }

    /**
     * Mark document as accessed
     */
    public function markAsAccessed(): void
    {
        $this->increment('access_count');
        $this->update(['last_accessed_at' => now()]);
    }

    /**
     * Check if user can view this document
     */
    public function canBeViewedBy(?User $user): bool
    {
        // Document public
        if ($this->is_public) {
            return true;
        }

        // Pas d'utilisateur connecté
        if (! $user) {
            return false;
        }

        // Propriétaire du document
        if ($this->user_id === $user->id) {
            return true;
        }

        // Même tenant
        if ($this->tenant_id === $user->tenant_id) {
            return true;
        }

        // Document partagé avec l'utilisateur
        if ($this->shares()->where('shared_with', $user->id)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can edit this document
     */
    public function canBeEditedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        // Propriétaire du document
        if ($this->user_id === $user->id) {
            return true;
        }

        // Admin du tenant
        if ($this->tenant_id === $user->tenant_id && $user->isTenantAdmin()) {
            return true;
        }

        // Document partagé avec droits d'édition
        $share = $this->shares()
            ->where('shared_with', $user->id)
            ->first();

        if ($share && in_array('edit', $share->permissions)) {
            return true;
        }

        return false;
    }

    /**
     * Add tag to document
     */
    public function addTag(string $tag): bool
    {
        $tags = $this->tags ?? [];

        if (! in_array($tag, $tags)) {
            $tags[] = $tag;

            return $this->update(['tags' => $tags]);
        }

        return false;
    }

    /**
     * Remove tag from document
     */
    public function removeTag(string $tag): bool
    {
        $tags = $this->tags ?? [];
        $tags = array_diff($tags, [$tag]);

        return $this->update(['tags' => array_values($tags)]);
    }

    /**
     * Update metadata
     */
    public function updateMetadata(array $metadata): bool
    {
        $currentMetadata = $this->metadata ?? [];
        $this->metadata = array_merge($currentMetadata, $metadata);

        return $this->save();
    }

    /**
     * Get metadata value
     */
    public function getMetadata(string $key, $default = null)
    {
        return data_get($this->metadata, $key, $default);
    }

    /**
     * Scope for searching documents
     */
    public function scopeSearch($query, string $searchTerm)
    {
        return $query->where(function ($q) use ($searchTerm) {
            $q->where('original_name', 'like', "%{$searchTerm}%")
              ->orWhere('search_content', 'like', "%{$searchTerm}%")
              ->orWhereJsonContains('tags', $searchTerm);
        });
    }

    /**
     * Scope for filtering by type
     */
    public function scopeOfType($query, string $type)
    {
        return match($type) {
            'pdf' => $query->where('mime_type', 'application/pdf'),
            'image' => $query->where('mime_type', 'like', 'image/%'),
            'office' => $query->whereIn('mime_type', [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.ms-powerpoint',
            ]),
            default => $query
        };
    }
}
