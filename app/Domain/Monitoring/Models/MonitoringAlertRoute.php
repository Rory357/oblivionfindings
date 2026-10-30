<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringAlertRoute extends Model
{
    protected $fillable = [
        'name',
        'scope_kind',
        'site_id',
        'device_id',
        'monitor_id',
        'device_domain',
        'device_category',
        'monitor_kind',
        'warning_notify_in_app',
        'warning_notify_email',
        'warning_open_it_ticket',
        'critical_notify_in_app',
        'critical_notify_email',
        'critical_open_it_ticket',
        'critical_escalate_control_room',
        'recipient_role',
        'is_active',
        'version',
        'created_by_user_id',
        'updated_by_user_id',
        'deactivated_at',
        'deactivated_by_user_id',
        'deactivation_reason',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'device_id' => 'integer',
        'monitor_id' => 'integer',
        'warning_notify_in_app' => 'boolean',
        'warning_notify_email' => 'boolean',
        'warning_open_it_ticket' => 'boolean',
        'critical_notify_in_app' => 'boolean',
        'critical_notify_email' => 'boolean',
        'critical_open_it_ticket' => 'boolean',
        'critical_escalate_control_room' => 'boolean',
        'is_active' => 'boolean',
        'version' => 'integer',
        'deactivated_at' => 'immutable_datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class, 'monitor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by_user_id');
    }
}
