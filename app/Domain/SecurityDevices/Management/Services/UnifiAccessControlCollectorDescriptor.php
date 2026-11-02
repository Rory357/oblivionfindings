<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorCredentialLeaseSealer;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Access\AccessConsoles;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\UnifiAccessSync;
use App\Domain\SecurityDevices\Cctv\Services\CameraEndpointTrustService;
use App\Domain\SecurityDevices\Management\Contracts\CollectorCommandDescriptor;
use App\Domain\SecurityDevices\Management\Data\CollectorCommandOutcome;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use RuntimeException;
use Throwable;

/**
 * S&D Phase H: door and Site actions in UniFi Access, through the hub that covers the Site, as
 * governed commands (step-up, approval or break-glass, audit). Keep a door unlocked for a while
 * (pop-up 70) or lock it again; lock down, evacuate, or end either (pop-up 86). The hub sends the
 * one change to the console's trusted key and reads the result straight back. The server never
 * connects into the house, and Sites without a hub can't do these.
 */
abstract class UnifiAccessControlCollectorDescriptor implements CollectorCommandDescriptor
{
    public const string PROTOCOL = 'command.unifi_access_control';

    public const string ADAPTER = 'unifi_access_control_v1';

    public const string PROVIDER = 'unifi_access';

    public const array FAILURES = [
        'UniFi Access rejected the saved login.',
        'UniFi Access doesn’t know that door.',
        'UniFi Access refused the change in its current state.',
        'UniFi Access did not accept the change.',
        'The hub did not receive a confirmed answer from UniFi Access. Check the doors before trying again.',
        'The hub could not complete the checks before sending the change.',
        'The hub restarted after starting the change without a final answer. It was not sent again.',
    ];

    public const array RECONCILIATION_SUMMARIES = [
        'The hub read UniFi Access straight after and it shows what was asked for.',
        'The hub read UniFi Access straight after and it does not show what was asked for.',
        'The hub could not read UniFi Access straight after. Check the doors before trying again.',
        'Fresh state is required before any new attempt.',
    ];

    public function __construct(
        private readonly EgressPolicy $egress,
        private readonly CameraEndpointTrustService $trust,
        private readonly CredentialLeaseProvider $leases,
        private readonly CollectorCredentialLeaseSealer $sealer,
        private readonly CollectorCommandContract $contracts,
    ) {}

    /** keep_unlocked | keep_unlocked_end | lockdown | evacuate | emergency_end */
    abstract protected function operation(): string;

    /** Whether the action is on one door (otherwise the whole Site, on the console's device). */
    abstract protected function onDoor(): bool;

    /** What the hub must read back: ['keep_unlocked' => bool] or ['emergency' => mode]. @return array<string, bool|string> */
    abstract protected function expected(): array;

    public function routesThroughCoveringHub(): bool
    {
        return true;
    }

    public function maxAttachmentBytes(): int
    {
        return 0;
    }

    /** A hub refuses its whole config when it sees a job type it doesn't know: only hubs that list it get one. */
    public static function hubRunsIt(MonitoringCollector $collector): bool
    {
        return HubJobTypes::runs($collector, self::ADAPTER);
    }

