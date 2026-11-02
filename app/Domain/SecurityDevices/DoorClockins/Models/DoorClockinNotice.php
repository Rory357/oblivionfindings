<?php

namespace App\Domain\SecurityDevices\DoorClockins\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What staff were told before clock-ins from doors started at their Site (Privacy Act IPP3; door
 * events used for a second purpose, IPP10), kept word for word with who sent it and when.
 *
 * @property int $id
 * @property string $body
 * @property bool $by_email
 * @property list<int> $site_ids
 * @property list<int> $door_ids
 */
final class DoorClockinNotice extends Model
{
    protected $table = 'security_door_clockin_notices';

    protected $fillable = ['body', 'by_email', 'site_ids', 'door_ids', 'starts_on', 'sent_by_user_id', 'sent_at', 'recipients'];

    protected $casts = [
        'by_email' => 'boolean',
        'site_ids' => 'array',
        'door_ids' => 'array',
        'starts_on' => 'date:Y-m-d',
        'sent_at' => 'immutable_datetime',
        'recipients' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
