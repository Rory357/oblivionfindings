<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataRetentionExecution extends Model
{
    protected $fillable = [
        'data_retention_policy_id',
        'source',
        'idempotency_key',
        'contract_fingerprint',
        'status',
        'actor_user_id',
        'previewed_by_user_id',
        'approved_by_user_id',
        'preview_snapshot',
        'result',
        'failure_code',
        'failure_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'preview_snapshot' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(DataRetentionPolicy::class, 'data_retention_policy_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DataRetentionExecutionItem::class);
    }
}
