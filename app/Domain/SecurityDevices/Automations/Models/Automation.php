<?php

namespace App\Domain\SecurityDevices\Automations\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of a house's automations (S&D Phase F, pg-sdr-13b): when this happens, only if…, then do this.
 * `trigger`, `conditions` and `actions` are only ever written through AutomationDefinition::normalise,
 * so the actions are SafeActions keys with their settings (D12). AutomationEngine is the only thing
 * that runs them, and it checks each one again with SafeActions::assertAllowed().
 *
 * @property int $id
 * @property int $site_id
 * @property string $name
 * @property string $trigger_kind
 * @property array<string, mixed> $trigger
 * @property array<string, mixed>|null $conditions
 * @property list<array<string, mixed>> $actions
 * @property bool $is_on
 * @property int $version
 * @property array<string, mixed>|null $state
 * @property \Carbon\CarbonImmutable|null $last_run_at
 * @property bool $last_run_test
 * @property string|null $last_run_outcome
 * @property \Carbon\CarbonImmutable|null $removed_at
 */
final class Automation extends Model
{
    /** A Site can have up to this many automations (removed ones don't count). */
    public const int MAX_PER_SITE = 50;

    protected $table = 'security_device_automations';

    protected $fillable = [
        'site_id', 'name', 'trigger_kind', 'trigger', 'conditions', 'actions', 'is_on', 'version', 'state',
        'last_run_at', 'last_run_test', 'last_run_outcome', 'created_by_user_id', 'updated_by_user_id',
        'removed_at', 'removed_by_user_id',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'trigger' => 'array',
        'conditions' => 'array',
        'actions' => 'array',
        'is_on' => 'boolean',
        'version' => 'integer',
        'state' => 'array',
        'last_run_at' => 'immutable_datetime',
        'last_run_test' => 'boolean',
        'removed_at' => 'immutable_datetime',
    ];

    /** @param  Builder<Automation>  $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /** @param  Builder<Automation>  $query */
    public function scopeRunning(Builder $query): void
    {
        $query->whereNull('removed_at')->where('is_on', true);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** @return HasMany<AutomationRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /** @return list<array<string, mixed>> */
    public function actionList(): array
    {
        return array_values(array_filter((array) $this->actions, 'is_array'));
    }

    /** The trigger's device, or null for "any at the Site" and a time of day. */
    public function triggerDeviceId(): ?int
    {
        $id = $this->trigger['device_id'] ?? null;

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }
}
