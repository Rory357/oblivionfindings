<?php

namespace App\Domain\SecurityDevices\Endpoints\Models;

use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EndpointAgent extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['enrolment_hash', 'credential_hash', 'assignment_fingerprint', 'heartbeat_hash'];

    protected $casts = [
        'software_actions' => 'array', 'software_observations' => 'array',
        'restart_actions' => 'array', 'restart_observations' => 'array', 'inventory' => 'array', 'service_actions' => 'array', 'service_action_observations' => 'array', 'enrolment_expires_at' => 'immutable_datetime',
        'enrolled_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime', 'observed_at' => 'immutable_datetime',
        'sequence' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
