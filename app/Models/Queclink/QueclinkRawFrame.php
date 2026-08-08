<?php

namespace App\Models\Queclink;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class QueclinkRawFrame extends Model
{
    use WritesLegacyStorageContext;

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
        'canonical_device_id',
        'device_assignment_id',
        'binding_uuid',
        'imei',
        'direction',
        'frame_type',
        'command_word',
        'raw_frame',
        'encrypted_raw_frame',
        'parsed_payload',
        'encrypted_parsed_payload',
        'parse_ok',
        'parse_error',
        'session_id',
        'remote_address',
    ];

    protected $hidden = [
        'raw_frame',
        'encrypted_raw_frame',
        'parsed_payload',
        'encrypted_parsed_payload',
        'remote_address',
        'session_id',
        'binding_uuid',
    ];

    protected $casts = [
        'parsed_payload' => 'array',
        'parse_ok' => 'boolean',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Queclink raw frame evidence is immutable.');
        });
    }

    protected function rawFrame(): Attribute
    {
        return Attribute::make(
            get: function (?string $value, array $attributes): ?string {
                $encrypted = $attributes['encrypted_raw_frame'] ?? null;

                return is_string($encrypted) && $encrypted !== ''
                    ? Crypt::decryptString($encrypted)
                    : $value;
            },
        );
    }

    /** @return array<string, mixed>|null */
    public function protectedParsedPayload(): ?array
    {
        $encrypted = $this->getRawOriginal('encrypted_parsed_payload');
        if (! is_string($encrypted) || $encrypted === '') {
            return is_array($this->parsed_payload) ? $this->parsed_payload : null;
        }

        $decoded = json_decode(Crypt::decryptString($encrypted), true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('The protected Queclink frame payload is invalid.');
        }

        return $decoded;
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(QueclinkDevice::class, 'queclink_device_id');
    }

    public function canonicalDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'canonical_device_id');
    }

    public function deviceAssignment(): BelongsTo
    {
        return $this->belongsTo(DeviceAssignment::class, 'device_assignment_id');
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
