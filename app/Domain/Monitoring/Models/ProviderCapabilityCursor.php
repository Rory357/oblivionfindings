<?php

namespace App\Domain\Monitoring\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProviderCapabilityCursor extends Model
{
    protected $table = 'monitoring_provider_cursors';

    protected $fillable = [
        'site_id',
        'provider',
        'capability',
        'cursor',
        'last_started_at',
        'last_completed_at',
        'retry_not_before',
        'exception_count',
    ];

    protected $casts = [
        'last_started_at' => 'immutable_datetime',
        'last_completed_at' => 'immutable_datetime',
        'retry_not_before' => 'immutable_datetime',
        'exception_count' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
