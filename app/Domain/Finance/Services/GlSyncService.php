<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Contracts\AccountingSyncProviderInterface;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinAccountingIntegration;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinGlSyncLog;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\AccountingSyncProviders\MyobSyncProvider;
use App\Domain\Finance\Services\AccountingSyncProviders\XeroSyncProvider;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class GlSyncService
{
    /**
     * Sync chart-of-accounts in the specified direction.
     */
    public function syncAccounts(FinAccountingIntegration $integration, string $direction): FinGlSyncLog
    {
        $this->validateDirection($direction, $integration);
        $provider = $this->getProvider($integration->provider);

        $log = $this->createSyncLog($integration, $direction, 'account');

        try {
            if ($direction === 'push') {
                $accounts = FinAccount::forOrganization($integration->organization_id)
                    ->active()
                    ->get();

                $result = $provider->pushAccounts($integration, $accounts);

                $log = $this->completeSyncLog($log, $accounts->count(), $result['success'], $result['errors']);
            } else {
                $result = $provider->pullAccounts($integration);
                $pulledAccounts = $result['accounts'] ?? [];

                $this->upsertPulledAccounts($integration, $pulledAccounts);

                $log = $this->completeSyncLog($log, count($pulledAccounts), count($pulledAccounts), $result['errors'] ?? []);
            }

            $this->updateIntegrationStatus($integration, 'success');
        } catch (\Throwable $e) {
            $log = $this->failSyncLog($log, $e->getMessage());
            $this->updateIntegrationStatus($integration, 'failed', $e->getMessage());

            Log::error('GL sync accounts failed', [
                'integration_id' => $integration->id,
                'direction' => $direction,
                'error' => $e->getMessage(),
            ]);
        }

        return $log;
    }

    /**
     * Sync journals in the specified direction.
     */
    public function syncJournals(FinAccountingIntegration $integration, string $direction): FinGlSyncLog
    {
        $this->validateDirection($direction, $integration);
        $provider = $this->getProvider($integration->provider);

        $log = $this->createSyncLog($integration, $direction, 'journal');

        try {
            if ($direction === 'push') {
                $externalIdColumn = $integration->provider === 'xero' ? 'xero_journal_id' : 'myob_journal_id';

                $journals = FinJournal::forOrganization($integration->organization_id)
                    ->posted()
                    ->whereNull($externalIdColumn)
                    ->with('lines')
                    ->get();

                $result = $provider->pushJournals($integration, $journals);

                $log = $this->completeSyncLog($log, $journals->count(), $result['success'], $result['errors']);
            } else {
                $since = $integration->last_sync_at
                    ? $integration->last_sync_at->toDateString()
                    : now()->subMonths(3)->toDateString();

                $result = $provider->pullJournals($integration, $since);
                $pulledJournals = $result['journals'] ?? [];

                $log = $this->completeSyncLog($log, count($pulledJournals), count($pulledJournals), $result['errors'] ?? []);
            }

            $this->updateIntegrationStatus($integration, 'success');
        } catch (\Throwable $e) {
            $log = $this->failSyncLog($log, $e->getMessage());
            $this->updateIntegrationStatus($integration, 'failed', $e->getMessage());

            Log::error('GL sync journals failed', [
                'integration_id' => $integration->id,
                'direction' => $direction,
                'error' => $e->getMessage(),
            ]);
        }

        return $log;
    }

    /**
     * Sync invoices/bills in the specified direction.
     */
    public function syncInvoices(FinAccountingIntegration $integration, string $direction): FinGlSyncLog
    {
        $this->validateDirection($direction, $integration);
        $provider = $this->getProvider($integration->provider);

        $log = $this->createSyncLog($integration, $direction, 'invoice');

        try {
            if ($direction === 'push') {
                $externalIdColumn = $integration->provider === 'xero' ? 'xero_invoice_id' : 'myob_invoice_id';

                $bills = FinBill::forOrganization($integration->organization_id)
                    ->whereIn('status', ['approved', 'paid', 'partial'])
                    ->whereNull($externalIdColumn)
                    ->with(['vendor', 'lines'])
                    ->get();

                $result = $provider->pushInvoices($integration, $bills);

                $log = $this->completeSyncLog($log, $bills->count(), $result['success'], $result['errors']);
            } else {
                $since = $integration->last_sync_at
                    ? $integration->last_sync_at->toDateString()
                    : now()->subMonths(3)->toDateString();

                $result = $provider->pullInvoices($integration, $since);
                $pulledInvoices = $result['invoices'] ?? [];

                $log = $this->completeSyncLog($log, count($pulledInvoices), count($pulledInvoices), $result['errors'] ?? []);
            }

            $this->updateIntegrationStatus($integration, 'success');
        } catch (\Throwable $e) {
            $log = $this->failSyncLog($log, $e->getMessage());
            $this->updateIntegrationStatus($integration, 'failed', $e->getMessage());

            Log::error('GL sync invoices failed', [
                'integration_id' => $integration->id,
                'direction' => $direction,
                'error' => $e->getMessage(),
            ]);
        }

        return $log;
    }

    /**
     * Sync contacts/vendors in the specified direction.
     */
    public function syncContacts(FinAccountingIntegration $integration, string $direction): FinGlSyncLog
    {
        $this->validateDirection($direction, $integration);
        $provider = $this->getProvider($integration->provider);

        $log = $this->createSyncLog($integration, $direction, 'contact');

        try {
            if ($direction === 'push') {
                $externalIdColumn = $integration->provider === 'xero' ? 'xero_contact_id' : 'myob_contact_id';

                $vendors = FinVendor::forOrganization($integration->organization_id)
                    ->active()
                    ->whereNull($externalIdColumn)
                    ->get();

                $result = $provider->pushContacts($integration, $vendors);

                $log = $this->completeSyncLog($log, $vendors->count(), $result['success'], $result['errors']);
            } else {
                $result = $provider->pullContacts($integration);
                $pulledContacts = $result['contacts'] ?? [];

                $log = $this->completeSyncLog($log, count($pulledContacts), count($pulledContacts), $result['errors'] ?? []);
            }

            $this->updateIntegrationStatus($integration, 'success');
        } catch (\Throwable $e) {
            $log = $this->failSyncLog($log, $e->getMessage());
            $this->updateIntegrationStatus($integration, 'failed', $e->getMessage());

            Log::error('GL sync contacts failed', [
                'integration_id' => $integration->id,
                'direction' => $direction,
                'error' => $e->getMessage(),
            ]);
        }

        return $log;
    }

    /**
     * Run a full sync for all entity types based on the integration's sync direction.
     *
     * @return array<string, FinGlSyncLog>
     */
    public function fullSync(FinAccountingIntegration $integration): array
    {
        $logs = [];
        $directions = $this->getDirectionsForIntegration($integration);

        Log::info('Starting full GL sync', [
            'integration_id' => $integration->id,
            'provider' => $integration->provider,
            'directions' => $directions,
        ]);

        // Sync order: contacts first (dependencies), then accounts, journals, invoices
        foreach ($directions as $direction) {
            $logs["contacts_{$direction}"] = $this->syncContacts($integration, $direction);
            $logs["accounts_{$direction}"] = $this->syncAccounts($integration, $direction);
            $logs["journals_{$direction}"] = $this->syncJournals($integration, $direction);
            $logs["invoices_{$direction}"] = $this->syncInvoices($integration, $direction);
        }

        Log::info('Full GL sync completed', [
            'integration_id' => $integration->id,
            'log_count' => count($logs),
        ]);

        return $logs;
    }

    /**
     * Resolve the appropriate provider implementation for the given provider name.
     */
    public function getProvider(string $provider): AccountingSyncProviderInterface
    {
        return match ($provider) {
            'xero' => app(XeroSyncProvider::class),
            'myob' => app(MyobSyncProvider::class),
            default => throw new InvalidArgumentException("Unsupported accounting provider: {$provider}"),
        };
    }

    // ── Private Helpers ──────────────────────────────────────────────────

    private function validateDirection(string $direction, FinAccountingIntegration $integration): void
    {
        if (! in_array($direction, ['push', 'pull'])) {
            throw new InvalidArgumentException("Invalid sync direction: {$direction}. Must be 'push' or 'pull'.");
        }

        $syncDirection = $integration->sync_direction;

        if ($syncDirection === 'push' && $direction === 'pull') {
            throw new InvalidArgumentException('This integration is configured for push-only sync.');
        }

        if ($syncDirection === 'pull' && $direction === 'push') {
            throw new InvalidArgumentException('This integration is configured for pull-only sync.');
        }
    }

    /**
     * Get the sync directions that should be executed for the integration.
     *
     * @return array<int, string>
     */
    private function getDirectionsForIntegration(FinAccountingIntegration $integration): array
    {
        return match ($integration->sync_direction) {
            'push' => ['push'],
            'pull' => ['pull'],
            'bidirectional' => ['push', 'pull'],
            default => ['push'],
        };
    }

    private function createSyncLog(FinAccountingIntegration $integration, string $direction, string $entityType): FinGlSyncLog
    {
        return FinGlSyncLog::create([
            'integration_id' => $integration->id,
            'direction' => $direction,
            'entity_type' => $entityType,
            'entity_count' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'started_at' => now(),
        ]);
    }

    private function completeSyncLog(FinGlSyncLog $log, int $entityCount, int $successCount, array $errors): FinGlSyncLog
    {
        $startedAt = $log->started_at;
        $completedAt = now();
        $durationMs = $startedAt ? (int) round($startedAt->diffInMilliseconds($completedAt)) : null;

        $log->update([
            'entity_count' => $entityCount,
            'success_count' => $successCount,
            'error_count' => count($errors),
            'errors' => count($errors) > 0 ? $errors : null,
            'completed_at' => $completedAt,
            'duration_ms' => $durationMs,
        ]);

        return $log->refresh();
    }

    private function failSyncLog(FinGlSyncLog $log, string $errorMessage): FinGlSyncLog
    {
        $startedAt = $log->started_at;
        $completedAt = now();
        $durationMs = $startedAt ? (int) round($startedAt->diffInMilliseconds($completedAt)) : null;

        $log->update([
            'error_count' => 1,
            'errors' => [['message' => $errorMessage]],
            'completed_at' => $completedAt,
            'duration_ms' => $durationMs,
        ]);

        return $log->refresh();
    }

    private function updateIntegrationStatus(FinAccountingIntegration $integration, string $status, ?string $error = null): void
    {
        $integration->update([
            'last_sync_at' => now(),
            'last_sync_status' => $status,
            'last_error' => $error,
        ]);
    }

    /**
     * Upsert accounts pulled from the external system into local fin_accounts.
     *
     * @param  array<int, array{external_id: string, code: string, name: string, type: string}>  $pulledAccounts
     */
    private function upsertPulledAccounts(FinAccountingIntegration $integration, array $pulledAccounts): void
    {
        $externalIdColumn = $integration->provider === 'xero' ? 'xero_account_id' : 'myob_account_id';

        foreach ($pulledAccounts as $external) {
            $existing = FinAccount::where('organization_id', $integration->organization_id)
                ->where($externalIdColumn, $external['external_id'])
                ->first();

            if ($existing) {
                $existing->update([
                    'name' => $external['name'],
                    'code' => $external['code'],
                ]);
            } else {
                // Check if there's a matching account by code
                $byCode = FinAccount::where('organization_id', $integration->organization_id)
                    ->where('code', $external['code'])
                    ->first();

                if ($byCode) {
                    $byCode->update([$externalIdColumn => $external['external_id']]);
                }
                // New accounts from external systems are not auto-created;
                // they appear in the mapping UI for manual linking.
            }
        }
    }
}
