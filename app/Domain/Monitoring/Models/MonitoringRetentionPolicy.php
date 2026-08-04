<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MonitoringRetentionPolicy extends Model
{
    protected $table = 'monitoring_retention_policies';

    protected $fillable = [
        'name',
        'scope_kind',
        'site_id',
        'device_id',
        'data_class',
        'privacy_class',
        'raw_days',
        'hourly_days',
        'daily_days',
        'legal_hold',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'raw_days' => 'integer',
        'hourly_days' => 'integer',
        'daily_days' => 'integer',
        'legal_hold' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
