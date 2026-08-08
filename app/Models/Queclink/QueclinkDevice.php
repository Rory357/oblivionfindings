<?php

namespace App\Models\Queclink;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QueclinkDevice extends Model
{
    use WritesLegacyStorageContext;

    protected $table = 'queclink_devices';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAIRED = 'paired';

    public const STATUS_REJECTED = 'rejected';

    public const PAIRING_VEHICLE = 'vehicle';

    public const PAIRING_STAFF = 'staff';

    public const PAIRING_CLIENT = 'client';

    public const CONN_CONNECTED = 'connected';

    public const CONN_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'imei',
        'device_id',
        'binding_uuid',
        'model_hint',
        'protocol_version',
        'firmware_version',
        'status',
        'pending_pairing_type',
        'connection_state',
        'current_session_id',
        'remote_address',
        'first_seen_at',
        'last_seen_at',
        'last_frame_at',
        'last_count_number',
        'notes',
    ];

    protected $hidden = [
        'binding_uuid',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_frame_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function rawFrames(): HasMany
    {
        return $this->hasMany(QueclinkRawFrame::class, 'queclink_device_id');
    }

    public function pendingCommands(): HasMany
    {
        return $this->hasMany(QueclinkPendingCommand::class, 'queclink_device_id');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING);
    }

    public function scopePaired(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PAIRED);
    }

    public function scopeRejected(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_REJECTED);
    }

    public function scopeConnected(Builder $q): Builder
    {
        return $q->where('connection_state', self::CONN_CONNECTED);
    }

    public function isPaired(): bool
    {
        return $this->status === self::STATUS_PAIRED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
