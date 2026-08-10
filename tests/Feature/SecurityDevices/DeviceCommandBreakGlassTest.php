<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CommandDispatchPort;
use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandReconciliationService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

final class BreakGlassDoorAdapter implements CommandExecutionAdapter
{
    /** @var list<CommandExecutionContext> */
    public array $executions = [];

    public function supports(Device $device, string $capability): bool
    {
        return $device->provider === 'break-glass-door'
            && $capability === 'access.door.unlock_timed';
    }

    public function execute(CommandExecutionContext $context): CommandExecutionResult
    {
        $this->executions[] = $context;

        return new CommandExecutionResult(
            status: CommandAttemptStatus::Succeeded,
            safeSummary: ['provider_state' => 'accepted'],
            providerRequestReference: 'break-glass-provider-request',
        );
    }

    public function observe(CommandExecutionContext $context): CommandObservedState
    {
        return new CommandObservedState(
            state: ['locked' => true],
            observedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            observationReference: 'break-glass-observation',
            safeEvidenceSummary: 'The door returned to its confirmed locked state.',
        );
    }
}

function breakGlassActor(Site $site, bool $commandAdmin = true): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    $actor->roles()->attach(Role::query()->where('name', 'coordinator')->firstOrFail());
    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);
    $keys = ['securityDevices.commands.control'];
    if ($commandAdmin) {
        $keys = [...$keys, 'securityDevices.commands.approve', 'securityDevices.commands.admin'];
    }
    foreach (Permission::query()->whereIn('key', $keys)->get() as $permission) {
        $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    }

    return $actor;
}

function breakGlassDoor(Site $site, array $overrides = []): Device
{
    $device = Device::factory()->security()->create(array_replace([
        'name' => 'Harbour emergency door',
        'category' => 'access_control',
        'subcategory' => 'door_controller',
        'provider' => 'break-glass-door',
        'config' => ['management' => ['capabilities' => ['access.door.unlock_timed']]],
    ], $overrides));
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now(),
    ]);

    return $device;
}

function declareBreakGlassCommand(
    Device $device,
    User $requester,
    User $reviewer,
    ?string $idempotencyKey = null,
    string $emergencyReason = 'An immediate safety risk requires emergency access before normal approval can be obtained.',
): DeviceCommandRequest {
    return app(DeviceCommandRequestService::class)->request(
        $device,
        $requester,
        new CommandRequestInput(
            capability: 'access.door.unlock_timed',
            parameters: ['duration_seconds' => 15],
            reason: 'Permit the verified response technician through the service entrance.',
            idempotencyKey: $idempotencyKey ?? 'break-glass-'.$device->id.'-'.Str::uuid(),
            stepUpConfirmedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            breakGlass: true,
            breakGlassReason: $emergencyReason,
            breakGlassReviewerUserId: $reviewer->id,
            impactAcknowledged: true,
        ),
    );
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'break-glass-test-key');
    config()->set('monitoring.signing.keys', [
        'break-glass-test-key' => base64_encode(str_repeat('B', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);
});

it('creates one short-lived signed declaration and immediately notifies the designated Site reviewer without the emergency narrative', function () {
    $site = Site::factory()->create(['name' => 'Harbour Site']);
    $requester = breakGlassActor($site);
    $reviewer = breakGlassActor($site);
    $device = breakGlassDoor($site);
    $emergencyReason = 'A person is trapped inside the secured area and emergency access is required immediately.';
    $idempotencyKey = 'break-glass-contract-'.$device->id;

    $command = declareBreakGlassCommand($device, $requester, $reviewer, $idempotencyKey, $emergencyReason);
    $notification = $reviewer->notifications()->sole();
    $stored = DB::table('device_command_requests')->where('id', $command->id)->first();

    expect($command->status)->toBe(CommandStatus::Ready)
        ->and($command->is_break_glass)->toBeTrue()
        ->and($command->break_glass_reviewer_user_id)->toBe($reviewer->id)
        ->and($command->break_glass_reason)->toBe($emergencyReason)
        ->and($command->expires_at->diffInSeconds($command->break_glass_declared_at))->toBeLessThanOrEqual(120)
        ->and($command->break_glass_review_due_at->greaterThan($command->expires_at))->toBeTrue()
        ->and($command->break_glass_notification_sent_at)->not->toBeNull()
        ->and($command->approvals()->count())->toBe(0)
        ->and($command->auditEvents()->orderBy('id')->pluck('action')->all())->toBe([
            'requested',
            'break_glass_declared',
            'break_glass_reviewer_notified',
        ])
        ->and($notification->data['type'])->toBe('security_devices_command_break_glass_declared')
        ->and($notification->data['action_url'])->toBe("/security-devices/devices/{$device->id}?section=management")
        ->and(json_encode($notification->data))->not->toContain($emergencyReason)
        ->and($stored->break_glass_reason)->not->toContain($emergencyReason)
        ->and($command->toArray())->not->toHaveKeys(['break_glass_reason', 'signature', 'signing_key_id']);

    $same = declareBreakGlassCommand($device, $requester, $reviewer, $idempotencyKey, $emergencyReason);
    expect($same->id)->toBe($command->id)
        ->and($reviewer->notifications()->count())->toBe(1)
        ->and(DeviceCommandRequest::query()->count())->toBe(1);
});

it('fails closed for unapproved break-glass capability, stale assurance, self review, or an inaccessible reviewer', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $requester = breakGlassActor($site);
    $reviewer = breakGlassActor($site);
    $outsideReviewer = breakGlassActor($otherSite);
    $device = breakGlassDoor($site);

    $requester->forceFill(['two_factor_confirmed_at' => null])->save();
    expect(fn () => declareBreakGlassCommand($device, $requester->fresh(), $reviewer))
        ->toThrow(ValidationException::class, 'Configured multi-factor authentication');
    $requester->forceFill(['two_factor_confirmed_at' => now()])->save();

    expect(fn () => declareBreakGlassCommand($device, $requester->fresh(), $requester->fresh()))
        ->toThrow(ValidationException::class, 'Choose a different current command administrator')
        ->and(fn () => declareBreakGlassCommand($device, $requester->fresh(), $outsideReviewer))
        ->toThrow(ValidationException::class, 'Choose a different current command administrator');

    $cctvPermission = Permission::query()
        ->where('key', 'securityDevices.cctv.media.view')
        ->firstOrFail();
    foreach ([$requester, $reviewer] as $cameraActor) {
        $cameraActor->permissionOverrides()->syncWithoutDetaching([
            $cctvPermission->id => ['allowed' => true],
        ]);
        $cameraActor->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }
    $camera = breakGlassDoor($site, [
        'category' => 'cctv',
        'subcategory' => 'camera',
        'config' => ['management' => ['capabilities' => ['camera.privacy.disable']]],
    ]);
    expect(fn () => app(DeviceCommandRequestService::class)->request(
        $camera,
        $requester->fresh(),
        new CommandRequestInput(
            capability: 'camera.privacy.disable',
            parameters: [],
            reason: 'Attempt an emergency bypass for a capability that forbids it.',
            idempotencyKey: 'forbidden-break-glass-'.$camera->id,
            stepUpConfirmedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            breakGlass: true,
            breakGlassReason: 'An emergency narrative that must not override the capability policy.',
            breakGlassReviewerUserId: $reviewer->id,
            impactAcknowledged: true,
            confirmationText: $camera->name,
        ),
    ))->toThrow(ValidationException::class, 'Break glass is not permitted');

    expect(DeviceCommandRequest::query()->count())->toBe(0)
        ->and($reviewer->notifications()->count())->toBe(0)
        ->and($outsideReviewer->notifications()->count())->toBe(0);
});

