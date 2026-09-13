<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\ItTicket;
use App\Models\Site;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MonitoringIncidentEvidenceSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'control_room_alert_id',
        'it_ticket_id',
        'device_id',
        'device_event_id',
        'fleet_signal_id',
        'asset_id',
        'site_id',
        'evidence_version',
        'captured_at',
        'snapshot',
        'checksum',
    ];

    protected $casts = [
        'evidence_version' => 'integer',
        'captured_at' => 'immutable_datetime',
        'snapshot' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $snapshot): void {
            if ($snapshot->evidence_version === 3
                ? ($snapshot->device_event_id !== null || $snapshot->fleet_signal_id === null || $snapshot->asset_id === null)
                : ($snapshot->fleet_signal_id !== null || $snapshot->asset_id !== null || $snapshot->device_event_id === null)) {
                throw new DomainException('Evidence must have exactly one canonical source type.');
            }
        });
        $immutable = static function (): never {
            throw new DomainException('Monitoring incident evidence is immutable.');
        };

        self::updating($immutable);
        self::deleting($immutable);
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'control_room_alert_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'it_ticket_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function deviceEvent(): BelongsTo
    {
        return $this->belongsTo(DeviceEvent::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function fleetSignal(): BelongsTo
    {
        return $this->belongsTo(FleetSignal::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @param array<string, mixed> $snapshot */
    public static function checksumFor(array $snapshot): string
    {
        return hash('sha256', json_encode(
            self::canonicalise($snapshot),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    public function hasValidChecksum(): bool
    {
        return is_array($this->snapshot)
            && preg_match('/\A[a-f0-9]{64}\z/', (string) $this->checksum) === 1
            && hash_equals((string) $this->checksum, self::checksumFor($this->snapshot));
    }

    /** @return array<string, mixed> */
    private static function canonicalise(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalise($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
