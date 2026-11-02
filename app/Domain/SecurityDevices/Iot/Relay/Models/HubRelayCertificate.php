<?php

namespace App\Domain\SecurityDevices\Iot\Relay\Models;

use App\Domain\Monitoring\Models\MonitoringCollector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The certificate Oblivion signed for one hub's encrypted sensor relay (S&D Phase E, E5). The hub
 * keeps its private key; only the certificate is stored here.
 *
 * @property int $collector_id
 * @property string $certificate_pem
 * @property string $serial
 * @property string $fingerprint_sha256
 * @property string $public_key_sha256
 * @property list<string> $ip_addresses
 */
final class HubRelayCertificate extends Model
{
    protected $table = 'security_device_hub_relay_certificates';

    protected $fillable = [
        'collector_id', 'certificate_pem', 'serial', 'fingerprint_sha256', 'public_key_sha256',
        'ip_addresses', 'issued_at', 'expires_at', 'revoked_at',
    ];

    protected $casts = [
        'ip_addresses' => 'array',
        'issued_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    /** @param Builder<self> $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /** @return BelongsTo<MonitoringCollector, $this> */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(MonitoringCollector::class, 'collector_id');
    }
}
