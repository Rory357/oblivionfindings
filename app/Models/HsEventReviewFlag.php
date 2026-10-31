<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person must look at this H&S event again because its source record changed after a decision was made on it.
 * Holds no narrative or clinical text: only the reason, the source revision and the severity step.
 */
class HsEventReviewFlag extends Model
{
    /** The source was corrected after the event's WorkSafe decision was signed. The decision is never changed for you. */
    public const REASON_WORKSAFE_REVIEW = 'worksafe_review';

    /** The source raised the severity of an event that was already closed. The event is never reopened for you. */
    public const REASON_ESCALATED_AFTER_CLOSE = 'escalated_after_close';

    public const REASONS = [self::REASON_WORKSAFE_REVIEW, self::REASON_ESCALATED_AFTER_CLOSE];

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'hs_event_id' => 'integer',
        'source_id' => 'integer',
        'source_revision' => 'integer',
        'resolved_by' => 'integer',
        'raised_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(HsEvent::class, 'hs_event_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
