<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Monitor extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'device_id',
        'profile_id',
        'collector_id',
        'kind',
        'name',
        'target',
        'config',
        'current_state',
        'pending_state',
        'pending_count',
        'affects_availability',
        'is_enabled',
        'last_observation_at',
        'last_state_changed_at',
        'suppressed_until',
    ];

    protected $casts = [
        'kind' => MonitorKind::class,
        'current_state' => MonitorState::class,
        'pending_state' => MonitorState::class,
        'config' => 'array',
        'pending_count' => 'integer',
        'affects_availability' => 'boolean',
        'is_enabled' => 'boolean',
        'last_observation_at' => 'datetime',
        'last_state_changed_at' => 'datetime',
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

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
