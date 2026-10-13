<?php

namespace App\Domain\SecurityDevices\Endpoints\Models;

use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntuneDeviceLink extends Model
{
    protected $table = 'endpoint_intune_links';

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'synced_at' => 'immutable_datetime', 'last_attempt_at' => 'immutable_datetime'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
