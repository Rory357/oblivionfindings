<?php

namespace App\Domain\SecurityDevices\DoorClockins\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Who was actually told before clock-ins from doors counted for them at a Site (Privacy Act IPP3),
 * and when. A person's badges there count only from a week after this, so someone matched to a card
 * after the notice went out is told then, and counts a week later.
 *
 * @property int $notice_id
 * @property int $site_id
 * @property int $user_id
 * @property \Carbon\CarbonImmutable $told_at
 */
final class DoorClockinTold extends Model
{
    protected $table = 'security_door_clockin_told';

    protected $fillable = ['notice_id', 'site_id', 'user_id', 'told_at', 'by_email'];

    protected $casts = [
        'told_at' => 'immutable_datetime',
        'by_email' => 'boolean',
    ];
}
