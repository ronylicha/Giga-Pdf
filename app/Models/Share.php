<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Share extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'document_id',
        'shared_by',
        'shared_with',
        'type',
        'permissions',
        'token',
        'password',
        'expires_at',
        'views_count',
        'downloads_count',
        'last_accessed_at',
        'last_accessed_ip',
        'message',
        'access_log',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'permissions' => 'array',
        'access_log' => 'array',
        'expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'is_active' => 'boolean',
        'views_count' => 'integer',
        'downloads_count' => 'integer',
    ];

    /**
     * Default attributes
     */
    protected $attributes = [
        'permissions' => '["view"]',
        'views_count' => 0,
        'downloads_count' => 0,
        'is_active' => true,
        'access_log' => '[]',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Type constants
     */
    public const TYPE_INTERNAL = 'internal';
    public const TYPE_PUBLIC = 'public';
    public const TYPE_PROTECTED = 'protected';

    /**
     * Permission constants
     */
    public const PERMISSION_VIEW = 'view';
    public const PERMISSION_DOWNLOAD = 'download';
    public const PERMISSION_EDIT = 'edit';
    public const PERMISSION_COMMENT = 'comment';

    /**
     * Boot method
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($share) {
            // Générer un token unique pour les partages publics/protégés
            if (in_array($share->type, [self::TYPE_PUBLIC, self::TYPE_PROTECTED]) && ! $share->token) {
                do {
                    $token = Str::random(32);
                } while (static::where('token', $token)->exists());

                $share->token = $token;
            }
        });
    }

    /**
     * Get document relationship
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get sharer relationship
     */
    public function sharer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }

    /**
     * Get recipient relationship
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_with');
    }

    /**
     * Check if share is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if share is active
     */
    public function isActive(): bool
    {
        return $this->is_active && ! $this->isExpired();
    }

    /**
     * Check if share is internal
     */
    public function isInternal(): bool
    {
        return $this->type === self::TYPE_INTERNAL;
    }

    /**
     * Check if share is public
     */
    public function isPublic(): bool
    {
        return $this->type === self::TYPE_PUBLIC;
    }

    /**
     * Check if share is protected
     */
    public function isProtected(): bool
    {
        return $this->type === self::TYPE_PROTECTED;
    }

    /**
     * Check if share has specific permission
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? []);
    }

    /**
     * Check if can view
     */
    public function canView(): bool
    {
        return $this->hasPermission(self::PERMISSION_VIEW);
    }

    /**
     * Check if can download
     */
    public function canDownload(): bool
    {
        return $this->hasPermission(self::PERMISSION_DOWNLOAD);
    }

    /**
     * Check if can edit
     */
    public function canEdit(): bool
    {
        return $this->hasPermission(self::PERMISSION_EDIT);
    }

    /**
     * Check if can comment
     */
    public function canComment(): bool
    {
        return $this->hasPermission(self::PERMISSION_COMMENT);
    }

    /**
     * Get share URL
     */
    public function getShareUrl(): string
    {
        if (! $this->token) {
            return '';
        }

        return route('share.view', $this->token);
    }

    /**
     * Get share QR code URL
     */
    public function getQrCodeUrl(): string
    {
        $url = $this->getShareUrl();

        if (! $url) {
            return '';
        }

        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($url);
    }

    /**
     * Record access
     */
    public function recordAccess(string $ip, string $userAgent = null): void
    {
        $this->increment('views_count');

        $accessLog = $this->access_log ?? [];
        $accessLog[] = [
            'ip' => $ip,
            'user_agent' => $userAgent,
            'accessed_at' => now()->toIso8601String(),
        ];

        // Garder seulement les 100 derniers accès
        if (count($accessLog) > 100) {
            $accessLog = array_slice($accessLog, -100);
        }

        $this->update([
            'last_accessed_at' => now(),
            'last_accessed_ip' => $ip,
            'access_log' => $accessLog,
        ]);
    }

    /**
     * Record download
     */
    public function recordDownload(string $ip = null): void
    {
        $this->increment('downloads_count');

        if ($ip) {
            $accessLog = $this->access_log ?? [];
            $accessLog[] = [
                'type' => 'download',
                'ip' => $ip,
                'downloaded_at' => now()->toIso8601String(),
            ];

            $this->update(['access_log' => $accessLog]);
        }
    }

    /**
     * Revoke share
     */
    public function revoke(): bool
    {
        return $this->update(['is_active' => false]);
    }

    /**
     * Extend expiration
     */
    public function extendExpiration(int $days): bool
    {
        $newExpiration = $this->expires_at
            ? $this->expires_at->addDays($days)
            : now()->addDays($days);

        return $this->update(['expires_at' => $newExpiration]);
    }

    /**
     * Update permissions
     */
    public function updatePermissions(array $permissions): bool
    {
        // Valider les permissions
        $validPermissions = [
            self::PERMISSION_VIEW,
            self::PERMISSION_DOWNLOAD,
            self::PERMISSION_EDIT,
            self::PERMISSION_COMMENT,
        ];

        $permissions = array_intersect($permissions, $validPermissions);

        // La permission "view" est toujours requise
        if (! in_array(self::PERMISSION_VIEW, $permissions)) {
            $permissions[] = self::PERMISSION_VIEW;
        }

        return $this->update(['permissions' => $permissions]);
    }

    /**
     * Scope for active shares
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope for expired shares
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }

    /**
     * Scope for shares by type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
