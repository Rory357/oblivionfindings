<?php

namespace App\Domain\SecurityDevices\Cctv\Models;

use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Camera privacy on until a time (S&D Phase F): set by a house mode's "Turn camera privacy on". It
 * only ever adds privacy. CameraPrivacy treats it like the camera's privacy hours, so every picture
 * refusal covers it, and nothing ends it early.
 *
 * @property int $device_id
 * @property \Carbon\CarbonImmutable $until
 * @property string $source
 * @property int|null $house_mode_event_id
 */
final class CameraPrivacyOverride extends Model
{
    protected $table = 'security_device_camera_privacy_overrides';

    protected $fillable = ['device_id', 'until', 'source', 'house_mode_event_id'];

    protected $casts = [
        'device_id' => 'integer',
        'until' => 'immutable_datetime',
        'house_mode_event_id' => 'integer',
    ];

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
