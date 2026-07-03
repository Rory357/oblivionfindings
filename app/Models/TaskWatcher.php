<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user "following" a company-wide /tasks work item. Watchers receive FYI
 * notifications when a watched item is reassigned or falls overdue, without
 * owning it. Keyed on the provider source key + the source record's numeric
 * id (TaskItems are never persisted — see TaskAggregator).
 */
class TaskWatcher extends Model
{
    protected $table = 'task_watchers';

    protected $fillable = [
        'source',
        'item_id',
        'user_id',
    ];

    protected $casts = [
        'item_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
