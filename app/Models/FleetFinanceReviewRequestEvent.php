<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Retained history of a vehicle Finance review request. */
class FleetFinanceReviewRequestEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'review_request_id', 'action', 'actor_user_id', 'note', 'request_key', 'request_fingerprint', 'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Finance review history is retained as recorded.'));
        static::deleting(fn () => throw new \LogicException('Finance review history is retained as recorded.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
