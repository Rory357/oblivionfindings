<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class IncidentLifecycleSignal extends Model
{
    public const TYPE_CLOSED = 'incident_closed';

    public const TYPE_REOPENED = 'incident_reopened';

    protected $fillable = [
        'client_incident_id',
        'actor_user_id',
        'site_id',
        'client_id',
        'hs_event_id',
        'control_room_alert_id',
        'sequence',
        'signal_type',
        'incident_source',
        'from_status',
        'target_status',
        'effective_at',
        'idempotency_key',
        'payload',
    ];

    protected $casts = [
        'effective_at' => 'datetime',
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Incident lifecycle signals are immutable source evidence.');
        });

        static::deleting(function (): never {
            throw new LogicException('Incident lifecycle signals cannot be deleted.');
        });
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'client_incident_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function hsEvent(): BelongsTo
    {
        return $this->belongsTo(HsEvent::class);
    }

    public function controlRoomAlert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class);
    }

    public function outbox(): HasOne
    {
        return $this->hasOne(IncidentLifecycleSignalOutbox::class);
    }
}
