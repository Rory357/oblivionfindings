<?php

namespace App\Models\ControlRoom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignalSource extends Model
{
    protected $table = 'control_room_signal_sources';

    protected $fillable = [
        'name',
        'slug',
        'vendor',
        'status',
        'config',
        'capabilities',
        'last_heartbeat_at',
        'last_signal_at',
        'signal_count_24h',
    ];

    protected $casts = [
        'config' => 'array',
        'capabilities' => 'array',
        'last_heartbeat_at' => 'datetime',
        'last_signal_at' => 'datetime',
    ];

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class, 'signal_source_id');
    }

    public function signalRules(): HasMany
    {
        return $this->hasMany(SignalRule::class, 'signal_source_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'signal_source_id');
    }

    public function maintenanceWindows(): HasMany
    {
        return $this->hasMany(MaintenanceWindow::class, 'signal_source_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeHealthy($query)
    {
        return $query->where('status', 'active')
            ->where('last_heartbeat_at', '>=', now()->subMinutes(5));
    }

    public function isHealthy(): bool
    {
        return $this->status === 'active'
            && $this->last_heartbeat_at
            && $this->last_heartbeat_at->gte(now()->subMinutes(5));
    }

    public function recordHeartbeat(): void
    {
        $this->update([
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);
    }

    public function recordSignal(): void
    {
        $this->increment('signal_count_24h');
        $this->update(['last_signal_at' => now()]);
    }
}
