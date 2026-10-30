<?php

namespace App\Services\Tracking;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** One consent/assignment/retention boundary for history and reporting. */
final class ClientLocationReportWindow
{
    public function resolve(DeviceAssignment $assignment, array $filters, ?int $maximumDays = null): array
    {
        $data = Validator::make($filters, [
            'date_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ])->validate();
        $now = CarbonImmutable::now();
        $today = $now->setTimezone('Pacific/Auckland')->toDateString();
        if (($data['date_from'] ?? '') > $today || ($data['date_to'] ?? '') > $today
            || (isset($data['date_from'], $data['date_to']) && $data['date_from'] > $data['date_to'])) {
            throw ValidationException::withMessages(['date_to' => 'Choose a date range ending today or earlier, with To on or after From.']);
        }
        $requestedFrom = isset($data['date_from']) ? CarbonImmutable::parse($data['date_from'], 'Pacific/Auckland')->startOfDay() : null;
        $requestedTo = isset($data['date_to']) ? CarbonImmutable::parse($data['date_to'], 'Pacific/Auckland')->endOfDay() : $now;
        if ($maximumDays !== null && (! $requestedFrom || $requestedFrom->startOfDay()->diffInDays($requestedTo->startOfDay()) >= $maximumDays)) {
            throw ValidationException::withMessages(['date_to' => "Choose a range of {$maximumDays} calendar days or less."]);
        }
        abort_unless($assignment->assigned_at && $assignment->collection_started_at && $assignment->consent?->given_at && $assignment->retention_days > 0, 403);
        $from = collect([
            $now->subDays((int) $assignment->retention_days),
            CarbonImmutable::parse($assignment->assigned_at),
            CarbonImmutable::parse($assignment->collection_started_at),
            CarbonImmutable::parse($assignment->consent->given_at),
            $requestedFrom,
        ])->filter()->max();

        return ['from' => $from, 'to' => $now->min($requestedTo), 'timezone' => 'Pacific/Auckland'];
    }
}
