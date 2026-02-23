<?php

namespace App\Http\Controllers\Hr\Concerns;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Models\User;

trait ResolvesHrTenant
{
    protected function resolveHrTenantIdForUser(User $user): int
    {
        $candidateTenantId = $user->getAttribute('tenant_id');
        if (is_numeric($candidateTenantId)) {
            return (int) $candidateTenantId;
        }

        $organizationTenantId = $user->getAttribute('organization_id');
        if (is_numeric($organizationTenantId)) {
            return (int) $organizationTenantId;
        }

        $profileTenantId = HrEmployeeProfile::query()
            ->where('user_id', $user->id)
            ->value('tenant_id');

        if (is_numeric($profileTenantId)) {
            return (int) $profileTenantId;
        }

        $fallbackTenantId = HrEmployeeProfile::query()->whereNotNull('tenant_id')->orderBy('id')->value('tenant_id')
            ?? HrLeaveRequest::query()->whereNotNull('tenant_id')->orderBy('id')->value('tenant_id')
            ?? HrLeaveBalance::query()->whereNotNull('tenant_id')->orderBy('id')->value('tenant_id')
            ?? HrPayrollRun::query()->whereNotNull('tenant_id')->orderBy('id')->value('tenant_id');

        return (int) ($fallbackTenantId ?? 1);
    }

    protected function assertHrTenantAccess(int $tenantId, ?int $resourceTenantId): void
    {
        if (is_numeric($resourceTenantId) && (int) $resourceTenantId !== $tenantId) {
            abort(404);
        }
    }

    /**
     * @return array<int, int>
     */
    protected function hrStaffUserIdsForTenant(int $tenantId): array
    {
        return HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->pluck('user_id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
