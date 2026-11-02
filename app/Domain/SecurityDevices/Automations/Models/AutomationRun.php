<?php

namespace App\Domain\SecurityDevices\Automations\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of an automation (S&D Phase F): real, or a test (pop-up 41), with what started it and
 * exactly what each action did. Every run is also in the audit log.
 *
 * @property int $id
 * @property int $automation_id
 * @property int $site_id
 * @property bool $test
 * @property string $source
 * @property int|null $actor_user_id
 * @property int|null $device_id
 * @property string $outcome
 * @property string $summary
 * @property list<array{action: string, tone: string, text: string, at: string}> $lines
 * @property \Carbon\CarbonImmutable $at
 */
final class AutomationRun extends Model
{
    public const string OK = 'ok';

    public const string PARTIAL = 'partial';

    public const string FAILED = 'failed';

    /** Runs are kept this long. */
    public const int KEEP_DAYS = 400;

    protected $table = 'security_device_automation_runs';

    protected $fillable = ['automation_id', 'site_id', 'test', 'source', 'actor_user_id', 'device_id', 'outcome', 'summary', 'lines', 'at'];

    protected $casts = [
        'automation_id' => 'integer',
        'site_id' => 'integer',
        'test' => 'boolean',
        'actor_user_id' => 'integer',
        'device_id' => 'integer',
        'lines' => 'array',
        'at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Automation, $this> */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
