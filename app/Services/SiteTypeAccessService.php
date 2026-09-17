<?php

namespace App\Services;

use App\Models\User;

/** Current Site type grants shared by directory reads. */
final class SiteTypeAccessService
{
    public function allowedTypes(?User $user): array
    {
        $map = [
            'head_office' => 'sites.type.head_office.view',
            'house' => 'sites.type.house.view',
            'facility' => 'sites.type.facility.view',
            'residential' => 'sites.type.house.view',
        ];
        $allowed = array_keys(array_filter($map, fn (string $permission): bool => (bool) $user?->canDo($permission)));

        return $allowed !== [] ? $allowed : array_keys($map);
    }
}
