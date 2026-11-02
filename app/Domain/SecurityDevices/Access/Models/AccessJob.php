<?php

namespace App\Domain\SecurityDevices\Access\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A change Oblivion asked a Site's hub to make in UniFi Access (S&D Phase H, H1). */
final class AccessJob extends Model
{
    protected $table = 'security_access_jobs';

    protected $fillable = [
        'uuid', 'console_id', 'kind', 'params', 'requested_by_user_id', 'reason', 'state', 'collector_id',
        'result', 'failure', 'sent_at', 'completed_at', 'expires_at',
    ];

    protected $casts = [
        'params' => 'encrypted:array',
        'result' => 'encrypted:array',
        'sent_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<AccessConsole, $this> */
    public function console(): BelongsTo
    {
        return $this->belongsTo(AccessConsole::class, 'console_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function finished(): bool
    {
        return in_array($this->state, ['succeeded', 'failed', 'uncertain', 'expired'], true);
    }
}
