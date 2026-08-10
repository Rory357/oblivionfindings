<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\Database\MonitorObservationBuilder;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\MonitoringObservationScopeGuard;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\Site;
use Database\Factories\MonitorObservationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class MonitorObservation extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    public const array IMMUTABLE_PROVENANCE_ATTRIBUTES = [
        'monitor_id',
        'device_id',
        'site_id',
        'collector_id',
    ];

    private static bool $provenanceColumnsAvailable = false;

    protected static function newFactory(): MonitorObservationFactory
    {
        return MonitorObservationFactory::new();
    }

    protected $fillable = [
        'monitor_id',
        'source_key',
        'state',
        'value',
        'unit',
        'latency_ms',
        'message',
        'metrics',
        'observed_at',
        'ingested_at',
    ];

    protected $casts = [
        'state' => MonitorState::class,
        'value' => 'decimal:6',
        'latency_ms' => 'integer',
        'metrics' => 'array',
        'observed_at' => 'datetime',
        'ingested_at' => 'datetime',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(MonitoringCollector::class);
    }

    public function fill(array $attributes)
    {
        if ($this->exists && array_intersect(array_keys($attributes), self::IMMUTABLE_PROVENANCE_ATTRIBUTES) !== []) {
            throw new \LogicException('Monitoring observation provenance is immutable.');
        }

        return parent::fill($attributes);
    }

    public function newEloquentBuilder($query): MonitorObservationBuilder
    {
        return new MonitorObservationBuilder($query);
    }

    public static function supportsProvenanceColumns(): bool
    {
        if (! self::$provenanceColumnsAvailable) {
            self::$provenanceColumnsAvailable = Schema::hasColumns('monitor_observations', [
                'device_id',
                'site_id',
                'collector_id',
            ]);
        }

        return self::$provenanceColumnsAvailable;
    }

    protected function performInsert(Builder $query)
    {
        if (self::supportsProvenanceColumns()) {
            $monitor = Monitor::query()
                ->with('collector')
                ->findOrFail($this->getAttribute('monitor_id'));
            $siteId = app(CanonicalDeviceSiteResolver::class)->resolve((int) $monitor->device_id);
            app(MonitoringObservationScopeGuard::class)->assertCanonicalSite($monitor, $siteId);

            $this->forceFill([
                'device_id' => $monitor->device_id,
                'site_id' => $siteId,
                'collector_id' => $monitor->collector_id,
            ]);
        }

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query)
    {
        if ($this->isDirty(self::IMMUTABLE_PROVENANCE_ATTRIBUTES)) {
            throw new \LogicException('Monitoring observation provenance is immutable.');
        }

        return parent::performUpdate($query);
    }
}
