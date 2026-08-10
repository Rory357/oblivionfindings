<?php

namespace App\Domain\SecurityDevices\Management\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

class DeviceCommandBatchTarget extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'device_command_batch_targets';

    protected $fillable = [
        'device_command_batch_id',
        'device_id',
        'site_id',
        'device_command_request_id',
        'position',
        'inclusion_status',
        'safe_exclusion_code',
        'safe_exclusion_reason',
        'created_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new UnexpectedValueException('Device command batch targets are retained as immutable audit evidence.');
        });
    }

    protected function performUpdate(Builder $query): never
    {
        throw new UnexpectedValueException('Device command batch targets are immutable.');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DeviceCommandBatch::class, 'device_command_batch_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(DeviceCommandRequest::class, 'device_command_request_id');
    }
}
