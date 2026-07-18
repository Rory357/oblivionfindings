<?php

namespace App\Domain\Monitoring\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitoringCollector extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
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

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
