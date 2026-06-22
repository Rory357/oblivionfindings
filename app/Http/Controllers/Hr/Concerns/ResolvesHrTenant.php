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

    /**
     * A validation closure that rejects a recipient whose employee profile is in a
     * different tenant. Bare users (no profile) and same-tenant employees pass — so
     * it closes the cross-tenant-employee gap without restricting the (single-tenant)
     * happy path or the recipient-has-no-profile case.
     */
    protected function rejectForeignTenantRecipient(int $tenantId): \Closure
    {
        return function ($attribute, $value, $fail) use ($tenantId) {
            $inOtherTenant = HrEmployeeProfile::query()
                ->where('user_id', (int) $value)
                ->whereNotNull('tenant_id')
                ->where('tenant_id', '!=', $tenantId)
                ->exists();

            if ($inOtherTenant) {
                $fail('That colleague is in a different organisation.');
            }
        };
    }
}
