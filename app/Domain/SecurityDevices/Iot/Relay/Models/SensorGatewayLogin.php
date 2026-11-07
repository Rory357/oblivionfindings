<?php

namespace App\Domain\SecurityDevices\Iot\Relay\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S&D Phase B (D21): one sensor gateway's or plug's own login. It may send only to its own topic,
 * and the reading's Site comes from here, never from the message.
 *
 * @property int $id
 * @property int $site_id
 * @property int|null $device_id
 * @property string $label
 * @property string $username
 * @property string $password_hash
 * @property string $topic_prefix
 */
final class SensorGatewayLogin extends Model
{
    protected $table = 'security_device_sensor_gateway_logins';

    protected $fillable = [
        'site_id', 'device_id', 'label', 'username', 'password_hash', 'topic_prefix',
        'created_by_user_id', 'last_reading_at', 'revoked_at', 'revoked_by_user_id',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'last_reading_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
