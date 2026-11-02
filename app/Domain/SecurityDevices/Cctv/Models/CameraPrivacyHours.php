<?php

namespace App\Domain\SecurityDevices\Cctv\Models;

use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A camera's privacy hours (S&D rebuild Phase E). `ranges` is a list of
 * {days: list<int 1–7, Monday first>, from: "HH:MM", to: "HH:MM"} in NZ wall-clock time.
 *
 * @property int $device_id
 * @property bool $enabled
 * @property list<array{days: list<int>, from: string, to: string}> $ranges
 */
final class CameraPrivacyHours extends Model
{
    protected $table = 'security_device_camera_privacy_hours';

    protected $guarded = [];

    protected $casts = [
        'device_id' => 'integer',
        'enabled' => 'boolean',
        'ranges' => 'array',
        'updated_by_user_id' => 'integer',
    ];

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
