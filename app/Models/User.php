<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'role_id',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'phone',
        'is_active',
        'preferences',
        'last_login_at',
        'last_login_ip',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'two_factor_confirmed_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'preferences' => 'array',
        'two_factor_recovery_codes' => 'array',
    ];
    
    /**
     * Default attributes
     */
    protected $attributes = [
        'is_active' => true,
        'preferences' => '{}',
    ];
    
    /**
     * Boot method
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($user) {
            // Assigner au tenant de l'utilisateur créateur si disponible
            if (!$user->tenant_id && auth()->check() && auth()->user()->tenant_id) {
                $user->tenant_id = auth()->user()->tenant_id;
            }
        });
    }
    
    /**
     * Get the tenant relationship
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
    
    /**
     * Get documents relationship
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
    
    /**
     * Get conversions relationship
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(Conversion::class);
    }
    
    /**
     * Get shares created by this user
     */
    public function shares(): HasMany
    {
        return $this->hasMany(Share::class, 'shared_by');
    }
    
    /**
     * Get shares received by this user
     */
    public function receivedShares(): HasMany
    {
        return $this->hasMany(Share::class, 'shared_with');
    }
    
    /**
     * Get activity logs caused by this user
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'causer_id');
    }
    
    /**
     * Check if 2FA is enabled
     */
    public function hasTwoFactorEnabled(): bool
    {
        return !is_null($this->two_factor_secret) && !is_null($this->two_factor_confirmed_at);
    }
    
    /**
     * Enable two factor authentication
     */
    public function enableTwoFactor(string $secret, array $recoveryCodes): void
    {
        $this->update([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => now(),
        ]);
    }
    
    /**
     * Disable two factor authentication
     */
    public function disableTwoFactor(): void
    {
        $this->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }
    
    /**
     * Generate new recovery codes
     */
    public function generateRecoveryCodes(): array
    {
        $codes = collect(range(1, 8))->map(function () {
            return Str::random(10) . '-' . Str::random(10);
        })->toArray();
        
        $this->update([
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
        ]);
        
        return $codes;
    }
    
    /**
     * Verify a recovery code
     */
    public function verifyRecoveryCode(string $code): bool
    {
        if (!$this->two_factor_recovery_codes) {
            return false;
        }
        
        $codes = json_decode(decrypt($this->two_factor_recovery_codes), true);
        
        if (in_array($code, $codes)) {
            // Remove the used code
            $codes = array_diff($codes, [$code]);
            $this->update([
                'two_factor_recovery_codes' => encrypt(json_encode(array_values($codes))),
            ]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Get the roles relationship
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('tenant_id', 'assigned_at', 'assigned_by')
            ->withTimestamps();
    }
    
    /**
     * Get the primary role for the current tenant
     */
    public function role(): ?Role
    {
        $roleId = Cache::remember("user_{$this->id}_role_{$this->tenant_id}", 3600, function () {
            return $this->roles()
                ->wherePivot('tenant_id', $this->tenant_id)
                ->orderBy('level')
                ->first();
        });
        
        return $roleId;
    }
    
    /**
     * Assign a role to the user
     */
    public function assignRole(Role|string $role, ?int $tenantId = null): void
    {
        if (is_string($role)) {
            $role = Role::where('slug', $role)
                ->where(function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)
                      ->orWhereNull('tenant_id');
                })
                ->firstOrFail();
        }
        
        // For super admin role, always use null tenant_id
        if ($role->slug === Role::SUPER_ADMIN) {
            $tenantId = null;
        } else {
            $tenantId = $tenantId ?? $this->tenant_id;
        }
        
        $this->roles()->attach($role->id, [
            'tenant_id' => $tenantId,
            'assigned_at' => now(),
            'assigned_by' => auth()->id(),
        ]);
        
        $this->clearPermissionCache();
    }
    
    /**
     * Remove a role from the user
     */
    public function removeRole(Role|string $role, ?int $tenantId = null): void
    {
        if (is_string($role)) {
            $role = Role::where('slug', $role)->first();
            if (!$role) return;
        }
        
        $this->roles()->wherePivot('tenant_id', $tenantId ?? $this->tenant_id)
            ->detach($role->id);
        
        $this->clearPermissionCache();
    }
    
    /**
     * Sync roles for the user
     */
    public function syncRoles(array $roleIds, ?int $tenantId = null): void
    {
        $tenantId = $tenantId ?? $this->tenant_id;
        
        // Remove existing roles for this tenant
        $this->roles()->wherePivot('tenant_id', $tenantId)->detach();
        
        // Attach new roles
        foreach ($roleIds as $roleId) {
            $this->roles()->attach($roleId, [
                'tenant_id' => $tenantId,
                'assigned_at' => now(),
                'assigned_by' => auth()->id(),
            ]);
        }
        
        $this->clearPermissionCache();
    }
    
    /**
     * Check if user has a specific role
     */
    public function hasRole(string $roleSlug, ?int $tenantId = null): bool
    {
        // For super admin, check with null tenant_id
        if ($roleSlug === Role::SUPER_ADMIN) {
            return Cache::remember("user_{$this->id}_has_role_{$roleSlug}_null", 3600, function () use ($roleSlug) {
                return $this->roles()
                    ->where('slug', $roleSlug)
                    ->wherePivot('tenant_id', null)
                    ->exists();
            });
        }
        
        $tenantId = $tenantId ?? $this->tenant_id;
        
        return Cache::remember("user_{$this->id}_has_role_{$roleSlug}_{$tenantId}", 3600, function () use ($roleSlug, $tenantId) {
            return $this->roles()
                ->where('slug', $roleSlug)
                ->wherePivot('tenant_id', $tenantId)
                ->exists();
        });
    }
    
    /**
     * Check if user has any of the given roles
     */
    public function hasAnyRole(array $roles, ?int $tenantId = null): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role, $tenantId)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if user has all of the given roles
     */
    public function hasAllRoles(array $roles, ?int $tenantId = null): bool
    {
        foreach ($roles as $role) {
            if (!$this->hasRole($role, $tenantId)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Check if user has a specific permission
     */
    public function hasPermission(string $permission, ?int $tenantId = null): bool
    {
        $tenantId = $tenantId ?? $this->tenant_id;
        
        return Cache::remember("user_{$this->id}_permission_{$permission}_{$tenantId}", 3600, function () use ($permission, $tenantId) {
            $roles = $this->roles()->wherePivot('tenant_id', $tenantId)->get();
            
            foreach ($roles as $role) {
                if ($role->hasPermission($permission)) {
                    return true;
                }
            }
            
            return false;
        });
    }
    
    /**
     * Check if user has any of the given permissions
     */
    public function hasAnyPermission(array $permissions, ?int $tenantId = null): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission, $tenantId)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if user has all of the given permissions
     */
    public function hasAllPermissions(array $permissions, ?int $tenantId = null): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission, $tenantId)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Get all permissions for the user
     */
    public function getPermissions(?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->tenant_id;
        
        return Cache::remember("user_{$this->id}_all_permissions_{$tenantId}", 3600, function () use ($tenantId) {
            $permissions = [];
            $roles = $this->roles()->wherePivot('tenant_id', $tenantId)->get();
            
            foreach ($roles as $role) {
                $permissions = array_merge($permissions, $role->permissions ?? []);
            }
            
            return array_unique($permissions);
        });
    }
    
    /**
     * Clear permission cache for the user
     */
    public function clearPermissionCache(): void
    {
        Cache::forget("user_{$this->id}_role_{$this->tenant_id}");
        
        // Clear all permission caches for this user
        $patterns = [
            "user_{$this->id}_has_role_*",
            "user_{$this->id}_permission_*",
            "user_{$this->id}_all_permissions_*",
        ];
        
        foreach ($patterns as $pattern) {
            // Note: This requires a cache driver that supports pattern deletion
            // For Redis, you might need to use Cache::getRedis()->keys() and delete
        }
    }
    
    /**
     * Check if user is a super admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN);
    }
    
    /**
     * Check if user is a tenant admin
     */
    public function isTenantAdmin(): bool
    {
        // Check for generic tenant_admin or tenant-specific role
        if ($this->hasRole(Role::TENANT_ADMIN)) {
            return true;
        }
        
        // Check for tenant-specific role
        if ($this->tenant_id) {
            return $this->hasRole(Role::TENANT_ADMIN . '_' . $this->tenant_id);
        }
        
        return false;
    }
    
    /**
     * Check if user can access a specific tenant
     */
    public function canAccessTenant(Tenant $tenant): bool
    {
        return $this->tenant_id === $tenant->id || $this->isSuperAdmin();
    }
    
    /**
     * Check if user can manage another user
     */
    public function canManageUser(User $targetUser): bool
    {
        // Super admin can manage all users
        if ($this->isSuperAdmin()) {
            return true;
        }
        
        // Must be in same tenant
        if ($this->tenant_id !== $targetUser->tenant_id) {
            return false;
        }
        
        // Check if user has permission to manage users
        if (!$this->hasPermission('users.update')) {
            return false;
        }
        
        // Check role hierarchy
        $userRole = $this->role();
        $targetRole = $targetUser->role();
        
        if ($userRole && $targetRole) {
            return $userRole->canManage($targetRole);
        }
        
        return false;
    }
    
    /**
     * Get user's storage usage
     */
    public function getStorageUsage(): int
    {
        return $this->documents()->sum('size');
    }
    
    /**
     * Update last login information
     */
    public function updateLastLogin(?string $ip = null): void
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);
    }
    
    /**
     * Get user preference
     */
    public function getPreference(string $key, $default = null)
    {
        return data_get($this->preferences, $key, $default);
    }
    
    /**
     * Set user preference
     */
    public function setPreference(string $key, $value): bool
    {
        $preferences = $this->preferences ?? [];
        data_set($preferences, $key, $value);
        return $this->update(['preferences' => $preferences]);
    }
    
    /**
     * Check if user account is active
     */
    public function isActive(): bool
    {
        return $this->is_active && 
               $this->tenant && 
               $this->tenant->is_active && 
               $this->tenant->isSubscriptionActive();
    }
    
    /**
     * Get display name
     */
    public function getDisplayName(): string
    {
        return $this->name ?: explode('@', $this->email)[0];
    }
}