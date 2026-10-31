<?php

namespace App\Services\HealthSafety;

use App\Domain\Governance\Models\GovernanceSetting;
use App\Models\User;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Services\UserSiteAccessService;

/** Named assignment is configuration, never an access grant. */
final class WorkerParticipationTrainingReviewerResolver
{
    public const KEY = 'hs_training_reviewers';

    public function configuration(): array
    {
        $raw = GovernanceSetting::query()->where('key', self::KEY)->value('value');
        $value = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($value) ? $value : [];
    }

    public function reviewerId(?int $siteId, ?array $configuration = null): ?int
    {
        $configuration ??= $this->configuration();
        $overrides = $configuration['site_overrides'] ?? [];
        $value = $siteId !== null && is_array($overrides) && array_key_exists((string) $siteId, $overrides)
            ? $overrides[(string) $siteId] : ($configuration['default_user_id'] ?? null);

        return is_int($value) && $value > 0 ? $value : null;
    }

    public function user(?int $siteId): ?User
    {
        $id = $this->reviewerId($siteId);

        $user = $id === null ? null : User::query()->whereKey($id)->first();

        return $user && $this->eligible($user, $siteId) ? $user : null;
    }

    /** Fresh capabilities and Site membership; configuration itself grants nothing. */
    public function eligible(User $candidate, ?int $siteId): bool
    {
        return HealthSafetyReadContext::within($candidate, ['hazards.view', 'healthSafety.viewAllSites', 'governance.compliance.manage', 'governance.compliance.view'],
            fn ($context): bool => $context->consumeSourceViewer(['hazards.view', 'healthSafety.viewAllSites', 'governance.compliance.manage', 'governance.compliance.view'],
                function (User $current, $reads) use ($siteId): bool {
                    if ($current->isExternalClinicianAccount() || ! $current->canDo('hazards.view')
                        || ! $current->canDo('governance.compliance.manage') || ! $current->canDo('governance.compliance.view')
                        || ! $reads->query(app(HrCurrentStaffService::class)->currentUsersQuery())->whereKey($current->id)->exists()) {
                        return false;
                    }
                    $sites = app(UserSiteAccessService::class);

                    return $siteId === null
                        ? $sites->canBypass($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS)
                        : in_array($siteId, $sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads), true);
                }));
    }

    /** One fresh candidate collector for all presented Sites, not one per Site. */
    public function identityForSites(User $candidate, array $requestedSiteIds): ?array
    {
        $keys = ['hazards.view', 'healthSafety.viewAllSites', 'governance.compliance.manage', 'governance.compliance.view'];

        return HealthSafetyReadContext::within($candidate, $keys, function ($context) use ($keys, $requestedSiteIds): ?array {
            return $context->consumeSourceViewer($keys, function (User $current, $reads) use ($context, $keys, $requestedSiteIds): ?array {
                if ($current->isExternalClinicianAccount() || ! $current->canDo('hazards.view')
                    || ! $current->canDo('governance.compliance.manage') || ! $current->canDo('governance.compliance.view')
                    || ! $reads->query(app(HrCurrentStaffService::class)->currentUsersQuery())->whereKey($current->id)->exists()) {
                    return null;
                }
                $siteIds = array_values(array_intersect($requestedSiteIds,
                    app(UserSiteAccessService::class)->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads)));
                if ($siteIds === []) { return null; }
                $authority = $context->originalSourceAuthorityQuery($keys, $siteIds);
                $name = $reads->query(app(HrCurrentStaffService::class)->currentUsersQuery())->whereKey($current->id)
                    ->whereExists($authority->selectRaw('1')->toBase())->value('users.name');

                return is_string($name) ? ['id' => (int) $current->id, 'name' => $name, 'site_ids' => $siteIds] : null;
            });
        });
    }
}
