<?php

namespace App\Domain\SecurityDevices\Iot\Relay\Models;

use App\Domain\Monitoring\Models\MonitoringCollector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hub's daily check that Oblivion's public address refuses MQTT (S&D Phase E, D21).
 *
 * @property int $collector_id
 * @property string $host
 * @property array<string, string> $ports
 * @property bool $passed
 */
final class BrokerExposureCheck extends Model
{
    protected $table = 'security_device_broker_checks';

    protected $fillable = ['collector_id', 'host', 'ports', 'passed', 'checked_at'];

    protected $casts = [
        'ports' => 'array',
        'passed' => 'boolean',
        'checked_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<MonitoringCollector, $this> */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(MonitoringCollector::class, 'collector_id');
    }
}
