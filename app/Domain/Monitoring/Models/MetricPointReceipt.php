<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

final class MetricPointReceipt extends Model
{
    protected $table = 'monitoring_metric_point_receipts';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'idempotency_key',
        'series_id',
        'observed_at',
    ];

    protected $hidden = [
        'idempotency_key',
    ];

    protected $casts = [
        'observed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new UnexpectedValueException('Metric point receipts are immutable.');
        });
        self::deleting(function (): never {
            throw new UnexpectedValueException('Metric point receipts are immutable.');
        });
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(MetricSeries::class, 'series_id');
    }
}
