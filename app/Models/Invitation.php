<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Invitation extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'email',
        'token',
        'role',
        'permissions',
        'invited_by',
        'accepted_at',
        'expires_at',
        'first_name',
        'last_name',
        'message',
    ];

    protected $casts = [
        'permissions' => 'array',
        'accepted_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invitation) {
            // Generate unique token
            $invitation->token = Str::random(64);
            
            // Set expiration to 7 days from now if not set
            if (!$invitation->expires_at) {
                $invitation->expires_at = now()->addDays(7);
            }
        });
    }

    /**
     * Get the user who sent the invitation
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Get the tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Check if invitation has expired
     */
    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if invitation has been accepted
     */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Mark invitation as accepted
     */
    public function markAsAccepted(): void
    {
        $this->update(['accepted_at' => now()]);
    }

    /**
     * Get the invitation URL
     */
    public function getInvitationUrl(): string
    {
        return route('invitations.accept', ['token' => $this->token]);
    }
}