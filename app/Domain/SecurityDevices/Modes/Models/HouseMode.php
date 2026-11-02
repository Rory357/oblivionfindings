<?php

namespace App\Domain\SecurityDevices\Modes\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a Site's house modes (S&D Phase F, pg-sdr-12c). `schedule` is null for "by hand only", or
 * {days: list<int 1–7, Monday first>, starts: "HH:MM"} in NZ wall-clock time. `actions` holds only
 * SafeActions keys with their settings (HouseModeActions::normalise checks them on every save).
 *
 * @property int $id
 * @property int $site_id
 * @property string $name
 * @property int $sort_order
 * @property array{days: list<int>, starts: string}|null $schedule
 * @property list<array<string, mixed>> $actions
 */
final class HouseMode extends Model
{
    public const int MAX_PER_SITE = 8;

    protected $table = 'security_device_house_modes';

    protected $fillable = ['site_id', 'name', 'sort_order', 'schedule', 'actions', 'created_by_user_id', 'updated_by_user_id'];

    protected $casts = [
        'site_id' => 'integer',
        'sort_order' => 'integer',
        'schedule' => 'array',
        'actions' => 'array',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return list<array<string, mixed>> */
    public function actionList(): array
    {
        return array_values(array_filter((array) $this->actions, 'is_array'));
    }

    /** @return array<string, mixed>|null the settings of one action, when the mode takes it */
    public function action(string $key): ?array
    {
        foreach ($this->actionList() as $action) {
            if (($action['action'] ?? null) === $key) {
                return $action;
            }
        }

        return null;
    }
}
