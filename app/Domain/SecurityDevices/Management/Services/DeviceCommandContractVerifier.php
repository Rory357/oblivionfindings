<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Data\ClientLocationCommandOrigin;
use App\Domain\SecurityDevices\Management\Data\CommandSigningPayload;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use Carbon\CarbonImmutable;
use Throwable;

/** Verify before deciding whether a stored command has a client origin. */
final class DeviceCommandContractVerifier
{
    public function __construct(private readonly CommandRequestSigner $signer) {}

    public function verify(DeviceCommandRequest $request): bool
    {
        try {
            $origin = $request->origin_context === null ? null : ClientLocationCommandOrigin::fromArray($request->origin_context);
            $payload = new CommandSigningPayload(
                commandUuid: $request->command_uuid,
                deviceId: $request->device_id,
                siteId: $request->site_id,
                requestedByUserId: $request->requested_by_user_id,
                capability: $request->capability,
                capabilityVersion: $request->capability_version,
                managementLevel: $request->management_level->value,
                risk: $request->risk->value,
                idempotencyKey: $request->idempotency_key,
                parametersHash: $this->signer->parametersHash($request->encrypted_parameters),
                reasonHash: $this->signer->reasonHash($request->reason),
                expectedState: $request->expected_state,
                reconciliationRule: $request->reconciliation_rule,
                expiresAt: CarbonImmutable::instance($request->expires_at),
                itChangeId: $request->it_change_id,
                collectorId: $request->collector_id,
                isBreakGlass: $request->is_break_glass,
                provider: $request->provider,
                breakGlassReviewerUserId: $request->break_glass_reviewer_user_id,
                breakGlassReasonHash: $request->is_break_glass && is_string($request->break_glass_reason)
                    ? $this->signer->reasonHash($request->break_glass_reason) : null,
                assignmentFingerprint: $request->assignment_fingerprint,
                confirmationMode: $request->impact_acknowledged_at === null ? null : $request->confirmation_mode?->value,
                impactAcknowledgedAt: $request->impact_acknowledged_at === null ? null : CarbonImmutable::instance($request->impact_acknowledged_at),
                originContext: $origin,
            );

            return is_string($request->signing_key_id) && is_string($request->signature)
                && $this->signer->verify($payload, $request->signing_key_id, $request->signature);
        } catch (Throwable) {
            return false;
        }
    }
}
