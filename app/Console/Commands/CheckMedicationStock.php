<?php

namespace App\Console\Commands;

use App\Models\ClientMedicationStock;
use App\Models\MedicationDashboardAlert;
use App\Services\Medication\Alerts\MedicationAlertSources;
use App\Services\Medication\MedicationSignalService;
use App\Support\Medication\MedicationStockQuantity;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckMedicationStock extends Command
{
    protected $signature = 'emar:check-medication-stock';

    protected $description = 'Check medication stock levels and expiry dates, creating alerts as needed';

    public function handle(MedicationSignalService $signalService, MedicationAlertSources $alerts): int
    {
        $this->info('Checking medication stock levels and expiry dates...');

        $alertsCreated = 0;

        // Check for stocks expiring within 30 days (but not yet expired)
        $expiringSoon = ClientMedicationStock::expiringSoon()
            ->with(['medication' => fn ($q) => $q->with('client:id,first_name,last_name,site_id')])
            ->get();

        foreach ($expiringSoon as $stock) {
            if ($this->hasRecentAlert($stock, 'expiring_soon')) {
                continue;
            }

            $expiry = $stock->packExpiries(false)->sortBy('date')->first();
            if (! $expiry) {
                continue;
            }
            $daysUntilExpiry = (int) Carbon::today(config('app.worker_timezone', 'Pacific/Auckland'))->diffInDays($expiry['date']);
            $medicationName = $stock->medication?->name ?? 'Unknown';
            $clientName = $stock->medication?->client
                ? $stock->medication->client->first_name.' '.$stock->medication->client->last_name
                : 'Unknown';

            // Dashboard alert only — expiring soon is NOT operational
            MedicationDashboardAlert::createOrUpdateAlert(
                clientId: $stock->medication?->client_id ?? 0,
                alertType: 'expiring_soon',
                severity: $daysUntilExpiry <= 7 ? 'critical' : 'warning',
                message: "{$medicationName} for {$clientName} expires in {$daysUntilExpiry} days (batch: {$expiry['batch']}).",
                medicationId: $stock->client_medication_id,
            );

            $alertsCreated++;
            $this->info("  Expiring soon: {$medicationName} ({$daysUntilExpiry} days)");
        }

        // Check for expired stocks — OPERATIONAL
        $expired = ClientMedicationStock::expired()
            ->with(['medication' => fn ($q) => $q->with('client:id,first_name,last_name,site_id')])
            ->get();

        foreach ($expired as $stock) {
            if ($this->hasRecentAlert($stock, 'expired')) {
                continue;
            }

            $medicationName = $stock->medication?->name ?? 'Unknown';
            $publicMedicationName = $stock->medication?->unrestrictedName() ?? 'Unknown';
            $clientName = $stock->medication?->client
                ? $stock->medication->client->first_name.' '.$stock->medication->client->last_name
                : 'Unknown';

            $expiry = $stock->packExpiries(true)->sortBy('date')->first();
            if (! $expiry) {
                continue;
            }
            $expiryLabel = $expiry['date']->format('d/m/Y');

            // Dashboard alert (UI compat)
            MedicationDashboardAlert::createOrUpdateAlert(
                clientId: $stock->medication?->client_id ?? 0,
                alertType: 'expired',
                severity: 'critical',
                message: "{$medicationName} for {$clientName} has EXPIRED (expiry: {$expiryLabel}, batch: {$expiry['batch']}).",
                medicationId: $stock->client_medication_id,
            );

            // Operational signal → Control Room
            if ($stock->medication?->client_id) {
                $signalService->emit(
                    MedicationSignalService::TYPE_EXPIRED,
                    $stock->medication->client_id,
                    'high',
                    "{$publicMedicationName} for {$clientName} has EXPIRED",
                    [
                        'client_medication_id' => $stock->client_medication_id,
                        'medication_name' => $publicMedicationName,
                        'expiry_date' => $expiry['date']->toDateString(),
                        'stock_lot_id' => $expiry['lot_id'],
                        'batch_number' => $expiry['batch'],
                        'site_id' => $stock->medication->client?->site_id,
                    ],
                );
            }

            $alertsCreated++;
            $this->info("  Expired: {$medicationName}");
        }

        // Check for low stock
        $lowStock = ClientMedicationStock::lowStock()
            ->with(['medication' => fn ($q) => $q->with('client:id,first_name,last_name,site_id')])
            ->get();

        foreach ($lowStock as $stock) {
            if ($this->hasRecentAlert($stock, 'stock_low')) {
                continue;
            }

            $medicationName = $stock->medication?->name ?? 'Unknown';
            $publicMedicationName = $stock->medication?->unrestrictedName() ?? 'Unknown';
            $clientName = $stock->medication?->client
                ? $stock->medication->client->first_name.' '.$stock->medication->client->last_name
                : 'Unknown';

            $available = $stock->usableQuantity();
            $suggestedQty = $stock->reorder_quantity
                ? " Suggested reorder: {$stock->reorder_quantity} {$stock->unit}."
                : '';

            // Dashboard alert (UI compat)
            MedicationDashboardAlert::createOrUpdateAlert(
                clientId: $stock->medication?->client_id ?? 0,
                alertType: 'stock_low',
                severity: MedicationStockQuantity::lessThanOrEqual($available ?? 0, 0) ? 'critical' : 'warning',
                message: "{$medicationName} for {$clientName} is low ({$available} {$stock->unit} remaining, reorder level: {$stock->reorder_level}).{$suggestedQty}",
                medicationId: $stock->client_medication_id,
            );

            // OUT OF STOCK → operational signal. Low stock → dashboard only.
            if (MedicationStockQuantity::lessThanOrEqual($available ?? 0, 0) && $stock->medication?->client_id) {
                $signalService->emit(
                    MedicationSignalService::TYPE_STOCK_OUT,
                    $stock->medication->client_id,
                    'high',
                    "{$publicMedicationName} for {$clientName}: OUT OF STOCK",
                    [
                        'client_medication_id' => $stock->client_medication_id,
                        'medication_name' => $publicMedicationName,
                        'site_id' => $stock->medication->client?->site_id,
                    ],
                );
            }

            $alertsCreated++;
            $this->info("  Low stock: {$medicationName} ({$available} {$stock->unit})");
        }

        // Stock expiring, expired and run out: people are told as Medication
        // Settings › Alerts & access says (P11 B2), once per open alert.
        $alerts->stockCheck($expiringSoon, $expired);

        $this->info("Stock check complete. {$alertsCreated} new alerts created.");

        return self::SUCCESS;
    }

    /**
     * Check if an alert of the given type already exists for this stock's medication in the last 24 hours.
     * This is this job's only de-duplication; last_reorder_alert_at belongs to the
     * low-stock notification in emar:send-alerts, so writing it here suppressed that.
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
