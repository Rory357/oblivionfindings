<?php

namespace App\Domain\Monitoring\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\Site;
use Database\Factories\MonitoringCollectorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'status',
        'last_seen_at',
        'config',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
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
}
