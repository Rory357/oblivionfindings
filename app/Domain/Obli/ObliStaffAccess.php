<?php

namespace App\Domain\Obli;

use App\Domain\It\Services\ItWorkAccessService;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Support\SecurityPolicy;

final class ObliStaffAccess
{
    /** Call within a transaction. Always use current RBAC, account and Site evidence. */
    public function actor(int $id): User
    {
        $user = app(AuthorizationEvidenceLockService::class)->lockForUser($id, ['*']);
        abort_unless($user->approved_at && $user->email_verified_at, 403);
        abort_if($user->external_clinical_account, 403);
        foreach (['client', 'next_of_kin', 'external_clinician'] as $role) {
            abort_if($user->role === $role || $user->hasRole($role), 403);
        }
        abort_unless($user->canDo('it.request') || $user->canDo('it.manage'), 403);
        abort_if(SecurityPolicy::forceTwoFactor() && ! $user->two_factor_confirmed_at, 403);
        abort_if(app(ItWorkAccessService::class)->approvedSiteIds($user, true) === [], 403);

        return $user;
    }

    public static function fingerprint(User $user): string
    {
        return hash('sha256', json_encode([
            $user->id, $user->email, $user->getRawOriginal('password'),
            $user->getRawOriginal('approved_at'), $user->getRawOriginal('email_verified_at'),
            $user->getRawOriginal('two_factor_secret'), $user->getRawOriginal('two_factor_confirmed_at'),
        ], JSON_THROW_ON_ERROR));
    }
}
