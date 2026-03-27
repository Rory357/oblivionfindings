<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Jobs\SyncAccountingIntegrationJob;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinAccountingIntegration;
use App\Domain\Finance\Services\GlSyncService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AccountingIntegrationController extends Controller
{
    public function __construct(
        private readonly GlSyncService $syncService,
    ) {}

    /**
     * List all accounting integrations for the organisation.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $integrations = FinAccountingIntegration::forOrganization($orgId)
            ->with('createdBy:id,name')
            ->withCount('syncLogs')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (FinAccountingIntegration $integration) => [
                'id' => $integration->id,
                'provider' => $integration->provider,
                'tenant_id' => $integration->tenant_id,
                'sync_direction' => $integration->sync_direction,
                'is_active' => $integration->is_active,
                'last_sync_at' => $integration->last_sync_at?->toDateTimeString(),
                'last_sync_status' => $integration->last_sync_status,
                'last_error' => $integration->last_error,
                'has_token' => (bool) $integration->access_token,
                'token_expired' => $integration->isTokenExpired(),
                'sync_logs_count' => $integration->sync_logs_count,
                'created_by' => $integration->createdBy?->name,
                'created_at' => $integration->created_at->toDateTimeString(),
                'settings' => $integration->settings ?? [],
                'recent_logs' => $integration->syncLogs()
                    ->orderByDesc('started_at')
                    ->limit(5)
                    ->get()
                    ->map(fn ($log) => [
                        'id' => $log->id,
                        'direction' => $log->direction,
                        'entity_type' => $log->entity_type,
                        'entity_count' => $log->entity_count,
                        'success_count' => $log->success_count,
                        'error_count' => $log->error_count,
                        'started_at' => $log->started_at->toDateTimeString(),
                        'completed_at' => $log->completed_at?->toDateTimeString(),
                        'duration_ms' => $log->duration_ms,
                    ]),
            ]);

        return Inertia::render('finance/Integrations/Index', [
            'integrations' => $integrations,
        ]);
    }

    /**
     * Create a new accounting integration.
     */
    public function store(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $validated = $request->validate([
            'provider' => [
                'required',
                Rule::in(['xero', 'myob']),
                Rule::unique('fin_accounting_integrations')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
            'tenant_id' => 'nullable|string|max:255',
            'sync_direction' => ['required', Rule::in(['push', 'pull', 'bidirectional'])],
            'settings' => 'nullable|array',
        ]);

        $integration = FinAccountingIntegration::create([
            'organization_id' => $orgId,
            'provider' => $validated['provider'],
            'tenant_id' => $validated['tenant_id'] ?? null,
            'sync_direction' => $validated['sync_direction'],
            'settings' => $validated['settings'] ?? null,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('finance.integrations.index')
            ->with('success', ucfirst($integration->provider) . ' integration created successfully.');
    }

    /**
     * Update an existing integration's settings.
     */
    public function update(Request $request, FinAccountingIntegration $integration)
    {
        $this->authorizeOrganization($request, $integration);

        $validated = $request->validate([
            'tenant_id' => 'nullable|string|max:255',
            'sync_direction' => ['required', Rule::in(['push', 'pull', 'bidirectional'])],
            'is_active' => 'boolean',
            'settings' => 'nullable|array',
        ]);

        $integration->update($validated);

        return redirect()->route('finance.integrations.index')
            ->with('success', ucfirst($integration->provider) . ' integration updated successfully.');
    }

    /**
     * Trigger a manual sync for the integration.
     */
    public function sync(Request $request, FinAccountingIntegration $integration)
    {
        $this->authorizeOrganization($request, $integration);

        if (! $integration->is_active) {
            return back()->withErrors(['integration' => 'Cannot sync an inactive integration.']);
        }

        SyncAccountingIntegrationJob::dispatch($integration->id);

        return redirect()->route('finance.integrations.index')
            ->with('success', ucfirst($integration->provider) . ' sync has been queued.');
    }

    /**
     * Test the connection to the external accounting system.
     */
    public function testConnection(Request $request, FinAccountingIntegration $integration)
    {
        $this->authorizeOrganization($request, $integration);

        try {
            $provider = $this->syncService->getProvider($integration->provider);
            $connected = $provider->testConnection($integration);

            if ($connected) {
                return back()->with('success', ucfirst($integration->provider) . ' connection test successful.');
            }

            return back()->withErrors(['connection' => ucfirst($integration->provider) . ' connection test failed. Please check your credentials.']);
        } catch (\Throwable $e) {
            return back()->withErrors(['connection' => 'Connection test failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Soft-delete (disconnect) an integration.
     */
    public function destroy(Request $request, FinAccountingIntegration $integration)
    {
        $this->authorizeOrganization($request, $integration);

        $provider = $integration->provider;
        $integration->delete();

        return redirect()->route('finance.integrations.index')
            ->with('success', ucfirst($provider) . ' integration disconnected successfully.');
    }

    /**
     * Show the account mapping page for an integration.
     */
    public function mapping(Request $request, FinAccountingIntegration $integration)
    {
        $this->authorizeOrganization($request, $integration);

        $orgId = $request->user()->organization_id;

        $localAccounts = FinAccount::forOrganization($orgId)
            ->active()
            ->orderBy('code')
            ->get()
            ->map(fn (FinAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'sub_type' => $account->sub_type,
                'external_id' => $integration->provider === 'xero'
                    ? $account->xero_account_id
                    : $account->myob_account_id,
            ]);

        return Inertia::render('finance/Integrations/Mapping', [
            'integration' => [
                'id' => $integration->id,
                'provider' => $integration->provider,
                'tenant_id' => $integration->tenant_id,
                'account_mapping' => $integration->account_mapping ?? [],
                'tax_mapping' => $integration->tax_mapping ?? [],
            ],
            'localAccounts' => $localAccounts,
        ]);
    }

    /**
     * Update the account/tax mapping for an integration.
     */
    public function updateMapping(Request $request, FinAccountingIntegration $integration)
    {
        $this->authorizeOrganization($request, $integration);

        $validated = $request->validate([
            'account_mapping' => 'nullable|array',
            'account_mapping.*' => 'nullable|string|max:255',
            'tax_mapping' => 'nullable|array',
            'tax_mapping.*' => 'nullable|string|max:255',
        ]);

        $integration->update([
            'account_mapping' => $validated['account_mapping'] ?? null,
            'tax_mapping' => $validated['tax_mapping'] ?? null,
        ]);

        return redirect()->route('finance.integrations.mapping', $integration)
            ->with('success', 'Account mapping updated successfully.');
    }

    // ── Private Helpers ──────────────────────────────────────────────────

    private function authorizeOrganization(Request $request, FinAccountingIntegration $integration): void
    {
        if ($integration->organization_id !== $request->user()->organization_id) {
            abort(403, 'You do not have access to this integration.');
        }
    }
}
