<?php

namespace App\Domain\Monitoring\Discovery\Models;

use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

final class DeviceIdentityEvidence extends Model
{
    protected $table = 'monitoring_device_identity_evidence';

    protected $fillable = [
        'canonical_device_id',
        'evidence_type',
        'value_hash',
        'source',
        'first_seen_at',
        'last_seen_at',
        'confidence',
        'is_active',
        'superseded_at',
    ];

    protected $casts = [
        'first_seen_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
        'confidence' => 'integer',
        'is_active' => 'boolean',
        'superseded_at' => 'immutable_datetime',
    ];

    public function canonicalDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'canonical_device_id');
    }

    public static function record(
        Device $device,
        string $type,
        string $value,
        string $source,
        int $confidence,
    ): self {
        $type = self::type($type);
        $source = trim($source);
        if ($source === '' || strlen($source) > 128 || $confidence < 0 || $confidence > 100) {
            throw new InvalidArgumentException('Device identity evidence is invalid.');
        }
        $now = now();

        $evidence = self::query()->firstOrNew([
            'canonical_device_id' => $device->id,
            'evidence_type' => $type,
            'value_hash' => self::hashValue($type, $value),
            'source' => $source,
        ]);
        if (! $evidence->exists) {
            $evidence->first_seen_at = $now;
        }
        $evidence->forceFill([
            'last_seen_at' => $now,
            'confidence' => $confidence,
            'is_active' => true,
            'superseded_at' => null,
        ])->save();

        return $evidence;
    }

    public static function hashValue(string $type, string $value): string
    {
        return hash('sha256', self::normaliseValue($type, $value));
    }

    public static function normaliseValue(string $type, string $value): string
    {
        $type = self::type($type);
        $value = trim($value);
        if ($value === '' || strlen($value) > 2048) {
            throw new InvalidArgumentException('Device identity evidence value is invalid.');
        }

        return match ($type) {
            'serial_number' => strtoupper(preg_replace('/\s+/', '', $value) ?? ''),
            'mac_address' => self::mac($value),
            'address_history' => self::address($value),
            'hostname' => strtolower(rtrim($value, '.')),
            default => strtolower(preg_replace('/\s+/', '', $value) ?? ''),
        };
    }

    private static function type(string $type): string
    {
        $type = strtolower(trim($type));
        if (! in_array($type, [
            'provider_id',
            'serial_number',
            'certificate_fingerprint',
            'hardware_id',
            'mac_address',
            'device_fingerprint',
            'hostname',
            'address_history',
        ], true)) {
            throw new InvalidArgumentException('Device identity evidence type is invalid.');
        }

        return $type;
    }

    private static function mac(string $value): string
    {
        $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', $value) ?? '');
        if (strlen($mac) !== 12) {
            throw new InvalidArgumentException('MAC identity evidence is invalid.');
        }

        return $mac;
    }

    private static function address(string $value): string
    {
        $packed = @inet_pton($value);
        if ($packed === false) {
            throw new InvalidArgumentException('Address identity evidence is invalid.');
        }

        return strtolower((string) inet_ntop($packed));
    }
}
