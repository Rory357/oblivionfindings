<?php

namespace App\Domain\SecurityDevices\Modes\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a Site's "Mode changes" log (S&D Phase F, pg-sdr-12c): a switch and what its actions
 * found, or an alarm check run after a switch. A check due later waits as a `pending` row.
 *
 * @property int $id
 * @property int $site_id
 * @property int|null $mode_id
 * @property string $kind switch | check
 * @property string $state done | pending | cancelled
 * @property string $source schedule | hand
 * @property string $title
 * @property int|null $actor_user_id
 * @property list<array{tone: string, text: string}>|null $lines
 * @property array<string, mixed>|null $payload
 * @property \Carbon\CarbonImmutable $at
 * @property \Carbon\CarbonImmutable|null $due_at
 */
final class HouseModeEvent extends Model
{
    public const string KIND_SWITCH = 'switch';

    public const string KIND_CHECK = 'check';

    public const string STATE_DONE = 'done';

    public const string STATE_PENDING = 'pending';

    public const string STATE_CANCELLED = 'cancelled';

    protected $table = 'security_device_house_mode_events';

    protected $fillable = ['site_id', 'mode_id', 'kind', 'state', 'source', 'title', 'actor_user_id', 'lines', 'payload', 'at', 'due_at'];

    protected $casts = [
        'site_id' => 'integer',
        'mode_id' => 'integer',
        'actor_user_id' => 'integer',
        'lines' => 'array',
        'payload' => 'array',
        'at' => 'immutable_datetime',
        'due_at' => 'immutable_datetime',
    ];

    /** @param Builder<self> $query */
    public function scopeDone(Builder $query): void
    {
        $query->where('state', self::STATE_DONE);
    }

    /** @return BelongsTo<HouseMode, $this> */
    public function mode(): BelongsTo
    {
        return $this->belongsTo(HouseMode::class, 'mode_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
