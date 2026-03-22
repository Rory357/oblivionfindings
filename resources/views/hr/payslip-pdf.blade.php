<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip - {{ $employee->name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #333; padding: 20px; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 15px; }
        .header h1 { font-size: 20px; }
        .header .period { font-size: 11px; color: #666; }
        .section { margin-bottom: 15px; }
        .section-title { font-size: 13px; font-weight: bold; background: #f5f5f5; padding: 6px 10px; border-bottom: 1px solid #ddd; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 4px 10px; text-align: left; }
        .right { text-align: right; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px; }
        .info-grid .label { color: #666; font-size: 10px; text-transform: uppercase; }
        .info-grid .value { font-weight: bold; }
        .totals-row { border-top: 2px solid #333; font-weight: bold; font-size: 14px; }
        .totals-row td { padding-top: 8px; }
        .deduction { color: #c00; }
        .footer { margin-top: 20px; font-size: 10px; color: #999; border-top: 1px solid #ddd; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>Payslip</h1>
            <div class="period">
                Period: {{ $payslip->pay_period_start->format('d M Y') }} &ndash; {{ $payslip->pay_period_end->format('d M Y') }}
            </div>
        </div>
        <div style="text-align: right;">
            <div><strong>Status:</strong> {{ ucfirst($payslip->status) }}</div>
            @if($payslip->payment_date)
                <div><strong>Payment Date:</strong> {{ $payslip->payment_date->format('d M Y') }}</div>
            @endif
        </div>
    </div>

    {{-- Employee Details --}}
    <div class="section">
        <div class="section-title">Employee Details</div>
        <table>
            <tr>
                <td class="label" style="width: 25%; color: #666;">Name</td>
                <td style="width: 25%;">{{ $employee->name }}</td>
                <td class="label" style="width: 25%; color: #666;">Employee No.</td>
                <td style="width: 25%;">{{ $profile->employee_number ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label" style="color: #666;">Tax Code</td>
                <td>{{ $payslip->tax_code }}</td>
                <td class="label" style="color: #666;">KiwiSaver Rate</td>
                <td>{{ $payslip->kiwisaver_rate }}%</td>
            </tr>
            <tr>
                <td class="label" style="color: #666;">Position</td>
                <td colspan="3">{{ $profile->position_title ?? '-' }}</td>
            </tr>
        </table>
    </div>

    {{-- Earnings --}}
    <div class="section">
        <div class="section-title">Earnings</div>
        <table>
            <tr>
                <td>Regular Hours</td>
                <td class="right">{{ number_format($payslip->regular_hours, 2) }} hrs</td>
                <td class="right">${{ number_format($payslip->gross_pay - collect($payslip->allowances ?? [])->sum('amount'), 2) }}</td>
            </tr>
            @if($payslip->overtime_hours > 0)
            <tr>
                <td>Overtime Hours (x1.5)</td>
                <td class="right">{{ number_format($payslip->overtime_hours, 2) }} hrs</td>
                <td class="right">-</td>
            </tr>
            @endif
            @foreach($payslip->allowances ?? [] as $allowance)
            <tr>
                <td>{{ $allowance['name'] ?? 'Allowance' }}</td>
                <td class="right"></td>
                <td class="right">${{ number_format($allowance['amount'] ?? 0, 2) }}</td>
            </tr>
            @endforeach
            @if($payslip->holiday_pay > 0)
            <tr>
                <td>Holiday Pay (8%)</td>
                <td class="right"></td>
                <td class="right">${{ number_format($payslip->holiday_pay, 2) }}</td>
            </tr>
            @endif
            <tr style="border-top: 1px solid #ccc; font-weight: bold;">
                <td>Gross Pay</td>
                <td></td>
                <td class="right">${{ number_format($payslip->gross_pay + $payslip->holiday_pay, 2) }}</td>
            </tr>
        </table>
    </div>

    {{-- Deductions --}}
    <div class="section">
        <div class="section-title">Deductions</div>
        <table>
            <tr>
                <td>PAYE</td>
                <td class="right deduction">-${{ number_format($payslip->paye, 2) }}</td>
            </tr>
            <tr>
                <td>ACC Earner Levy</td>
                <td class="right deduction">-${{ number_format($payslip->acc_levy, 2) }}</td>
            </tr>
            <tr>
                <td>KiwiSaver Employee ({{ $payslip->kiwisaver_rate }}%)</td>
                <td class="right deduction">-${{ number_format($payslip->kiwisaver_employee, 2) }}</td>
            </tr>
            @if($payslip->student_loan > 0)
            <tr>
                <td>Student Loan</td>
                <td class="right deduction">-${{ number_format($payslip->student_loan, 2) }}</td>
            </tr>
            @endif
            @foreach($payslip->other_deductions ?? [] as $deduction)
            <tr>
                <td>{{ $deduction['name'] ?? 'Deduction' }}</td>
                <td class="right deduction">-${{ number_format($deduction['amount'] ?? 0, 2) }}</td>
            </tr>
            @endforeach
            <tr style="border-top: 1px solid #ccc; font-weight: bold;">
                <td>Total Deductions</td>
                <td class="right deduction">-${{ number_format($payslip->total_deductions, 2) }}</td>
            </tr>
        </table>
    </div>

    {{-- Employer Contributions (informational) --}}
    <div class="section">
        <div class="section-title">Employer Contributions</div>
        <table>
            <tr>
                <td>KiwiSaver Employer</td>
                <td class="right">${{ number_format($payslip->kiwisaver_employer, 2) }}</td>
            </tr>
        </table>
    </div>

    {{-- Summary --}}
    <div class="section">
        <div class="section-title">Pay Summary</div>
        <table>
            <tr>
                <td>Gross Pay</td>
                <td class="right">${{ number_format($payslip->gross_pay, 2) }}</td>
            </tr>
            @if($payslip->holiday_pay > 0)
            <tr>
                <td>Holiday Pay</td>
                <td class="right">${{ number_format($payslip->holiday_pay, 2) }}</td>
            </tr>
            @endif
            <tr>
                <td>Total Deductions</td>
                <td class="right deduction">-${{ number_format($payslip->total_deductions, 2) }}</td>
            </tr>
            <tr class="totals-row">
                <td>Net Pay</td>
                <td class="right">${{ number_format($payslip->net_pay, 2) }}</td>
            </tr>
        </table>
    </div>

    <div class="footer">
        Generated on {{ now()->format('d M Y H:i') }}. This is a computer-generated document.
    </div>
</body>
</html>
