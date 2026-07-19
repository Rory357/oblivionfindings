<?php

namespace App\Domain\SecurityDevices\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Integration\Integration;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Integration\IntegrationTenantSecret;
use App\Services\Integration\Adapters\QueclinkAdapter;
use App\Services\Integration\IntegrationAdapterRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;

/**
 * Queclink provider configuration (scaffold stage).
 *
 * Credential management and connection testing are fully functional.
 * Site mapping and device sync are deferred to PR C1; this controller
 * ships only the methods needed for the credential-management UI.
 */
class QueclinkController extends Controller
{
    private const PROVIDER = QueclinkAdapter::PROVIDER_SLUG;

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $tenantId = $this->resolveTenantId($user);

        $tenantSecret = IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->where('provider', self::PROVIDER)
            ->first();

        $config = is_array($tenantSecret?->config) ? $tenantSecret->config : [];

        $syncLogs = IntegrationSyncLog::query()
            ->forTenant($tenantId)
            ->forProvider(self::PROVIDER)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (IntegrationSyncLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'status' => $log->status,
                'items_processed' => $log->items_processed,
                'items_created' => $log->items_created,
                'items_updated' => $log->items_updated,
                'items_errored' => $log->items_errored,
                'failure_category' => $log->status === IntegrationSyncLog::STATUS_FAILED ? 'provider_failure' : null,
                'started_at' => $log->started_at?->toDateTimeString(),
                'completed_at' => $log->completed_at?->toDateTimeString(),
            ])
            ->values()
            ->all();

        return Inertia::render('security-devices/integrations/queclink', [
            'tenantSecret' => $tenantSecret ? [
                'status' => $tenantSecret->status,
                'secret_last4' => $tenantSecret->secret_last4,
                'last_tested_at' => $tenantSecret->last_tested_at?->toDateTimeString(),
                'last_synced_at' => $tenantSecret->last_synced_at?->toDateTimeString(),
                'endpoint_configured' => filled($config['base_url'] ?? null),
            ] : null,
            'syncLogs' => $syncLogs,
            'can' => [
                'manage' => $this->userCanManage($user),
            ],
        ]);
    }

    public function saveKey(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $request->validate([
            'api_key' => ['required', 'string'],
            'base_url' => ['nullable', 'string', 'max:255', 'url'],
        ]);

        $tenantId = $this->resolveTenantId($user);
        $baseUrl = trim((string) $request->input('base_url', '')) ?: null;

        IntegrationTenantSecret::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'provider' => self::PROVIDER,
            ],
            [
                'secret_encrypted' => Crypt::encryptString($request->string('api_key')->toString()),
                'secret_last4' => substr($request->string('api_key')->toString(), -4),
                'status' => IntegrationTenantSecret::STATUS_DISCONNECTED,
                'last_error' => null,
                'config' => $baseUrl ? ['base_url' => $baseUrl] : [],
                'created_by' => $user->id,
            ],
        );

        Integration::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'provider' => self::PROVIDER,
            ],
            [
                'display_name' => 'Queclink',
                'status' => Integration::STATUS_INACTIVE,
                'last_error' => null,
            ],
        );

        return redirect()->back()->with('success', 'Queclink API key saved. Run Test Connection to verify.');
    }

    public function testKey(Request $request, IntegrationAdapterRegistry $registry)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $tenantId = $this->resolveTenantId($user);

        $secret = IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->where('provider', self::PROVIDER)
            ->firstOrFail();

        if (! $registry->has(self::PROVIDER)) {
            return redirect()->back()->with('error', 'Queclink adapter is not registered.');
        }

        $adapter = $registry->resolve(self::PROVIDER);
        $ok = $adapter->testConnection($secret);

        if ($ok) {
            $secret->update([
                'status' => IntegrationTenantSecret::STATUS_CONNECTED,
                'last_tested_at' => now(),
                'last_error' => null,
            ]);

            Integration::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'provider' => self::PROVIDER,
                ],
                [
                    'display_name' => 'Queclink',
                    'status' => Integration::STATUS_ACTIVE,
                    'last_tested_at' => now(),
                    'last_error' => null,
                ],
            );

            return redirect()->back()->with('success', 'Queclink connection test succeeded.');
        }

        $secret->update([
            'status' => IntegrationTenantSecret::STATUS_ERROR,
            'last_tested_at' => now(),
            'last_error' => 'Queclink rejected the key or the server was unreachable.',
        ]);

        Integration::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'provider' => self::PROVIDER,
            ],
            [
                'display_name' => 'Queclink',
                'status' => Integration::STATUS_ERROR,
                'last_tested_at' => now(),
                'last_error' => 'Queclink rejected the key or the server was unreachable.',
            ],
        );

        return redirect()->back()->with('error', 'Queclink connection test failed. Check the API key and server URL.');
    }

    public function rotateKey(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $request->validate([
            'api_key' => ['required', 'string'],
        ]);

        $tenantId = $this->resolveTenantId($user);

        $secret = IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->where('provider', self::PROVIDER)
            ->firstOrFail();

        $secret->update([
            'secret_encrypted' => Crypt::encryptString($request->string('api_key')->toString()),
            'secret_last4' => substr($request->string('api_key')->toString(), -4),
            'rotated_at' => now(),
            'status' => IntegrationTenantSecret::STATUS_DISCONNECTED,
            'last_error' => null,
        ]);

        return redirect()->back()->with('success', 'API key rotated. Run Test Connection.');
    }

    public function removeKey(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $tenantId = $this->resolveTenantId($user);

        IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->where('provider', self::PROVIDER)
            ->delete();

        Integration::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', self::PROVIDER)
            ->update([
                'status' => Integration::STATUS_INACTIVE,
            ]);

        return redirect()->back()->with('success', 'Queclink credentials removed.');
    }

    private function userCanManage($user): bool
    {
        return $user && $user->canDo('securityDevices.integrations.manage');
    }

    private function resolveTenantId($user): int
    {
        return (int) ($user->tenant_id ?? $user->organization_id ?? 1);
    }
}
