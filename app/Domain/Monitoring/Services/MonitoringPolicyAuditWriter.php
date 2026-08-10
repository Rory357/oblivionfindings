<?php

namespace App\Domain\Monitoring\Services;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class MonitoringPolicyAuditWriter
{
    /** @param array<string, mixed> $safeContext */
    public function write(string $action, Model $policy, User $actor, array $safeContext): void
    {
        AuditLogger::logOrFail($action, $policy, [
            'actor_id' => $actor->id,
            ...$safeContext,
        ]);
    }
}
