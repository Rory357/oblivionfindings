<?php

namespace App\Domain\SecurityDevices\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceGroupMember extends Model
{
    protected $table = 'device_group_members';

    protected $fillable = [
        'device_group_id',
        'device_id',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class, 'device_group_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
