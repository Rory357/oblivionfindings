<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayrollRunItem;

class PayrollExportFormatService
{
    public function exportToXero(HrPayrollRun $run): string
    {
        $items = $run->items()->with('user:id,name', 'employeeProfile:id,user_id,employee_number')->get();

        $csv = "Employee Name,Employee Number,Pay Item,Hours,Rate,Amount\n";
        foreach ($items as $item) {
            $name = $item->user?->name ?? 'Unknown';
            $empNo = $item->employeeProfile?->employee_number ?? '';

            if ((float) $item->regular_hours > 0) {
                $csv .= "\"{$name}\",\"{$empNo}\",\"Ordinary Hours\",{$item->regular_hours},,\n";
            }
            if ((float) $item->overtime_hours > 0) {
                $csv .= "\"{$name}\",\"{$empNo}\",\"Overtime\",{$item->overtime_hours},,\n";
            }
            if ((float) $item->gross_pay > 0) {
                $csv .= "\"{$name}\",\"{$empNo}\",\"Gross Pay\",,,{$item->gross_pay}\n";
            }
        }

        return $csv;
    }

    public function exportToMyob(HrPayrollRun $run): string
    {
        $items = $run->items()->with('user:id,name', 'employeeProfile:id,user_id,employee_number')->get();

        $csv = "Co./Last Name,First Name,Pay Period Start,Pay Period End,Hours,Gross Pay\n";
        foreach ($items as $item) {
            $nameParts = explode(' ', $item->user?->name ?? 'Unknown', 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';
            $totalHours = (float) $item->regular_hours + (float) $item->overtime_hours;

            $csv .= "\"{$lastName}\",\"{$firstName}\",\"{$run->period_start->toDateString()}\",\"{$run->period_end->toDateString()}\",{$totalHours},{$item->gross_pay}\n";
        }

        return $csv;
    }

    public function exportToIPayroll(HrPayrollRun $run): string
    {
        $items = $run->items()->with('user:id,name', 'employeeProfile:id,user_id,employee_number,tax_code,kiwisaver_rate')->get();

        $csv = "EmployeeNo,Name,TaxCode,GrossPay,PAYE,KiwiSaver,NetPay\n";
        foreach ($items as $item) {
            $csv .= "\"{$item->employeeProfile?->employee_number}\","
                . "\"{$item->user?->name}\","
                . "\"{$item->employeeProfile?->tax_code}\","
                . "{$item->gross_pay},"
                . ",," // PAYE and KiwiSaver calculated by iPayroll
                . "\n";
        }

        return $csv;
    }

    public function exportToBankFile(HrPayrollRun $run): string
    {
        $items = $run->items()->with('user:id,name', 'employeeProfile:id,user_id,employee_number,bank_account')->get();

        $csv = "Particulars,Code,Reference,Amount,Account\n";
        foreach ($items as $item) {
            $name = $item->user?->name ?? 'Unknown';
            $bankAccount = $item->employeeProfile?->bank_account ?? '';
            $ref = "PAY-{$run->id}";

            $csv .= "\"{$name}\",\"SALARY\",\"{$ref}\",{$item->gross_pay},\"{$bankAccount}\"\n";
        }

        return $csv;
    }
}
