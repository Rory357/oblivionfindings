<?php

namespace App\Domain\Hr\Services;

class NzPayrollCalculatorService
{
    /**
     * NZ PAYE tax brackets for the 2025-2026 tax year.
     */
    private const PAYE_BRACKETS = [
        ['min' => 0, 'max' => 14000, 'rate' => 0.105],
        ['min' => 14000, 'max' => 48000, 'rate' => 0.175],
        ['min' => 48000, 'max' => 70000, 'rate' => 0.30],
        ['min' => 70000, 'max' => 180000, 'rate' => 0.33],
        ['min' => 180000, 'max' => PHP_INT_MAX, 'rate' => 0.39],
    ];

    /**
     * ACC earner levy rate for 2025-2026 (1.53%).
     */
    private const ACC_EARNER_LEVY_RATE = 0.0153;

    /**
     * Maximum liable earnings for ACC earner levy 2025-2026.
     */
    private const ACC_MAX_LIABLE_EARNINGS = 142283;

    /**
     * Valid employee KiwiSaver contribution rate options (%).
     */
    private const KIWISAVER_RATES = [3, 4, 6, 8, 10];

    /**
     * Minimum employer KiwiSaver contribution rate (%).
     */
    private const KIWISAVER_EMPLOYER_MIN = 3;

    /**
     * Student loan annual repayment threshold 2025-2026.
     */
    private const SL_REPAYMENT_THRESHOLD = 22828;

    /**
     * Student loan repayment rate (12 cents per dollar over threshold).
     */
    private const SL_REPAYMENT_RATE = 0.12;

    /**
     * Calculate annual PAYE tax using cumulative bracket method.
     *
     * Applies progressive tax rates across NZ income tax brackets.
     * Supports standard tax codes (M, ME, M SL, S, SH, ST, etc.).
     *
     * @param  float   $annualGross  Annual gross income
     * @param  string  $taxCode      IRD tax code (default 'M')
     * @return float   Annual PAYE amount
     */
    public function calculatePaye(float $annualGross, string $taxCode = 'M'): float
    {
        if ($annualGross <= 0) {
            return 0.0;
        }

        // Secondary tax codes use flat rates
        $secondaryRates = [
            'SB'  => 0.105,
            'S'   => 0.175,
            'SH'  => 0.30,
            'ST'  => 0.33,
            'SA'  => 0.39,
        ];

        $upperCode = strtoupper(trim($taxCode));

        // Check for secondary tax codes (flat rate on all income)
        if (isset($secondaryRates[$upperCode])) {
            return round($annualGross * $secondaryRates[$upperCode], 2);
        }

        // Standard cumulative bracket calculation for M, ME, M SL, etc.
        $tax = 0.0;
        $remaining = $annualGross;

        foreach (self::PAYE_BRACKETS as $bracket) {
            if ($remaining <= 0) {
                break;
            }

            $taxableInBracket = min($remaining, $bracket['max'] - $bracket['min']);
            $tax += $taxableInBracket * $bracket['rate'];
            $remaining -= $taxableInBracket;
        }

        return round($tax, 2);
    }

    /**
     * Calculate ACC earner levy.
     *
     * Levy is charged at the ACC rate on earnings up to the maximum
     * liable earnings cap.
     *
     * @param  float  $annualGross  Annual gross income
     * @return float  Annual ACC earner levy
     */
    public function calculateAccLevy(float $annualGross): float
    {
        if ($annualGross <= 0) {
            return 0.0;
        }

        $liable = min($annualGross, self::ACC_MAX_LIABLE_EARNINGS);

        return round($liable * self::ACC_EARNER_LEVY_RATE, 2);
    }

    /**
     * Calculate KiwiSaver contributions for both employee and employer.
     *
     * @param  float  $grossPay      Gross pay for the period
     * @param  float  $employeeRate  Employee contribution rate (3, 4, 6, 8, or 10%)
     * @param  float  $employerRate  Employer contribution rate (minimum 3%)
     * @return array{employee: float, employer: float}
     */
    public function calculateKiwiSaver(float $grossPay, float $employeeRate, float $employerRate = 3.0): array
    {
        $employeeRate = max(0, $employeeRate);
        $employerRate = max(self::KIWISAVER_EMPLOYER_MIN, $employerRate);

        return [
            'employee' => round($grossPay * ($employeeRate / 100), 2),
            'employer' => round($grossPay * ($employerRate / 100), 2),
        ];
    }

    /**
     * Calculate student loan repayment for a pay period.
     *
     * Repayment is 12% of gross income over the annual threshold,
     * prorated to the pay period.
     *
     * @param  float  $annualGross  Annual gross income
     * @return float  Annual student loan repayment
     */
    public function calculateStudentLoan(float $annualGross): float
    {
        if ($annualGross <= self::SL_REPAYMENT_THRESHOLD) {
            return 0.0;
        }

        $overThreshold = $annualGross - self::SL_REPAYMENT_THRESHOLD;

        return round($overThreshold * self::SL_REPAYMENT_RATE, 2);
    }

