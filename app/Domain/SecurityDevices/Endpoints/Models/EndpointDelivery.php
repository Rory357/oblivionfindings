<?php

namespace App\Domain\SecurityDevices\Endpoints\Models;

use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EndpointDelivery extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['encrypted_payload', 'encrypted_claim', 'encrypted_cancellation'];

    protected $casts = [
        'software_readback' => 'array',
        'encrypted_payload' => 'encrypted:array', 'encrypted_claim' => 'encrypted:array', 'claimed_at' => 'immutable_datetime', 'claim_expires_at' => 'immutable_datetime', 'result' => 'array', 'restart_readback' => 'array', 'cancel_requested_at' => 'immutable_datetime', 'encrypted_cancellation' => 'encrypted:array', 'service_readback' => 'array', 'readback_sequence' => 'integer', 'readback_received_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(DeviceCommandAttempt::class, 'device_command_attempt_id');
    }
}
