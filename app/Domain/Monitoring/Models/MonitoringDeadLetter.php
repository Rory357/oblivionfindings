<?php

namespace App\Domain\Monitoring\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringDeadLetter extends Model
{
    protected $fillable = [
        'message_id',
        'consumer',
        'source',
        'sequence',
        'idempotency_key',
        'reason_code',
        'reason_message',
        'envelope',
        'replay_count',
        'last_replayed_at',
        'resolved_at',
        'resolved_by_user_id',
        'resolution_reason',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'envelope' => 'array',
        'replay_count' => 'integer',
        'last_replayed_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
    ];

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
