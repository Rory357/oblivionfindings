<?php

use App\Domain\SecurityDevices\Management\Data\CommandSigningPayload;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandReconciliationOutcome;
use App\Domain\SecurityDevices\Management\Enums\CommandRisk;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Enums\ManagementLevel;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandApproval;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAuditEvent;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandIntakeAudit;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandReconciliation;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandRequestSigner;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function commandContractRecord(array $overrides = []): DeviceCommandRequest
{
    $user = User::factory()->create();
    $site = Site::factory()->create();
    $device = Device::factory()->security()->create();
    $parameters = ['duration_seconds' => 15];
    $signer = app(CommandRequestSigner::class);
    $commandUuid = (string) Str::orderedUuid();
    $expiresAt = CarbonImmutable::now('UTC')->addMinutes(5);
    $idempotencyKey = 'door-unlock:'.$device->id.':'.Str::uuid();
    $payload = new CommandSigningPayload(
        commandUuid: $commandUuid,
        deviceId: $device->id,
        siteId: $site->id,
        requestedByUserId: $user->id,
        capability: 'access.door.unlock_timed',
        capabilityVersion: 1,
        managementLevel: ManagementLevel::Control->value,
        risk: CommandRisk::High->value,
        idempotencyKey: $idempotencyKey,
        parametersHash: $signer->parametersHash($parameters),
        reasonHash: $signer->reasonHash('Let the approved technician through the service entrance.'),
        expectedState: ['locked' => true],
        reconciliationRule: 'door_locked_after_window',
        expiresAt: $expiresAt,
        itChangeId: null,
        collectorId: null,
        isBreakGlass: false,
        provider: null,
    );
    $signature = $signer->sign($payload);

    return DeviceCommandRequest::query()->create(array_replace([
        'command_uuid' => $commandUuid,
        'device_id' => $device->id,
        'site_id' => $site->id,
        'requested_by_user_id' => $user->id,
        'capability' => 'access.door.unlock_timed',
        'capability_version' => 1,
        'management_level' => ManagementLevel::Control,
        'risk' => CommandRisk::High,
        'status' => CommandStatus::AwaitingApproval,
        'encrypted_parameters' => $parameters,
        'safe_parameter_summary' => $parameters,
        'reason' => 'Let the approved technician through the service entrance.',
        'expected_state' => ['locked' => true],
        'reconciliation_rule' => 'door_locked_after_window',
        'idempotency_key' => $idempotencyKey,
        'signing_key_id' => $signature['key_id'],
        'signature' => $signature['signature'],
        'step_up_confirmed_at' => now(),
        'expires_at' => $expiresAt,
    ], $overrides));
}

