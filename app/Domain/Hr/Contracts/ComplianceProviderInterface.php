<?php

namespace App\Domain\Hr\Contracts;

interface ComplianceProviderInterface
{
    /**
     * Get compliance status for a staff member.
     */
    public function getStaffComplianceStatus(int $userId): array;

    /**
     * Check if a staff member meets all requirements for a given role/position.
     */
    public function meetsRoleRequirements(int $userId, int $positionId): bool;

    /**
     * Get upcoming compliance expirations within the given days window.
     */
    public function getUpcomingExpirations(int $daysAhead = 30): array;

    /**
     * Get compliance summary statistics for governance reporting.
     */
    public function getComplianceSummaryForGovernance(): array;
}
