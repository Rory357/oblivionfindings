<?php

namespace App\Domain\Finance\Contracts;

interface PayrollDataProviderInterface
{
    public function getPayrollRunData(int $payrollRunId): array;

    public function getPayrollSummaryForPeriod(string $startDate, string $endDate): array;

    public function getEmployeeCostAllocations(int $payrollRunId): array;
}
