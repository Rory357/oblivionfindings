<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataRetentionExecutionItem extends Model
{
    protected $fillable = [
        'data_retention_execution_id',
        'data_retention_policy_id',
        'owner_key',
        'record_id',
        'action',
        'outcome',
        'evidence',
    ];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(DataRetentionExecution::class);
    }
}
