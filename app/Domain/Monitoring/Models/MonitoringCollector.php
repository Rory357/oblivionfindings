<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\Site;
use Database\Factories\MonitoringCollectorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MonitoringCollector extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected static function newFactory(): MonitoringCollectorFactory
    {
        return MonitoringCollectorFactory::new();
    }

    protected $fillable = [
        'collector_uuid',
        'name',
        'site_id',
        'collector_device_id',
        'public_key',
        'public_key_fingerprint',
        'client_certificate_fingerprint',
        'configuration_sequence',
        'acknowledged_source_sequence',
        'highest_seen_source_sequence',
        'backlog_items',
        'spool_bytes',
        'corrupted_frames',
        'runtime_state',
        'runtime_status',
        'gap_count',
        'last_clock_drift_seconds',
        'backlog_oldest_at',
        'last_heartbeat_at',
        'enrolled_at',
        'revoked_at',
        'last_recovered_at',
        'status',
        'last_seen_at',
        'config',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'configuration_sequence' => 'integer',
        'acknowledged_source_sequence' => 'integer',
        'highest_seen_source_sequence' => 'integer',
        'backlog_items' => 'integer',
        'spool_bytes' => 'integer',
        'corrupted_frames' => 'integer',
        'runtime_status' => 'array',
        'gap_count' => 'integer',
        'last_clock_drift_seconds' => 'integer',
        'backlog_oldest_at' => 'immutable_datetime',
        'last_heartbeat_at' => 'immutable_datetime',
        'enrolled_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
        'last_recovered_at' => 'immutable_datetime',
        'config' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class, 'collector_id');
    }

    public function collectorDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'collector_device_id');
    }

    public function checkpoint(): HasOne
    {
        return $this->hasOne(CollectorCheckpoint::class, 'collector_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(CollectorEnrollment::class, 'consumed_collector_id');
    }

    /** @return list<int> */
    public function coveredSiteIds(): array
    {
        $raw = is_array($this->config) ? ($this->config['covered_site_ids'] ?? []) : [];
        if (! is_array($raw)) {
            return [];
        }

        $homeSiteId = (int) $this->site_id;
        $ids = [];
        foreach ($raw as $candidate) {
            if (is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) {
                $id = (int) $candidate;
                if ($id > 0 && $id !== $homeSiteId) {
                    $ids[] = $id;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    public function authorisedSiteIds(): array
    {
        $homeSiteId = (int) $this->site_id;

        return $homeSiteId > 0
            ? array_values(array_unique([$homeSiteId, ...$this->coveredSiteIds()]))
            : $this->coveredSiteIds();
    }

    public function softwareVersion(): ?string
    {
        if (! is_array($this->runtime_status)) {
            return null;
        }

        foreach (['version', 'collector_version', 'software_version'] as $key) {
            $value = $this->runtime_status[$key] ?? null;
            if (is_string($value) && trim($value) !== '' && strlen($value) <= 64) {
                return trim($value);
            }
        }

        return null;
    }
}
