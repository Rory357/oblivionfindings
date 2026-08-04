<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ConfigurationSnapshot extends Model
{
    protected $table = 'monitoring_configuration_snapshots';

    protected $fillable = [
        'snapshot_uuid',
        'site_id',
        'device_id',
        'source_kind',
        'source',
        'storage_disk',
        'storage_path',
        'storage_path_hash',
        'storage_state',
        'content_hash',
        'configuration_hash',
        'content_size',
        'mime_type',
        'firmware_version',
        'captured_at',
        'payload_deleted_at',
        'retention_policy_id',
        'previous_snapshot_id',
        'diff_summary',
        'created_by_user_id',
    ];

    protected $hidden = [
        'storage_path',
    ];

    protected $casts = [
        'content_size' => 'integer',
        'captured_at' => 'immutable_datetime',
        'payload_deleted_at' => 'immutable_datetime',
        'diff_summary' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function retentionPolicy(): BelongsTo
    {
        return $this->belongsTo(MonitoringRetentionPolicy::class, 'retention_policy_id');
    }

    public function previousSnapshot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_snapshot_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
