<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoneWorkerSession extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'user_id',
        'site_id',
        'client_id',
        'started_at',
        'expected_end_at',
        'ended_at',
        'location',
        'location_lat',
        'location_lng',
        'activity_description',
        'check_in_interval_minutes',
        'last_check_in_at',
        'status',
        'emergency_triggered_at',
        'emergency_notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expected_end_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_check_in_at' => 'datetime',
        'emergency_triggered_at' => 'datetime',
        'location_lat' => 'decimal:7',
        'location_lng' => 'decimal:7',
    ];

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(LoneWorkerCheckIn::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(LoneWorkerAlert::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }

    public function scopeEmergency($query)
    {
        return $query->where('status', 'emergency');
    }

    // Helpers

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'overdue';
    }

    public function isCheckInOverdue(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $lastCheckIn = $this->last_check_in_at ?? $this->started_at;

        return $lastCheckIn->addMinutes($this->check_in_interval_minutes)->isPast();
    }
}
