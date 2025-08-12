<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversion extends Model
{
    use HasFactory;
    use BelongsToTenant;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'tenant_id',
        'document_id',
        'user_id',
        'from_format',
        'to_format',
        'status',
        'progress',
        'error_message',
        'options',
        'started_at',
        'completed_at',
        'result_document_id',
        'retry_count',
        'queue_id',
        'processing_time',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'options' => 'array',
        'progress' => 'integer',
        'retry_count' => 'integer',
        'processing_time' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Default attributes
     */
    protected $attributes = [
        'status' => 'pending',
        'progress' => 0,
        'retry_count' => 0,
        'options' => '{}',
    ];

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Boot method
     */
    protected static function boot()
    {
        parent::boot();

        static::updating(function ($conversion) {
            // Calculer le temps de traitement lors de la complétion
            if ($conversion->status === self::STATUS_COMPLETED &&
                $conversion->started_at &&
                $conversion->completed_at) {
                $conversion->processing_time = $conversion->started_at->diffInSeconds($conversion->completed_at);
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
     * Get result document relationship
     */
    public function resultDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'result_document_id');
    }

    /**
     * Get user relationship
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if conversion is pending
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if conversion is processing
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if conversion is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if conversion failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if conversion can be retried
     */
    public function canBeRetried(): bool
    {
        return $this->isFailed() && $this->retry_count < 3;
    }

    /**
     * Mark as processing
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
            'progress' => 0,
        ]);
    }

    /**
     * Mark as completed
     */
    public function markAsCompleted(?int $resultDocumentId = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
            'progress' => 100,
            'result_document_id' => $resultDocumentId,
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => now(),
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Update progress
     */
    public function updateProgress(int $progress): void
    {
        $this->update(['progress' => min(100, max(0, $progress))]);
    }

    /**
     * Increment retry count
     */
    public function incrementRetryCount(): void
    {
        $this->increment('retry_count');
    }

    /**
     * Get formatted processing time
     */
    public function getFormattedProcessingTime(): string
    {
        if (! $this->processing_time) {
            return 'N/A';
        }

        if ($this->processing_time < 60) {
            return $this->processing_time . ' secondes';
        }

        $minutes = floor($this->processing_time / 60);
        $seconds = $this->processing_time % 60;

        return sprintf('%d min %d sec', $minutes, $seconds);
    }

    /**
     * Get status color for UI
     */
    public function getStatusColor(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'gray',
            self::STATUS_PROCESSING => 'blue',
            self::STATUS_COMPLETED => 'green',
            self::STATUS_FAILED => 'red',
            default => 'gray'
        };
    }

    /**
     * Get status label
     */
    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'En attente',
            self::STATUS_PROCESSING => 'En cours',
            self::STATUS_COMPLETED => 'Terminé',
            self::STATUS_FAILED => 'Échoué',
            default => 'Inconnu'
        };
    }

    /**
     * Get format label
     */
    public function getFormatLabel(): string
    {
        return strtoupper($this->from_format) . ' → ' . strtoupper($this->to_format);
    }

    /**
     * Scope for pending conversions
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope for processing conversions
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope for completed conversions
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope for failed conversions
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope for recent conversions
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
