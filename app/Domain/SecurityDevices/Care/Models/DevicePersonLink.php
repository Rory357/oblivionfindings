<?php

namespace App\Domain\SecurityDevices\Care\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\CarePlan;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sensor or tracker linked to a person's care (S&D Phase F, pop-up 65), made only while their
 * consent to monitoring is active. Unlinked by hand, or automatically when that consent ends.
 *
 * @property int $device_id
 * @property int $client_id
 * @property int|null $site_id
 * @property string $purpose falls | wandering | room | other
 * @property array<string, mixed>|null $rule
 * @property array<string, mixed>|null $state
 */
final class DevicePersonLink extends Model
{
    public const array PURPOSES = ['falls', 'wandering', 'room', 'other'];

    protected $table = 'security_device_person_links';

    protected $fillable = [
        'device_id', 'client_id', 'site_id', 'purpose', 'care_plan_id', 'rule', 'state', 'note', 'consent_id',
        'linked_by_user_id', 'linked_at', 'unlinked_at', 'unlinked_by_user_id', 'unlink_reason',
    ];

    protected $casts = [
        'rule' => 'array',
        'state' => 'array',
        'linked_at' => 'immutable_datetime',
        'unlinked_at' => 'immutable_datetime',
    ];

    /** @param Builder<self> $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('unlinked_at');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<CarePlan, $this> */
    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(CarePlan::class);
    }

    /** @return BelongsTo<ClientConsent, $this> */
    public function consent(): BelongsTo
    {
        return $this->belongsTo(ClientConsent::class);
    }

    /** @return BelongsTo<User, $this> */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }
}
