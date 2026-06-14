<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinIrdFiling;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IrdFilingService
{
    /**
     * Create a GST filing record from an existing GST return.
     */
    public function createGstFiling(?int $orgId, FinGstReturn $gstReturn, string $irdNumber): FinIrdFiling
    {
        $filingData = $this->buildGstFilingPayload($gstReturn);

        return FinIrdFiling::create([
            'organization_id' => $orgId,
            'ird_number' => $irdNumber,
            'filing_type' => 'gst',
            'period_from' => $gstReturn->period_start,
            'period_to' => $gstReturn->period_end,
            'gst_return_id' => $gstReturn->id,
            'filing_data' => $filingData,
            'total_amount' => $gstReturn->gst_payable,
            'status' => 'draft',
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Validate a filing before submission.
     *
     * @return array<string> List of validation errors (empty if valid)
     */
    public function validateFiling(FinIrdFiling $filing): array
    {
        $errors = [];

        // Validate IRD number format (NZ: 8 or 9 digits)
        $irdNum = preg_replace('/[^0-9]/', '', $filing->ird_number);
        if (strlen($irdNum) < 8 || strlen($irdNum) > 9) {
            $errors[] = 'Invalid IRD number format. Must be 8 or 9 digits.';
        } else {
            // IRD number check digit validation (modulus 11 algorithm)
            if (! $this->validateIrdCheckDigit($irdNum)) {
                $errors[] = 'IRD number failed check digit validation.';
            }
        }

        // Validate period dates
        if ($filing->period_from && $filing->period_to) {
            if ($filing->period_from->gt($filing->period_to)) {
                $errors[] = 'Period from date must be before period to date.';
            }
        } else {
            $errors[] = 'Filing period dates are required.';
        }

        // Validate filing data completeness
        $data = $filing->filing_data;
        if ($filing->filing_type === 'gst') {
            $required = ['total_sales', 'total_purchases', 'gst_collected', 'gst_paid', 'gst_payable'];
            foreach ($required as $field) {
                if (! isset($data[$field])) {
                    $errors[] = "Missing required field: {$field}";
                }
            }

            // Validate GST calculation consistency
            if (isset($data['gst_collected'], $data['gst_paid'], $data['gst_payable'])) {
                $expectedPayable = bcsub((string) $data['gst_collected'], (string) $data['gst_paid'], 2);
                $actualPayable = (string) $data['gst_payable'];
                if (bccomp($expectedPayable, $actualPayable, 2) !== 0) {
                    $errors[] = 'GST payable amount does not match collected minus paid.';
                }
            }

            // Validate amounts are numeric
            foreach (['total_sales', 'total_purchases', 'gst_collected', 'gst_paid'] as $field) {
                if (isset($data[$field]) && ! is_numeric($data[$field])) {
                    $errors[] = "Field {$field} must be a numeric value.";
                }
            }
        }

        if (empty($errors)) {
            $filing->update(['status' => 'validated']);
        }

        return $errors;
    }

    /**
     * Submit a validated filing to IRD.
     */
    public function submitFiling(FinIrdFiling $filing): FinIrdFiling
    {
        if (! in_array($filing->status, ['validated', 'error'])) {
            throw new \InvalidArgumentException(
                "Filing must be validated before submission. Current status: {$filing->status}"
            );
        }

        try {
            $response = $this->callIrdApi($filing);

            $filing->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'ird_reference' => $response['reference'] ?? null,
                'ird_response' => $response,
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            $filing->update([
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);
        }

        return $filing->refresh();
    }

    /**
     * Build the GST filing payload in the standard IRD Gateway Services format.
     */
    protected function buildGstFilingPayload(FinGstReturn $gstReturn): array
    {
        return [
            // Header
            'return_type' => 'GST101A',
            'period_from' => $gstReturn->period_start->format('Y-m-d'),
            'period_to' => $gstReturn->period_end->format('Y-m-d'),
            'ird_period' => $gstReturn->ird_period,
            'filing_frequency' => $gstReturn->filing_frequency,
            'accounting_basis' => $gstReturn->basis,

            // Box 5: Total sales and income (including GST)
            'total_sales' => (string) $gstReturn->total_sales,

            // Box 6: Zero-rated supplies
            'zero_rated_supplies' => '0.00',

            // Box 7: Total sales subject to GST (Box 5 - Box 6)
            'taxable_sales' => (string) $gstReturn->total_sales,

            // Box 8: GST on sales (15%)
            'gst_collected' => (string) $gstReturn->total_gst_collected,

            // Box 9: Adjustments (from output tax side)
            'output_adjustments' => (string) ($gstReturn->adjustments ?? '0.00'),

            // Box 10: Total GST collected (Box 8 + Box 9)
            'total_gst_collected' => (string) $gstReturn->total_gst_collected,

            // Box 11: Total purchases and expenses (including GST)
            'total_purchases' => (string) $gstReturn->total_purchases,

            // Box 12: GST on purchases
            'gst_paid' => (string) $gstReturn->total_gst_paid,

            // Box 13: Credit adjustments
            'input_adjustments' => '0.00',

            // Box 14: Total GST credit (Box 12 + Box 13)
            'total_gst_credit' => (string) $gstReturn->total_gst_paid,

            // Box 15: GST payable (Box 10 - Box 14)
            'gst_payable' => (string) $gstReturn->gst_payable,

            // Metadata
            'prepared_at' => now()->toIso8601String(),
            'source_system' => config('app.name', 'Oblivion Findings'),
        ];
    }

    /**
     * Build a payday filing payload.
     * Placeholder for future implementation.
     */
    protected function buildPaydayFilingPayload(array $payrollData): array
    {
        return [
            'return_type' => 'EI',
            'pay_period_start' => $payrollData['period_start'] ?? null,
            'pay_period_end' => $payrollData['period_end'] ?? null,
            'payday' => $payrollData['payday'] ?? null,
            'employees' => $payrollData['employees'] ?? [],
            'total_gross' => $payrollData['total_gross'] ?? '0.00',
            'total_paye' => $payrollData['total_paye'] ?? '0.00',
            'total_student_loan' => $payrollData['total_student_loan'] ?? '0.00',
            'total_kiwisaver_employee' => $payrollData['total_kiwisaver_employee'] ?? '0.00',
            'total_kiwisaver_employer' => $payrollData['total_kiwisaver_employer'] ?? '0.00',
            'total_esct' => $payrollData['total_esct'] ?? '0.00',
            'prepared_at' => now()->toIso8601String(),
            'source_system' => config('app.name', 'Oblivion Findings'),
        ];
    }

    /**
     * Call the IRD Gateway Services API.
     * In production: POST to https://services.ird.govt.nz/gateway/
     */
    private function callIrdApi(FinIrdFiling $filing): array
    {
        Log::info('IRD filing submission initiated', [
            'filing_id' => $filing->id,
            'type' => $filing->filing_type,
            'ird_number' => '***'.substr($filing->ird_number, -3),
        ]);

        // No live IRD Gateway Services integration is wired yet (it requires a
        // SOAP request signed with WS-Security / X.509 to
        // https://services.ird.govt.nz/gateway/gws/returns/). Rather than fake a
        // successful filing, refuse unless an explicit simulation is enabled — so
        // a user is never misled into thinking a return was transmitted to IRD.
        if (! config('services.ird.simulation_enabled', false)) {
            throw new \RuntimeException(
                'Live IRD Gateway Services submission is not yet available. '.
                'File this return directly via myIR, or enable IRD simulation mode '.
                '(IRD_SIMULATION_ENABLED=true) for testing.'
            );
        }

        // Explicit, clearly-labelled simulation — NOT transmitted to IRD.
        return [
            'reference' => 'SIM-'.strtoupper(Str::random(8)),
            'status' => 'simulated',
            'simulated' => true,
            'timestamp' => now()->toIso8601String(),
            'message' => 'SIMULATED submission — NOT transmitted to IRD. File via myIR for a real submission.',
        ];
    }

    /**
     * Validate IRD number using the NZ modulus 11 check digit algorithm.
     */
    private function validateIrdCheckDigit(string $irdNum): bool
    {
        // Pad to 9 digits if 8 digits
        if (strlen($irdNum) === 8) {
            $irdNum = '0'.$irdNum;
        }

        if (strlen($irdNum) !== 9) {
            return false;
        }

        $digits = array_map('intval', str_split($irdNum));
        $checkDigit = $digits[8];

        // Primary weights
        $primaryWeights = [3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += $digits[$i] * $primaryWeights[$i];
        }

        $remainder = $sum % 11;
        if ($remainder === 0) {
            return $checkDigit === 0;
        }

        $check = 11 - $remainder;
        if ($check < 10) {
            return $checkDigit === $check;
        }

        // Secondary weights (used when primary check > 9)
        $secondaryWeights = [7, 4, 3, 2, 5, 2, 7, 6];
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += $digits[$i] * $secondaryWeights[$i];
        }

        $remainder = $sum % 11;
        if ($remainder === 0) {
            return $checkDigit === 0;
        }

        $check = 11 - $remainder;

        return $check < 10 && $checkDigit === $check;
    }
}
