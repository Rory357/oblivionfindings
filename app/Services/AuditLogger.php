<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditLogger
{
    public static function log(string $action, ?Model $auditable = null, array $meta = [], ?Request $request = null): void
    {
        try {
            $request = $request ?? request();
            $user = $request?->user() ?? auth()->user();
            $actorId = $user?->id;

            // Service/listener calls may run without an HTTP user even though
            // their domain command carries an explicit actor. Preserve that
            // attribution instead of presenting the event as a system write.
            if ($actorId === null && is_int($meta['actor_id'] ?? null) && $meta['actor_id'] > 0) {
                $actorId = $meta['actor_id'];
            }

            $actor = $user;
            if ($actor === null && $actorId !== null) {
                $actor = User::query()->find($actorId);
            }

            $clientId = null;
            if ($auditable instanceof Client) {
                $clientId = $auditable->id;
            } elseif ($auditable && isset($auditable->client_id)) {
                $clientId = $auditable->client_id;
            } elseif (isset($meta['client_id'])) {
                $clientId = $meta['client_id'];
            }

            $client = $auditable instanceof Client
                ? $auditable
                : ($clientId ? Client::query()->find($clientId) : null);

            $organizationId = $meta['organization_id'] ?? null;
            $organizationId ??= $auditable?->getAttribute('organization_id');
            $organizationId ??= $auditable?->getAttribute('tenant_id');
            $organizationId ??= $client?->organization_id;
            $organizationId ??= $actor?->organization_id;
            $organizationId = is_numeric($organizationId) ? (int) $organizationId : null;

            unset($meta['organization_id']);

            AuditLog::create([
                'organization_id' => $organizationId,
                'user_id' => $actorId,
                'client_id' => $clientId,
                'action' => $action,
                'auditable_type' => $auditable ? $auditable->getMorphClass() : null,
                'auditable_id' => $auditable ? $auditable->getKey() : null,
                'meta' => $meta,
                'ip_address' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 5000),
            ]);
        } catch (\Throwable $e) {
            Log::error('AuditLogger failed: '.$e->getMessage(), [
                'action' => $action,
                'exception' => $e,
            ]);
        }
    }
}