it('dispatches a valid declaration without self approval but blocks expiry, reviewer revocation, and signed-contract tampering', function () {
    $site = Site::factory()->create();
    $requester = breakGlassActor($site);
    $reviewer = breakGlassActor($site);
    $device = breakGlassDoor($site);
    $adapter = new BreakGlassDoorAdapter;
    app()->instance(CommandExecutionAdapterRegistry::class, new CommandExecutionAdapterRegistry([$adapter]));

    $command = declareBreakGlassCommand($device, $requester, $reviewer);
    $this->actingAs($requester)
        ->get("/security-devices/devices/{$device->id}?section=management")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.management.history.0.id', $command->id)
            ->where('profile.management.history.0.canDispatch', true));
    $attempt = app(CommandDispatchPort::class)->dispatch($command, $requester);
    expect($attempt->status)->toBe(CommandAttemptStatus::Succeeded)
        ->and($adapter->executions)->toHaveCount(1)
        ->and($command->fresh()->status)->toBe(CommandStatus::Reconciling)
        ->and($command->approvals()->count())->toBe(0);

    $revokedReviewer = breakGlassActor($site);
    $revoked = declareBreakGlassCommand($device, $requester, $revokedReviewer);
    $adminPermission = Permission::query()->where('key', 'securityDevices.commands.admin')->firstOrFail();
    $revokedReviewer->permissionOverrides()->updateExistingPivot($adminPermission->id, ['allowed' => false]);
    expect(fn () => app(CommandDispatchPort::class)->dispatch($revoked->fresh(), $requester))
        ->toThrow(ValidationException::class, 'Break-glass governance is no longer complete')
        ->and($revoked->attempts()->count())->toBe(0);

    $revokedRequester = breakGlassActor($site);
    $revokedRequesterReviewer = breakGlassActor($site);
    $requesterRevoked = declareBreakGlassCommand($device, $revokedRequester, $revokedRequesterReviewer);
    $revokedRequester->permissionOverrides()->updateExistingPivot($adminPermission->id, ['allowed' => false]);
    expect(fn () => app(CommandDispatchPort::class)->dispatch($requesterRevoked->fresh(), $revokedRequester))
        ->toThrow(ValidationException::class, 'Break-glass governance is no longer complete')
        ->and($requesterRevoked->attempts()->count())->toBe(0);

    $expiredReviewer = breakGlassActor($site);
    $expired = declareBreakGlassCommand($device, $requester, $expiredReviewer);
    $this->travel(121)->seconds();
    expect(fn () => app(CommandDispatchPort::class)->dispatch($expired->fresh(), $requester))
        ->toThrow(ValidationException::class, 'expired before dispatch')
        ->and($expired->attempts()->count())->toBe(0);
    $this->travelBack();

    $tamperReviewer = breakGlassActor($site);
    $tampered = declareBreakGlassCommand($device, $requester, $tamperReviewer);
    DB::table('device_command_requests')->where('id', $tampered->id)->update([
        'break_glass_reason' => encrypt('A different encrypted emergency declaration inserted after signing.'),
    ]);
    expect(fn () => app(CommandDispatchPort::class)->dispatch($tampered->fresh(), $requester))
        ->toThrow(ValidationException::class, 'signed command contract could not be verified')
        ->and($tampered->attempts()->count())->toBe(0);
});

