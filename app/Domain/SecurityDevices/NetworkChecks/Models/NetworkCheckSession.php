<?php

namespace App\Domain\SecurityDevices\NetworkChecks\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One question IT asked Obli about a Site's network, and what Obli checked and said (pop-up 97). */
final class NetworkCheckSession extends Model
{
    public const array OPEN = ['planning', 'checking', 'answering'];

    protected $table = 'security_network_check_sessions';

    protected $fillable = [
        'uuid', 'site_id', 'open_site_id', 'collector_id', 'hub_label', 'device_id', 'user_id', 'asked_by',
        'question', 'plan', 'answer', 'focus_device_id', 'suggest_unifi', 'unchecked_numbers', 'state', 'failure',
        'ticket_id', 'charged_micros', 'lease_until', 'planned_at', 'answered_at', 'expires_at',
    ];

    protected $casts = [
        'question' => 'encrypted',
        'plan' => 'encrypted:array',
        'answer' => 'encrypted',
        'suggest_unifi' => 'boolean',
        'unchecked_numbers' => 'boolean',
        'charged_micros' => 'integer',
        'lease_until' => 'immutable_datetime',
        'planned_at' => 'immutable_datetime',
        'answered_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];

    /** @return HasMany<NetworkCheckStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(NetworkCheckStep::class, 'session_id')->orderBy('position');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function focusDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'focus_device_id');
    }

    public function open(): bool
    {
        return in_array($this->state, self::OPEN, true);
    }
}
