<?php

namespace App\Domain\SecurityDevices\Screens\Setup;

use App\Domain\SecurityDevices\Endpoints\Models\EndpointRemoteSession;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteSupportSettings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase G (pg-sdr-15k): Setup › Computers & Obli › Remote support. "Allow remote support" is
 * today's remote support setting (the same one Settings › Obli changes), for the people who may
 * change it there. The person at the computer always has to click Allow, and sessions are never
 * recorded (D7): both are facts, not switches. The relay status is honest.
 */
final class SetupRemoteSupport
{
    public function __construct(private readonly EndpointRemoteSupportSettings $settings) {}

    public static function canSee(User $viewer): bool
    {
        return app(EndpointRemoteSupportSettings::class)->canManage($viewer)
            || $viewer->canDo('securityDevices.remote.managePolicy')
            || $viewer->canDo('securityDevices.integrations.manage');
    }

    /** @return array<string, mixed> */
    public function present(User $viewer): array
    {
        $current = $this->settings->current();
        $since = CarbonImmutable::now('UTC')->subDays(30);

        return [
            'allowed' => (bool) $current['remote_support_enabled'],
            // Remote support also needs computer management switched on for the organisation.
            'working' => $this->settings->sessionBrokerEnabled(),
            'computers_on' => $this->settings->endpointEnabled(),
            'relay_ready' => $this->settings->hasValidRelay(),
            'relays' => count($this->settings->relayUrls()),
            'sessions_30_days' => Schema::hasTable('endpoint_remote_sessions')
                ? EndpointRemoteSession::query()->where('created_at', '>=', $since)->whereNotNull('allowed_at')->count()
                : 0,
            'can_change' => $this->settings->canManage($viewer),
            'settings_href' => '/settings/obli',
        ];
    }

    /** Turn remote support on or off, through today's settings save (audited there). */
    public function setAllowed(User $actor, bool $allowed): void
    {
        abort_unless($this->settings->canManage($actor), 403);
        $current = $this->settings->current();
        $this->settings->save($actor, [
            'expected_actor_id' => (int) $actor->id,
            'expected_version' => (int) $current['version'],
            'endpoint_management_enabled' => (bool) $current['endpoint_management_enabled'],
            'obli_helper_enabled' => (bool) $current['obli_helper_enabled'],
            'obli_technician_enabled' => (bool) $current['obli_technician_enabled'],
            'remote_support_enabled' => $allowed,
            'relay_urls' => $current['relay_urls'],
            'relay_secret' => null,
            'clear_relay_secret' => false,
        ]);
    }
}