it('allows only the designated Site reviewer to record one permanent post-use review after reconciliation', function () {
    $site = Site::factory()->create();
    $requester = breakGlassActor($site);
    $reviewer = breakGlassActor($site);
    $otherReviewer = breakGlassActor($site);
    $device = breakGlassDoor($site);
    $adapter = new BreakGlassDoorAdapter;
    app()->instance(CommandExecutionAdapterRegistry::class, new CommandExecutionAdapterRegistry([$adapter]));
    $command = declareBreakGlassCommand($device, $requester, $reviewer);

    $this->actingAs($reviewer)
        ->post("/security-devices/commands/{$command->id}/break-glass-review", [
            'outcome' => 'confirmed_appropriate',
            'summary' => 'Attempt to review before any execution has completed.',
        ])
        ->assertSessionHasErrors('outcome');

    app(CommandDispatchPort::class)->dispatch($command->fresh(), $requester);
    app(DeviceCommandReconciliationService::class)->reconcile($command->fresh(), $requester);

    foreach ([$requester, $otherReviewer] as $wrongReviewer) {
        $this->actingAs($wrongReviewer)
            ->post("/security-devices/commands/{$command->id}/break-glass-review", [
                'outcome' => 'confirmed_appropriate',
                'summary' => 'This actor is not the designated independent reviewer.',
            ])
            ->assertNotFound();
    }

    $summary = 'The safety emergency and bounded unlock were appropriate; no further action is required.';
    $this->actingAs($reviewer)
        ->post("/security-devices/commands/{$command->id}/break-glass-review", [
            'outcome' => 'confirmed_appropriate',
            'summary' => $summary,
        ])
        ->assertRedirect();

    $reviewed = $command->fresh();
    $stored = DB::table('device_command_requests')->where('id', $command->id)->first();
    expect($reviewed->status)->toBe(CommandStatus::Reconciled)
        ->and($reviewed->break_glass_reviewed_by_user_id)->toBe($reviewer->id)
        ->and($reviewed->break_glass_review_outcome->value)->toBe('confirmed_appropriate')
        ->and($reviewed->break_glass_review_summary)->toBe($summary)
        ->and($reviewed->break_glass_reviewed_at)->not->toBeNull()
        ->and($stored->break_glass_review_summary)->not->toContain($summary)
        ->and($reviewed->auditEvents()->where('action', 'break_glass_post_use_reviewed')->count())->toBe(1)
        ->and(json_encode($reviewed->auditEvents()->latest('id')->firstOrFail()->safe_context))->not->toContain($summary);

    $this->actingAs($reviewer)
        ->post("/security-devices/commands/{$command->id}/break-glass-review", [
            'outcome' => 'follow_up_required',
            'summary' => 'A second review must never overwrite permanent evidence.',
        ])
        ->assertSessionHasErrors('outcome');
    expect($command->fresh()->break_glass_review_outcome->value)->toBe('confirmed_appropriate');
});

it('conceals the emergency narrative and review summary from a non-admin observer at the same Site', function () {
    $site = Site::factory()->create();
    $requester = breakGlassActor($site);
    $reviewer = breakGlassActor($site);
    $observer = breakGlassActor($site, false);
    $device = breakGlassDoor($site);
    $emergencyReason = 'A confidential emergency narrative visible only to the governed participants.';
    declareBreakGlassCommand($device, $requester, $reviewer, null, $emergencyReason);

    $this->actingAs($observer)
        ->get("/security-devices/devices/{$device->id}?section=management")
        ->assertOk()
        ->assertDontSee($emergencyReason)
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.management.history.0.isBreakGlass', true)
            ->where('profile.management.history.0.breakGlass.emergencyReason', null)
            ->where('profile.management.history.0.canReviewBreakGlass', false));
});
