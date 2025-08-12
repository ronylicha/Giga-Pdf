<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory;
    use Notifiable;
    use HasRoles;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'phone',
        'is_active',
        'preferences',
        'last_login_at',
        'last_login_ip',
        'two_factor_required',
        'password_changed_at',
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
        'password_changed_at' => 'datetime',
        'preferences' => 'array',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_secret' => 'encrypted',
        'is_active' => 'boolean',
        'two_factor_required' => 'boolean',
    ];

    /**
     * Get the tenant that owns the user
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get user's documents
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Get user's conversions
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(Conversion::class);
    }

    /**
     * Get user's shares
     */
    public function shares(): HasMany
    {
        return $this->hasMany(Share::class, 'shared_by');
    }

    /**
     * Get shares with this user
     */
    public function sharedWithMe(): HasMany
    {
        return $this->hasMany(Share::class, 'shared_with');
    }

    /**
     * Get user's activity logs
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * Check if user is super admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }

    /**
     * Check if user is tenant admin
     */
    public function isTenantAdmin(): bool
    {
        return $this->hasRole('tenant-admin');
    }

    /**
     * Check if user is manager
     */
    public function isManager(): bool
    {
        return $this->hasRole('manager');
    }

    /**
     * Check if user is editor
     */
    public function isEditor(): bool
    {
        return $this->hasRole('editor');
    }

    /**
     * Check if user is viewer
     */
    public function isViewer(): bool
    {
        return $this->hasRole('viewer');
    }

    /**
     * Check if 2FA is enabled
     */
    public function hasTwoFactorEnabled(): bool
    {
        return ! is_null($this->two_factor_secret);
    }

    /**
     * Check if 2FA is confirmed
     */
    public function hasTwoFactorConfirmed(): bool
    {
        return ! is_null($this->two_factor_confirmed_at);
    }

    /**
     * Check if user account is active
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if user account is suspended
     */
    public function isSuspended(): bool
    {
        return ! $this->is_active;
    }

    /**
     * Suspend user account
     */
    public function suspend(): void
    {
        $this->update(['is_active' => false]);
    }

    /**
     * Activate user account
     */
    public function activate(): void
    {
        $this->update(['is_active' => true]);
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

        // Must be in the same tenant
        if ($this->tenant_id !== $targetUser->tenant_id) {
            return false;
        }

        // Tenant admin can manage all users in their tenant
        if ($this->isTenantAdmin()) {
            return ! $targetUser->isTenantAdmin(); // Cannot manage other tenant admins
        }

        // Manager can manage editors and viewers
        if ($this->isManager()) {
            return $targetUser->isEditor() || $targetUser->isViewer();
        }

        return false;
    }

    /**
     * Update last login information
     */
    public function updateLastLogin(): void
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ]);
    }

    /**
     * Generate two factor recovery codes
     */
    public function generateTwoFactorRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }

        $this->update([
            'two_factor_recovery_codes' => $codes,
        ]);

        return $codes;
    }

    /**
     * Enable two-factor authentication for the user
     */
    public function enableTwoFactor(string $secret, array $recoveryCodes): void
    {
        $this->update([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Disable two-factor authentication for the user
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
     * Verify a recovery code and consume it if valid
     */
    public function verifyRecoveryCode(string $code): bool
    {
        $stored = $this->two_factor_recovery_codes ?: [];
        if (! is_array($stored)) {
            return false;
        }
        $index = array_search($code, $stored, true);
        if ($index === false) {
            return false;
        }
        unset($stored[$index]);
        $this->two_factor_recovery_codes = array_values($stored);
        $this->save();

        return true;
    }

    /**
     * Regenerate new recovery codes set
     */
    public function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
        }
        $this->two_factor_recovery_codes = $codes;
        $this->save();

        return $codes;
    }

    /**
     * Check if password needs to be changed
     */
    public function needsPasswordChange(): bool
    {
        if (! $this->password_changed_at) {
            return true;
        }

        // Password expires after 90 days
        return $this->password_changed_at->lt(now()->subDays(90));
    }
}
