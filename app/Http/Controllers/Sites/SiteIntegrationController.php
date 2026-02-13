<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Integration\IntegrationTenantSecret;
use App\Services\Integration\IntegrationAdapterRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class SiteIntegrationController extends Controller
{
    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $configs = IntegrationSiteConfig::where('site_id', $site->id)->get();

        $siteSecrets = IntegrationSiteSecret::where('site_id', $site->id)
            ->select(['id', 'tenant_id', 'site_id', 'provider', 'capability', 'base_url', 'is_enabled', 'last_tested_at', 'last_error'])
            ->get();

        $tenantSecrets = IntegrationTenantSecret::where('tenant_id', $request->user()->tenant_id)
            ->select(['id', 'tenant_id', 'provider', 'secret_last4', 'status', 'last_tested_at', 'last_synced_at', 'last_error'])
            ->get();

        return response()->json([
            'configs' => $configs,
            'siteSecrets' => $siteSecrets,
            'tenantSecrets' => $tenantSecrets,
        ]);
    }

    public function configure(Request $request, Site $site, string $provider)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'mapped_external_site_id' => 'nullable|string|max:255',
            'mapped_external_site_name' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        IntegrationSiteConfig::updateOrCreate(
            [
                'site_id' => $site->id,
                'provider' => $provider,
            ],
            [
                'tenant_id' => $request->user()->tenant_id,
                'mapped_external_site_id' => $validated['mapped_external_site_id'] ?? null,
                'mapped_external_site_name' => $validated['mapped_external_site_name'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]
        );

        return redirect()->back()->with('success', 'Integration configured successfully.');
    }

    public function testConnection(Request $request, Site $site, string $provider, IntegrationAdapterRegistry $registry)
    {
        $this->authorize('view', $site);

        $tenantSecret = IntegrationTenantSecret::where('tenant_id', $request->user()->tenant_id)
            ->where('provider', $provider)
            ->first();

        if (!$tenantSecret) {
            return redirect()->back()->with('error', 'No tenant credentials found for this integration.');
        }

        if (!$registry->has($provider)) {
            return redirect()->back()->with('error', 'No adapter registered for this integration provider.');
        }

        $isConnected = $registry->resolve($provider)->testConnection($tenantSecret);

        $tenantSecret->update([
            'status' => $isConnected
                ? IntegrationTenantSecret::STATUS_CONNECTED
                : IntegrationTenantSecret::STATUS_ERROR,
            'last_tested_at' => now(),
            'last_error' => $isConnected ? null : 'Connection test failed.',
        ]);

        if ($isConnected) {
            return redirect()->back()->with('success', 'Connection test passed.');
        }

        return redirect()->back()->with('error', 'Connection test failed.');
    }

    public function syncDevices(Request $request, Site $site, string $provider, IntegrationAdapterRegistry $registry)
    {
        $this->authorize('update', $site);

        if (!$registry->has($provider)) {
            return redirect()->back()->with('error', 'No adapter registered for this integration provider.');
        }

        $tenantId = $request->user()->tenant_id;

        $siteConfig = IntegrationSiteConfig::query()
            ->forTenant($tenantId)
            ->forProvider($provider)
            ->where('site_id', $site->id)
            ->active()
            ->first();

        if (!$siteConfig || empty($siteConfig->mapped_external_site_id)) {
            return redirect()->back()->with('error', 'Integration mapping is missing for this location.');
        }

        $tenantSecret = IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->where('provider', $provider)
            ->connected()
            ->first();

        if (!$tenantSecret) {
            return redirect()->back()->with('error', 'Tenant credentials are not connected for this integration.');
        }

        $syncLog = IntegrationSyncLog::create([
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'site_id' => $site->id,
            'action' => 'sync_devices',
            'status' => IntegrationSyncLog::STATUS_STARTED,
            'started_at' => now(),
        ]);

        try {
            $result = $registry->resolve($provider)->syncDevices($siteConfig, $tenantSecret);

            $syncLog->update([
                'items_processed' => $result->processed,
                'items_created' => $result->created,
                'items_updated' => $result->updated,
                'items_errored' => $result->errored,
            ]);

            if ($result->isSuccess()) {
                $syncLog->markCompleted(IntegrationSyncLog::STATUS_SUCCESS);
            } elseif ($result->isPartial()) {
                $syncLog->markCompleted(IntegrationSyncLog::STATUS_PARTIAL);
            } else {
                $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, $result->error);
            }

            $tenantSecret->update([
                'last_synced_at' => now(),
                'last_error' => $result->error,
            ]);

            if (!$result->isSuccess() && !$result->isPartial()) {
                return redirect()->back()->with('error', $result->error ?? 'Device sync failed.');
            }

            return redirect()->back()->with(
                'success',
                "Device sync complete. Processed {$result->processed}, created {$result->created}, updated {$result->updated}, errored {$result->errored}."
            );
        } catch (\Throwable $e) {
            $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, $e->getMessage());
            return redirect()->back()->with('error', 'Device sync failed: ' . $e->getMessage());
        }
    }

    public function updateSecret(Request $request, Site $site, string $provider, string $capability)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'base_url' => 'nullable|string|max:500',
            'secret' => 'required|string',
            'is_enabled' => 'boolean',
        ]);

        IntegrationSiteSecret::updateOrCreate(
            [
                'site_id' => $site->id,
                'provider' => $provider,
                'capability' => $capability,
            ],
            [
                'tenant_id' => $request->user()->tenant_id,
                'base_url' => $validated['base_url'] ?? null,
                'secret_encrypted' => Crypt::encryptString($validated['secret']),
                'is_enabled' => $validated['is_enabled'] ?? true,
            ]
        );

        return redirect()->back()->with('success', 'Site credential saved successfully.');
    }

    public function updateOverrides(Request $request, Site $site, string $provider)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'overrides' => 'nullable|array',
        ]);

        $config = IntegrationSiteConfig::where('site_id', $site->id)
            ->where('provider', $provider)
            ->firstOrFail();

        $config->update([
            'overrides' => $validated['overrides'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Integration overrides updated successfully.');
    }
}
