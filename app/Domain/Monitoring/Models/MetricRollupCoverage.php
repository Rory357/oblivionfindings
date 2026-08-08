<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MetricRollupCoverage extends Model
{
    protected $table = 'monitoring_metric_rollup_coverages';

    protected $fillable = [
        'source_series_id',
        'target_series_id',
        'target_tier',
        'covered_from',
        'covered_until',
        'completed_at',
    ];

    protected $casts = [
        'covered_from' => 'immutable_datetime',
        'covered_until' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    public function sourceSeries(): BelongsTo
    {
        return $this->belongsTo(MetricSeries::class, 'source_series_id');
    }

    public function targetSeries(): BelongsTo
    {
        return $this->belongsTo(MetricSeries::class, 'target_series_id');
    }
}
