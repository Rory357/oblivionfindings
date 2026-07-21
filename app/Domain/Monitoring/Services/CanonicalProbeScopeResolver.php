<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\ProbeScopeResolver;
use App\Domain\Monitoring\Data\ProbeScope;
use App\Domain\Monitoring\Exceptions\EgressDenied;
use Throwable;
use UnexpectedValueException;

final class CanonicalProbeScopeResolver implements ProbeScopeResolver
{
    public function __construct(
        private readonly ApprovedProbeScopeProvider $scopeProvider,
        private readonly CanonicalDeviceSiteResolver $deviceSiteResolver,
    ) {}

    public function resolve(int $siteId, int $deviceId): ProbeScope
    {
        if ($siteId < 1 || $deviceId < 1) {
            throw new EgressDenied('probe scope is invalid');
        }

        try {
            $canonicalSiteId = $this->deviceSiteResolver->resolve($deviceId);
        } catch (UnexpectedValueException $exception) {
            throw new EgressDenied(strtolower(rtrim($exception->getMessage(), '.')));
        } catch (Throwable) {
            throw new EgressDenied('canonical device is unavailable');
        }

        if ($canonicalSiteId !== $siteId) {
            throw new EgressDenied('canonical site mismatch');
        }

        try {
            $scope = $this->scopeProvider->forDeviceAtSite($siteId, $deviceId);
        } catch (Throwable) {
            throw new EgressDenied('approved probe scope is unavailable');
        }

        if ($scope->siteId !== $siteId || $scope->deviceId !== $deviceId) {
            throw new EgressDenied('approved scope mismatch');
        }

        return $scope;
    }
}
