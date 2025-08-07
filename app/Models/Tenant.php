<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasFactory;
    
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'slug',
        'domain',
        'settings',
        'max_storage_gb',
        'max_users',
        'max_file_size_mb',
        'features',
        'subscription_plan',
        'subscription_expires_at',
        'is_active',
    ];
    
    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'settings' => 'array',
        'features' => 'array',
        'subscription_expires_at' => 'datetime',
        'is_active' => 'boolean',
        'max_storage_gb' => 'integer',
        'max_users' => 'integer',
        'max_file_size_mb' => 'integer',
    ];
    
    /**
     * Default settings for new tenants
     */
    protected $attributes = [
        'settings' => '{}',
        'features' => '[]',
        'max_storage_gb' => 10,
        'max_users' => 5,
        'max_file_size_mb' => 100,
        'subscription_plan' => 'basic',
        'is_active' => true,
    ];
    
    /**
     * Boot method
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($tenant) {
            // Générer un slug unique si non fourni
            if (empty($tenant->slug)) {
                $tenant->slug = Str::slug($tenant->name);
                
                // S'assurer que le slug est unique
                $originalSlug = $tenant->slug;
                $count = 1;
                while (static::where('slug', $tenant->slug)->exists()) {
                    $tenant->slug = $originalSlug . '-' . $count;
                    $count++;
                }
            }
            
            // Définir les features par défaut selon le plan
            if (empty($tenant->features)) {
                $tenant->features = $tenant->getDefaultFeatures();
            }
        });
    }
    
    /**
     * Get users relationship
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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
     * Get activity logs relationship
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
    
    /**
     * Check if tenant has a specific feature
     */
    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? []);
    }
    
    /**
     * Get default features based on subscription plan
     */
    public function getDefaultFeatures(): array
    {
        return match($this->subscription_plan) {
            'enterprise' => [
                'unlimited_users',
                'unlimited_storage',
                'api_access',
                'white_label',
                'priority_support',
                'advanced_security',
                'custom_domain',
                'sso',
                'audit_logs',
                'digital_signatures',
                'ocr',
                'redaction',
                'collaboration',
                'advanced_editor',
                'batch_processing',
                'webhooks',
                'custom_integrations'
            ],
            'professional' => [
                'api_access',
                'priority_support',
                'custom_domain',
                'audit_logs',
                'digital_signatures',
                'ocr',
                'redaction',
                'collaboration',
                'advanced_editor',
                'batch_processing'
            ],
            'basic' => [
                'basic_editor',
                'basic_conversions',
                'basic_sharing',
                'email_support'
            ],
            default => ['basic_conversions']
        };
    }
    
    /**
     * Get current storage usage in bytes
     */
    public function getStorageUsage(): int
    {
        return $this->documents()->sum('size');
    }
    
    /**
     * Get storage usage percentage
     */
    public function getStorageUsagePercentage(): float
    {
        $maxStorage = $this->max_storage_gb * 1024 * 1024 * 1024;
        $currentUsage = $this->getStorageUsage();
        
        if ($maxStorage == 0) {
            return 0;
        }
        
        return round(($currentUsage / $maxStorage) * 100, 2);
    }
    
    /**
     * Check if storage quota is exceeded
     */
    public function isStorageQuotaExceeded(): bool
    {
        return $this->getStorageUsage() >= ($this->max_storage_gb * 1024 * 1024 * 1024);
    }
    
    /**
     * Get available storage in bytes
     */
    public function getAvailableStorage(): int
    {
        $maxStorage = $this->max_storage_gb * 1024 * 1024 * 1024;
        $currentUsage = $this->getStorageUsage();
        
        return max(0, $maxStorage - $currentUsage);
    }
    
    /**
     * Check if user limit is reached
     */
    public function isUserLimitReached(): bool
    {
        return $this->users()->count() >= $this->max_users;
    }
    
    /**
     * Check if subscription is active
     */
    public function isSubscriptionActive(): bool
    {
        if (!$this->is_active) {
            return false;
        }
        
        if ($this->subscription_expires_at && $this->subscription_expires_at->isPast()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get plan limits
     */
    public function getPlanLimits(): array
    {
        return [
            'max_storage_gb' => $this->max_storage_gb,
            'max_users' => $this->max_users,
            'max_file_size_mb' => $this->max_file_size_mb,
            'features' => $this->features,
        ];
    }
    
    /**
     * Update settings
     */
    public function updateSettings(array $settings): bool
    {
        $currentSettings = $this->settings ?? [];
        $this->settings = array_merge($currentSettings, $settings);
        return $this->save();
    }
    
    /**
     * Get a specific setting
     */
    public function getSetting(string $key, $default = null)
    {
        return data_get($this->settings, $key, $default);
    }
}