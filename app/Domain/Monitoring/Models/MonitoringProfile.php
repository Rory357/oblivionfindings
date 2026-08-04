<?php

namespace App\Domain\Monitoring\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\DataRetentionPolicy;
use Database\Factories\MonitoringProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitoringProfile extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected static function newFactory(): MonitoringProfileFactory
    {
        return MonitoringProfileFactory::new();
    }

    protected $fillable = [
        'name',
        'description',
        'interval_seconds',
        'failure_confirmations',
        'failure_duration_seconds',
        'recovery_confirmations',
        'recovery_duration_seconds',
        'stale_after_seconds',
        'rising_threshold',
        'falling_threshold',
        'baseline_window_seconds',
        'baseline_minimum_samples',
        'baseline_deviation_multiplier',
        'maintenance_policy',
        'rollup_policy',
        'retention_policy_id',
        'is_active',
    ];

    protected $casts = [
        'interval_seconds' => 'integer',
        'failure_confirmations' => 'integer',
        'failure_duration_seconds' => 'integer',
        'recovery_confirmations' => 'integer',
        'recovery_duration_seconds' => 'integer',
        'stale_after_seconds' => 'integer',
        'rising_threshold' => 'decimal:6',
        'falling_threshold' => 'decimal:6',
        'baseline_window_seconds' => 'integer',
        'baseline_minimum_samples' => 'integer',
        'baseline_deviation_multiplier' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class, 'profile_id');
    }

    public function retentionPolicy(): BelongsTo
    {
        return $this->belongsTo(DataRetentionPolicy::class, 'retention_policy_id');
    }
}
