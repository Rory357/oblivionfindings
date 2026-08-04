<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

final class MonitoringExternalHeartbeatState extends Model
{
    public const KEY_CENTRAL_RUNTIME = 'central_runtime';

    public const STATE_DISABLED = 'disabled';

    public const STATE_SENT = 'sent';

    public const STATE_SUPPRESSED = 'suppressed';

    public const STATE_FAILED = 'failed';

    protected $fillable = [
        'key',
        'state',
        'reason_code',
        'endpoint_fingerprint',
        'response_status',
        'last_evaluated_at',
        'last_attempted_at',
        'last_sent_at',
        'last_suppressed_at',
        'last_failed_at',
    ];

    protected $hidden = [
        'endpoint_fingerprint',
    ];

    protected $casts = [
        'response_status' => 'integer',
        'last_evaluated_at' => 'immutable_datetime',
        'last_attempted_at' => 'immutable_datetime',
        'last_sent_at' => 'immutable_datetime',
        'last_suppressed_at' => 'immutable_datetime',
        'last_failed_at' => 'immutable_datetime',
    ];
}
