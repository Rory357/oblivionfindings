<?php

namespace App\Services\Tracking;

use App\Domain\SecurityDevices\Management\Data\ClientLocationCommandOrigin;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Management\Services\CommandChangeEligibilityService;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandContractVerifier;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandQueueService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Management\Services\DeviceManagementAuthorizationService;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\Queclink\QueclinkDevice;
use App\Models\User;
use App\Services\Queclink\ConfigurationSnapshotService;
use App\Services\Queclink\QueclinkConfigurationProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ClientTrackerModeService
{
    private const CAPABILITY = 'configuration.apply';

    public function __construct(private ClientLocationAccessService $access, private ClientTrackerModeProfiles $profiles) {}

    public function read(User $actor, Client $client, string $fingerprint): array
    {
        $assignment = $this->access->recheck($actor, $client, $fingerprint, true);
        $device = $assignment->device;
        $profiles = $this->profiles->profiles($device);
        $reason = $this->unavailable($actor, $assignment);
        $changes = $reason ? collect() : app(CommandChangeEligibilityService::class)->eligibleFor($actor, $device, (int) $assignment->custody_site_id);
        $canReadCommands = app(DeviceManagementAuthorizationService::class)->evaluate($actor, $device,
            app(CommandCapabilityRegistry::class)->definition(self::CAPABILITY), fresh: true)->allowed;
        $latest = $canReadCommands ? DeviceCommandRequest::query()->where('device_id', $device->id)->where('requested_by_user_id', $actor->id)
            ->where('capability', self::CAPABILITY)->where('origin_context->access_fingerprint', $fingerprint)
            ->where('origin_context->client_id', $client->id)->latest('id')->first() : null;
        $observed = ['mode' => null, 'reported_at' => null];
        $paired = QueclinkDevice::query()->where('device_id', $device->id)->where('status', 'paired')->latest('id')->first();
        if ($paired && in_array($paired->imei, array_filter([$device->imei, $device->device_uid]), true)) {
            $snapshot = app(ConfigurationSnapshotService::class)->latestForDevice($paired);
            $at = isset($snapshot['received_at']) ? CarbonImmutable::parse($snapshot['received_at']) : null;
            if ($at && ! $at->isFuture() && $at->greaterThanOrEqualTo($assignment->assigned_at)
                && $at->greaterThanOrEqualTo($assignment->collection_started_at)
                && $at->greaterThanOrEqualTo($assignment->consent->given_at)) {
                foreach ($profiles as $mode => $profile) {
                    if ((int) data_get($snapshot, 'summary.global.mode_selection', -1) === 1
                        && (int) data_get($snapshot, 'summary.global.charge_standby_mode', -1) === 0
                        && app(QueclinkConfigurationProfileService::class)->matches($profile, $snapshot)) {
                        $observed = ['mode' => $mode, 'reported_at' => $at->toISOString()];
                        break;
                    }
                }
            }
        }
        $result = [
            'access_fingerprint' => $fingerprint, 'checked_at' => now()->toISOString(),
            'observed' => $observed, 'unavailable_reason' => $reason,
            'modes' => collect(ClientTrackerModeProfiles::MODES)->map(fn ($definition, $mode) => [
                'id' => $mode, 'label' => $definition['label'], 'interval_seconds' => $definition['seconds'],
                'profile_id' => $profiles[$mode]->id ?? null, 'profile_version' => $profiles[$mode]->version ?? null,
                'available' => $reason === null && isset($profiles[$mode]),
            ])->values()->all(),
            'changes' => $changes->map(fn ($change) => ['id' => $change->id, 'label' => $change->ticket->title,
                'ends_at' => $change->maintenance_ends_at->toISOString()])->all(),
            'request' => $latest ? $this->present($this->owned($actor, $client, $assignment, $latest->command_uuid)) : null,
        ];
        $this->access->recheck($actor, $client, $fingerprint, true);

        return $result;
    }

    public function request(User $actor, Client $client, array $data, ?CarbonImmutable $confirmedAt): array
    {
        $assignment = $this->access->recheck($actor, $client, $data['access_fingerprint'], true);
        if ($reason = $this->unavailable($actor, $assignment)) {
            throw ValidationException::withMessages(['tracker' => $reason]);
        }
        $profile = $this->profiles->profiles($assignment->device)[$data['mode']] ?? null;
        if (! $profile || (int) $profile->id !== (int) $data['profile_id']) {
            throw ValidationException::withMessages(['mode' => 'The mode profile changed. Reload and review the current settings.']);
        }
        $command = app(DeviceCommandRequestService::class)->request($assignment->device, $actor, new CommandRequestInput(
            capability: self::CAPABILITY, parameters: ['configuration_profile_id' => (int) $profile->id],
            reason: $data['reason'], idempotencyKey: 'client-location:'.strtolower($data['idempotency_key']),
            stepUpConfirmedAt: $confirmedAt, itChangeId: (int) $data['it_change_id'], impactAcknowledged: true,
            originContext: app(ClientLocationCommandContext::class)->origin($assignment),
        ));
        $this->queue($actor, $command);

        return $this->read($actor, $client, $data['access_fingerprint']);
    }

    public function resume(User $actor, Client $client, string $uuid, string $fingerprint, ?CarbonImmutable $confirmedAt): array
    {
        $assignment = $this->access->recheck($actor, $client, $fingerprint, true);
        $command = $this->owned($actor, $client, $assignment, $uuid);
        $command = app(DeviceCommandRequestService::class)->request($assignment->device, $actor, new CommandRequestInput(
            capability: self::CAPABILITY, parameters: $command->encrypted_parameters,
            reason: $command->reason, idempotencyKey: $command->idempotency_key,
            stepUpConfirmedAt: $confirmedAt, itChangeId: $command->it_change_id, impactAcknowledged: true,
            originContext: ClientLocationCommandOrigin::fromArray($command->origin_context),
        ));
        $this->queue($actor, $command);

        return $this->read($actor, $client, $fingerprint);
    }

    public function owned(User $actor, Client $client, DeviceAssignment $assignment, string $uuid): DeviceCommandRequest
    {
        $command = DeviceCommandRequest::query()->where('command_uuid', $uuid)->where('requested_by_user_id', $actor->id)
            ->where('device_id', $assignment->device_id)->where('site_id', $assignment->custody_site_id)
            ->where('capability', self::CAPABILITY)->firstOrFail();
        abort_unless(app(DeviceCommandContractVerifier::class)->verify($command) && is_array($command->origin_context), 404);
        $origin = ClientLocationCommandOrigin::fromArray($command->origin_context);
        abort_unless($origin->clientId === (int) $client->id && $origin->assignmentId === (int) $assignment->id
            && $origin->consentId === (int) $assignment->consent_id
            && hash_equals($origin->accessFingerprint, $this->access->fingerprint($assignment)), 404);
        abort_unless(app(DeviceManagementAuthorizationService::class)->evaluate($actor, $assignment->device,
            app(CommandCapabilityRegistry::class)->definition(self::CAPABILITY), fresh: true)->allowed, 404);

        return $command;
    }

    private function unavailable(User $actor, DeviceAssignment $assignment): ?string
    {
        $device = $assignment->device;
        $capability = app(CommandCapabilityRegistry::class)->definition(self::CAPABILITY);
        if (! app(DeviceManagementAuthorizationService::class)->evaluate($actor, $device, $capability, fresh: true)->allowed) {
            return 'Your device permissions do not include changing tracker modes.';
        }
        if ($this->profiles->profiles($device) === [] || ! app(DeclaredDeviceCommandCapabilities::class)->supports($device, self::CAPABILITY)) {
            return 'Approved tracking modes are not configured for this tracker model.';
        }
        if (! in_array($device->status->value, $capability->allowedCurrentStates, true)) {
            return 'This tracker is not available for a mode change.';
        }
        $route = app(CommandExecutionRouteResolver::class)->resolve($device, (int) $assignment->custody_site_id, self::CAPABILITY);

        return $route->available && $route->mode === 'central' ? null : 'A supported tracker connection is not configured.';
    }

    private function queue(User $actor, DeviceCommandRequest $command): void
    {
        if ($command->status === CommandStatus::Ready) {
            try {
                app(DeviceCommandQueueService::class)->queue($command, $actor);
            } catch (ValidationException $error) {
                if ($command->fresh()->status !== CommandStatus::Queued) {
                    throw $error;
                }
            }
        }
    }

    private function present(DeviceCommandRequest $command): array
    {
        return ['id' => $command->command_uuid, 'status' => $command->status->value, 'terminal' => $command->status->isTerminal(),
            'profile_id' => $command->encrypted_parameters['configuration_profile_id'], 'requested_at' => $command->created_at->toISOString(),
            'expires_at' => $command->expires_at->toISOString()];
    }
}
