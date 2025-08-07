<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * Check if user is a super admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->email === config('app.super_admin_email', 'admin@giga-pdf.com');
    }
    
    /**
     * Check if user is a tenant admin
     */
    public function isTenantAdmin(): bool
    {
        // TODO: Implémenter avec le système de rôles
        return $this->role_id === 1;
    }
    
    /**
     * Check if user can access a specific tenant
     */
    public function canAccessTenant(Tenant $tenant): bool
    {
        return $this->tenant_id === $tenant->id || $this->isSuperAdmin();
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