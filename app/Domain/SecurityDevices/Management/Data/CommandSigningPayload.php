<?php

namespace App\Domain\SecurityDevices\Management\Data;

use Carbon\CarbonImmutable;

final readonly class CommandSigningPayload
{
    /** @param array<string, scalar|null> $expectedState */
    public function __construct(
        public string $commandUuid,
        public int $deviceId,
        public int $siteId,
        public int $requestedByUserId,
        public string $capability,
        public int $capabilityVersion,
        public string $managementLevel,
        public string $risk,
        public string $idempotencyKey,
        public string $parametersHash,
        public string $reasonHash,
        public array $expectedState,
        public string $reconciliationRule,
        public CarbonImmutable $expiresAt,
        public ?int $itChangeId,
        public ?int $collectorId,
        public bool $isBreakGlass,
        public ?string $provider,
        public ?int $breakGlassReviewerUserId = null,
        public ?string $breakGlassReasonHash = null,
        public ?string $assignmentFingerprint = null,
        public ?string $confirmationMode = null,
        public ?CarbonImmutable $impactAcknowledgedAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
            'capability' => $this->capability,
            'capability_version' => $this->capabilityVersion,
            'command_uuid' => $this->commandUuid,
            'device_id' => $this->deviceId,
            'expected_state' => $this->expectedState,
            'expires_at' => $this->expiresAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'idempotency_key' => $this->idempotencyKey,
            'is_break_glass' => $this->isBreakGlass,
            'it_change_id' => $this->itChangeId,
            'collector_id' => $this->collectorId,
            'management_level' => $this->managementLevel,
            'parameters_hash' => $this->parametersHash,
            'reason_hash' => $this->reasonHash,
            'reconciliation_rule' => $this->reconciliationRule,
            'requested_by_user_id' => $this->requestedByUserId,
            'risk' => $this->risk,
            'provider' => $this->provider,
            'schema_version' => 2,
            'site_id' => $this->siteId,
        ];

        if ($this->isBreakGlass) {
            $payload['break_glass_reviewer_user_id'] = $this->breakGlassReviewerUserId;
            $payload['break_glass_reason_hash'] = $this->breakGlassReasonHash;
            $payload['schema_version'] = 3;
        }
        if ($this->assignmentFingerprint !== null) {
            $payload['assignment_fingerprint'] = $this->assignmentFingerprint;
            $payload['schema_version'] = $this->isBreakGlass ? 5 : 4;
        }
        if ($this->confirmationMode !== null && $this->impactAcknowledgedAt !== null) {
            $payload['confirmation_mode'] = $this->confirmationMode;
            $payload['impact_acknowledged_at'] = $this->impactAcknowledgedAt->utc()->format('Y-m-d\TH:i:s.u\Z');
            $payload['schema_version'] = $this->isBreakGlass ? 7 : 6;
        }

        return $payload;
    }
}
