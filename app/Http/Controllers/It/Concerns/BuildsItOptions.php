<?php

namespace App\Http\Controllers\It\Concerns;

use App\Domain\Hr\Models\HrEmployeeProfile;

/**
 * Shared option lists for the IT hub and the ticket workspace.
 */
trait BuildsItOptions
{
    /** Active tenant staff usable as request/ticket assignees (IT owners). */
    protected function tenantUserOptions(int $tenantId): array
    {
        return HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('user:id,name')
            ->get()
            ->map(fn (HrEmployeeProfile $p) => [
                'id' => $p->user_id,
                'name' => $p->user?->name,
            ])
            ->filter(fn ($u) => $u['id'] && $u['name'])
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->all();
    }
}