    /**
     * Calculate holiday pay accrual.
     *
     * Casual employees receive 8% holiday pay loading on gross pay.
     * Permanent/fixed-term employees accrue annual leave entitlement
     * instead (returned as 0 here).
     *
     * @param  float   $grossPay        Gross pay for the period
     * @param  string  $employmentType  e.g. 'casual', 'permanent', 'fixed_term'
     * @return float   Holiday pay amount
     */
    public function calculateHolidayPay(float $grossPay, string $employmentType): float
    {
        if (strtolower($employmentType) === 'casual') {
            return round($grossPay * 0.08, 2);
        }

        return 0.0;
    }

    /**
     * Calculate a complete pay period breakdown.
     *
     * Takes all employee pay parameters and returns a comprehensive
     * breakdown of earnings, deductions, and net pay.
     *
     * @param  float   $annualSalary    Annual salary (0 for hourly-only workers)
     * @param  float|null $hourlyRate   Hourly rate (null for salaried workers)
     * @param  float   $hoursWorked     Regular hours worked in the period
     * @param  float   $overtimeHours   Overtime hours worked in the period
     * @param  string  $payFrequency    'weekly', 'fortnightly', or 'monthly'
     * @param  string  $taxCode         IRD tax code
     * @param  float   $kiwiSaverRate   Employee KiwiSaver rate (%)
     * @param  bool    $hasStudentLoan  Whether employee has student loan
     * @param  string  $employmentType  Employment type for holiday pay
     * @param  array   $allowances      Additional allowances [{name, amount}]
     * @param  array   $deductions      Additional deductions [{name, amount}]
     * @return array   Comprehensive pay breakdown
     */
    public function calculatePayPeriod(
        float $annualSalary,
        ?float $hourlyRate,
        float $hoursWorked,
        float $overtimeHours,
        string $payFrequency,
        string $taxCode,
        float $kiwiSaverRate,
        bool $hasStudentLoan,
        string $employmentType,
        array $allowances = [],
        array $deductions = [],
    ): array {
        // Determine periods per year
        $periodsPerYear = match (strtolower($payFrequency)) {
            'weekly'      => 52,
            'fortnightly' => 26,
            'monthly'     => 12,
            default       => 26,
        };

        // Calculate gross pay for the period
        if ($annualSalary > 0 && $hourlyRate === null) {
            // Salaried employee — base pay from salary
            $basePay = round($annualSalary / $periodsPerYear, 2);
            $overtimePay = 0.0;
        } else {
            // Hourly employee
            $rate = $hourlyRate ?? 0;
            $basePay = round($hoursWorked * $rate, 2);
            $overtimePay = round($overtimeHours * $rate * 1.5, 2);
        }

        $totalAllowances = round(collect($allowances)->sum('amount'), 2);
        $grossPay = $basePay + $overtimePay + $totalAllowances;

        // Annualise for tax calculations
        $annualGross = $grossPay * $periodsPerYear;

        // PAYE (annualised then prorated to period)
        $annualPaye = $this->calculatePaye($annualGross, $taxCode);
        $paye = round($annualPaye / $periodsPerYear, 2);

        // ACC earner levy (annualised then prorated)
        $annualAcc = $this->calculateAccLevy($annualGross);
        $accLevy = round($annualAcc / $periodsPerYear, 2);

        // KiwiSaver
        $kiwiSaver = $this->calculateKiwiSaver($grossPay, $kiwiSaverRate);

        // Student loan (annualised then prorated)
        $studentLoan = 0.0;
        if ($hasStudentLoan) {
            $annualSl = $this->calculateStudentLoan($annualGross);
            $studentLoan = round($annualSl / $periodsPerYear, 2);
        }

        // Holiday pay
        $holidayPay = $this->calculateHolidayPay($grossPay, $employmentType);

        // Other deductions
        $totalOtherDeductions = round(collect($deductions)->sum('amount'), 2);

        // Total deductions
        $totalDeductions = $paye + $accLevy + $kiwiSaver['employee'] + $studentLoan + $totalOtherDeductions;
        $totalDeductions = round($totalDeductions, 2);

        // Net pay
        $netPay = round($grossPay + $holidayPay - $totalDeductions, 2);

        return [
            'gross_pay'           => $grossPay,
            'base_pay'            => $basePay,
            'overtime_pay'        => $overtimePay,
            'paye'                => $paye,
            'acc_levy'            => $accLevy,
            'kiwisaver_employee'  => $kiwiSaver['employee'],
            'kiwisaver_employer'  => $kiwiSaver['employer'],
            'student_loan'        => $studentLoan,
            'holiday_pay'         => $holidayPay,
            'total_allowances'    => $totalAllowances,
            'total_other_deductions' => $totalOtherDeductions,
            'total_deductions'    => $totalDeductions,
            'net_pay'             => $netPay,
            'allowances'          => $allowances,
            'deductions'          => $deductions,
        ];
    }

    /**
     * Convert annual salary to fortnightly pay.
     *
     * @param  float  $annualSalary
     * @return float  Fortnightly gross pay
     */
    public function calculateFortnightlyFromAnnual(float $annualSalary): float
    {
        return round($annualSalary / 26, 2);
    }

    /**
     * Convert annual salary to weekly pay.
     *
     * @param  float  $annualSalary
     * @return float  Weekly gross pay
     */
    public function calculateWeeklyFromAnnual(float $annualSalary): float
    {
        return round($annualSalary / 52, 2);
    }
}
