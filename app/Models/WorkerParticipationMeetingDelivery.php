<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Database receipt is transactional; mail uncertainty is retained without automatic resend. */
final class WorkerParticipationMeetingDelivery extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'database_attempts' => 'integer', 'mail_attempts' => 'integer',
        'next_attempt_at' => 'datetime', 'database_delivered_at' => 'datetime',
        'mail_started_at' => 'datetime', 'mail_delivered_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(WorkerParticipationMeetingEvent::class, 'event_id');
    }
}
