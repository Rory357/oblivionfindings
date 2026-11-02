<?php

namespace App\Domain\SecurityDevices\WebPages\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One opening of a device's web page through its Site's hub (S&D Phase G, pop-up 61). Only the hash
 * of its link's token is stored.
 *
 * @property string $uuid
 * @property int $device_id
 * @property int $collector_id
 * @property int $user_id
 * @property string $scheme
 * @property string $address
 * @property int $port
 * @property string|null $trusted_pin
 * @property string $state
 * @property \Carbon\CarbonImmutable $last_used_at
 */
final class WebSession extends Model
{
    protected $table = 'security_device_web_sessions';

    protected $fillable = [
        'uuid', 'token_hash', 'device_id', 'site_id', 'collector_id', 'user_id', 'scheme', 'address', 'port',
        'trusted_pin', 'state', 'last_used_at', 'closed_at', 'close_reason',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'port' => 'integer',
        'last_used_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
