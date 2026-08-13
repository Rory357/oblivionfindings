<?php

namespace App\Domain\SecurityDevices\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DeviceEvent extends Model
{
    // Events are append-only — no updated_at.
    public $timestamps = false;

    protected $table = 'device_events';

    protected $fillable = [
        'device_id',
        'event_type',
        'severity',
        'payload',
        'source',
        'occurred_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function signalOutbox(): HasOne
    {
        return $this->hasOne(DeviceEventSignalOutbox::class, 'device_event_id');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeUnprocessed($query)
    {
        return $query->whereNull('processed_at');
    }

    public function scopeOfType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    public function scopeSince($query, \DateTimeInterface $since)
    {
        return $query->where('occurred_at', '>=', $since);
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }
}
