<?php

namespace App\Domain\SecurityDevices\Http\Controllers\Concerns;

use App\Models\User;

trait ResolvesDeviceTenant
{
    protected function resolveDeviceTenantId(User $user): int
    {
        return (int) ($user->tenant_id ?? $user->organization_id ?? 1);
    }
}
