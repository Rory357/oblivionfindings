<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    public static function log(string $action, ?Model $auditable = null, array $meta = [], ?Request $request = null): void
    {
        try {
            $request = $request ?? request();
            $user = $request?->user();

            $clientId = null;
            if ($auditable instanceof Client) {
                $clientId = $auditable->id;
            } elseif ($auditable && isset($auditable->client_id)) {
                $clientId = $auditable->client_id;
            } elseif (isset($meta['client_id'])) {
                $clientId = $meta['client_id'];
            }

            AuditLog::create([
                'user_id' => $user?->id,
                'client_id' => $clientId,
                'action' => $action,
                'auditable_type' => $auditable ? $auditable->getMorphClass() : null,
                'auditable_id' => $auditable ? $auditable->getKey() : null,
                'meta' => $meta,
                'ip_address' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 5000),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuditLogger failed: ' . $e->getMessage(), [
                'action' => $action,
                'exception' => $e,
            ]);
        }
    }
}
