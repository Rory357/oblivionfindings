<?php

namespace App\Console\Commands;

use App\Models\ClientMedicationStock;
use App\Models\MedicationDashboardAlert;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckMedicationStock extends Command
{
    protected $signature = 'emar:check-medication-stock';

    protected $description = 'Check medication stock levels and expiry dates, creating alerts as needed';

    public function handle(): int
    {
        $this->info('Checking medication stock levels and expiry dates...');

        $alertsCreated = 0;

        // Check for stocks expiring within 30 days (but not yet expired)
        $expiringSoon = ClientMedicationStock::expiringSoon()
            ->with(['medication' => fn ($q) => $q->with('client:id,first_name,last_name')])
            ->get();

        foreach ($expiringSoon as $stock) {
            if ($this->hasRecentAlert($stock, 'expiring_soon')) {
                continue;
            }

            $daysUntilExpiry = Carbon::today()->diffInDays($stock->expiry_date);
            $medicationName = $stock->medication?->name ?? 'Unknown';
            $clientName = $stock->medication?->client
                ? $stock->medication->client->first_name . ' ' . $stock->medication->client->last_name
                : 'Unknown';

            MedicationDashboardAlert::createOrUpdateAlert(
                clientId: $stock->medication?->client_id ?? 0,
                alertType: 'expiring_soon',
                severity: $daysUntilExpiry <= 7 ? 'critical' : 'warning',
                message: "{$medicationName} for {$clientName} expires in {$daysUntilExpiry} days (batch: {$stock->batch_number}).",
                medicationId: $stock->client_medication_id,
            );

            $stock->update(['last_reorder_alert_at' => now()]);
            $alertsCreated++;
            $this->info("  Expiring soon: {$medicationName} ({$daysUntilExpiry} days)");
        }

        // Check for expired stocks
        $expired = ClientMedicationStock::expired()
            ->with(['medication' => fn ($q) => $q->with('client:id,first_name,last_name')])
            ->get();

        foreach ($expired as $stock) {
            if ($this->hasRecentAlert($stock, 'expired')) {
                continue;
            }

            $medicationName = $stock->medication?->name ?? 'Unknown';
            $clientName = $stock->medication?->client
                ? $stock->medication->client->first_name . ' ' . $stock->medication->client->last_name
                : 'Unknown';

            MedicationDashboardAlert::createOrUpdateAlert(
                clientId: $stock->medication?->client_id ?? 0,
                alertType: 'expired',
                severity: 'critical',
                message: "{$medicationName} for {$clientName} has EXPIRED (expiry: {$stock->expiry_date->format('d/m/Y')}, batch: {$stock->batch_number}).",
                medicationId: $stock->client_medication_id,
            );

            $stock->update(['last_reorder_alert_at' => now()]);
            $alertsCreated++;
            $this->info("  Expired: {$medicationName}");
        }

        // Check for low stock
        $lowStock = ClientMedicationStock::lowStock()
            ->with(['medication' => fn ($q) => $q->with('client:id,first_name,last_name')])
            ->get();

        foreach ($lowStock as $stock) {
            if ($this->hasRecentAlert($stock, 'stock_low')) {
                continue;
            }

            $medicationName = $stock->medication?->name ?? 'Unknown';
            $clientName = $stock->medication?->client
                ? $stock->medication->client->first_name . ' ' . $stock->medication->client->last_name
                : 'Unknown';

            $suggestedQty = $stock->reorder_quantity
                ? " Suggested reorder: {$stock->reorder_quantity} {$stock->unit}."
                : '';

            MedicationDashboardAlert::createOrUpdateAlert(
                clientId: $stock->medication?->client_id ?? 0,
                alertType: 'stock_low',
                severity: $stock->on_hand <= 0 ? 'critical' : 'warning',
                message: "{$medicationName} for {$clientName} is low ({$stock->on_hand} {$stock->unit} remaining, reorder level: {$stock->reorder_level}).{$suggestedQty}",
                medicationId: $stock->client_medication_id,
            );

            $stock->update(['last_reorder_alert_at' => now()]);
            $alertsCreated++;
            $this->info("  Low stock: {$medicationName} ({$stock->on_hand} {$stock->unit})");
        }

        $this->info("Stock check complete. {$alertsCreated} new alerts created.");

        return self::SUCCESS;
    }

    /**
     * Check if an alert of the given type already exists for this stock's medication in the last 24 hours.
     */
    private function hasRecentAlert(ClientMedicationStock $stock, string $alertType): bool
    {
        return MedicationDashboardAlert::where('client_medication_id', $stock->client_medication_id)
            ->where('alert_type', $alertType)
            ->where('status', 'active')
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }
}
