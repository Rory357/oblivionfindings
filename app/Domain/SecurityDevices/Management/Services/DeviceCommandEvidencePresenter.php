<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Enums\ManagementLevel;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use DomainException;

final class DeviceCommandEvidencePresenter
{
    public function __construct(
        private readonly CommandCapabilityRegistry $capabilities,
        private readonly DeviceManagementAuthorizationService $authorization,
    ) {}

    public function assertCanView(
        User $viewer,
        Device $device,
        DeviceCommandRequest $command,
    ): DeviceCommandRequest {
        abort_unless((int) $command->device_id === (int) $device->id, 404);

        try {
            $capability = $this->capabilities->definition($command->capability);
        } catch (DomainException) {
            abort(404);
        }

        $decision = $this->authorization->evaluate(
            $viewer,
            $device,
            $capability,
            ManagementLevel::Observe,
            true,
        );
        abort_unless($decision->allowed, 404);

        return $command;
    }

    /** @return array<string, mixed> */
    public function present(
        User $viewer,
        Device $device,
        DeviceCommandRequest $command,
    ): array {
        $this->assertCanView($viewer, $device, $command);
        $command->loadMissing([
            'site:id,name',
            'requestedBy:id,name',
            'approvedBy:id,name',
            'approvals.decidedBy:id,name',
            'attempts',
            'reconciliations',
            'auditEvents.actor:id,name',
        ]);

        $auditEvents = $command->auditEvents->sortBy('id')->values();
        $previousHash = null;
        $linkedChain = true;
        foreach ($auditEvents as $event) {
            if ($event->previous_hash !== $previousHash
                || preg_match('/^[a-f0-9]{64}$/', (string) $event->event_hash) !== 1) {
                $linkedChain = false;
            }
            $previousHash = $event->event_hash;
        }

        return [
            'schema_version' => 1,
            'exported_at' => now('UTC')->toISOString(),
            'exported_by' => [
                'id' => (int) $viewer->id,
                'name' => $viewer->name,
            ],
            'command' => [
                'uuid' => $command->command_uuid,
                'device' => [
                    'uid' => $device->device_uid,
                    'name' => $device->name,
                ],
                'site' => [
                    'id' => (int) $command->site_id,
                    'name' => $command->site?->name,
                ],
                'capability' => $command->capability,
                'capability_version' => (int) $command->capability_version,
                'management_level' => $command->management_level->value,
                'risk' => $command->risk->value,
                'status' => $command->status->value,
                'execution_route' => $command->execution_route,
                'provider' => $command->provider,
                'safe_parameters' => $command->safe_parameter_summary ?? [],
                'expected_state' => $command->expected_state ?? [],
                'safe_result_summary' => $command->safe_result_summary,
                'safe_failure_reason' => $command->safe_failure_reason,
                'blocked_reason_code' => $command->blocked_reason_code,
                'is_break_glass' => (bool) $command->is_break_glass,
                'requested_by' => $command->requestedBy?->name,
                'approved_by' => $command->approvedBy?->name,
                'requested_at' => $command->created_at?->toISOString(),
                'approved_at' => $command->approved_at?->toISOString(),
                'dispatched_at' => $command->dispatched_at?->toISOString(),
                'started_at' => $command->started_at?->toISOString(),
                'execution_completed_at' => $command->execution_completed_at?->toISOString(),
                'reconciled_at' => $command->reconciled_at?->toISOString(),
                'expires_at' => $command->expires_at?->toISOString(),
            ],
            'approvals' => $command->approvals
                ->sortBy('id')
                ->map(fn ($approval): array => [
                    'decision' => $approval->decision->value,
                    'decided_by' => $approval->decidedBy?->name,
                    'decided_at' => $approval->decided_at?->toISOString(),
                ])->values()->all(),
            'attempts' => $command->attempts
                ->sortBy('attempt_number')
                ->map(fn ($attempt): array => [
                    'uuid' => $attempt->attempt_uuid,
                    'number' => (int) $attempt->attempt_number,
                    'status' => $attempt->status->value,
                    'runtime' => $attempt->runtime,
                    'safe_result' => $attempt->safe_result_summary ?? [],
                    'safe_failure_reason' => $attempt->safe_failure_reason,
                    'accepted_at' => $attempt->accepted_at?->toISOString(),
                    'started_at' => $attempt->started_at?->toISOString(),
                    'completed_at' => $attempt->completed_at?->toISOString(),
                ])->values()->all(),
            'reconciliations' => $command->reconciliations
                ->sortBy('id')
                ->map(fn ($reconciliation): array => [
                    'outcome' => $reconciliation->outcome->value,
                    'expected_state' => $reconciliation->expected_state ?? [],
                    'observed_state' => $reconciliation->observed_state ?? [],
                    'safe_evidence_summary' => $reconciliation->safe_evidence_summary,
                    'observation_reference_hash' => is_string($reconciliation->observation_reference)
                        ? hash('sha256', $reconciliation->observation_reference)
                        : null,
                    'observed_at' => $reconciliation->observed_at?->toISOString(),
                ])->values()->all(),
            'audit_chain' => [
                'linked' => $linkedChain,
                'event_count' => $auditEvents->count(),
                'events' => $auditEvents->map(fn ($event): array => [
                    'action' => $event->action,
                    'actor' => $event->actor?->name,
                    'safe_context' => $event->safe_context ?? [],
                    'previous_hash' => $event->previous_hash,
                    'event_hash' => $event->event_hash,
                    'occurred_at' => $event->occurred_at?->toISOString(),
                ])->all(),
            ],
            'redactions' => [
                'request and approval narratives',
                'break-glass narratives and review text',
                'signatures and signing-key identifiers',
                'credential references, leases, and reusable secret material',
                'raw provider requests, responses, and provider request references',
                'raw collector identity and observation references',
            ],
        ];
    }
}
