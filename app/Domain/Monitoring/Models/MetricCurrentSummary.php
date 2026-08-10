<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MetricCurrentSummary extends Model
{
    protected $table = 'monitoring_metric_current_summaries';

    protected $fillable = [
        'series_id',
        'value',
        'statistics',
        'sample_count',
        'observed_at',
        'last_idempotency_key',
        'storage_state',
        'storage_checked_at',
    ];

    protected $casts = [
        'value' => 'decimal:6',
        'statistics' => 'array',
        'sample_count' => 'integer',
        'observed_at' => 'immutable_datetime',
        'storage_checked_at' => 'immutable_datetime',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(MetricSeries::class, 'series_id');
    }
}
