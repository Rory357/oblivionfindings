<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Concerns\WritesLegacyStorageContext;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Monitor extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected static function newFactory(): MonitorFactory
    {
        return MonitorFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $monitor): void {
            if (! $monitor->isDirty('effective_state')) {
                $monitor->effective_state = $monitor->current_state ?? MonitorState::Unknown;
            }
        });
    }

    protected $fillable = [
        'device_id',
        'profile_id',
        'collector_id',
        'kind',
        'name',
        'target',
        'config',
        'current_state',
        'effective_state',
        'pending_state',
        'pending_count',
        'pending_since_at',
        'root_cause_monitor_id',
        'suppression_reason',
        'suppressed_at',
        'affects_availability',
        'is_enabled',
        'last_observation_at',
        'last_state_changed_at',
        'suppressed_until',
    ];

    protected $casts = [
        'kind' => MonitorKind::class,
        'current_state' => MonitorState::class,
        'effective_state' => MonitorState::class,
        'pending_state' => MonitorState::class,
        'config' => 'array',
        'pending_count' => 'integer',
        'affects_availability' => 'boolean',
        'is_enabled' => 'boolean',
        'last_observation_at' => 'datetime',
        'last_state_changed_at' => 'datetime',
        'pending_since_at' => 'immutable_datetime',
        'suppressed_at' => 'immutable_datetime',
        'suppressed_until' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MonitoringProfile::class, 'profile_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(MonitoringCollector::class, 'collector_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(MonitorObservation::class, 'monitor_id');
    }

    public function rootCauseMonitor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_cause_monitor_id');
    }

    public function upstreamDependencies(): HasMany
    {
        return $this->hasMany(MonitorDependency::class, 'downstream_monitor_id');
    }

    public function downstreamDependencies(): HasMany
    {
        return $this->hasMany(MonitorDependency::class, 'upstream_monitor_id');
    }
}
