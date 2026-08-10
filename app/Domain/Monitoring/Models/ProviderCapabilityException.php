<?php

namespace App\Domain\Monitoring\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProviderCapabilityException extends Model
{
    protected $table = 'monitoring_provider_exceptions';

    protected $fillable = [
        'site_id',
        'provider',
        'capability',
        'code',
        'item_reference',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'immutable_datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
