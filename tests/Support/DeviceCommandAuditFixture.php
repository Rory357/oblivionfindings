<?php

namespace Tests\Support;

use App\Domain\SecurityDevices\Management\Data\CommandSigningPayload;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandRequestSigner;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class DeviceCommandAuditFixture
{
    /** An authentic signed storage fixture before the first audit append. */
    public static function request(CommandStatus $status = CommandStatus::Ready): DeviceCommandRequest
    {
        config(['monitoring.signing.active_key_id' => 'audit-test',
            'monitoring.signing.keys' => ['audit-test' => base64_encode(str_repeat('A', SODIUM_CRYPTO_AUTH_KEYBYTES))]]);
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $device = Device::factory()->tracking()->create();
        $uuid = (string) Str::orderedUuid();
        $key = 'audit-fixture:'.Str::uuid();
        $expires = CarbonImmutable::now('UTC')->startOfSecond()->addMinutes(5);
        $reason = 'Synthetic audit integrity verification.';
        $signer = app(CommandRequestSigner::class);
        $signature = $signer->sign(new CommandSigningPayload(
            commandUuid: $uuid, deviceId: $device->id, siteId: $site->id, requestedByUserId: $actor->id,
            capability: 'tracking.location_refresh', capabilityVersion: 1, managementLevel: 'control', risk: 'high',
            idempotencyKey: $key, parametersHash: $signer->parametersHash([]), reasonHash: $signer->reasonHash($reason),
            expectedState: [], reconciliationRule: 'provider_acknowledged', expiresAt: $expires,
            itChangeId: null, collectorId: null, isBreakGlass: false, provider: null,
        ));

        return DeviceCommandRequest::query()->create([
            'command_uuid' => $uuid, 'device_id' => $device->id, 'site_id' => $site->id, 'requested_by_user_id' => $actor->id,
            'capability' => 'tracking.location_refresh', 'capability_version' => 1, 'management_level' => 'control', 'risk' => 'high',
            'status' => $status, 'encrypted_parameters' => [], 'safe_parameter_summary' => [], 'reason' => $reason,
            'expected_state' => [], 'reconciliation_rule' => 'provider_acknowledged', 'idempotency_key' => $key,
            'signing_key_id' => $signature['key_id'], 'signature' => $signature['signature'], 'expires_at' => $expires,
        ]);
    }
}
