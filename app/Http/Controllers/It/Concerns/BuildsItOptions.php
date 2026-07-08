<?php

namespace App\Http\Controllers\It\Concerns;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;

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

    /**
     * Active entries from the canonical (fleet-)assets register — the picker
     * source for linking a ticket to an asset. Never a parallel IT register.
     *
     * @return array<int, array{id: int, name: string, tag: string|null}>
     */
    protected function assetOptions(): array
    {
        return Asset::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'asset_tag'])
            ->map(fn (Asset $a) => ['id' => $a->id, 'name' => $a->name, 'tag' => $a->asset_tag])
            ->values()
            ->all();
    }
}
