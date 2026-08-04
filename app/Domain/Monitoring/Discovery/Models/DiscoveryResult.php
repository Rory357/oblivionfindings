<?php

namespace App\Domain\Monitoring\Discovery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DiscoveryResult extends Model
{
    protected $table = 'monitoring_discovery_results';

    protected $fillable = [
        'discovery_run_id',
        'discovery_candidate_id',
        'target_reference_hash',
        'target_source',
        'outcome',
        'failure_code',
        'evidence_hash',
        'observed_at',
    ];

    protected $casts = [
        'observed_at' => 'immutable_datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(DiscoveryRun::class, 'discovery_run_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(DiscoveryCandidate::class, 'discovery_candidate_id');
    }
}
