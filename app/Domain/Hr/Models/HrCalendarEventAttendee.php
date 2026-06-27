<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of an event's audience. A group descriptor (org/site/team/department)
 * sets the reach; a `person` row (user_id set) is a named invitee who can RSVP.
 */
class HrCalendarEventAttendee extends Model
{
    protected $table = 'hr_calendar_event_attendees';

    protected $fillable = [
        'event_id',
        'user_id',
        'audience_type',
        'audience_ref',
        'rsvp_status',
        'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(HrCalendarEvent::class, 'event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
