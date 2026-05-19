<?php

namespace App\Models\Queclink;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueclinkAuditEvent extends Model
{
    protected $table = 'queclink_audit_events';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'queclink_device_id',
        'imei',
        'user_id',
        'event_type',
        'section',
        'payload_before',
        'payload_after',
        'raw_command',
        'notes',
        'created_at',
    ];

    protected $casts = [
        'payload_before' => 'array',
        'payload_after' => 'array',
        'created_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(QueclinkDevice::class, 'queclink_device_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param array<string, mixed> $attributes */
    public static function log(array $attributes): self
    {
        return self::create([
            ...$attributes,
            'created_at' => $attributes['created_at'] ?? now(),
        ]);
    }
}
