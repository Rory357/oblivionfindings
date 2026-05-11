<?php

namespace App\Models\Queclink;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueclinkRawFrame extends Model
{
    protected $table = 'queclink_raw_frames';

    public const UPDATED_AT = null;

    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const FRAME_RESP = 'RESP';
    public const FRAME_ACK = 'ACK';
    public const FRAME_SACK = 'SACK';
    public const FRAME_BUFF = 'BUFF';
    public const FRAME_AT = 'AT';
    public const FRAME_UNKNOWN = 'unknown';

    protected $fillable = [
        'queclink_device_id',
        'imei',
        'tenant_id',
        'direction',
        'frame_type',
        'command_word',
        'raw_frame',
        'parsed_payload',
        'parse_ok',
        'parse_error',
        'session_id',
        'remote_address',
    ];

    protected $casts = [
        'parsed_payload' => 'array',
        'parse_ok' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(QueclinkDevice::class, 'queclink_device_id');
    }

    public function scopeInbound(Builder $q): Builder
    {
        return $q->where('direction', self::DIRECTION_INBOUND);
    }

    public function scopeOutbound(Builder $q): Builder
    {
        return $q->where('direction', self::DIRECTION_OUTBOUND);
    }

    public function scopeForImei(Builder $q, string $imei): Builder
    {
        return $q->where('imei', $imei);
    }

    public function scopeSince(Builder $q, $when): Builder
    {
        return $q->where('created_at', '>=', $when);
    }
}
