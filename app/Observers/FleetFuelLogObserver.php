<?php

namespace App\Observers;

use App\Domain\Finance\Jobs\ProcessFinancialEventJob;
use App\Domain\Finance\Models\FinFinancialEvent;
use App\Models\FleetFuelLog;
use Illuminate\Support\Facades\Log;

class FleetFuelLogObserver
{
    /**
     * Dispatch GL posting job when a fuel log is created.
     *
     * Trigger: FleetFuelLog::created
     * GL Entry: DR 6200 Fuel & Oil / CR 1180 Card Clearing (config
     * finance.event_accounts.fuel_expense.credit). Fuel is paid at the pump on
     * a fuel card — crediting AP here created 2000 balances no payment run
     * could ever settle (there is no bill); Card Clearing instead clears on
     * bank reconciliation.
     */
    public function created(FleetFuelLog $fuelLog): void
    {
        if (! $fuelLog->total_cost || bccomp((string) $fuelLog->total_cost, '0', 2) <= 0) {
            return;
        }

        try {
            $asset = $fuelLog->asset;
            if (! $asset) {
                return;
            }

            $site = $asset->site;
            $orgId = $site?->tenant_id;
            if (! $orgId) {
                return;
            }

            $accountConfig = config('finance.event_accounts.fuel_expense');

            ProcessFinancialEventJob::dispatch([
                'organization_id' => $orgId,
                'source_type' => FleetFuelLog::class,
                'source_id' => $fuelLog->id,
                'event_type' => 'fuel_expense',
                'description' => "Fuel: {$fuelLog->quantity_litres}L @ \${$fuelLog->cost_per_litre}/L — {$asset->name}"
                    . ($fuelLog->station_name ? " ({$fuelLog->station_name})" : ''),
                'amount' => (string) $fuelLog->total_cost,
                'event_date' => ($fuelLog->logged_at ?? $fuelLog->created_at)->toDateString(),
                'debit_account_code' => $accountConfig['debit'],
                'credit_account_code' => $accountConfig['credit'] ?? null,
                'payment_type' => FinFinancialEvent::PAYMENT_AP,
                'journal_type' => $accountConfig['journal_type'],
                'site_id' => $asset->site_id,
                'asset_id' => $asset->id,
                'staff_id' => $fuelLog->user_id,
                'source_updated_at' => $fuelLog->updated_at?->toISOString(),
            ]);
        } catch (\Throwable $e) {
            Log::error("FleetFuelLogObserver: Failed to dispatch GL job for fuel log #{$fuelLog->id}: {$e->getMessage()}");
        }
    }
}
