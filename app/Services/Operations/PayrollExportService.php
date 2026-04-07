<?php

namespace App\Services\Operations;

use App\Models\PayrollExport;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PayrollExportService
{
    public function __construct(
        protected PayrollRateResolver $rateResolver,
    ) {
    }

    public function generate(int $organizationId, string $periodStart, string $periodEnd, string $format, int $createdBy): PayrollExport
    {
        $periodStartAt = Carbon::parse($periodStart)->startOfDay();
        $periodEndAt = Carbon::parse($periodEnd)->addDay()->startOfDay();

        $export = PayrollExport::create([
            'organization_id' => $organizationId,
            'export_type' => $format === 'csv' ? 'csv' : 'json',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'generating',
            'timesheet_count' => 0,
            'total_hours' => 0,
            'total_amount' => 0,
            'exported_at' => now(),
            'exported_by' => $createdBy,
            'notes' => 'Preparing payroll export.',
        ]);

        $reference = 'operations-payroll-export:' . $export->id;

        [$rows, $timesheetIds] = DB::transaction(function () use ($organizationId, $periodStartAt, $periodEndAt, $reference) {
            $timesheets = Timesheet::query()
                ->whereHas('client', fn ($q) => $q->where('organization_id', $organizationId))
                ->where('status', 'approved')
                ->whereNull('exported_to_payroll_at')
                ->whereNull('payroll_reference')
                ->where('starts_at', '<', $periodEndAt)
                ->where('ends_at', '>', $periodStartAt)
                ->with([
                    'user:id,name',
                    'client:id,first_name,last_name',
                    'shift:id,service_context_id,starts_at,ends_at,location,shift_type,is_sleepover,is_on_call,expected_break_minutes',
                    'shift.serviceContext:id,name',
                ])
                ->lockForUpdate()
                ->orderBy('starts_at')
                ->get();

            $rows = collect();
            $timesheetIds = [];

            foreach ($timesheets as $timesheet) {
                $segments = $this->segmentsForPeriod($timesheet, $periodStartAt, $periodEndAt);
                foreach ($segments as $segment) {
                    $rows->push($this->buildRow($timesheet, $segment));
                    $timesheetIds[] = $timesheet->id;
                }
            }

            $timesheetIds = array_values(array_unique($timesheetIds));
            if ($timesheetIds !== []) {
                Timesheet::query()
                    ->whereIn('id', $timesheetIds)
                    ->update(['payroll_reference' => $reference]);
            }

            return [$rows, $timesheetIds];
        });

        $fileName = sprintf('payroll-export-%s-to-%s.%s', $periodStart, $periodEnd, $format === 'csv' ? 'csv' : 'json');
        $filePath = 'payroll-exports/' . $fileName;

        if ($format === 'csv') {
            Storage::put($filePath, $this->generateCsv($rows->toArray()));
        } else {
            Storage::put($filePath, json_encode($rows, JSON_PRETTY_PRINT));
        }

        $export->update([
            'status' => 'exported',
            'timesheet_count' => count($timesheetIds),
            'total_hours' => round((float) $rows->sum('hours'), 2),
            'total_amount' => round((float) $rows->sum('estimated_pay'), 2),
            'file_path' => $filePath,
            'notes' => sprintf(
                'Generated from approved timesheets between %s and %s. Includes %d payroll segment(s).',
                $periodStart,
                $periodEnd,
                $rows->count(),
            ),
        ]);

        return $export->fresh();
    }

    public function confirmExport(PayrollExport $export): void
    {
        $periodStartAt = $export->period_start->copy()->startOfDay();
        $periodEndAt = $export->period_end->copy()->addDay()->startOfDay();
        $reference = 'operations-payroll-export:' . $export->id;

        DB::transaction(function () use ($export, $periodStartAt, $periodEndAt, $reference) {
            $timesheets = Timesheet::query()
                ->whereHas('client', fn ($q) => $q->where('organization_id', $export->organization_id))
                ->where('status', 'approved')
                ->where('payroll_reference', $reference)
                ->with(['shift', 'client', 'user'])
                ->lockForUpdate()
                ->get();

            foreach ($timesheets as $timesheet) {
                $existing = collect($timesheet->payroll_segments_exported ?? []);
                $newSegments = $this->segmentsForPeriod($timesheet, $periodStartAt, $periodEndAt);

                foreach ($newSegments as $segment) {
                    $existing->push([
                        'export_id' => $export->id,
                        'reference' => $reference,
                        'segment_key' => $segment['segment_key'],
                        'segment_start_at' => $segment['segment_start_at'],
                        'segment_end_at' => $segment['segment_end_at'],
                        'segment_minutes' => $segment['segment_minutes'],
                        'allocated_break_minutes' => $segment['allocated_break_minutes'],
                        'exported_at' => now()->toIso8601String(),
                    ]);
                }

                $confirmedMinutes = $existing->sum(fn (array $segment) => (int) ($segment['segment_minutes'] ?? 0));
                $totalMinutes = $this->totalMinutes($timesheet);

                $timesheet->forceFill([
                    'payroll_segments_exported' => $existing->values()->all(),
                    'payroll_reference' => null,
                    'exported_to_payroll_at' => $confirmedMinutes >= $totalMinutes ? now() : null,
                ])->saveQuietly();
            }

            $export->update(['status' => 'confirmed']);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function segmentsForPeriod(Timesheet $timesheet, Carbon $periodStartAt, Carbon $periodEndAt): array
    {
        if (! $timesheet->starts_at || ! $timesheet->ends_at) {
            return [];
        }

        $startsAt = $timesheet->starts_at->copy();
        $endsAt = $timesheet->ends_at->copy();
        $windowStart = $startsAt->greaterThan($periodStartAt) ? $startsAt : $periodStartAt->copy();
        $windowEnd = $endsAt->lessThan($periodEndAt) ? $endsAt : $periodEndAt->copy();

        if ($windowStart->greaterThanOrEqualTo($windowEnd)) {
            return [];
        }

        $remaining = $this->subtractConfirmedIntervals(
            [['start' => $windowStart, 'end' => $windowEnd]],
            collect($timesheet->payroll_segments_exported ?? [])
                ->map(function (array $segment) {
                    return [
                        'start' => Carbon::parse($segment['segment_start_at']),
                        'end' => Carbon::parse($segment['segment_end_at']),
                    ];
                })
                ->all(),
        );

        if ($remaining === []) {
            return [];
        }

        $confirmedBreak = collect($timesheet->payroll_segments_exported ?? [])
            ->sum(fn (array $segment) => (int) ($segment['allocated_break_minutes'] ?? 0));
        $remainingBreak = max((int) ($timesheet->break_minutes ?? 0) - $confirmedBreak, 0);
        $remainingUnexportedMinutes = max($this->totalMinutes($timesheet) - $this->confirmedMinutes($timesheet), 0);

        $segments = [];

        foreach ($remaining as $index => $interval) {
            $segmentMinutes = $interval['start']->diffInMinutes($interval['end']);
            if ($segmentMinutes <= 0) {
                continue;
            }

            $isLastPossibleSegment = ($index === array_key_last($remaining)) && $segmentMinutes >= $remainingUnexportedMinutes;
            $allocatedBreak = $remainingUnexportedMinutes > 0
                ? ($isLastPossibleSegment
                    ? $remainingBreak
                    : min($remainingBreak, (int) round(($segmentMinutes / $remainingUnexportedMinutes) * $remainingBreak)))
                : 0;

            $segments[] = [
                'segment_key' => sha1($timesheet->id . '|' . $interval['start']->toIso8601String() . '|' . $interval['end']->toIso8601String()),
                'segment_start_at' => $interval['start']->toIso8601String(),
                'segment_end_at' => $interval['end']->toIso8601String(),
                'segment_minutes' => $segmentMinutes,
                'allocated_break_minutes' => $allocatedBreak,
            ];

            $remainingBreak = max($remainingBreak - $allocatedBreak, 0);
            $remainingUnexportedMinutes = max($remainingUnexportedMinutes - $segmentMinutes, 0);
        }

        return $segments;
    }

    /**
     * @param  array<int, array{start: Carbon, end: Carbon}>  $baseIntervals
     * @param  array<int, array{start: Carbon, end: Carbon}>  $confirmedIntervals
     * @return array<int, array{start: Carbon, end: Carbon}>
     */
    protected function subtractConfirmedIntervals(array $baseIntervals, array $confirmedIntervals): array
    {
        $remaining = $baseIntervals;

        foreach ($confirmedIntervals as $confirmed) {
            $next = [];
            foreach ($remaining as $interval) {
                if ($confirmed['end']->lessThanOrEqualTo($interval['start']) || $confirmed['start']->greaterThanOrEqualTo($interval['end'])) {
                    $next[] = $interval;
                    continue;
                }

                if ($confirmed['start']->greaterThan($interval['start'])) {
                    $next[] = [
                        'start' => $interval['start']->copy(),
                        'end' => $confirmed['start']->copy(),
                    ];
                }

                if ($confirmed['end']->lessThan($interval['end'])) {
                    $next[] = [
                        'start' => $confirmed['end']->copy(),
                        'end' => $interval['end']->copy(),
                    ];
                }
            }
            $remaining = $next;
        }

        return array_values(array_filter($remaining, fn (array $interval) => $interval['start']->lt($interval['end'])));
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return array<string, mixed>
     */
    protected function buildRow(Timesheet $timesheet, array $segment): array
    {
        $rate = $this->rateResolver->resolve($timesheet);
        $hours = round(max(((int) $segment['segment_minutes'] - (int) $segment['allocated_break_minutes']), 0) / 60, 2);
        $estimatedPay = match ($rate['pay_type']) {
            'sleepover' => (float) $rate['pay_rate'],
            default => round($hours * (float) $rate['pay_rate'], 2),
        };
        $legacyFallbackUsed = blank($timesheet->staff_name_snapshot) || blank($timesheet->client_name_snapshot);

        return [
            'source_timesheet_id' => $timesheet->id,
            'source_shift_id' => $timesheet->shift_id,
            'segment_key' => $segment['segment_key'],
            'segment_start_at' => $segment['segment_start_at'],
            'segment_end_at' => $segment['segment_end_at'],
            'employee_name' => $timesheet->staff_name_snapshot ?? $timesheet->user?->name,
            'employee_id' => $timesheet->user_id,
            'date' => Carbon::parse($segment['segment_start_at'])->format('Y-m-d'),
            'client' => $timesheet->client_name_snapshot ?? $timesheet->client?->full_name ?? '',
            'shift_type' => $timesheet->shift_type_snapshot ?? ($timesheet->sleepover ? 'sleepover' : ($timesheet->on_call ? 'on_call' : 'standard')),
            'service_context' => $timesheet->service_context_name_snapshot,
            'location' => $timesheet->shift_location_snapshot,
            'site' => $timesheet->shift_site_name_snapshot,
            'hours' => $hours,
            'break_minutes' => (int) $segment['allocated_break_minutes'],
            'segment_minutes' => (int) $segment['segment_minutes'],
            'mileage_km' => 0.0,
            'sleepover' => $timesheet->sleepover ? 'Yes' : 'No',
            'on_call' => $timesheet->on_call ? 'Yes' : 'No',
            'public_holiday' => $timesheet->public_holiday ? 'Yes' : 'No',
            'pay_type' => $rate['pay_type'],
            'pay_rate' => $rate['pay_rate'],
            'estimated_pay' => $estimatedPay,
            'coverage_roles' => implode(', ', (array) ($timesheet->coverage_roles_snapshot ?? [])),
            'snapshot_safe' => $timesheet->is_snapshot_complete ? 'Yes' : 'No',
            'legacy_fallback_used' => $legacyFallbackUsed ? 'Yes' : 'No',
            'payroll_segment_complete' => $timesheet->is_payroll_segment_complete ? 'Yes' : 'No',
        ];
    }

    protected function totalMinutes(Timesheet $timesheet): int
    {
        return $timesheet->starts_at && $timesheet->ends_at
            ? (int) $timesheet->starts_at->diffInMinutes($timesheet->ends_at)
            : 0;
    }

    protected function confirmedMinutes(Timesheet $timesheet): int
    {
        return (int) collect($timesheet->payroll_segments_exported ?? [])
            ->sum(fn (array $segment) => (int) ($segment['segment_minutes'] ?? 0));
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
