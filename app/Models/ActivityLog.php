<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use HasFactory;
    
    /**
     * The table associated with the model.
     */
    protected $table = 'activity_log';
    
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'tenant_id',
        'log_name',
        'description',
        'subject_type',
        'subject_id',
        'causer_type',
        'causer_id',
        'properties',
        'event',
        'batch_uuid',
        'ip_address',
        'user_agent',
        'method',
        'url',
        'response_time',
    ];
    
    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'properties' => 'array',
        'response_time' => 'integer',
    ];
    
    /**
     * Default attributes
     */
    protected $attributes = [
        'properties' => '{}',
    ];
    
    /**
     * Boot method
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($log) {
            // Assigner le tenant_id si disponible
            if (!$log->tenant_id && auth()->check() && auth()->user()->tenant_id) {
                $log->tenant_id = auth()->user()->tenant_id;
            }
            
            // Assigner le causer si disponible
            if (!$log->causer_id && auth()->check()) {
                $log->causer_type = get_class(auth()->user());
                $log->causer_id = auth()->id();
            }
            
            // Capturer les informations de la requête
            if (request()) {
                $log->ip_address = request()->ip();
                $log->user_agent = request()->userAgent();
                $log->method = request()->method();
                $log->url = request()->fullUrl();
            }
        });
    }
    
    /**
     * Get tenant relationship
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
    
    /**
     * Get the subject of the activity.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
    
    /**
     * Get the causer of the activity.
     */
    public function causer(): MorphTo
    {
        return $this->morphTo();
    }
    
    /**
     * Get causer name
     */
    public function getCauserName(): string
    {
        if ($this->causer) {
            if ($this->causer instanceof User) {
                return $this->causer->getDisplayName();
            }
            
            return class_basename($this->causer);
        }
        
        return 'System';
    }
    
    /**
     * Get subject name
     */
    public function getSubjectName(): string
    {
        if ($this->subject) {
            if (method_exists($this->subject, 'getActivitySubjectName')) {
                return $this->subject->getActivitySubjectName();
            }
            
            if (property_exists($this->subject, 'name')) {
                return $this->subject->name;
            }
            
            if (property_exists($this->subject, 'original_name')) {
                return $this->subject->original_name;
            }
            
            return class_basename($this->subject) . ' #' . $this->subject->id;
        }
        
        return 'N/A';
    }
    
    /**
     * Get formatted description
     */
    public function getFormattedDescription(): string
    {
        $description = $this->description;
        
        // Remplacer les variables dans la description
        if ($this->causer) {
            $description = str_replace(':causer', $this->getCauserName(), $description);
        }
        
        if ($this->subject) {
            $description = str_replace(':subject', $this->getSubjectName(), $description);
        }
        
        // Remplacer les propriétés
        if ($this->properties) {
            foreach ($this->properties as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $description = str_replace(':' . $key, $value, $description);
                }
            }
        }
        
        return $description;
    }
    
    /**
     * Get activity icon
     */
    public function getIcon(): string
    {
        return match($this->event) {
            'created' => 'plus-circle',
            'updated' => 'pencil',
            'deleted' => 'trash',
            'restored' => 'refresh',
            'uploaded' => 'upload',
            'downloaded' => 'download',
            'shared' => 'share',
            'viewed' => 'eye',
            'login' => 'login',
            'logout' => 'logout',
            'failed_login' => 'shield-exclamation',
            default => 'information-circle'
        };
    }
    
    /**
     * Get activity color
     */
    public function getColor(): string
    {
        return match($this->event) {
            'created' => 'green',
            'updated' => 'blue',
            'deleted' => 'red',
            'restored' => 'yellow',
            'uploaded' => 'indigo',
            'downloaded' => 'purple',
            'shared' => 'cyan',
            'viewed' => 'gray',
            'login' => 'green',
            'logout' => 'gray',
            'failed_login' => 'red',
            default => 'gray'
        };
    }
    
    /**
     * Log activity helper
     */
    public static function log(
        string $description,
        $subject = null,
        array $properties = [],
        string $event = null,
        string $logName = 'default'
    ): self {
        $log = new static();
        $log->log_name = $logName;
        $log->description = $description;
        $log->event = $event;
        $log->properties = $properties;
        
        if ($subject) {
            $log->subject_type = get_class($subject);
            $log->subject_id = $subject->id;
        }
        
        $log->save();
        
        return $log;
    }
    
    /**
     * Scope for filtering by log name
     */
    public function scopeInLog($query, string $logName)
    {
        return $query->where('log_name', $logName);
    }
    
    /**
     * Scope for filtering by event
     */
    public function scopeForEvent($query, string $event)
    {
        return $query->where('event', $event);
    }
    
    /**
     * Scope for filtering by subject
     */
    public function scopeForSubject($query, Model $subject)
    {
        return $query->where('subject_type', get_class($subject))
                     ->where('subject_id', $subject->id);
    }
    
    /**
     * Scope for filtering by causer
     */
    public function scopeByCauser($query, Model $causer)
    {
        return $query->where('causer_type', get_class($causer))
                     ->where('causer_id', $causer->id);
    }
    
    /**
     * Scope for recent logs
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
    
    /**
     * Clean old logs
     */
    public static function cleanOld(int $days = 90): int
    {
        return static::where('created_at', '<', now()->subDays($days))->delete();
    }
}