<?php

namespace App\Domain\SecurityDevices\Screens\Setup;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Iot\Relay\Models\BrokerExposureCheck;
use App\Domain\SecurityDevices\Iot\Relay\Models\SensorGatewayLogin;
use App\Domain\SecurityDevices\Iot\Relay\Services\SensorRelaySites;
use App\Domain\SecurityDevices\Iot\Relay\Services\SensorsCertificateAuthority;
use App\Domain\SecurityDevices\Management\Services\HubJobTypes;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\Site;
use App\Models\User;

/**
 * Setup › Hubs & networks › Sensor broker (pg-sdr-15o): every gateway and plug with its own
 * login, how its readings reach Oblivion, whether they're sent today and why not, and the last
 * check that Oblivion's public address refuses MQTT (D21, D22).
 */
final class SetupBroker
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CoveringHubResolver $hubs,
        private readonly SecurityDevicesSettings $settings,
        private readonly SensorsCertificateAuthority $authority,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer): array
    {
        $siteIds = $this->access->accessibleSiteIds($viewer);
        $plain = $this->settings->bool(SecurityDevicesSettings::MQTT_PLAIN);
        $clashing = SensorRelaySites::clashing();
        $rows = $siteIds === [] ? collect() : SensorGatewayLogin::query()->active()
            ->with(['site:id,name', 'device:id,name,model'])
            ->whereIn('site_id', $siteIds)
            ->orderBy('site_id')->orderBy('label')
            ->get();

        $check = BrokerExposureCheck::query()->with('collector')->latest('checked_at')->first();
        // S&D Phase E (E5): which hubs run the encrypted relay with a current certificate.
        $encrypted = [];
        $encryptedAt = function (MonitoringCollector $hub) use (&$encrypted): ?string {
            return $encrypted[$hub->id] ??= (HubJobTypes::runs($hub, SensorsCertificateAuthority::HUB_FEATURE)
                ? ($this->authority->currentFor($hub) !== null ? 'ready' : 'waiting')
                : 'update');
        };

        return [
            'rows' => $rows->map(function (SensorGatewayLogin $login) use ($plain, $clashing, $encryptedAt): array {
                $hub = $this->hubs->forSite((int) $login->site_id);
                $clash = in_array((int) $login->site_id, $clashing, true);
                $tls = $hub !== null ? $encryptedAt($hub) : null;
                $plainToo = $plain && ! $clash;

                return [
                    'id' => (int) $login->id,
                    'label' => (string) $login->label,
                    'sub' => $login->device ? trim((string) $login->device->name.($login->device->model ? ' · '.$login->device->model : '')) : 'Login '.$login->username,
                    'site' => ['id' => (int) $login->site_id, 'name' => (string) ($login->site?->name ?? 'Site')],
                    'reaches' => $hub !== null ? 'Through '.CoveringHubResolver::label($hub) : 'No hub covers this Site yet',
                    'connection' => match (true) {
                        $hub === null => ['tone' => 'neutral', 'text' => 'Waiting for a hub'],
                        $tls === 'ready' && $plainToo => ['tone' => 'success', 'text' => 'Encrypted (8883); unencrypted also allowed'],
                        $tls === 'ready' => ['tone' => 'success', 'text' => 'Encrypted, port 8883'],
                        $plainToo => ['tone' => 'warning', 'text' => 'Unencrypted, house network only'],
                        $clash && $tls !== 'ready' => ['tone' => 'warning', 'text' => 'Refused: address range clash'],
                        $tls === 'waiting' => ['tone' => 'neutral', 'text' => 'Waiting for the hub’s certificate'],
                        default => ['tone' => 'neutral', 'text' => 'Off until the hub is updated'],
                    },
                    'last' => $login->last_reading_at?->toIso8601String(),
                ];
            })->values()->all(),
            'certificate' => ($fingerprint = $this->authority->existingFingerprint()) === null ? null : [
                'fingerprint' => $fingerprint,
                'download_url' => route('security-devices.setup.broker.sensors-certificate', [], false),
            ],
            'check' => $check === null ? null : [
                'passed' => (bool) $check->passed,
                'at' => $check->checked_at->toIso8601String(),
                'by' => $check->collector ? CoveringHubResolver::label($check->collector) : 'A hub',
                'answered' => array_keys(array_filter((array) $check->ports, fn (mixed $result): bool => $result === 'answered')),
            ],
            'clashes' => $clashing === [] ? [] : Site::query()->whereIn('id', array_intersect($clashing, $siteIds ?: [0]))->orderBy('name')->pluck('name')->all(),
            'can_add' => $viewer->canDo('securityDevices.savedLogins.manage'),
        ];
    }
}
