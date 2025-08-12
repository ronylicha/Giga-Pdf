<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Certificate extends Model
{
    use HasFactory;
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'type',
        'key_size',
        'common_name',
        'organization',
        'organizational_unit',
        'country',
        'state',
        'locality',
        'email',
        'certificate_path',
        'private_key_path',
        'password',
        'is_default',
        'is_active',
        'valid_from',
        'valid_to',
        'serial_number',
        'fingerprint',
        'metadata',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'metadata' => 'array',
        'key_size' => 'integer',
    ];

    protected $hidden = [
        'password',
        'private_key_path',
    ];

    /**
     * Encrypt sensitive attributes
     */
    public function setCertificatePathAttribute($value)
    {
        $this->attributes['certificate_path'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getCertificatePathAttribute($value)
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setPrivateKeyPathAttribute($value)
    {
        $this->attributes['private_key_path'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getPrivateKeyPathAttribute($value)
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getPasswordAttribute($value)
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * Scope for active certificates
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                    ->where('valid_from', '<=', now())
                    ->where('valid_to', '>=', now());
    }

    /**
     * Scope for default certificate
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Check if certificate is valid
     */
    public function isValid()
    {
        return $this->is_active
            && $this->valid_from <= now()
            && $this->valid_to >= now();
    }

    /**
     * Get days until expiration
     */
    public function getDaysUntilExpirationAttribute()
    {
        return now()->diffInDays($this->valid_to, false);
    }

    /**
     * Check if certificate is expiring soon (30 days)
     */
    public function isExpiringSoon()
    {
        return $this->days_until_expiration > 0 && $this->days_until_expiration <= 30;
    }

    /**
     * Check if certificate is expired
     */
    public function isExpired()
    {
        return $this->valid_to < now();
    }
}
