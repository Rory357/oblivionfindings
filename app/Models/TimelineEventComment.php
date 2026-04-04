<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TimelineEventComment extends Model
{
    protected $fillable = [
        'timeline_event_id',
        'user_id',
        'parent_id',
        'body',
    ];

    public function timelineEvent(): BelongsTo
    {
        return $this->belongsTo(TimelineEvent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(TimelineCommentLike::class, 'comment_id');
    }
}
