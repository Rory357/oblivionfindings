<?php

namespace App\Domain\Finance\Services;

use LogicException;

/**
 * Immutable result of resolving a Financial Insights request boundary.
 *
 * @phpstan-type SiteIdList list<int>
 */
final readonly class FinancialInsightsScopeDecision
{
    /**
     * @param  list<int>  $siteIds
     * @param  list<int>  $clientIds
     */
    private function __construct(
        public FinancialInsightsScope $scope,
        public array $siteIds,
        public array $clientIds,
        public ?int $siteId = null,
        public ?int $clientId = null,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<int>  $clientIds
     */
    public static function global(
        array $siteIds,
        array $clientIds,
        ?int $siteId = null,
        ?int $clientId = null,
    ): self {
        return new self(FinancialInsightsScope::Global, $siteIds, $clientIds, $siteId, $clientId);
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<int>  $clientIds
     */
    public static function accessibleSites(array $siteIds, array $clientIds, ?int $siteId = null): self
    {
        return new self(FinancialInsightsScope::AccessibleSite, $siteIds, $clientIds, $siteId);
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<int>  $clientIds
     */
    public static function clientRelationship(
        array $siteIds,
        array $clientIds,
        int $siteId,
        int $clientId,
    ): self {
        return new self(FinancialInsightsScope::ClientRelationship, $siteIds, $clientIds, $siteId, $clientId);
    }

    public static function denied(): self
    {
        return new self(FinancialInsightsScope::Denied, [], []);
    }

    public function isDenied(): bool
    {
        return $this->scope === FinancialInsightsScope::Denied;
    }

    public function targetSiteId(): int
    {
        return $this->siteId
            ?? throw new LogicException('The Financial Insights decision has no target Site.');
    }

    public function targetClientId(): int
    {
        return $this->clientId
            ?? throw new LogicException('The Financial Insights decision has no target Client.');
    }
}
