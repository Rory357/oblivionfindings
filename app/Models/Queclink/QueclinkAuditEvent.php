<?php

namespace App\Models\Queclink;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueclinkAuditEvent extends Model
{
    protected $table = 'queclink_audit_events';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'provider_connection_id',
        'site_id',
        'canonical_device_id',
        'queclink_device_id',
        'imei',
        'user_id',
        'event_type',
        'outcome',
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

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationProviderConnection::class, 'provider_connection_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function canonicalDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'canonical_device_id');
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
