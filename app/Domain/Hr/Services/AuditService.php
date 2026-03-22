<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AuditService
{
    /**
     * Log an audit entry for an HR model action.
     */
    public function log(
        string $action,
        Model $auditable,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $user = null,
    ): void {
        $user ??= auth()->user();

        HrAuditLog::create([
            'tenant_id' => $auditable->tenant_id ?? $user?->tenant_id,
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Get the audit trail for a specific record.
     */
    public function getAuditTrail(string $type, int $id): Collection
    {
        return HrAuditLog::forModel($type, $id)
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Get activity log for a specific user.
     */
    public function getUserActivity(int $userId, ?int $limit = 50): Collection
    {
        return HrAuditLog::forUser($userId)
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
