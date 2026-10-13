<?php

namespace App\Domain\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reminder that fires `offset_minutes` before an event (or each occurrence of a
 * recurring series) on the given channel. Dispatched by the every-minute
 * hr:dispatch-calendar-reminders command. Delivery receipts now live in
 * hr_calendar_reminder_deliveries; last_sent_at is retained for legacy deduplication.
 */
class HrCalendarEventReminder extends Model
{
    protected $table = 'hr_calendar_event_reminders';

    protected $fillable = [
        'event_id',
        'offset_minutes',
        'channel',
        'last_sent_at',
    ];

    protected $casts = [
        'offset_minutes' => 'integer',
        'last_sent_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(HrCalendarEvent::class, 'event_id');
    }
}
