<?php

namespace App\Domain\Monitoring\Discovery\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Database\Factories\DiscoveryCandidateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DiscoveryCandidate extends Model
{
    use HasFactory;

    protected $table = 'monitoring_discovery_candidates';

    protected $fillable = [
        'discovery_run_id',
        'candidate_uuid',
        'canonical_device_id',
        'decision',
        'confidence',
        'reasons',
        'evidence_snapshot',
        'evidence_hash',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_action',
        'superseded_by_candidate_id',
    ];

    protected $casts = [
        'confidence' => 'integer',
        'reasons' => 'array',
        'evidence_snapshot' => 'array',
        'reviewed_at' => 'immutable_datetime',
    ];

    protected static function newFactory(): DiscoveryCandidateFactory
    {
        return DiscoveryCandidateFactory::new();
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DiscoveryRun::class, 'discovery_run_id');
    }

    public function canonicalDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'canonical_device_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_candidate_id');
    }
}
