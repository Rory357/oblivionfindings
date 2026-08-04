<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MonitoringCoverageExpectation extends Model
{
    protected $fillable = [
        'site_id',
        'device_domain',
        'device_category',
        'capability',
        'monitor_kind',
        'minimum_count',
        'support_status',
        'support_evidence',
        'is_active',
    ];

    protected $casts = [
        'monitor_kind' => MonitorKind::class,
        'minimum_count' => 'integer',
        'support_evidence' => 'array',
        'is_active' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
