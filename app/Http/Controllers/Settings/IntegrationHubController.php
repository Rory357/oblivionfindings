<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Integration\Integration;
use App\Models\Integration\IntegrationTenantSecret;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IntegrationHubController extends Controller
{
    /**
     * Known integration providers and their display names.
     */
    private const PROVIDERS = [
        'unifi'           => 'UniFi',
        'queclink'        => 'Queclink',
        'hikvision'       => 'Hikvision',
        'iot'             => 'IoT Sensors',
        'generic_webhook' => 'Generic Webhook',
    ];

    /**
     * Show the integration hub listing page.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('integrations.view'), 403);

        $tenantId = $user->tenant_id;

        // Load existing integrations and tenant secrets for this tenant
        $existingIntegrations = Integration::query()
            ->forTenant($tenantId)
            ->get()
            ->keyBy('provider');

        $existingSecrets = IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->get()
            ->keyBy('provider');

        // Build an entry for every known provider, merging in DB data when it exists
        $integrations = collect(self::PROVIDERS)->map(function (string $displayName, string $slug) use ($existingIntegrations, $existingSecrets) {
            $integration = $existingIntegrations->get($slug);
            $secret      = $existingSecrets->get($slug);

            return [
                'provider'       => $slug,
                'display_name'   => $integration?->display_name ?? $displayName,
                'status'         => $integration?->status ?? Integration::STATUS_INACTIVE,
                'last_tested_at' => $integration?->last_tested_at?->toISOString(),
                'has_key'        => $secret !== null,
            ];
        })->values()->all();

        return Inertia::render('settings/integrations/index', [
            'integrations' => $integrations,
            'can'          => [
                'manage' => $user->canDo('integrations.manage_tenant_secrets'),
            ],
        ]);
    }
}
