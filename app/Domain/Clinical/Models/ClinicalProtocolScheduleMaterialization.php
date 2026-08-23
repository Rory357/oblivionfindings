<?php

namespace App\Domain\Clinical\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalProtocolScheduleMaterialization extends Model
{
    protected $fillable = [
        'idempotency_key',
        'action',
        'request_fingerprint',
        'requested_by',
        'clinical_protocol_id',
        'schedule_version',
        'window_start_at',
        'window_end_at',
        'materialization_timezone',
        'occurrence_keys',
        'occurrence_count',
        'completed_at',
    ];

    protected $casts = [
        'schedule_version' => 'integer',
        'window_start_at' => 'immutable_datetime',
        'window_end_at' => 'immutable_datetime',
        'occurrence_keys' => 'array',
        'occurrence_count' => 'integer',
        'completed_at' => 'immutable_datetime',
    ];

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(ClinicalProtocol::class, 'clinical_protocol_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