beforeEach(function () {
    config()->set('monitoring.signing.active_key_id', 'command-test-key');
    config()->set('monitoring.signing.keys', [
        'command-test-key' => base64_encode(str_repeat('K', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);
});

it('creates the durable command, approval, attempt, reconciliation, and audit schema', function () {
    expect(Schema::hasColumns('device_command_requests', [
        'command_uuid', 'device_id', 'site_id', 'assignment_fingerprint', 'requested_by_user_id', 'capability',
        'management_level', 'risk', 'confirmation_mode', 'impact_acknowledged_at', 'status', 'encrypted_parameters', 'safe_parameter_summary',
        'reason', 'expected_state', 'reconciliation_rule', 'idempotency_key', 'signing_key_id',
        'signature', 'expires_at', 'blocked_reason_code', 'blocked_at',
        'break_glass_reviewer_user_id', 'break_glass_declared_at',
        'break_glass_review_due_at', 'break_glass_notification_sent_at',
        'break_glass_review_outcome',
    ]))->toBeTrue()
        ->and(Schema::hasTable('device_command_approvals'))->toBeTrue()
        ->and(Schema::hasTable('device_command_attempts'))->toBeTrue()
        ->and(Schema::hasTable('device_command_reconciliations'))->toBeTrue()
        ->and(Schema::hasTable('device_command_audit_events'))->toBeTrue()
        ->and(Schema::hasTable('device_command_intake_audits'))->toBeTrue();
});

it('retains allowed and denied intake evidence as immutable minimum-data records', function () {
    $request = commandContractRecord();
    $audit = DeviceCommandIntakeAudit::query()->create([
        'device_command_request_id' => $request->id,
        'actor_user_id' => $request->requested_by_user_id,
        'outcome' => 'allowed',
        'safe_reason_code' => 'request_created',
        'target_fingerprint' => hash('sha256', 'target'),
        'capability' => $request->capability,
        'capability_fingerprint' => hash('sha256', 'capability'),
        'occurred_at' => now(),
    ]);

    expect(fn () => $audit->update(['safe_reason_code' => 'changed']))
        ->toThrow(UnexpectedValueException::class, 'intake audit evidence is immutable')
        ->and(fn () => $audit->delete())
        ->toThrow(UnexpectedValueException::class, 'retained permanently');
});

it('encrypts execution parameters and hides signed contract material from serialization', function () {
    $request = commandContractRecord();
    $stored = DB::table('device_command_requests')->where('id', $request->id)->first();

    expect($request->encrypted_parameters)->toBe(['duration_seconds' => 15])
        ->and($stored->encrypted_parameters)->not->toContain('duration_seconds')
        ->and($request->toArray())->not->toHaveKeys([
            'assignment_fingerprint', 'encrypted_parameters', 'signing_key_id', 'signature', 'break_glass_reason',
            'break_glass_review_summary',
        ])
        ->and($request->safe_parameter_summary)->toBe(['duration_seconds' => 15]);
});

it('keeps the signed request identity immutable while allowing lifecycle progress', function () {
    $request = commandContractRecord();

    $request->status = CommandStatus::Ready;
    $request->save();
    expect($request->fresh()->status)->toBe(CommandStatus::Ready);

    $request->reason = 'A different reason after signing.';
    expect(fn () => $request->save())
        ->toThrow(UnexpectedValueException::class, 'The signed device command contract is immutable.');
    expect(fn () => $request->delete())
        ->toThrow(UnexpectedValueException::class, 'Device command requests are retained as immutable audit evidence.');
});

it('rejects impossible lifecycle transitions and any mutation of terminal history', function () {
    $request = commandContractRecord();
    $request->status = CommandStatus::Reconciled;
    expect(fn () => $request->save())
        ->toThrow(UnexpectedValueException::class, 'Invalid device command transition');

    $terminal = commandContractRecord(['status' => CommandStatus::Rejected]);
    $terminal->safe_failure_reason = 'Changed after rejection.';
    expect(fn () => $terminal->save())
        ->toThrow(UnexpectedValueException::class, 'Terminal device command history is immutable.');
});

it('enforces idempotency at the database boundary', function () {
    $first = commandContractRecord();

    expect(fn () => commandContractRecord([
        'device_id' => $first->device_id,
        'site_id' => $first->site_id,
        'requested_by_user_id' => $first->requested_by_user_id,
        'capability' => $first->capability,
        'idempotency_key' => $first->idempotency_key,
    ]))->toThrow(QueryException::class);

    expect(commandContractRecord(['idempotency_key' => $first->idempotency_key]))
        ->toBeInstanceOf(DeviceCommandRequest::class);
});

it('enforces one independent decision per request at the durable boundary', function () {
    $request = commandContractRecord();
    $firstApprover = User::factory()->create();
    $secondApprover = User::factory()->create();
    DeviceCommandApproval::query()->create([
        'device_command_request_id' => $request->id,
        'decided_by_user_id' => $firstApprover->id,
        'decision' => CommandApprovalDecision::Approved,
        'comment' => 'Identity and maintenance attendance confirmed.',
        'decided_at' => now(),
    ]);

    expect(fn () => DeviceCommandApproval::query()->create([
        'device_command_request_id' => $request->id,
        'decided_by_user_id' => $secondApprover->id,
        'decision' => CommandApprovalDecision::Rejected,
        'comment' => 'A second decision must never replace the first decision.',
        'decided_at' => now(),
    ]))->toThrow(QueryException::class);

    $selfApprovalRequest = commandContractRecord();
    expect(fn () => DeviceCommandApproval::query()->create([
        'device_command_request_id' => $selfApprovalRequest->id,
        'decided_by_user_id' => $selfApprovalRequest->requested_by_user_id,
        'decision' => CommandApprovalDecision::Approved,
        'comment' => 'The requester must not approve this request directly.',
        'decided_at' => now(),
    ]))->toThrow(UnexpectedValueException::class, 'requester cannot record');
});

it('retains approval, execution, reconciliation, and hash-chain evidence as immutable records', function () {
    $request = commandContractRecord();
    $approver = User::factory()->create();
    $approval = DeviceCommandApproval::query()->create([
        'device_command_request_id' => $request->id,
        'decided_by_user_id' => $approver->id,
        'decision' => CommandApprovalDecision::Approved,
        'comment' => 'Identity and maintenance attendance confirmed.',
        'decided_at' => now(),
    ]);
    $attempt = DeviceCommandAttempt::query()->create([
        'device_command_request_id' => $request->id,
        'attempt_number' => 1,
        'status' => CommandAttemptStatus::Succeeded,
        'runtime' => 'central',
        'safe_result_summary' => ['provider_state' => 'accepted'],
        'completed_at' => now(),
    ]);
    $reconciliation = DeviceCommandReconciliation::query()->create([
        'device_command_request_id' => $request->id,
        'device_command_attempt_id' => $attempt->id,
        'outcome' => CommandReconciliationOutcome::Matched,
        'expected_state' => ['locked' => true],
        'observed_state' => ['locked' => true],
        'observation_reference' => 'observation:door-state:123',
        'safe_evidence_summary' => 'Door returned to its confirmed locked state.',
        'observed_at' => now(),
    ]);
    $audit = DeviceCommandAuditEvent::query()->create([
        'device_command_request_id' => $request->id,
        'actor_user_id' => $approver->id,
        'action' => 'approved',
        'safe_context' => ['decision' => 'approved'],
        'previous_hash' => null,
        'event_hash' => hash('sha256', $request->command_uuid.':approved'),
        'occurred_at' => now(),
    ]);

    $attempt->status = CommandAttemptStatus::Uncertain;
    expect(fn () => $attempt->save())
        ->toThrow(UnexpectedValueException::class, 'Invalid device command attempt transition')
        ->and(fn () => $approval->update(['comment' => 'changed']))
        ->toThrow(UnexpectedValueException::class, 'Device command approval decisions are immutable.')
        ->and(fn () => $reconciliation->delete())
        ->toThrow(UnexpectedValueException::class, 'Device command reconciliations are immutable evidence.')
        ->and(fn () => $audit->delete())
        ->toThrow(UnexpectedValueException::class, 'Device command audit events are immutable.');
});

it('signs a canonical secret-free command contract and rejects tampering', function () {
    $signer = app(CommandRequestSigner::class);
    $expiresAt = CarbonImmutable::parse('2026-07-24T12:00:00Z');
    $parameters = ['duration_seconds' => 15, 'nested' => ['b' => 2, 'a' => 1]];
    $payload = new CommandSigningPayload(
        commandUuid: '00000000-0000-0000-0000-000000000123',
        deviceId: 41,
        siteId: 7,
        requestedByUserId: 5,
        capability: 'access.door.unlock_timed',
        capabilityVersion: 1,
        managementLevel: 'control',
        risk: 'high',
        idempotencyKey: 'door-41-20260724',
        parametersHash: $signer->parametersHash($parameters),
        reasonHash: $signer->reasonHash('Approved maintenance attendance.'),
        expectedState: ['locked' => true],
        reconciliationRule: 'door_locked_after_window',
        expiresAt: $expiresAt,
        itChangeId: 71,
        collectorId: null,
        isBreakGlass: false,
        provider: 'contract-door',
    );
    $signed = $signer->sign($payload);

    expect($signer->verify($payload, $signed['key_id'], $signed['signature']))->toBeTrue()
        ->and($payload->toArray())->not->toHaveKeys(['parameters', 'signature', 'secret', 'token']);

    $tampered = new CommandSigningPayload(
        commandUuid: $payload->commandUuid,
        deviceId: $payload->deviceId,
        siteId: 8,
        requestedByUserId: $payload->requestedByUserId,
        capability: $payload->capability,
        capabilityVersion: $payload->capabilityVersion,
        managementLevel: $payload->managementLevel,
        risk: $payload->risk,
        idempotencyKey: $payload->idempotencyKey,
        parametersHash: $payload->parametersHash,
        reasonHash: $payload->reasonHash,
        expectedState: $payload->expectedState,
        reconciliationRule: $payload->reconciliationRule,
        expiresAt: $payload->expiresAt,
        itChangeId: $payload->itChangeId,
        collectorId: $payload->collectorId,
        isBreakGlass: $payload->isBreakGlass,
        provider: $payload->provider,
    );
    $tamperedChange = new CommandSigningPayload(
        commandUuid: $payload->commandUuid,
        deviceId: $payload->deviceId,
        siteId: $payload->siteId,
        requestedByUserId: $payload->requestedByUserId,
        capability: $payload->capability,
        capabilityVersion: $payload->capabilityVersion,
        managementLevel: $payload->managementLevel,
        risk: $payload->risk,
        idempotencyKey: $payload->idempotencyKey,
        parametersHash: $payload->parametersHash,
        reasonHash: $payload->reasonHash,
        expectedState: $payload->expectedState,
        reconciliationRule: $payload->reconciliationRule,
        expiresAt: $payload->expiresAt,
        itChangeId: 72,
        collectorId: $payload->collectorId,
        isBreakGlass: $payload->isBreakGlass,
        provider: $payload->provider,
    );

    expect($signer->verify($tampered, $signed['key_id'], $signed['signature']))->toBeFalse()
        ->and($signer->verify($tamperedChange, $signed['key_id'], $signed['signature']))->toBeFalse();
});
