<?php

namespace App\Domain\SecurityDevices\NetworkChecks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One check in a session: run by the Site's hub (source "hub"), or read from what Oblivion already
 * stores (source "stored"). Its finding is written by the server from the cleaned result, never by
 * the AI, so "What Obli checked" only ever shows what was really found.
 */
final class NetworkCheckStep extends Model
{
    protected $table = 'security_network_check_steps';

    protected $fillable = [
        'uuid', 'session_id', 'position', 'kind', 'source', 'target_device_id', 'title', 'params', 'state',
        'collector_id', 'result', 'finding', 'sent_at', 'done_at', 'expires_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'params' => 'array',
        'result' => 'array',
        'sent_at' => 'immutable_datetime',
        'done_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<NetworkCheckSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(NetworkCheckSession::class, 'session_id');
    }

    public function finished(): bool
    {
        return in_array($this->state, ['done', 'not_available'], true);
    }
}
