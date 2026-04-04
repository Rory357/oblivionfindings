<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimelineEventReaction extends Model
{
    protected $fillable = [
        'timeline_event_id',
        'user_id',
        'emoji',
    ];

    public function timelineEvent(): BelongsTo
    {
        return $this->belongsTo(TimelineEvent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
