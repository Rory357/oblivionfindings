<?php

namespace App\Domain\SecurityDevices\Endpoints\Models;

use Illuminate\Database\Eloquent\Model;

final class EndpointRemoteSession extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['consent_challenge', 'actor_fingerprint', 'request_hash'];

    protected $casts = [
        'consent_challenge' => 'encrypted',
        'request_expires_at' => 'immutable_datetime', 'session_expires_at' => 'immutable_datetime',
        'technician_seen_at' => 'immutable_datetime', 'allowed_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime', 'lease_expires_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime', 'policy_version' => 'integer',
    ];

    public function terminal(): bool
    {
        return in_array($this->status, ['declined', 'stopped', 'expired'], true);
    }
}
