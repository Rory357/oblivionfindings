<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RosterSuggestionRun extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'organization_id',
        'site_id',
        'roster_period_id',
        'requested_by',
        'week_start',
        'week_end',
        'status',
        'strategy',
        'parameters',
        'totals',
        'started_at',
        'completed_at',
        'expires_at',
        'failure_message',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'parameters' => 'array',
        'totals' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function period()
    {
        return $this->belongsTo(RosterPeriod::class, 'roster_period_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function suggestions()
    {
        return $this->hasMany(RosterSuggestion::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThan($this->expires_at);
    }
}
