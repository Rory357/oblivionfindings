<?php

namespace App\Domain\Monitoring\Discovery\Models;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Models\Site;
use Database\Factories\DiscoveryScopeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class DiscoveryScope extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'monitoring_discovery_scopes';

    protected $fillable = [
        'site_id',
        'collector_id',
        'name',
        'cidrs',
        'seed_hosts',
        'protocols',
        'snmp_credential_reference',
        'exclusions',
        'port_bounds',
        'max_targets_per_run',
        'packets_per_second',
        'schedule_cron',
        'status',
    ];

    protected $casts = [
        'cidrs' => 'array',
        'seed_hosts' => 'array',
        'protocols' => 'array',
        'exclusions' => 'array',
        'port_bounds' => 'array',
        'max_targets_per_run' => 'integer',
        'packets_per_second' => 'integer',
    ];

    protected static function newFactory(): DiscoveryScopeFactory
    {
        return DiscoveryScopeFactory::new();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(MonitoringCollector::class, 'collector_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(DiscoveryRun::class, 'discovery_scope_id');
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'cidrs' => array_values($this->cidrs ?? []),
            'collector_id' => $this->collector_id === null ? null : (int) $this->collector_id,
            'exclusions' => array_values($this->exclusions ?? []),
            'max_targets_per_run' => (int) $this->max_targets_per_run,
            'packets_per_second' => (int) $this->packets_per_second,
            'port_bounds' => $this->port_bounds ?? [],
            'protocols' => array_values($this->protocols ?? []),
            'schedule_cron' => $this->schedule_cron,
            'seed_hosts' => array_values($this->seed_hosts ?? []),
            'site_id' => (int) $this->site_id,
            'snmp_credential_reference' => $this->snmp_credential_reference,
            'status' => (string) $this->status,
        ];
    }
}
