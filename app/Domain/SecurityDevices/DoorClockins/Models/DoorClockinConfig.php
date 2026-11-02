<?php

namespace App\Domain\SecurityDevices\DoorClockins\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The one organisation-wide row for clock-ins from doors (D15): the window around a rostered
 * start, whether the badge time is offered, who hears about gaps, and how far the engine has read
 * the door events. Read through DoorClockinSetup::config(), which makes the row on first use.
 *
 * @property int $before_minutes
 * @property int $after_minutes
 * @property bool $badge_time
 * @property int $cursor_event_id
 */
final class DoorClockinConfig extends Model
{
    protected $table = 'security_door_clockin_settings';

    protected $fillable = [
        'before_minutes', 'after_minutes', 'badge_time',
        'gap_unconfirmed_app', 'gap_unconfirmed_email', 'gap_no_badge_app', 'gap_no_badge_email', 'gap_not_rostered_app', 'gap_not_rostered_email',
        'cursor_event_id', 'turned_off_at', 'turned_off_by_user_id', 'updated_by_user_id',
    ];

    protected $casts = [
        'before_minutes' => 'integer',
        'after_minutes' => 'integer',
        'badge_time' => 'boolean',
        'gap_unconfirmed_app' => 'boolean',
        'gap_unconfirmed_email' => 'boolean',
        'gap_no_badge_app' => 'boolean',
        'gap_no_badge_email' => 'boolean',
        'gap_not_rostered_app' => 'boolean',
        'gap_not_rostered_email' => 'boolean',
        'cursor_event_id' => 'integer',
        'turned_off_at' => 'immutable_datetime',
    ];

    /** @return list<string> notification channels for one kind of gap: unconfirmed | no_badge | not_rostered */
    public function channels(string $gap): array
    {
        return array_values(array_filter([
            $this->getAttribute("gap_{$gap}_app") ? 'database' : null,
            $this->getAttribute("gap_{$gap}_email") ? 'mail' : null,
        ]));
    }
}