    public function supports(Device $device, MonitoringCollector $collector, int $siteId): bool
    {
        try {
            if (! $collector->isApprovedForWork() || ! in_array($siteId, $collector->authorisedSiteIds(), true) || ! self::hubRunsIt($collector)) {
                return false;
            }
            $this->endpoint($device, $siteId);

            return $this->reference($siteId) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public function interpret(
        DeviceCommandRequest $request,
        MonitoringCollector $collector,
        string $executionStatus,
        array $safeResult,
        ?string $providerReference,
        ?string $failure,
        ?array $reconciliation,
        ?string $attachment,
    ): CollectorCommandOutcome {
        if ($executionStatus === 'succeeded') {
            if (array_keys($safeResult) !== ['operation', 'provider_state']
                || $safeResult['operation'] !== $this->operation()
                || $safeResult['provider_state'] !== 'accepted'
                || $providerReference !== 'unifi-access-control:'.$request->command_uuid
                || $failure !== null) {
                throw new RuntimePayloadInvalid('Collector UniFi Access success evidence is invalid.');
            }
        } elseif ($safeResult !== [] || $providerReference !== null || ! is_string($failure) || ! in_array($failure, self::FAILURES, true)) {
            throw new RuntimePayloadInvalid('Collector UniFi Access failure evidence is invalid.');
        }
        if ($executionStatus !== 'failed' && $reconciliation === null) {
            // A door change that may have happened always comes back with the hub's fresh look.
            throw new RuntimePayloadInvalid('Collector command reconciliation is invalid.');
        }
        if ($reconciliation !== null) {
            $expected = $this->expected();
            $key = array_key_first($expected);
            $observed = $reconciliation['observed_state'];
            $valid = $observed === null || (array_keys($observed) === [$key] && ($key === 'keep_unlocked'
                ? is_bool($observed[$key])
                : in_array($observed[$key], ['normal', 'lockdown', 'evacuation'], true)));
            if (! $valid
                || ! in_array($reconciliation['safe_evidence_summary'], self::RECONCILIATION_SUMMARIES, true)
                || ($reconciliation['outcome'] === 'matched' && $observed !== $expected)
                || ($reconciliation['outcome'] === 'mismatch' && ($observed === null || $observed === $expected))) {
                throw new RuntimePayloadInvalid('Collector command reconciliation evidence is not a declared typed outcome.');
            }
        }

        return new CollectorCommandOutcome($executionStatus, $safeResult, $providerReference, $failure, $reconciliation);
    }

    public function sameEvidence(DeviceCommandAttempt $attempt, string $executionStatus, array $safeResult, ?string $failure): bool
    {
        return $attempt->status->value === $executionStatus
            && ($attempt->safe_result_summary ?? []) === $safeResult
            && $attempt->safe_failure_reason === $failure;
    }

    /** @return array<string, mixed> */
    public function build(DeviceCommandRequest $request, DeviceCommandAttempt $attempt, MonitoringCollector $collector): array
    {
        $request->loadMissing('device');
        $siteId = (int) $request->site_id;
        if ($request->capability !== $this->capability() || ! in_array($siteId, $collector->authorisedSiteIds(), true) || ! self::hubRunsIt($collector)) {
            throw new RuntimeException('The collector command route is unavailable.');
        }
        $parameters = [];
        if ($this->operation() === 'keep_unlocked') {
            $minutes = $request->encrypted_parameters['minutes'] ?? null;
            if (! is_int($minutes) || $minutes < 5 || $minutes > 240) {
                throw new RuntimeException('The approved keep-unlocked time is invalid.');
            }
            $parameters = ['minutes' => $minutes];
        }
        ['console' => $console, 'door_id' => $doorId, 'pin' => $pin] = $this->endpoint($request->device, $siteId);
        $reference = $this->reference($siteId) ?? throw new RuntimeException('The UniFi Access saved login is unavailable.');
        $lease = $this->leases->acquire($siteId, $reference->reference_key, ['command:'.$this->capability()]);

        return [
            'command_uuid' => (string) $request->command_uuid,
            'attempt_uuid' => (string) $attempt->attempt_uuid,
            'attempt_number' => (int) $attempt->attempt_number,
            'site_id' => $siteId,
            'device_id' => (string) $request->device_id,
            'capability' => $this->capability(),
            'provider' => self::PROVIDER,
            'adapter' => self::ADAPTER,
            'protocol' => self::PROTOCOL,
            'target' => $console->address,
            'expires_at' => $request->expires_at->utc()->format(DATE_ATOM),
            'idempotency_hash' => hash('sha256', (string) $request->idempotency_key),
            'contract_hash' => $this->contracts->hash($request, $attempt, $collector),
            'parameters' => ['operation' => $this->operation(), ...$parameters],
            'expected_state' => $this->expected(),
            'endpoint' => [
                'address' => $console->address,
                'port' => (int) $console->port,
                'trusted_pin' => $pin,
                'door_id' => $doorId,
                'connect_timeout_seconds' => 5,
                'response_timeout_seconds' => 15,
                'max_response_bytes' => 65_536,
            ],
            'credential_lease' => $this->sealer->seal($collector, (string) $request->device_id, self::PROTOCOL, $console->address, $lease, $siteId),
        ];
    }

    /**
     * The Site's console (with a trusted key, at an address inside the Site's networks) and, for a
     * door action, the UniFi door this device is linked to.
     *
     * @return array{console: AccessConsole, door_id: string|null, pin: string}
     */
    private function endpoint(Device $device, int $siteId): array
    {
        if ($this->onDoor()) {
            $door = AccessConsoles::doorFor($device) ?? throw new RuntimeException('This door isn’t linked to a UniFi Access door.');
            $console = $door->console;
            $doorId = UnifiAccessSync::id($door->external_id) ?? throw new RuntimeException('The UniFi Access door id is invalid.');
            if (! $door->hub_bound) {
                throw new RuntimeException('UniFi Access says this door isn’t bound to an access hub.');
            }
        } else {
            $console = AccessConsoles::consoleFor($device) ?? throw new RuntimeException('This isn’t a UniFi Access console.');
            $doorId = null;
        }
        if (! $console instanceof AccessConsole || (int) $console->site_id !== $siteId) {
            throw new RuntimeException('The UniFi Access console isn’t at this Site.');
        }
        if ($this->operation() === 'lockdown' && $console->exit_buttons_confirmed_at === null) {
            // Lockdown never shuts anyone in: it waits until every door is confirmed to open from inside.
            throw new RuntimeException('Nobody has confirmed every door has an exit button and a handle yet.');
        }
        $pin = $this->trust->record($siteId, $console->address, (int) $console->port)?->trusted_pin;
        if (! is_string($pin) || preg_match(CameraEndpointTrustService::PIN_PATTERN, $pin) !== 1) {
            throw new RuntimeException('The UniFi Access console’s certificate isn’t trusted yet.');
        }
        $target = $this->egress->authorise($siteId, (int) $device->id, ProbeTarget::http('https://'.$console->address.':'.(int) $console->port));
        if ($target->addresses === [] || ! in_array($console->address, $target->addresses, true)) {
            throw new RuntimeException('The UniFi Access console is outside the Site’s networks.');
        }

        return ['console' => $console, 'door_id' => $doorId, 'pin' => $pin];
    }

    private function reference(int $siteId): ?\App\Domain\SecurityDevices\Credentials\Models\CredentialReference
    {
        $reference = UnifiAccessSync::reference($siteId);

        return $reference !== null && in_array('command:'.$this->capability(), $reference->capabilities ?? [], true) ? $reference : null;
    }
}
