<?php

namespace App\Domain\SecurityDevices\DoorClockins\Models;

use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry door whose badges suggest clock-ins (D15), from its notice's start date: badges before
 * that never count, so staff are always told at least a week first.
 *
 * @property int $door_id
 * @property int $site_id
 * @property int|null $notice_id
 */
final class DoorClockinDoor extends Model
{
    protected $table = 'security_door_clockin_doors';

    protected $fillable = ['door_id', 'site_id', 'notice_id', 'starts_on', 'added_by_user_id'];

    protected $casts = [
        'starts_on' => 'date:Y-m-d',
    ];

    /** @return BelongsTo<AccessDoor, $this> */
    public function door(): BelongsTo
    {
        return $this->belongsTo(AccessDoor::class, 'door_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /** @return BelongsTo<DoorClockinNotice, $this> */
    public function notice(): BelongsTo
    {
        return $this->belongsTo(DoorClockinNotice::class, 'notice_id');
    }
}
