<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'permissions',
        'is_system',
        'level',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_system' => 'boolean',
        'level' => 'integer',
    ];

    /**
     * System role slugs
     */
    const SUPER_ADMIN = 'super_admin';
    const TENANT_ADMIN = 'tenant_admin';
    const MANAGER = 'manager';
    const EDITOR = 'editor';
    const VIEWER = 'viewer';

    /**
     * Get the tenant that owns the role
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the users that have this role
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot('tenant_id', 'assigned_at', 'assigned_by')
            ->withTimestamps();
    }

    /**
     * Check if role has a specific permission
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->slug === self::SUPER_ADMIN) {
            return true; // Super admin has all permissions
        }

        $permissions = $this->permissions ?? [];
        
        // Check for wildcard permissions
        foreach ($permissions as $perm) {
            if ($perm === '*' || $perm === $permission) {
                return true;
            }
            
            // Check for category wildcard (e.g., 'documents.*')
            if (str_ends_with($perm, '.*')) {
                $category = str_replace('.*', '', $perm);
                if (str_starts_with($permission, $category . '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Grant a permission to this role
     */
    public function grantPermission(string $permission): void
    {
        $permissions = $this->permissions ?? [];
        
        if (!in_array($permission, $permissions)) {
            $permissions[] = $permission;
            $this->permissions = $permissions;
            $this->save();
        }
    }

    /**
     * Revoke a permission from this role
     */
    public function revokePermission(string $permission): void
    {
        $permissions = $this->permissions ?? [];
        $permissions = array_values(array_diff($permissions, [$permission]));
        $this->permissions = $permissions;
        $this->save();
    }

    /**
     * Sync permissions for this role
     */
    public function syncPermissions(array $permissions): void
    {
        $this->permissions = array_values(array_unique($permissions));
        $this->save();
    }

    /**
     * Get all available permissions for this role's level
     */
    public function getAvailablePermissions(): array
    {
        return Permission::all()->pluck('slug')->toArray();
    }

    /**
     * Check if role is higher level than another role
     */
    public function isHigherThan(Role $role): bool
    {
        return $this->level < $role->level;
    }

    /**
     * Check if role can manage another role
     */
    public function canManage(Role $role): bool
    {
        // Super admin can manage all roles
        if ($this->slug === self::SUPER_ADMIN) {
            return true;
        }

        // Tenant admin can manage roles in their tenant except super admin
        if ($this->slug === self::TENANT_ADMIN && $role->slug !== self::SUPER_ADMIN) {
            return $this->tenant_id === $role->tenant_id;
        }

        // Others can only manage lower level roles in same tenant
        return $this->tenant_id === $role->tenant_id && $this->isHigherThan($role);
    }

    /**
     * Scope to get roles for a specific tenant
     */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where(function ($q) use ($tenantId) {
            $q->where('tenant_id', $tenantId)
              ->orWhereNull('tenant_id'); // Include system roles
        });
    }

    /**
     * Get default permissions for a role
     */
    public static function getDefaultPermissions(string $roleSlug): array
    {
        $permissions = [
            self::SUPER_ADMIN => ['*'], // All permissions
            
            self::TENANT_ADMIN => [
                'users.*',
                'documents.*',
                'tools.*',
                'settings.*',
                'activity.*',
                'storage.*',
                'invitations.*',
                'roles.view',
                'roles.create',
                'roles.update',
                'roles.delete',
            ],
            
            self::MANAGER => [
                'users.view',
                'users.create',
                'users.update',
                'documents.*',
                'tools.*',
                'activity.view',
                'storage.view',
                'invitations.create',
                'invitations.view',
            ],
            
            self::EDITOR => [
                'documents.view',
                'documents.create',
                'documents.update',
                'documents.delete',
                'documents.share',
                'documents.download',
                'tools.merge',
                'tools.split',
                'tools.rotate',
                'tools.compress',
                'tools.watermark',
                'tools.ocr',
                'tools.extract',
            ],
            
            self::VIEWER => [
                'documents.view',
                'documents.download',
            ],
        ];

        return $permissions[$roleSlug] ?? [];
    }
}