<?php

namespace App\Domain\SecurityDevices\Terminal\Models;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Terminal tab session on a device, through its Site's hub (S&D Phase G, D5).
 *
 * @property string $uuid
 * @property int $device_id
 * @property int|null $site_id
 * @property int $collector_id
 * @property int $user_id
 * @property string $state requested | opening | open | closed
 * @property string $address
 * @property int $port
 * @property string|null $login_name
 * @property string|null $close_reason
 */
final class TerminalSession extends Model
{
    public const array LIVE = ['requested', 'opening', 'open'];

    protected $table = 'security_device_terminal_sessions';

    protected $fillable = [
        'uuid', 'device_id', 'site_id', 'collector_id', 'user_id', 'state', 'address', 'port', 'login_name',
        'opened_at', 'last_input_at', 'closed_at', 'close_reason',
    ];

    protected $casts = [
        'port' => 'integer',
        'opened_at' => 'immutable_datetime',
        'last_input_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
    ];

    /** @param Builder<self> $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('state', self::LIVE);
    }

    public function isLive(): bool
    {
        return in_array($this->state, self::LIVE, true);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<MonitoringCollector, $this> */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(MonitoringCollector::class, 'collector_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<TerminalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(TerminalLine::class, 'session_id');
    }
}
