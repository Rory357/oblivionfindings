<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Client;
use App\Support\SafeOperationalData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditLogger
{
    public static function log(string $action, ?Model $auditable = null, array $meta = [], ?Request $request = null): void
    {
        try {
            self::logOrFail($action, $auditable, $meta, $request);
        } catch (\Throwable $e) {
            Log::error('AuditLogger failed', SafeOperationalData::logContext([
                'action' => $action,
                'error_category' => SafeOperationalData::failureCategory($e),
            ]));
        }
    }

    /**
     * Write an integrity-sensitive audit record and surface any failure to the
     * caller so its surrounding database transaction can roll back.
     */
    public static function logOrFail(
        string $action,
        ?Model $auditable = null,
        array $meta = [],
        ?Request $request = null,
    ): void {
        $request = $request ?? request();
        $user = $request?->user() ?? auth()->user();
        $actorId = $user?->id;

        // Service/listener calls may run without an HTTP user even though
        // their domain command carries an explicit actor. Preserve that
        // attribution instead of presenting the event as a system write.
        if ($actorId === null && is_int($meta['actor_id'] ?? null) && $meta['actor_id'] > 0) {
            $actorId = $meta['actor_id'];
        }

        $protectRequestContext = SafeOperationalData::protectsRequestContext($auditable);
        $clientId = null;
        if ($auditable instanceof Client) {
            $clientId = $auditable->id;
        } elseif ($auditable && isset($auditable->client_id)) {
            $clientId = $auditable->client_id;
        } elseif (! $protectRequestContext && isset($meta['client_id'])) {
            $clientId = $meta['client_id'];
        }

        $canonicalScope = $protectRequestContext && $auditable !== null
            ? SafeOperationalData::auditScope($auditable)
            : [];

        unset($meta['organization_id'], $meta['tenant_id']);
        if ($protectRequestContext && $auditable !== null) {
            $meta = SafeOperationalData::auditMeta($meta, $auditable, $canonicalScope);
        }

        AuditLog::create([
            'user_id' => $actorId,
            'client_id' => $clientId,
            'action' => $action,
            'auditable_type' => $auditable ? $auditable->getMorphClass() : null,
            'auditable_id' => $auditable ? $auditable->getKey() : null,
            'meta' => $meta,
            'ip_address' => $protectRequestContext ? null : $request?->ip(),
            'user_agent' => $protectRequestContext ? null : substr((string) $request?->userAgent(), 0, 5000),
        ]);
    }
}
