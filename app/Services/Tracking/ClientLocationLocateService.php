<?php

namespace App\Services\Tracking;

use App\Domain\SecurityDevices\Management\Data\ClientLocationCommandOrigin;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandContractVerifier;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandQueueService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Management\Services\DeviceManagementAuthorizationService;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ClientLocationLocateService
{
    public function __construct(
        private readonly ClientLocationAccessService $access,
        private readonly ClientLocationCommandContext $context,
        private readonly DeviceCommandRequestService $requests,
        private readonly DeviceCommandQueueService $queue,
        private readonly DeviceCommandContractVerifier $verifier,
    ) {}

    public function availability(User $actor, Client $client, string $fingerprint): array
    {
        $assignment = $this->access->recheck($actor, $client, $fingerprint, true);
        $unavailable = $this->unavailableReason($actor, $assignment);
        $latest = DeviceCommandRequest::query()->where('device_id', $assignment->device_id)
            ->where('requested_by_user_id', $actor->id)->where('capability', 'tracking.location_refresh')
            ->where('origin_context->client_id', $client->id)
            ->where('origin_context->assignment_id', $assignment->id)
            ->where('origin_context->consent_id', $assignment->consent_id)
            ->where('origin_context->access_fingerprint', $fingerprint)
            ->latest('id')->first();
        $result = ['available' => $unavailable === null, 'unavailable_reason' => $unavailable,
            'tracker_name' => $assignment->device->name,
            'request' => $latest ? $this->present($actor, $client, $assignment, $latest) : null,
            'access_fingerprint' => $fingerprint, 'checked_at' => now()->toISOString()];
        $this->access->recheck($actor, $client, $fingerprint, true);

        return $result;
    }

    public function request(User $actor, Client $client, array $data, ?CarbonImmutable $confirmedAt): array
    {
        $assignment = $this->access->recheck($actor, $client, $data['access_fingerprint'], true);
        if ($reason = $this->unavailableReason($actor, $assignment)) {
            throw ValidationException::withMessages(['tracker' => $reason]);
        }
        $command = $this->requests->request($assignment->device, $actor, new CommandRequestInput(
            capability: 'tracking.location_refresh', parameters: [], reason: $data['reason'],
            idempotencyKey: 'client-location:'.strtolower($data['idempotency_key']), stepUpConfirmedAt: $confirmedAt,
            originContext: $this->context->origin($assignment),
        ));
        $this->queueWhenReady($actor, $command);

        return $this->status($actor, $client, $command->command_uuid, $data['access_fingerprint']);
    }

    public function resume(User $actor, Client $client, string $uuid, string $fingerprint, ?CarbonImmutable $confirmedAt): array
    {
        $assignment = $this->access->recheck($actor, $client, $fingerprint, true);
        $command = $this->owned($actor, $client, $assignment, $uuid);
        if ($reason = $this->unavailableReason($actor, $assignment)) {
            throw ValidationException::withMessages(['tracker' => $reason]);
        }
        $command = $this->requests->request($assignment->device, $actor, new CommandRequestInput(
            capability: 'tracking.location_refresh', parameters: [], reason: $command->reason,
            idempotencyKey: $command->idempotency_key, stepUpConfirmedAt: $confirmedAt,
            originContext: ClientLocationCommandOrigin::fromArray($command->origin_context),
        ));
        $this->queueWhenReady($actor, $command);

        return $this->status($actor, $client, $uuid, $fingerprint);
    }

    private function queueWhenReady(User $actor, DeviceCommandRequest $command): void
    {
        if ($command->status !== CommandStatus::Ready) {
            return;
        }
        try {
            $this->queue->queue($command, $actor);
        } catch (ValidationException $error) {
            // A simultaneous same-request submission may already have queued it.
            // Only that observed transition is a successful replay.
            if ($command->fresh()->status !== CommandStatus::Queued) {
                throw $error;
            }
        }
    }

    public function status(User $actor, Client $client, string $uuid, string $fingerprint): array
    {
        $assignment = $this->access->recheck($actor, $client, $fingerprint, true);
        $command = $this->owned($actor, $client, $assignment, $uuid);
        $result = ['request' => $this->present($actor, $client, $assignment, $command),
            'access_fingerprint' => $fingerprint, 'checked_at' => now()->toISOString()];
        $this->access->recheck($actor, $client, $fingerprint, true);

        return $result;
    }

    private function owned(User $actor, Client $client, DeviceAssignment $assignment, string $uuid): DeviceCommandRequest
    {
        $command = DeviceCommandRequest::query()->where('command_uuid', $uuid)
            ->where('requested_by_user_id', $actor->id)->where('device_id', $assignment->device_id)
            ->where('site_id', $assignment->custody_site_id)->where('capability', 'tracking.location_refresh')->firstOrFail();
        abort_unless($this->verifier->verify($command) && is_array($command->origin_context), 404);
        $origin = ClientLocationCommandOrigin::fromArray($command->origin_context);
        abort_unless($origin->clientId === (int) $client->id && $origin->assignmentId === (int) $assignment->id
            && $origin->consentId === (int) $assignment->consent_id
            && hash_equals($origin->accessFingerprint, $this->access->fingerprint($assignment)), 404);
        $capability = app(CommandCapabilityRegistry::class)->definition('tracking.location_refresh');
        abort_unless(app(DeviceManagementAuthorizationService::class)->evaluate($actor, $assignment->device, $capability, fresh: true)->allowed, 404);

        return $command;
    }

    private function unavailableReason(User $actor, DeviceAssignment $assignment): ?string
    {
        $device = $assignment->device;
        $capability = app(CommandCapabilityRegistry::class)->definition('tracking.location_refresh');
        $decision = app(DeviceManagementAuthorizationService::class)->evaluate($actor, $device, $capability, fresh: true);
        if (! $decision->allowed) {
            return 'Your current device permissions do not include requesting a location.';
        }
        if (! app(DeclaredDeviceCommandCapabilities::class)->supports($device, $capability->key)) {
            return 'This tracker does not support a location request.';
        }
        if (! in_array($device->status->value, $capability->allowedCurrentStates, true)) {
            return 'This tracker is not currently available for a location request.';
        }
        $route = app(CommandExecutionRouteResolver::class)->resolve($device, (int) $assignment->custody_site_id, $capability->key);

        return $route->available && $route->mode === 'central' ? null : 'A supported tracker connection is not configured. Check the device setup.';
    }

    private function present(User $actor, Client $client, DeviceAssignment $assignment, DeviceCommandRequest $command): array
    {
        $command = $this->owned($actor, $client, $assignment, $command->command_uuid);
        $pending = QueclinkPendingCommand::query()->where('device_command_request_id', $command->id)
            ->whereHas('device', fn ($query) => $query->where('device_id', $assignment->device_id))
            ->whereHas('governedAttempt', fn ($query) => $query->where('device_command_request_id', $command->id))
            ->orderByDesc('id')->first();
        $base = '/operations/clients/'.$client->id.'/location/locate-requests/'.$command->command_uuid;

        return ['id' => $command->command_uuid, 'status' => $command->status->value, 'reason' => $command->reason,
            'requested_at' => $command->created_at->toISOString(), 'expires_at' => $command->expires_at->toISOString(),
            'sent_at' => $pending?->sent_at?->toISOString(), 'acknowledged_at' => $pending?->acked_at?->toISOString(),
            'completed_at' => $command->execution_completed_at?->toISOString(), 'terminal' => $command->status->isTerminal(),
            'status_url' => $base, 'identity_url' => $base.'/confirm-identity', 'resume_url' => $base.'/resume',
            'observation' => $pending?->fulfilled_telemetry_event_id
                ? app(ClientLocationHistoryService::class)->forLocateRequest($actor, $client, $command, $pending) : null];
    }
}
