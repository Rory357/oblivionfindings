<?php

namespace App\Services\Operations;

use App\Models\PayrollExport;
use App\Models\Timesheet;
use Carbon\Carbon;

class PayrollExportService
{
    public function generate(int $organizationId, string $periodStart, string $periodEnd, string $format, int $createdBy): PayrollExport
    {
        $timesheets = Timesheet::whereHas('client', fn ($q) => $q->where('organization_id', $organizationId))
            ->where('status', 'approved')
            ->whereBetween('work_date', [$periodStart, $periodEnd])
            ->whereNull('exported_to_payroll_at')
            ->with(['user:id,name', 'client:id,first_name,last_name', 'shift:id,starts_at,ends_at'])
            ->orderBy('work_date')
            ->get();

        $rows = $timesheets->map(function ($ts) {
            $hours = 0;
            if ($ts->starts_at && $ts->ends_at) {
                $minutes = Carbon::parse($ts->starts_at)->diffInMinutes(Carbon::parse($ts->ends_at));
                $hours = round(($minutes - ($ts->break_minutes ?? 0)) / 60, 2);
            }

            return [
                'employee_name' => $ts->user?->name,
                'employee_id' => $ts->user_id,
                'date' => $ts->work_date->format('Y-m-d'),
                'client' => $ts->client?->full_name ?? '',
                'hours' => $hours,
                'break_minutes' => $ts->break_minutes ?? 0,
                'mileage_km' => (float) ($ts->mileage_km ?? 0),
                'sleepover' => $ts->sleepover ? 'Yes' : 'No',
                'on_call' => $ts->on_call ? 'Yes' : 'No',
                'public_holiday' => $ts->public_holiday ? 'Yes' : 'No',
                'pay_type' => app(TimesheetHrSyncService::class)->mapPayType($ts),
            ];
        });

        $fileName = sprintf('payroll-export-%s-to-%s.%s', $periodStart, $periodEnd, $format === 'csv' ? 'csv' : 'json');
        $filePath = 'payroll-exports/' . $fileName;

        if ($format === 'csv') {
            $csvContent = $this->generateCsv($rows->toArray());
            \Illuminate\Support\Facades\Storage::put($filePath, $csvContent);
        } else {
            \Illuminate\Support\Facades\Storage::put($filePath, json_encode($rows, JSON_PRETTY_PRINT));
        }

        $export = PayrollExport::create([
            'organization_id' => $organizationId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'generated',
            'format' => $format,
            'file_path' => $filePath,
            'created_by' => $createdBy,
        ]);

        return $export;
    }

    public function confirmExport(PayrollExport $export): void
    {
        Timesheet::whereHas('client', fn ($q) => $q->where('organization_id', $export->organization_id))
            ->where('status', 'approved')
            ->whereBetween('work_date', [$export->period_start, $export->period_end])
            ->whereNull('exported_to_payroll_at')
            ->update(['exported_to_payroll_at' => now()]);

        $export->update(['status' => 'confirmed']);
    }

    protected function generateCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');
        fputcsv($output, array_keys($rows[0]));

        foreach ($rows as $row) {
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
