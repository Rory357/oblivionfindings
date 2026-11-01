<?php

namespace App\Domain\SecurityDevices\Screens\Setup;

use App\Domain\SecurityDevices\Presenters\IntegrationsWorkspacePresenter;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Setup › Services & logins › Connected services (pg-sdr-15d): each account or receiver that
 * brings devices in without scanning, as a card with its state and what it brings in.
 *
 * The four accounts (UniFi, Queclink, Milesight, Intune) come from today's integrations
 * workspace, so their states match it exactly. Alarm reports and ONVIF cameras need no
 * account; they show only when Oblivion is listening for them or has found some.
 */
final class SetupServices
{
    private const array SUBS = [
        'unifi' => 'Network, Wi-Fi, gateways and Protect cameras',
        'queclink' => 'Care and vehicle trackers',
        'milesight' => 'LoRaWAN sensors and gateways',
        'intune' => 'Staff computers managed by Intune, read-only',
    ];

    public function __construct(private readonly IntegrationsWorkspacePresenter $integrations) {}

    /**
     * @param  Collection<int, DeviceEntry>  $entries  the viewer's devices
     * @return array{cards: list<array<string, mixed>>, connected: int, total: int, attention: list<array{name: string, text: string, tone: string}>}
     */
    public function present(User $viewer, Collection $entries): array
    {
        $workspace = $this->integrations->present($viewer);
        $cards = [];
        $attention = [];

        foreach ($workspace['providers'] as $provider) {
            $card = $this->providerCard($provider);
            $cards[] = $card;
            if (in_array($card['flag']['tone'], ['critical', 'warning'], true)) {
                $attention[] = ['name' => $card['name'], 'text' => $card['flag']['text'], 'tone' => $card['flag']['tone']];
            }
            foreach ($card['facts'] as $fact) {
                if ($fact['tone'] === 'warning' && str_contains($fact['text'], 'replaced')) {
                    $attention[] = ['name' => $card['name'], 'text' => $fact['text'], 'tone' => 'warning'];
                }
            }
        }

        $panels = $entries->filter(fn (DeviceEntry $entry): bool => $entry->kind === DeviceKind::AlarmPanel)->count();
        if ((bool) config('security_devices.listeners.sia_dc09.enabled', false) || $panels > 0) {
            $listening = (bool) config('security_devices.listeners.sia_dc09.enabled', false);
            $cards[] = [
                'key' => 'sia',
                'name' => 'Alarm panels (SIA)',
                'sub' => 'Ajax and other panels that send alarm reports (SIA DC-09) · view only',
                'flag' => $listening ? ['tone' => 'success', 'text' => 'Listening'] : ['tone' => 'neutral', 'text' => 'Not listening'],
                'facts' => array_values(array_filter([
                    $panels > 0 ? ['tone' => 'success', 'text' => $panels === 1 ? '1 panel' : "{$panels} panels", 'at' => null] : null,
                    ['tone' => 'info', 'text' => 'Panels also report to their monitoring company', 'at' => null],
                ])),
            ];
        }

        $cameras = $entries->filter(fn (DeviceEntry $entry): bool => $entry->kind === DeviceKind::Camera
            && ! in_array((string) $entry->device->provider, ['unifi', 'unifi_protect'], true))->count();
        if ($cameras > 0) {
            $cards[] = [
                'key' => 'onvif',
                'name' => 'ONVIF cameras',
                'sub' => 'Hikvision, Dahua, Uniview, Axis and others',
                'flag' => ['tone' => 'success', 'text' => 'No account needed'],
                'facts' => [['tone' => 'success', 'text' => $cameras === 1 ? '1 camera' : "{$cameras} cameras", 'at' => null]],
            ];
        }

        $accounts = collect($workspace['providers']);

        return [
            'cards' => $cards,
            'connected' => $accounts->filter(fn (array $provider): bool => (bool) ($provider['connected'] ?? false))->count(),
            'total' => $accounts->count(),
            'attention' => $attention,
        ];
    }

    /**
     * @param  array<string, mixed>  $provider
     * @return array<string, mixed>
     */
    private function providerCard(array $provider): array
    {
        $slug = (string) $provider['slug'];
        $status = (string) ($provider['connection_status'] ?? 'not_configured');
        $devices = (int) ($provider['device_count'] ?? 0);
        $listener = $status === 'unavailable';

        $flag = match (true) {
            $status === IntegrationProviderConnection::STATUS_CONNECTED => ['tone' => 'success', 'text' => 'Connected'],
            $status === IntegrationProviderConnection::STATUS_ERROR => ['tone' => 'critical', 'text' => 'Can’t reach'],
            $status === IntegrationProviderConnection::STATUS_DISABLED => ['tone' => 'warning', 'text' => 'Switched off'],
            $listener && $devices > 0 => ['tone' => 'success', 'text' => 'Listening'],
            $status === 'untested' => ['tone' => 'warning', 'text' => 'Not tested yet'],
            default => ['tone' => 'neutral', 'text' => 'Not connected'],
        };

        $facts = [];
        if ($devices > 0) {
            $facts[] = ['tone' => 'success', 'text' => $devices === 1 ? '1 device' : "{$devices} devices", 'at' => null];
        }
        $unmapped = (int) ($provider['site_mapping']['unmapped'] ?? 0);
        if ($unmapped > 0) {
            $facts[] = ['tone' => 'warning', 'text' => $unmapped === 1 ? '1 Site not matched yet' : "{$unmapped} Sites not matched yet", 'at' => null];
        }
        if (($provider['credential']['rotation_state'] ?? null) === 'rotation_due') {
            $facts[] = ['tone' => 'warning', 'text' => 'Key is due to be replaced (every 90 days)', 'at' => null];
        }
        $synced = $provider['last_synced_at'] ?? null;
        if (is_string($synced) && $synced !== '') {
            $facts[] = ['tone' => 'info', 'text' => 'Synced {ago}', 'at' => $synced];
        }
        if ($facts === []) {
            $facts[] = ['tone' => 'info', 'text' => $listener ? 'Units report to Oblivion when set up' : 'Nothing brought in yet', 'at' => null];
        }

        return [
            'key' => $slug,
            'name' => (string) $provider['name'],
            'sub' => self::SUBS[$slug] ?? (string) ($provider['summary'] ?? ''),
            'flag' => $flag,
            'facts' => $facts,
        ];
    }
}
