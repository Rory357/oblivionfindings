<?php

namespace App\Services\Operations;

use App\Models\BillingEntry;
use App\Models\RecurringCharge;
use Carbon\Carbon;

class RecurringChargeService
{
    public function processDueCharges(int $organizationId): int
    {
        $charges = RecurringCharge::where('organization_id', $organizationId)
            ->where('active', true)
            ->where('next_charge_date', '<=', now()->toDateString())
            ->get();

        $processed = 0;

        foreach ($charges as $charge) {
            BillingEntry::create([
                'organization_id' => $charge->organization_id,
                'client_id' => $charge->client_id,
                'service_agreement_id' => $charge->service_agreement_id,
                'service_date' => $charge->next_charge_date,
                'hours' => 0,
                'rate' => $charge->amount,
                'amount' => $charge->amount,
                'rate_type' => 'recurring',
                'status' => 'pending',
                'notes' => $charge->description,
            ]);

            // Advance next charge date
            $nextDate = $this->calculateNextDate($charge->next_charge_date, $charge->frequency);
            $charge->update(['next_charge_date' => $nextDate]);

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
