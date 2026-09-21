<?php

namespace Tests\Support;

use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\ClientConsent;
use App\Models\ConsentAuthorityScope;
use App\Models\ConsentRequest;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\User;
use App\Services\Tracking\ClientLocationCommandContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

final class ClientLocateFixture
{
    public static function make(string $provider = 'client-locate-test'): array
    {
        $fixture = ClientLocationWorkspaceFixture::make(assignedOnly: true);
        foreach (['securityDevices.devices.view', 'securityDevices.commands.operate'] as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'test', 'module' => 'Test']);
            $fixture['actor']->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }
        $fixture['actor']->unsetRelations();
        $fixture['device']->update(['provider' => $provider, 'category' => 'personal_tracker',
            'config' => ['management' => ['capabilities' => ['tracking.location_refresh']]]]);
        $fixture['assignment']->unsetRelations();
        $fixture['origin'] = app(ClientLocationCommandContext::class)->origin($fixture['assignment']);
        config(['monitoring.signing.active_key_id' => 'client-locate-test',
            'monitoring.signing.keys' => ['client-locate-test' => base64_encode(str_repeat('L', SODIUM_CRYPTO_AUTH_KEYBYTES))]]);
        app()->instance(CommandExecutionAdapterRegistry::class, new CommandExecutionAdapterRegistry([new class($provider) implements CommandExecutionAdapter
        {
            public function __construct(private string $provider) {}

            public function supports(Device $device, string $capability): bool
            {
                return $device->provider === $this->provider && $capability === 'tracking.location_refresh';
            }

            public function execute(CommandExecutionContext $context): CommandExecutionResult
            {
                throw new RuntimeException('This request fixture must never execute a provider.');
            }

            public function observe(CommandExecutionContext $context): CommandObservedState
            {
                throw new RuntimeException('This request fixture must never observe a provider.');
            }
        }]));

        return $fixture;
    }

    /** Stored provider queue only. No adapter execute, credential lease, worker or socket. */
    public static function awaitingDelivery(): array
    {
        $fixture = self::make('queclink');
        $command = app(DeviceCommandRequestService::class)->request(
            $fixture['device'], $fixture['actor'], new CommandRequestInput(
                'tracking.location_refresh', [], 'Check the agreed synthetic pickup location.', 'client-location:'.Str::uuid(),
                stepUpConfirmedAt: CarbonImmutable::now(), originContext: $fixture['origin'],
            ));
        $command->update(['status' => CommandStatus::Dispatching]);
        $command->update(['status' => CommandStatus::Accepted]);
        $attempt = DeviceCommandAttempt::query()->create([
            'device_command_request_id' => $command->id, 'attempt_number' => 1, 'runtime' => 'queclink_native',
            'status' => CommandAttemptStatus::Accepted, 'accepted_at' => now(),
        ]);
        $providerDevice = QueclinkDevice::query()->create([
            'device_id' => $fixture['device']->id, 'imei' => str_pad((string) $fixture['device']->id, 15, '8', STR_PAD_LEFT),
            'status' => QueclinkDevice::STATUS_PAIRED,
        ]);
        $pending = QueclinkPendingCommand::query()->create([
            'queclink_device_id' => $providerDevice->id, 'imei' => $providerDevice->imei, 'command_word' => 'GTRTO',
            'raw_command' => 'AT+GTRTO=synthetic,1,,,,,0042$', 'serial_number' => '0042', 'status' => 'queued',
            'device_command_request_id' => $command->id, 'device_command_attempt_id' => $attempt->id,
            'expires_at' => $command->expires_at,
        ]);

        return [...$fixture, ...compact('command', 'attempt', 'providerDevice', 'pending')];
    }

    /** Explicitly bound synthetic substitute evidence; uses the canonical predicate. */
    public static function substitute(): array
    {
        $f = self::make();
        $type = $f['consent']->consentType;
        $type->update(['requires_capacity_assessment' => true]);
        $representative = User::factory()->create();
        $authority = NextOfKin::query()->create([
            'client_id' => $f['client']->id, 'user_id' => $representative->id, 'relationship' => 'guardian',
            'legal_authority_type' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
            'legal_authority_verified_at' => now()->subDay(), 'legal_authority_verified_by_user_id' => $f['actor']->id,
            'legal_authority_expires_at' => now()->addYear(),
        ]);
        $capacity = ClientConsent::query()->create([
            'client_id' => $f['client']->id, 'site_id' => $f['site']->id, 'consent_type_id' => $type->id,
            'consent_type_version_id' => $f['consent']->consent_type_version_id, 'status' => 'given',
            'given_at' => now()->subDay(), 'given_by_user_id' => $f['actor']->id, 'given_method' => 'written',
            'capacity_assessed' => true, 'capacity_outcome' => 'lacks_capacity', 'capacity_assessor_id' => $f['actor']->id,
            'capacity_assessed_at' => now()->subDay(), 'expires_at' => now()->addYear(), 'created_by' => $f['actor']->id,
        ]);
        $scope = ConsentAuthorityScope::query()->create([
            'next_of_kin_id' => $authority->id, 'client_id' => $f['client']->id, 'site_id' => $f['site']->id,
            'representative_user_id' => $representative->id, 'consent_type_id' => $type->id,
            'authority_type' => $authority->legal_authority_type, 'purpose' => $f['consent']->decision_purpose,
            'version' => 1, 'valid_from' => now()->subDay(), 'expires_at' => now()->addYear(),
            'verified_at' => now()->subDay(), 'verified_by_user_id' => $f['actor']->id, 'capacity_evidence_consent_id' => $capacity->id,
            'evidence_reference' => 'synthetic-locate-authority', 'evidence_snapshot' => [
                'authority' => ['next_of_kin_id' => $authority->id, 'legal_authority_type' => $authority->legal_authority_type,
                    'verified_by_user_id' => $authority->legal_authority_verified_by_user_id,
                    'verified_at' => $authority->legal_authority_verified_at->toISOString(), 'expires_at' => $authority->legal_authority_expires_at->toISOString()],
                'capacity' => ['client_consent_id' => $capacity->id, 'outcome' => $capacity->capacity_outcome,
                    'assessor_user_id' => $capacity->capacity_assessor_id, 'assessed_at' => $capacity->capacity_assessed_at->toISOString()],
            ],
        ]);
        $f['consent']->update(['decision_basis' => 'substitute', 'decision_actor_user_id' => $representative->id,
            'given_by_user_id' => $representative->id, 'given_by_relationship' => $scope->authority_type,
            'authority_scope_id' => $scope->id, 'capacity_evidence_consent_id' => $capacity->id,
            'decision_evidence' => [...$f['consent']->decision_evidence,
                'authority_basis' => 'substitute', 'decision_actor_user_id' => $representative->id,
                'request_purpose' => $scope->purpose, 'authority_scope_version' => 1, 'authority_next_of_kin_id' => $authority->id,
                'capacity_evidence_consent_id' => $capacity->id, 'capacity_outcome' => $capacity->capacity_outcome,
                'capacity_assessor_user_id' => $capacity->capacity_assessor_id, 'capacity_assessed_at' => $capacity->capacity_assessed_at->toISOString(),
            ],
        ]);
        $f['assignment']->unsetRelations();
        $f['origin'] = app(ClientLocationCommandContext::class)->origin($f['assignment']);

        return [...$f, ...compact('type', 'representative', 'authority', 'capacity', 'scope')];
    }
}
