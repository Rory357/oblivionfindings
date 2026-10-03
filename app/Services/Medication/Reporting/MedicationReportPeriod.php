<?php

namespace App\Services\Medication\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class MedicationReportPeriod
{
    public function __construct(public string $from, public string $to, public string $key = 'custom')
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'Pacific/Auckland');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'Pacific/Auckland');
        if ($start->toDateString() !== $from || $end->toDateString() !== $to || $end < $start || $end >= $start->addYear() || $to > CarbonImmutable::now('Pacific/Auckland')->toDateString()) {
            throw ValidationException::withMessages(['date_to' => 'Choose up to 12 months ending today or earlier. No dates have been changed.']);
        }
    }

    public static function fromRequest(Request $request): self
    {
        $values = $request->validate(['period' => ['nullable', Rule::in(['today', 'week', 'month', 'last_month', 'custom'])], 'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from']]);
        $today = CarbonImmutable::now('Pacific/Auckland')->startOfDay();
        $key = $values['period'] ?? (isset($values['date_from']) ? 'custom' : 'week');
        [$from, $to] = match ($key) {
            'today' => [$today, $today],
            'month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'custom' => [CarbonImmutable::parse($values['date_from'] ?? $today->toDateString(), 'Pacific/Auckland'), CarbonImmutable::parse($values['date_to'] ?? $today->toDateString(), 'Pacific/Auckland')],
            default => [$today->subDays(6), $today],
        };

        return new self($from->toDateString(), $to->toDateString(), $key);
    }

    public function bounds(): array
    {
        return [CarbonImmutable::parse($this->from, 'Pacific/Auckland')->startOfDay()->utc(), CarbonImmutable::parse($this->to, 'Pacific/Auckland')->endOfDay()->utc()];
    }
}
