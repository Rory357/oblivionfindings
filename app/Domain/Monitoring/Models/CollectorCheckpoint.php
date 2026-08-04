<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CollectorCheckpoint extends Model
{
    protected $table = 'monitoring_collector_checkpoints';

    protected $fillable = [
        'collector_id',
        'acknowledged_source_sequence',
        'highest_seen_source_sequence',
        'gap_from',
        'gap_to',
        'last_item_at',
        'last_acknowledged_at',
        'last_gap_at',
        'last_error_code',
    ];

    protected $casts = [
        'acknowledged_source_sequence' => 'integer',
        'highest_seen_source_sequence' => 'integer',
        'gap_from' => 'integer',
        'gap_to' => 'integer',
        'last_item_at' => 'immutable_datetime',
        'last_acknowledged_at' => 'immutable_datetime',
        'last_gap_at' => 'immutable_datetime',
    ];

    public function collector(): BelongsTo
    {
        return $this->belongsTo(MonitoringCollector::class);
    }
}
