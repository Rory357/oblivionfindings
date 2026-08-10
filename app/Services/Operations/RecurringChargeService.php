<?php

namespace App\Services\Operations;

use App\Models\BillingEntry;
use App\Models\RecurringCharge;
use Carbon\Carbon;

class RecurringChargeService
{
    public function processDueCharges(): int
    {
        $today = now()->toDateString();

        // Columns are is_active / next_charge_at (the service previously queried
        // non-existent active / next_charge_date, so it matched zero rows). Skip
        // charges whose end date has passed.
        $charges = RecurringCharge::where('is_active', true)
            ->whereHas('client', fn ($clientQuery) => $clientQuery
                ->whereNotNull('site_id')
                ->whereHas('site', fn ($siteQuery) => $siteQuery
                    ->active()
                    ->notArchived()
                    ->whereNull('archived_at')))
            ->where(function ($query): void {
                $query->whereNull('service_agreement_id')
                    ->orWhereHas('serviceAgreement', fn ($agreementQuery) => $agreementQuery
                        ->whereColumn('service_agreements.client_id', 'recurring_charges.client_id'));
            })
            ->whereDate('next_charge_at', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today);
            })
            ->with('client:id,site_id')
            ->get();

        $processed = 0;

        foreach ($charges as $charge) {
            $chargeDate = $charge->next_charge_at->toDateString();

            BillingEntry::create([
                'client_id' => $charge->client_id,
                'site_id' => $charge->client->site_id,
                'service_agreement_id' => $charge->service_agreement_id,
                // Automated charge — no delivering staff member (column now nullable).
                'staff_id' => $charge->created_by,
                'service_date' => $chargeDate,
                'hours' => 0,
                'rate' => $charge->amount,
                'amount' => $charge->amount,
                'rate_type' => 'recurring',
                'status' => 'pending',
                'notes' => $charge->description ?? $charge->name,
            ]);

            // Advance the schedule; deactivate once the next run would pass the end date.
            $nextDate = $this->calculateNextDate($chargeDate, $charge->frequency);
            $stop = $charge->ends_at && $nextDate > $charge->ends_at->toDateString();

            $charge->update([
                'last_charged_at' => $chargeDate,
                'next_charge_at' => $nextDate,
                'is_active' => $stop ? false : $charge->is_active,
            ]);

            $processed++;
        }

        return $processed;
    }

    protected function calculateNextDate(string $currentDate, string $frequency): string
    {
        $date = Carbon::parse($currentDate);

        return match ($frequency) {
            'daily' => $date->addDay()->toDateString(),
            'weekly' => $date->addWeek()->toDateString(),
            'fortnightly' => $date->addWeeks(2)->toDateString(),
            'monthly' => $date->addMonth()->toDateString(),
            'quarterly' => $date->addMonths(3)->toDateString(),
            'annually' => $date->addYear()->toDateString(),
            default => $date->addMonth()->toDateString(),
        };
    }
}
