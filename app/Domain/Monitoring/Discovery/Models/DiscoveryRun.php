<?php

namespace App\Domain\Monitoring\Discovery\Models;

use App\Domain\Monitoring\Discovery\Database\DiscoveryRunBuilder;
use Database\Factories\DiscoveryRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class DiscoveryRun extends Model
{
    use HasFactory;

    public const array IMMUTABLE_SUMMARY_ATTRIBUTES = [
        'discovery_scope_id',
        'run_uuid',
        'scope_snapshot',
        'planned_targets',
        'found_count',
        'matched_count',
        'proposed_count',
        'changed_count',
        'excluded_count',
        'failed_count',
        'unresolved_count',
        'started_at',
        'completed_at',
        'cancelled_at',
        'failure_summary',
    ];

    private static bool $summaryWriteAllowed = false;

    protected $table = 'monitoring_discovery_runs';

    protected $fillable = [
        'discovery_scope_id',
        'run_uuid',
        'status',
        'trigger',
        'scope_snapshot',
        'planned_targets',
        'found_count',
        'matched_count',
        'proposed_count',
        'changed_count',
        'excluded_count',
        'failed_count',
        'unresolved_count',
        'started_at',
        'completed_at',
        'cancelled_at',
        'failure_summary',
    ];

    protected $casts = [
        'scope_snapshot' => 'array',
        'planned_targets' => 'integer',
        'found_count' => 'integer',
        'matched_count' => 'integer',
        'proposed_count' => 'integer',
        'changed_count' => 'integer',
        'excluded_count' => 'integer',
        'failed_count' => 'integer',
        'unresolved_count' => 'integer',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
    ];

    protected static function newFactory(): DiscoveryRunFactory
    {
        return DiscoveryRunFactory::new();
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(DiscoveryScope::class, 'discovery_scope_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(DiscoveryCandidate::class, 'discovery_run_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(DiscoveryResult::class, 'discovery_run_id');
    }

    public function newEloquentBuilder($query): DiscoveryRunBuilder
    {
        return new DiscoveryRunBuilder($query);
    }

    public static function summaryWriteAllowed(): bool
    {
        return self::$summaryWriteAllowed;
    }

    protected function performUpdate(Builder $query)
    {
        if (in_array($this->getOriginal('status'), ['completed', 'failed', 'cancelled'], true)
            && ($this->isDirty('status') || $this->isDirty(self::IMMUTABLE_SUMMARY_ATTRIBUTES))) {
            throw new LogicException('Completed discovery run summary is immutable.');
        }

        self::$summaryWriteAllowed = true;

        try {
            return parent::performUpdate($query);
        } finally {
            self::$summaryWriteAllowed = false;
        }
    }
}
