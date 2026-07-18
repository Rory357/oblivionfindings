<?php

namespace App\Domain\Monitoring\Models;

use Database\Factories\MonitoringProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitoringProfile extends Model
{
    use HasFactory;

    protected static function newFactory(): MonitoringProfileFactory
    {
        return MonitoringProfileFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'interval_seconds',
        'failure_confirmations',
        'recovery_confirmations',
        'stale_after_seconds',
        'is_active',
    ];

    protected $casts = [
        'interval_seconds' => 'integer',
        'failure_confirmations' => 'integer',
        'recovery_confirmations' => 'integer',
        'stale_after_seconds' => 'integer',
        'is_active' => 'boolean',
    ];

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class, 'profile_id');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
