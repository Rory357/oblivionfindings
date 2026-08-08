<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredential;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredentialDeviceBindingEvent;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredentialLifecycleEvent;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlSchedule;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlScheduleRevision;
use App\Domain\SecurityDevices\AccessControl\Services\AccessControlCredentialTransitionService;
use App\Domain\SecurityDevices\AccessControl\Services\AccessControlLifecycleService;
use App\Domain\SecurityDevices\AccessControl\Services\AccessControlScheduleTransitionService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class AccessControlWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, SecurityDevicesPermissionsSeeder::class]);

        $this->admin = $this->userWithRole('admin');
    }

    public function test_manager_cannot_claim_issue_or_revocation_without_an_executable_provider_adapter(): void
    {
        $site = Site::factory()->create(['name' => 'Harbour House']);
        $holder = User::factory()->create(['name' => 'Taylor Worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $holder->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $reader = $this->accessDeviceAt($site, 'Staff entrance reader');

        $this->actingAs($this->admin)->post('/security-devices/access-control/schedules', [
            'site_id' => $site->id,
            'name' => 'Weekday staff access',
            'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
        ])->assertRedirect();

        $schedule = AccessControlSchedule::query()->firstOrFail();
        $this->actingAs($this->admin)->post('/security-devices/access-control/credentials', [
            'site_id' => $site->id,
            'access_schedule_id' => $schedule->id,
            'label' => 'Taylor weekday badge',
            'holder_type' => 'staff',
            'holder_id' => $holder->id,
            'reference_key' => 'unifi:credential/taylor-001',
            'device_ids' => [$reader->id],
            'valid_from' => now()->startOfDay()->toIso8601String(),
            'valid_until' => now()->addYear()->toIso8601String(),
        ])->assertSessionHasErrors('provider_action');

        $this->assertSame(0, AccessControlCredential::query()->count());
        $this->assertFalse(Schema::hasColumn('access_control_credentials', 'card_number'));
        $this->assertFalse(Schema::hasColumn('access_control_credentials', 'pin'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access_control.schedule.created',
            'auditable_type' => AccessControlSchedule::class,
            'auditable_id' => $schedule->id,
        ]);
        $this->assertDatabaseHas('access_control_schedule_revisions', [
            'access_schedule_id' => $schedule->id,
            'version' => 1,
            'action' => 'created',
            'active_credentials_affected' => 0,
        ]);
        $this->assertSame(0, AuditLog::query()
            ->whereIn('action', ['access_control.credential.issued', 'access_control.credential.issue_requested'])
            ->count());

        $credential = $this->providerConfirmedCredential(
            $site,
            $schedule,
            $holder,
            $reader,
            'Taylor weekday badge',
            'unifi:credential/taylor-001',
        );

        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=access-control')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('securityWorkspace.activeTab.accessControl.restricted', false)
                ->where('securityWorkspace.activeTab.accessControl.summary.activeCredentials', 1)
                ->where('securityWorkspace.activeTab.accessControl.summary.activeSchedules', 0)
                ->where('securityWorkspace.activeTab.accessControl.summary.coveredDoors', 1)
                ->where('securityWorkspace.activeTab.accessControl.schedules.0.version', 1)
                ->where('securityWorkspace.activeTab.accessControl.schedules.0.impact.activeCredentials', 1)
                ->where('securityWorkspace.activeTab.accessControl.schedules.0.impact.updateConfirmation', 'UPDATE 1')
                ->where('securityWorkspace.activeTab.accessControl.schedules.0.providerReconciliation.status', 'required')
                ->where('securityWorkspace.activeTab.accessControl.schedules.0.revisionHistory.0.version', 1)
                ->where('securityWorkspace.activeTab.accessControl.schedules.0.revisionHistory.0.action', 'created')
                ->where('securityWorkspace.activeTab.accessControl.credentials.0.holderLabel', 'Taylor Worker')
                ->where('securityWorkspace.activeTab.accessControl.credentials.0.referenceKey', 'unifi:credential/taylor-001')
                ->where('securityWorkspace.activeTab.accessControl.credentials.0.providerLifecycle.state', 'active')
                ->where('securityWorkspace.activeTab.accessControl.credentials.0.providerLifecycle.label', 'Active — provider confirmed')
                ->where('securityWorkspace.activeTab.accessControl.providerActions.issue.available', false)
                ->where('securityWorkspace.activeTab.accessControl.providerActions.revoke.available', false));

        $this->actingAs($this->admin)->post(
            "/security-devices/access-control/credentials/{$credential->id}/revoke",
            ['reason' => 'Employment ended'],
        )->assertSessionHasErrors('provider_action');

        $this->assertDatabaseHas('access_control_credentials', [
            'id' => $credential->id,
            'status' => AccessControlCredential::STATUS_ACTIVE,
            'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_RECONCILED,
            'revocation_reason' => null,
            'revoked_by_user_id' => null,
        ]);
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'access_control.credential.revoked')
            ->where('auditable_id', $credential->id)
            ->count());
    }

    public function test_manager_versions_schedule_changes_and_reasoned_deactivation_after_exact_impact_confirmation(): void
    {
        $site = Site::factory()->create();
        $holder = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $holder->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $reader = $this->accessDeviceAt($site, 'Main access reader');

        $this->actingAs($this->admin)->post('/security-devices/access-control/schedules', [
            'site_id' => $site->id,
            'name' => 'Standard hours',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
        ])->assertRedirect();
        $schedule = AccessControlSchedule::query()->firstOrFail();
        $this->providerConfirmedCredential(
            $site,
            $schedule,
            $holder,
            $reader,
            'Main badge',
            'unifi:credential/main-001',
        );

        $update = [
            'expected_version' => 1,
            'name' => 'Extended hours',
            'days' => ['monday', 'tuesday'],
            'starts_at' => '07:00',
            'ends_at' => '19:00',
            'reason' => 'Approved operating-hours change',
            'confirmed_active_credentials' => 1,
            'confirmation_text' => 'UPDATE 0',
        ];
        $this->actingAs($this->admin)
            ->put("/security-devices/access-control/schedules/{$schedule->id}", $update)
            ->assertSessionHasErrors('confirmation_text');
        $this->assertSame(1, $schedule->fresh()->version);

        $this->actingAs($this->admin)
            ->put("/security-devices/access-control/schedules/{$schedule->id}", [
                ...$update,
                'confirmation_text' => 'UPDATE 1',
            ])->assertRedirect();

        $schedule->refresh();
        $this->assertSame(2, $schedule->version);
        $this->assertSame('Extended hours', $schedule->name);
        $this->assertSame('required', $schedule->provider_reconciliation_status);
        $this->assertNotNull($schedule->provider_reconciliation_required_at);
        $this->assertDatabaseHas('access_control_schedule_revisions', [
            'access_schedule_id' => $schedule->id,
            'version' => 2,
            'action' => 'updated',
            'change_reason' => 'Approved operating-hours change',
            'active_credentials_affected' => 1,
            'provider_confirmed_credentials_affected' => 1,
        ]);

        $this->actingAs($this->admin)
            ->put("/security-devices/access-control/schedules/{$schedule->id}", [
                ...$update,
                'confirmation_text' => 'UPDATE 1',
            ])->assertSessionHasErrors('expected_version');
        $this->assertSame(2, $schedule->fresh()->version);

        $this->actingAs($this->admin)
            ->post("/security-devices/access-control/schedules/{$schedule->id}/deactivate", [
                'expected_version' => 2,
                'reason' => 'Superseded by the approved seasonal schedule',
                'confirmed_active_credentials' => 1,
                'confirmation_text' => 'DEACTIVATE 1',
            ])->assertRedirect();

        $schedule->refresh();
        $this->assertFalse($schedule->is_active);
        $this->assertSame(3, $schedule->version);
        $this->assertSame('Superseded by the approved seasonal schedule', $schedule->deactivation_reason);
        $this->assertSame($this->admin->id, $schedule->deactivated_by_user_id);
        $this->assertSame(AccessControlCredential::STATUS_ACTIVE, AccessControlCredential::query()->firstOrFail()->status);
        $this->assertDatabaseHas('access_control_schedule_revisions', [
            'access_schedule_id' => $schedule->id,
            'version' => 3,
            'action' => 'deactivated',
            'active_credentials_affected' => 1,
            'provider_confirmed_credentials_affected' => 1,
        ]);
        $this->assertSame(3, AccessControlScheduleRevision::query()->where('access_schedule_id', $schedule->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access_control.schedule.deactivated',
            'auditable_type' => AccessControlSchedule::class,
            'auditable_id' => $schedule->id,
        ]);
        $this->actingAs($this->admin)->post('/security-devices/access-control/credentials', [
            'site_id' => $site->id,
            'access_schedule_id' => $schedule->id,
            'label' => 'Credential after deactivation',
            'holder_type' => 'staff',
            'holder_id' => $holder->id,
            'reference_key' => 'unifi:credential/after-deactivation',
            'device_ids' => [$reader->id],
        ])->assertNotFound();
        $this->assertSame(1, AccessControlCredential::query()->count());
    }

    public function test_workspace_counts_only_provider_confirmed_access_and_exposes_unconfirmed_and_failed_states(): void
    {
        $site = Site::factory()->create(['name' => 'Lifecycle Site']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->admin->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $schedule = AccessControlSchedule::query()->create([
            'site_id' => $site->id,
            'name' => 'Lifecycle schedule',
            'timezone' => 'Pacific/Auckland',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
            'is_active' => true,
            'provider_reconciliation_status' => 'required',
            'provider_reconciliation_required_at' => now(),
        ]);
        $device = $this->accessDeviceAt($site, 'Lifecycle reader');

        $pending = $this->pendingCredential($site, $schedule, 'Lifecycle pending', 'unifi:credential/lifecycle-0');
        $transitionService = app(AccessControlCredentialTransitionService::class);
        $transitionService->recordProviderTransition(
            $this->admin,
            $pending,
            'provider_issue_pending',
            [
                'status' => AccessControlCredential::STATUS_PENDING_ISSUE,
                'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_PENDING,
            ],
            'test.lifecycle.pending.request',
            'test.lifecycle.pending.event',
            [$device->id],
        );

        $failed = $this->pendingCredential($site, $schedule, 'Lifecycle failed', 'unifi:credential/lifecycle-1');
        $transitionService->recordProviderTransition(
            $this->admin,
            $failed,
            'provider_issue_pending',
            [
                'status' => AccessControlCredential::STATUS_PENDING_ISSUE,
                'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_PENDING,
            ],
            'test.lifecycle.failed.request',
            'test.lifecycle.failed.pending',
            [$device->id],
        );
        $transitionService->recordProviderTransition(
            $this->admin,
            $failed->refresh(),
            'provider_issue_failed',
            [
                'status' => AccessControlCredential::STATUS_ISSUE_FAILED,
                'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_FAILED,
                'provider_reconciliation_failure_reason' => 'Provider rejected the request.',
            ],
            'test.lifecycle.failed.request',
            'test.lifecycle.failed.event',
            [$device->id],
        );

        $this->providerConfirmedCredential(
            $site,
            $schedule,
            $this->admin,
            $device,
            'Lifecycle active',
            'unifi:credential/lifecycle-2',
        );

        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=access-control')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('securityWorkspace.activeTab.accessControl.summary.activeCredentials', 1)
                ->where('securityWorkspace.activeTab.accessControl.summary.coveredDoors', 1)
                ->where('securityWorkspace.activeTab.accessControl.schedules.0.impact.activeCredentials', 1)
                ->where('securityWorkspace.activeTab.accessControl.credentials.0.providerLifecycle.state', 'active')
                ->where('securityWorkspace.activeTab.accessControl.credentials.1.providerLifecycle.state', 'failed')
                ->where('securityWorkspace.activeTab.accessControl.credentials.1.providerLifecycle.failureReason', 'Provider rejected the request.')
                ->where('securityWorkspace.activeTab.accessControl.credentials.2.providerLifecycle.state', 'pending'));
    }

    public function test_site_scoped_manager_cannot_use_or_revoke_other_site_records(): void
    {
        $allowedSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $manager = $this->userWithRole('facilities_manager');
        HrEmployeeProfile::factory()->create([
            'user_id' => $manager->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $holder = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $holder->id,
            'primary_site_id' => $otherSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $otherDevice = $this->accessDeviceAt($otherSite, 'Other Site reader');
        $schedule = AccessControlSchedule::query()->create([
            'site_id' => $otherSite->id,
            'name' => 'Other schedule',
            'timezone' => 'Pacific/Auckland',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
            'is_active' => true,
        ]);
        $credential = $this->providerConfirmedCredential(
            $otherSite,
            $schedule,
            $holder,
            $otherDevice,
            'Other credential',
            'unifi:credential/other-001',
        );

        $this->actingAs($manager)->post(
            "/security-devices/access-control/credentials/{$credential->id}/revoke",
            ['reason' => 'Attempted cross-Site access'],
        )->assertNotFound();

        $this->actingAs($manager)->put(
            "/security-devices/access-control/schedules/{$schedule->id}",
            [
                'expected_version' => 1,
                'name' => 'Hidden update',
                'days' => ['monday'],
                'starts_at' => '08:00',
                'ends_at' => '18:00',
                'reason' => 'Attempted cross-Site schedule update',
                'confirmed_active_credentials' => 1,
                'confirmation_text' => 'UPDATE 1',
            ],
        )->assertNotFound();

        $this->actingAs($manager)->post(
            "/security-devices/access-control/schedules/{$schedule->id}/deactivate",
            [
                'expected_version' => 1,
                'reason' => 'Attempted cross-Site schedule deactivation',
                'confirmed_active_credentials' => 1,
                'confirmation_text' => 'DEACTIVATE 1',
            ],
        )->assertNotFound();

        $this->actingAs($manager)->post('/security-devices/access-control/credentials', [
            'site_id' => $allowedSite->id,
            'access_schedule_id' => $schedule->id,
            'label' => 'Invalid cross-Site credential',
            'holder_type' => 'staff',
            'holder_id' => $holder->id,
            'reference_key' => 'unifi:credential/invalid-001',
            'device_ids' => [$otherDevice->id],
        ])->assertNotFound();

        $this->assertSame('active', $credential->fresh()->status);
        $this->assertTrue($schedule->fresh()->is_active);
        $this->assertSame(1, $schedule->fresh()->version);
    }

    public function test_general_device_view_does_not_reveal_or_mutate_physical_access_records(): void
    {
        $site = Site::factory()->create();
        $worker = $this->userWithRole('support_worker');
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $this->accessDeviceAt($site, 'Visible reader');

        $this->actingAs($worker)
            ->get('/security-devices/security?tab=access-control')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('securityWorkspace.activeTab.inventoryTotal', 1)
                ->where('securityWorkspace.activeTab.accessControl.restricted', true)
                ->where('securityWorkspace.activeTab.accessControl.credentials', [])
                ->where('securityWorkspace.activeTab.accessControl.history', []));

        $this->actingAs($worker)->post('/security-devices/access-control/schedules', [
            'site_id' => $site->id,
            'name' => 'Unauthorised schedule',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
        ])->assertForbidden();
    }

    public function test_schedule_and_credential_lifecycle_evidence_cannot_be_changed_or_deleted(): void
    {
        $site = Site::factory()->create();
        $this->actingAs($this->admin)->post('/security-devices/access-control/schedules', [
            'site_id' => $site->id,
            'name' => 'Immutable history schedule',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
        ])->assertRedirect();

        $revision = AccessControlScheduleRevision::query()->firstOrFail();
        $credential = AccessControlCredential::query()->create([
            'site_id' => $site->id,
            'access_schedule_id' => $revision->access_schedule_id,
            'label' => 'Retained credential history',
            'holder_type' => AccessControlCredential::HOLDER_STAFF,
            'holder_id' => $this->admin->id,
            'reference_key' => 'unifi:credential/retained-history',
            'status' => AccessControlCredential::STATUS_PENDING_REVOKE,
            'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_REQUIRED,
            'provider_reconciliation_action' => 'revoke',
        ]);
        $legacyRevokedAt = now()->subDay();
        $event = $credential->lifecycleEvents()->create([
            'site_id' => $site->id,
            'sequence' => 1,
            'event_type' => 'legacy_local_state_snapshot',
            'evidence_kind' => 'unconfirmed_local_claim',
            'provider_action' => 'revoke',
            'provider_confirmed' => false,
            'occurred_at' => $legacyRevokedAt,
            'recorded_by_user_id' => $this->admin->id,
            'legacy_revoked_at' => $legacyRevokedAt,
            'legacy_revoked_by_user_id' => $this->admin->id,
            'legacy_revocation_reason' => 'Legacy local revocation claim.',
            'credential_snapshot' => $credential->attributesToArray(),
            'created_at' => now(),
        ]);
        $updateBlocked = false;
        try {
            $revision->change_reason = 'Rewritten history';
            $revision->save();
        } catch (\UnexpectedValueException $exception) {
            $updateBlocked = str_contains($exception->getMessage(), 'immutable');
        }
        $this->assertTrue($updateBlocked);

        $deleteBlocked = false;
        try {
            $revision->refresh()->delete();
        } catch (\UnexpectedValueException $exception) {
            $deleteBlocked = str_contains($exception->getMessage(), 'immutable');
        }
        $this->assertTrue($deleteBlocked);
        $this->assertDatabaseHas('access_control_schedule_revisions', ['id' => $revision->id]);

        $scheduleDeleteBlocked = false;
        try {
            AccessControlSchedule::query()->firstOrFail()->delete();
        } catch (\UnexpectedValueException $exception) {
            $scheduleDeleteBlocked = str_contains($exception->getMessage(), 'cannot be hard deleted');
        }
        $this->assertTrue($scheduleDeleteBlocked);
        $this->assertSame(1, AccessControlSchedule::query()->count());

        $scheduleUpdateBlocked = false;
        try {
            $schedule = AccessControlSchedule::query()->firstOrFail();
            $schedule->name = 'Rewritten without revision';
            $schedule->save();
        } catch (\UnexpectedValueException $exception) {
            $scheduleUpdateBlocked = str_contains($exception->getMessage(), 'evidence is governed');
        }
        $this->assertTrue($scheduleUpdateBlocked);

        $credentialUpdateBlocked = false;
        try {
            $credential->status = AccessControlCredential::STATUS_REVOKED;
            $credential->save();
        } catch (\UnexpectedValueException $exception) {
            $credentialUpdateBlocked = str_contains($exception->getMessage(), 'lifecycle evidence is immutable');
        }
        $this->assertTrue($credentialUpdateBlocked);

        $transitionService = app(AccessControlCredentialTransitionService::class);
        $invalidTransitionBlocked = false;
        try {
            $transitionService->recordProviderTransition(
                $this->admin,
                $credential->refresh(),
                'invalid_provider_state',
                [
                    'status' => AccessControlCredential::STATUS_PENDING_REVOKE,
                    'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_RECONCILED,
                ],
                'test.invalid.request',
                'test.invalid.event',
            );
        } catch (\UnexpectedValueException $exception) {
            $invalidTransitionBlocked = str_contains($exception->getMessage(), 'stale, reversed');
        }
        $this->assertTrue($invalidTransitionBlocked);
        $this->assertSame(1, $credential->lifecycleEvents()->count());

        $reader = $this->accessDeviceAt($site, 'Immutable evidence reader');
        $confirmed = $this->providerConfirmedCredential(
            $site,
            AccessControlSchedule::query()->firstOrFail(),
            $this->admin,
            $reader,
            'Governed provider credential',
            'unifi:credential/governed-provider',
        );
        $transitionService->recordProviderTransition(
            $this->admin,
            $confirmed,
            'provider_revocation_pending',
            [
                'status' => AccessControlCredential::STATUS_PENDING_REVOKE,
                'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_PENDING,
            ],
            'test.revoke.request.'.$confirmed->id,
            'test.revoke.pending.'.$confirmed->id,
            [$reader->id],
        );
        $confirmed = $transitionService->recordProviderTransition(
            $this->admin,
            $confirmed->refresh(),
            'provider_revocation_confirmed',
            [
                'status' => AccessControlCredential::STATUS_REVOKED,
                'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_RECONCILED,
                'revocation_reason' => 'Provider evidence confirmed revocation.',
            ],
            'test.revoke.request.'.$confirmed->id,
            'test.revoke.confirmed.'.$confirmed->id,
            [$reader->id],
        );
        $this->assertSame(4, $confirmed->lifecycleEvents()->count());
        $this->assertSame(AccessControlCredential::STATUS_REVOKED, $confirmed->status);
        $this->assertSame(
            AccessControlCredentialDeviceBindingEvent::STATUS_REMOVED,
            $confirmed->bindingEvents()->latest('sequence')->firstOrFail()->binding_status,
        );
        $bindingEvent = $confirmed->bindingEvents()->firstOrFail();
        $bindingUpdateBlocked = false;
        try {
            $bindingEvent->binding_status = AccessControlCredentialDeviceBindingEvent::STATUS_REMOVED;
            $bindingEvent->save();
        } catch (\UnexpectedValueException $exception) {
            $bindingUpdateBlocked = str_contains($exception->getMessage(), 'immutable');
        }
        $this->assertTrue($bindingUpdateBlocked);

        $bindingDeleteBlocked = false;
        try {
            $bindingEvent->refresh()->delete();
        } catch (\UnexpectedValueException $exception) {
            $bindingDeleteBlocked = str_contains($exception->getMessage(), 'immutable provider evidence');
        }
        $this->assertTrue($bindingDeleteBlocked);

        $eventUpdateBlocked = false;
        try {
            $event->event_type = 'rewritten_history';
            $event->save();
        } catch (\UnexpectedValueException $exception) {
            $eventUpdateBlocked = str_contains($exception->getMessage(), 'immutable');
        }
        $this->assertTrue($eventUpdateBlocked);

        $eventDeleteBlocked = false;
        try {
            $event->refresh()->delete();
        } catch (\UnexpectedValueException $exception) {
            $eventDeleteBlocked = str_contains($exception->getMessage(), 'immutable evidence');
        }
        $this->assertTrue($eventDeleteBlocked);

        $credentialDeleteBlocked = false;
        try {
            $credential->delete();
        } catch (\UnexpectedValueException $exception) {
            $credentialDeleteBlocked = str_contains($exception->getMessage(), 'cannot be hard deleted');
        }
        $this->assertTrue($credentialDeleteBlocked);
        $this->assertDatabaseHas('access_control_credentials', ['id' => $credential->id]);
    }

    public function test_credential_projection_rechecks_current_device_site_visibility(): void
    {
        $allowedSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $manager = $this->userWithRole('facilities_manager');
        HrEmployeeProfile::factory()->create([
            'user_id' => $manager->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $device = $this->accessDeviceAt($allowedSite, 'Reader moved elsewhere');
        $schedule = AccessControlSchedule::query()->create([
            'site_id' => $allowedSite->id,
            'name' => 'Current schedule',
            'timezone' => 'Pacific/Auckland',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
            'is_active' => true,
        ]);
        $credential = $this->providerConfirmedCredential(
            $allowedSite,
            $schedule,
            $manager,
            $device,
            'Credential needing review',
            'unifi:credential/review-001',
        );

        $device->assignments()->active()->update(['released_at' => now()]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $otherSite->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get('/security-devices/security?tab=access-control')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('securityWorkspace.activeTab.accessControl.summary.activeCredentials', 0)
                ->where('securityWorkspace.activeTab.accessControl.summary.coveredDoors', 0)
                ->where('securityWorkspace.activeTab.accessControl.credentials.0.id', $credential->id)
                ->where('securityWorkspace.activeTab.accessControl.credentials.0.providerLifecycle.label', 'Provider evidence inconsistent')
                ->where('securityWorkspace.activeTab.accessControl.credentials.0.devices', [])
                ->where('securityWorkspace.activeTab.accessControl.deviceOptions', []));

        // Even a role that can see both Sites must not project the moved reader as
        // provider-confirmed coverage for the credential's original Site.
        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=access-control')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('securityWorkspace.activeTab.accessControl.summary.activeCredentials', 0)
                ->where('securityWorkspace.activeTab.accessControl.summary.coveredDoors', 0)
                ->where('securityWorkspace.activeTab.accessControl.credentials.0.devices', [])
                ->where('securityWorkspace.activeTab.accessControl.deviceOptions.0.id', $device->id));
    }

    public function test_truthfulness_migration_preserves_legacy_revocation_as_an_unconfirmed_immutable_snapshot(): void
    {
        $site = Site::factory()->create();
        $schedule = AccessControlSchedule::query()->create([
            'site_id' => $site->id,
            'name' => 'Legacy schedule',
            'timezone' => 'Pacific/Auckland',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
            'is_active' => true,
        ]);
        $legacyRevokedAt = now()->subDays(3);
        $credential = AccessControlCredential::withoutEvents(fn (): AccessControlCredential => AccessControlCredential::query()->create([
            'site_id' => $site->id,
            'access_schedule_id' => $schedule->id,
            'label' => 'Legacy revoked badge',
            'holder_type' => AccessControlCredential::HOLDER_STAFF,
            'holder_id' => $this->admin->id,
            'reference_key' => 'unifi:credential/legacy-revoked',
            'status' => AccessControlCredential::STATUS_REVOKED,
            'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_REQUIRED,
            'provider_reconciliation_action' => AccessControlCredential::PROVIDER_ACTION_ISSUE,
            'revoked_at' => $legacyRevokedAt,
            'revoked_by_user_id' => $this->admin->id,
            'revocation_reason' => 'Legacy local decision.',
        ]));
        $migration = $this->credentialTruthfulnessMigration();

        $this->invokeMigrationMethod($migration, 'snapshotLegacyCredentials');
        $this->invokeMigrationMethod($migration, 'relabelLegacyLocalClaims');

        $event = AccessControlCredentialLifecycleEvent::query()->firstOrFail();
        $this->assertSame('legacy_local_state_snapshot', $event->event_type);
        $this->assertSame('unconfirmed_local_claim', $event->evidence_kind);
        $this->assertFalse($event->provider_confirmed);
        $this->assertSame($this->admin->id, $event->legacy_revoked_by_user_id);
        $this->assertSame('Legacy local decision.', $event->legacy_revocation_reason);
        $this->assertSame(AccessControlCredential::STATUS_REVOKED, $event->credential_snapshot['status']);
        $this->assertSame($legacyRevokedAt->toDateTimeString(), $event->legacy_revoked_at?->toDateTimeString());

        $credential->refresh();
        $this->assertSame(AccessControlCredential::STATUS_PENDING_REVOKE, $credential->status);
        $this->assertSame(AccessControlCredential::PROVIDER_ACTION_REVOKE, $credential->provider_reconciliation_action);
        $this->assertNull($credential->revoked_at);
        $this->assertNull($credential->revoked_by_user_id);
        $this->assertNull($credential->revocation_reason);

        $rollbackBlocked = false;
        try {
            $migration->down();
        } catch (\RuntimeException $exception) {
            $rollbackBlocked = str_contains($exception->getMessage(), 'lifecycle event evidence exists');
        }
        $this->assertTrue($rollbackBlocked);
    }

    public function test_access_control_migrations_refuse_destructive_rollbacks_while_evidence_exists(): void
    {
        $site = Site::factory()->create();
        $schedule = AccessControlSchedule::query()->create([
            'site_id' => $site->id,
            'name' => 'Rollback evidence schedule',
            'timezone' => 'Pacific/Auckland',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
            'is_active' => true,
        ]);
        AccessControlScheduleRevision::query()->create([
            'access_schedule_id' => $schedule->id,
            'site_id' => $site->id,
            'version' => 1,
            'action' => 'created',
            'snapshot' => [
                'site_id' => $site->id,
                'name' => $schedule->name,
                'provider_reconciliation_status' => 'required',
            ],
            'change_reason' => 'Rollback evidence.',
            'active_credentials_affected' => 0,
            'recorded_by_user_id' => $this->admin->id,
            'created_at' => now(),
        ]);
        $reader = $this->accessDeviceAt($site, 'Rollback evidence reader');
        $this->providerConfirmedCredential(
            $site,
            $schedule,
            $this->admin,
            $reader,
            'Rollback evidence credential',
            'unifi:credential/rollback-evidence',
        );

        foreach ([
            '2026_08_05_000033_create_access_control_operations.php',
            '2026_08_06_000037_add_access_control_schedule_lifecycle.php',
            '2026_08_06_000039_make_access_control_credential_lifecycle_truthful.php',
            '2026_08_06_000045_govern_access_control_provider_evidence.php',
        ] as $migrationFile) {
            $migration = require database_path('migrations/'.$migrationFile);
            $blocked = false;
            try {
                $migration->down();
            } catch (\RuntimeException) {
                $blocked = true;
            }
            $this->assertTrue($blocked, $migrationFile.' must fail closed while evidence exists.');
        }
    }

    public function test_provider_evidence_migration_retains_legacy_device_links_without_claiming_coverage(): void
    {
        $site = Site::factory()->create();
        $schedule = AccessControlSchedule::query()->create([
            'site_id' => $site->id,
            'name' => 'Legacy binding schedule',
            'timezone' => 'Pacific/Auckland',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
            'is_active' => true,
        ]);
        $reader = $this->accessDeviceAt($site, 'Legacy linked reader');
        $credential = $this->pendingCredential(
            $site,
            $schedule,
            'Legacy linked credential',
            'unifi:credential/legacy-link',
        );
        DB::table('access_control_credential_device')->insert([
            'access_credential_id' => $credential->id,
            'device_id' => $reader->id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subHour(),
        ]);

        $this->invokeMigrationMethod($this->providerEvidenceMigration(), 'snapshotLegacyDeviceLinks');

        $this->assertDatabaseHas('access_control_credential_device_binding_events', [
            'access_credential_id' => $credential->id,
            'site_id' => $site->id,
            'device_id' => $reader->id,
            'sequence' => 1,
            'binding_status' => AccessControlCredentialDeviceBindingEvent::STATUS_UNCONFIRMED,
            'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_REQUIRED,
            'provider_confirmed' => false,
        ]);
        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=access-control')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('securityWorkspace.activeTab.accessControl.summary.activeCredentials', 0)
                ->where('securityWorkspace.activeTab.accessControl.summary.coveredDoors', 0)
                ->where('securityWorkspace.activeTab.accessControl.credentials.0.devices', []));
    }

    public function test_mismatched_credential_schedule_site_is_rejected_by_schema_and_concealed_by_projection(): void
    {
        $scheduleSite = Site::factory()->create(['name' => 'Schedule Site']);
        $credentialSite = Site::factory()->create(['name' => 'Credential Site']);
        $schedule = AccessControlSchedule::query()->create([
            'site_id' => $scheduleSite->id,
            'name' => 'Schedule Site hours',
            'timezone' => 'Pacific/Auckland',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
            'is_active' => true,
        ]);
        $credential = AccessControlCredential::query()->create([
            'site_id' => $scheduleSite->id,
            'access_schedule_id' => $schedule->id,
            'label' => 'Mismatch evidence',
            'holder_type' => AccessControlCredential::HOLDER_STAFF,
            'holder_id' => $this->admin->id,
            'reference_key' => 'unifi:credential/mismatch-evidence',
            'status' => AccessControlCredential::STATUS_PENDING_ISSUE,
            'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_REQUIRED,
            'provider_reconciliation_action' => AccessControlCredential::PROVIDER_ACTION_ISSUE,
        ]);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::table('access_control_credentials')->where('id', $credential->id)->update(['site_id' => $credentialSite->id]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=access-control')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('securityWorkspace.activeTab.accessControl.summary.activeCredentials', 0)
                ->where('securityWorkspace.activeTab.accessControl.summary.coveredDoors', 0)
                ->where('securityWorkspace.activeTab.accessControl.schedules.0.activeCredentials', 0)
                ->where('securityWorkspace.activeTab.accessControl.credentials', []));

        $migrationBlocked = false;
        try {
            $this->invokeMigrationMethod($this->credentialTruthfulnessMigration(), 'assertCredentialScheduleSiteIntegrity');
        } catch (\RuntimeException $exception) {
            $migrationBlocked = str_contains($exception->getMessage(), 'will not rewrite it');
        }
        $this->assertTrue($migrationBlocked);

        DB::table('access_control_credentials')->where('id', $credential->id)->update(['site_id' => $scheduleSite->id]);

        $schemaRejected = false;
        try {
            DB::table('access_control_credentials')->insert([
                'site_id' => $credentialSite->id,
                'access_schedule_id' => $schedule->id,
                'label' => 'Rejected mismatch',
                'holder_type' => AccessControlCredential::HOLDER_STAFF,
                'holder_id' => $this->admin->id,
                'reference_key' => 'unifi:credential/rejected-mismatch',
                'status' => AccessControlCredential::STATUS_PENDING_ISSUE,
                'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_REQUIRED,
                'provider_reconciliation_action' => AccessControlCredential::PROVIDER_ACTION_ISSUE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $schemaRejected = true;
        }
        $this->assertTrue($schemaRejected);

        $lifecycleSiteRejected = false;
        try {
            DB::table('access_control_credential_lifecycle_events')->insert([
                'access_credential_id' => $credential->id,
                'site_id' => $credentialSite->id,
                'sequence' => 1,
                'event_type' => 'mismatched_site_evidence',
                'evidence_kind' => 'unconfirmed_local_claim',
                'provider_action' => 'issue',
                'provider_confirmed' => false,
                'credential_snapshot' => json_encode(['status' => 'pending_issue'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        } catch (QueryException) {
            $lifecycleSiteRejected = true;
        }
        $this->assertTrue($lifecycleSiteRejected);

        $otherReader = $this->accessDeviceAt($credentialSite, 'Other Site reader');
        $bindingSiteRejected = false;
        try {
            DB::table('access_control_credential_device_binding_events')->insert([
                'access_credential_id' => $credential->id,
                'site_id' => $credentialSite->id,
                'device_id' => $otherReader->id,
                'sequence' => 1,
                'binding_status' => 'unconfirmed',
                'provider_action' => 'issue',
                'provider_reconciliation_status' => 'required',
                'provider_confirmed' => false,
                'binding_snapshot' => json_encode(['evidence_kind' => 'mismatched_site'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        } catch (QueryException) {
            $bindingSiteRejected = true;
        }
        $this->assertTrue($bindingSiteRejected);
    }

    public function test_schedule_reconciliation_projection_reports_required_pending_failed_and_reconciled_truthfully(): void
    {
        $site = Site::factory()->create();
        $transitionService = app(AccessControlScheduleTransitionService::class);
        foreach ([
            ['A required', 'required'],
            ['B pending', 'pending'],
            ['C failed', 'failed'],
            ['D reconciled', 'reconciled'],
        ] as [$name, $status]) {
            $schedule = AccessControlSchedule::query()->create([
                'site_id' => $site->id,
                'name' => $name,
                'timezone' => 'Pacific/Auckland',
                'days' => ['monday'],
                'starts_at' => '08:00',
                'ends_at' => '18:00',
                'is_active' => true,
                'provider_reconciliation_status' => AccessControlSchedule::RECONCILIATION_REQUIRED,
                'provider_reconciliation_required_at' => now(),
            ]);
            if ($status === 'required') {
                continue;
            }

            $slug = strtolower(substr($name, 0, 1));
            $requestKey = 'test.schedule.'.$slug.'.request';
            $transitionService->recordProviderTransition(
                $this->admin,
                $schedule,
                AccessControlSchedule::RECONCILIATION_PENDING,
                $requestKey,
                'test.schedule.'.$slug.'.pending',
            );
            if ($status === 'failed') {
                $transitionService->recordProviderTransition(
                    $this->admin,
                    $schedule->refresh(),
                    AccessControlSchedule::RECONCILIATION_FAILED,
                    $requestKey,
                    'test.schedule.'.$slug.'.failed',
                    'Provider rejected the schedule.',
                );
            } elseif ($status === 'reconciled') {
                $transitionService->recordProviderTransition(
                    $this->admin,
                    $schedule->refresh(),
                    AccessControlSchedule::RECONCILIATION_RECONCILED,
                    $requestKey,
                    'test.schedule.'.$slug.'.reconciled',
                );
            }
        }

        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=access-control')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('securityWorkspace.activeTab.accessControl.schedules.0.providerReconciliation.label', 'Provider reconciliation required')
                ->where('securityWorkspace.activeTab.accessControl.schedules.0.providerReconciliation.tone', 'warning')
                ->where('securityWorkspace.activeTab.accessControl.schedules.1.providerReconciliation.label', 'Provider reconciliation pending')
                ->where('securityWorkspace.activeTab.accessControl.schedules.2.providerReconciliation.label', 'Provider reconciliation failed')
                ->where('securityWorkspace.activeTab.accessControl.schedules.2.providerReconciliation.tone', 'danger')
                ->where('securityWorkspace.activeTab.accessControl.schedules.2.providerReconciliation.failureReason', 'Provider rejected the schedule.')
                ->where('securityWorkspace.activeTab.accessControl.schedules.3.providerReconciliation.label', 'Provider reconciled')
                ->where('securityWorkspace.activeTab.accessControl.schedules.3.providerReconciliation.tone', 'positive')
                ->where('securityWorkspace.activeTab.accessControl.summary.activeSchedules', 1));
    }

    public function test_provider_events_are_idempotent_and_stale_or_reversed_credential_transitions_are_rejected(): void
    {
        $site = Site::factory()->create();
        $schedule = AccessControlSchedule::query()->create([
            'site_id' => $site->id,
            'name' => 'Correlated credential schedule',
            'timezone' => 'Pacific/Auckland',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
            'is_active' => true,
        ]);
        $reader = $this->accessDeviceAt($site, 'Correlated reader');
        $credential = $this->pendingCredential(
            $site,
            $schedule,
            'Correlated credential',
            'unifi:credential/correlated',
        );
        $service = app(AccessControlCredentialTransitionService::class);
        $pendingTransition = [
            'status' => AccessControlCredential::STATUS_PENDING_ISSUE,
            'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_PENDING,
        ];
        $service->recordProviderTransition(
            $this->admin,
            $credential,
            'provider_issue_pending',
            $pendingTransition,
            'test.correlated.request',
            'test.correlated.pending',
            [$reader->id],
        );
        $service->recordProviderTransition(
            $this->admin,
            $credential->refresh(),
            'provider_issue_pending',
            $pendingTransition,
            'test.correlated.request',
            'test.correlated.pending',
            [$reader->id],
        );
        $this->assertSame(1, $credential->lifecycleEvents()->count());

        $conflictingReplayBlocked = false;
        try {
            $service->recordProviderTransition(
                $this->admin,
                $credential->refresh(),
                'provider_issue_pending',
                [
                    'status' => AccessControlCredential::STATUS_ACTIVE,
                    'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_RECONCILED,
                ],
                'test.correlated.request',
                'test.correlated.pending',
                [$reader->id],
            );
        } catch (\UnexpectedValueException $exception) {
            $conflictingReplayBlocked = str_contains($exception->getMessage(), 'different evidence');
        }
        $this->assertTrue($conflictingReplayBlocked);

        $staleBlocked = false;
        try {
            $service->recordProviderTransition(
                $this->admin,
                $credential->refresh(),
                'provider_issue_confirmed',
                [
                    'status' => AccessControlCredential::STATUS_ACTIVE,
                    'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_RECONCILED,
                ],
                'test.stale.request',
                'test.stale.event',
                [$reader->id],
            );
        } catch (\UnexpectedValueException $exception) {
            $staleBlocked = str_contains($exception->getMessage(), 'stale, reversed');
        }
        $this->assertTrue($staleBlocked);
        $this->assertSame(1, $credential->lifecycleEvents()->count());

        $credential = $service->recordProviderTransition(
            $this->admin,
            $credential->refresh(),
            'provider_issue_confirmed',
            [
                'status' => AccessControlCredential::STATUS_ACTIVE,
                'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_RECONCILED,
            ],
            'test.correlated.request',
            'test.correlated.confirmed',
            [$reader->id],
        );
        $this->assertSame(2, $credential->lifecycleEvents()->count());
        $this->assertSame(1, $credential->bindingEvents()->count());

        $reversalBlocked = false;
        try {
            $service->recordProviderTransition(
                $this->admin,
                $credential->refresh(),
                'provider_issue_reopened',
                $pendingTransition,
                'test.reversed.request',
                'test.reversed.event',
                [$reader->id],
            );
        } catch (\UnexpectedValueException $exception) {
            $reversalBlocked = str_contains($exception->getMessage(), 'does not follow');
        }
        $this->assertTrue($reversalBlocked);
        $this->assertSame(AccessControlCredential::STATUS_ACTIVE, $credential->refresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access_control.credential.provider_transition',
            'auditable_type' => AccessControlCredential::class,
            'auditable_id' => $credential->id,
        ]);
        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=access-control')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('securityWorkspace.activeTab.accessControl.history.0.action', 'Provider confirmed credential access'));
    }

    public function test_legacy_pivot_links_cannot_expand_provider_confirmed_reader_coverage(): void
    {
        $site = Site::factory()->create();
        $schedule = AccessControlSchedule::query()->create([
            'site_id' => $site->id,
            'name' => 'Binding evidence schedule',
            'timezone' => 'Pacific/Auckland',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
            'is_active' => true,
        ]);
        $confirmedReader = $this->accessDeviceAt($site, 'Confirmed reader');
        $legacyOnlyReader = $this->accessDeviceAt($site, 'Legacy-only reader');
        $credential = $this->providerConfirmedCredential(
            $site,
            $schedule,
            $this->admin,
            $confirmedReader,
            'Binding evidence credential',
            'unifi:credential/binding-evidence',
        );
        DB::table('access_control_credential_device')->insert([
            'access_credential_id' => $credential->id,
            'device_id' => $legacyOnlyReader->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertFalse(
            method_exists($legacyOnlyReader, 'accessControlCredentials'),
            'The retained legacy pivot must not remain an application-writable Device relationship.',
        );

        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=access-control')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('securityWorkspace.activeTab.accessControl.summary.activeCredentials', 1)
                ->where('securityWorkspace.activeTab.accessControl.summary.coveredDoors', 1)
                ->where('securityWorkspace.activeTab.accessControl.credentials.0.devices.0.id', $confirmedReader->id)
                ->missing('securityWorkspace.activeTab.accessControl.credentials.0.devices.1'));
    }

    public function test_provider_transition_services_enforce_manage_permission_and_current_site_access(): void
    {
        $allowedSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $manager = $this->userWithRole('facilities_manager');
        HrEmployeeProfile::factory()->create([
            'user_id' => $manager->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $schedule = AccessControlSchedule::query()->create([
            'site_id' => $otherSite->id,
            'name' => 'Other Site provider schedule',
            'timezone' => 'Pacific/Auckland',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
            'is_active' => true,
        ]);
        $credential = $this->pendingCredential(
            $otherSite,
            $schedule,
            'Other Site provider credential',
            'unifi:credential/other-site-provider',
        );

        foreach ([
            fn () => app(AccessControlScheduleTransitionService::class)->recordProviderTransition(
                $manager,
                $schedule,
                AccessControlSchedule::RECONCILIATION_PENDING,
                'test.other-site.schedule.request',
                'test.other-site.schedule.event',
            ),
            fn () => app(AccessControlCredentialTransitionService::class)->recordProviderTransition(
                $manager,
                $credential,
                'provider_issue_pending',
                [
                    'status' => AccessControlCredential::STATUS_PENDING_ISSUE,
                    'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_PENDING,
                ],
                'test.other-site.credential.request',
                'test.other-site.credential.event',
                [],
            ),
        ] as $operation) {
            $blocked = false;
            try {
                $operation();
            } catch (NotFoundHttpException) {
                $blocked = true;
            }
            $this->assertTrue($blocked);
        }

        $this->assertSame(AccessControlSchedule::RECONCILIATION_REQUIRED, $schedule->refresh()->provider_reconciliation_status);
        $this->assertSame(AccessControlCredential::RECONCILIATION_REQUIRED, $credential->refresh()->provider_reconciliation_status);
    }

    public function test_provider_evidence_writers_remain_inside_governed_access_control_services(): void
    {
        $normalisePath = static fn (string $path): string => strtolower(str_replace('\\', '/', $path));
        $allowedWriters = collect([
            app_path('Domain/SecurityDevices/AccessControl/Services/AccessControlCredentialTransitionService.php'),
            app_path('Domain/SecurityDevices/AccessControl/Services/AccessControlLifecycleService.php'),
            app_path('Domain/SecurityDevices/AccessControl/Services/AccessControlScheduleTransitionService.php'),
        ])->map($normalisePath)->all();
        $unexpected = collect(File::allFiles(app_path()))
            ->filter(function (\SplFileInfo $file): bool {
                if ($file->getExtension() !== 'php') {
                    return false;
                }
                $contents = File::get($file->getPathname());

                return str_contains($contents, 'bindingEvents()->create(')
                    || str_contains($contents, 'lifecycleEvents()->create(')
                    || str_contains($contents, 'AccessControlScheduleRevision::query()->create(')
                    || str_contains($contents, 'AccessControlSchedule::query()->create(')
                    || str_contains($contents, 'AccessControlCredential::query()->create(');
            })
            ->map(fn (\SplFileInfo $file): string => $normalisePath($file->getPathname()))
            ->reject(fn (string $path): bool => in_array($path, $allowedWriters, true))
            ->values();

        $this->assertSame([], $unexpected->all(), 'Access Control provider evidence has an ungoverned application writer.');
    }

    public function test_credential_controller_rethrows_database_failures_that_are_not_the_named_reference_index(): void
    {
        $service = \Mockery::mock(AccessControlLifecycleService::class);
        $service->shouldReceive('issueCredential')->once()->andThrow(new QueryException(
            'mysql',
            'select * from access_control_schedules',
            [],
            new \RuntimeException('Database connection unavailable.'),
        ));
        $this->instance(AccessControlLifecycleService::class, $service);
        $this->withoutExceptionHandling();

        $this->expectException(QueryException::class);
        $this->actingAs($this->admin)->post('/security-devices/access-control/credentials', [
            'site_id' => 1,
            'access_schedule_id' => 1,
            'label' => 'Unavailable database',
            'holder_type' => AccessControlCredential::HOLDER_STAFF,
            'holder_id' => $this->admin->id,
            'reference_key' => 'unifi:credential/database-unavailable',
            'device_ids' => [1],
        ]);
    }

    public function test_schedule_creation_rechecks_operational_site_inside_the_transaction(): void
    {
        $site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $access = \Mockery::mock(SecurityDevicesAccessService::class);
        $access->shouldReceive('assertCanViewSite')
            ->once()
            ->with($this->admin, $site->id)
            ->andReturnUsing(function () use ($site): void {
                Site::query()->whereKey($site->id)->update([
                    'archived' => true,
                    'archived_at' => now(),
                ]);
            });
        $service = new AccessControlLifecycleService($access);

        $blocked = false;
        try {
            $service->createSchedule($this->admin, [
                'site_id' => $site->id,
                'name' => 'Must not be created',
                'days' => ['monday'],
                'starts_at' => '08:00',
                'ends_at' => '18:00',
            ]);
        } catch (NotFoundHttpException) {
            $blocked = true;
        }

        $this->assertTrue($blocked);
        $this->assertSame(0, AccessControlSchedule::query()->count());
    }

    public function test_schedule_update_and_deactivation_recheck_locked_operational_site_before_mutation(): void
    {
        foreach (['update', 'deactivate'] as $operation) {
            $site = Site::factory()->create([
                'is_active' => true,
                'archived' => false,
                'archived_at' => null,
            ]);
            $schedule = AccessControlSchedule::query()->create([
                'site_id' => $site->id,
                'name' => ucfirst($operation).' guarded schedule',
                'timezone' => 'Pacific/Auckland',
                'days' => ['monday'],
                'starts_at' => '08:00',
                'ends_at' => '18:00',
                'is_active' => true,
            ]);
            $access = \Mockery::mock(SecurityDevicesAccessService::class);
            $access->shouldReceive('assertCanViewSite')
                ->once()
                ->with($this->admin, $site->id)
                ->andReturnUsing(function () use ($site): void {
                    Site::query()->whereKey($site->id)->update([
                        'archived' => true,
                        'archived_at' => now(),
                    ]);
                });
            $service = new AccessControlLifecycleService($access);

            $blocked = false;
            try {
                if ($operation === 'update') {
                    $service->updateSchedule($this->admin, $schedule, [
                        'expected_version' => 1,
                        'name' => 'Must not be updated',
                        'days' => ['monday'],
                        'starts_at' => '08:00',
                        'ends_at' => '18:00',
                        'reason' => 'Race regression proof.',
                    ]);
                } else {
                    $service->deactivateSchedule($this->admin, $schedule, [
                        'expected_version' => 1,
                        'reason' => 'Race regression proof.',
                    ]);
                }
            } catch (NotFoundHttpException) {
                $blocked = true;
            }

            $this->assertTrue($blocked);
            $schedule->refresh();
            $this->assertSame(1, $schedule->version);
            $this->assertTrue($schedule->is_active);
            $this->assertNotSame('Must not be updated', $schedule->name);
        }
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }

    private function accessDeviceAt(Site $site, string $name): Device
    {
        $device = Device::factory()->create([
            'domain' => 'security',
            'category' => 'access_control',
            'subcategory' => 'card_reader',
            'name' => $name,
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
        ]);

        return $device;
    }

    private function providerConfirmedCredential(
        Site $site,
        AccessControlSchedule $schedule,
        User $holder,
        Device $device,
        string $label,
        string $reference,
    ): AccessControlCredential {
        $credential = AccessControlCredential::query()->create([
            'site_id' => $site->id,
            'access_schedule_id' => $schedule->id,
            'label' => $label,
            'holder_type' => AccessControlCredential::HOLDER_STAFF,
            'holder_id' => $holder->id,
            'reference_key' => $reference,
            'status' => AccessControlCredential::STATUS_PENDING_ISSUE,
            'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_REQUIRED,
            'provider_reconciliation_action' => AccessControlCredential::PROVIDER_ACTION_ISSUE,
            'created_by_user_id' => $this->admin->id,
        ]);
        $requestKey = 'test.issue.request.'.$credential->id;
        $transitionService = app(AccessControlCredentialTransitionService::class);
        $transitionService->recordProviderTransition(
            $this->admin,
            $credential,
            'provider_issue_pending',
            [
                'status' => AccessControlCredential::STATUS_PENDING_ISSUE,
                'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_PENDING,
            ],
            $requestKey,
            'test.issue.pending.'.$credential->id,
            [$device->id],
            now()->subMinute(),
        );
        $credential = $transitionService->recordProviderTransition(
            $this->admin,
            $credential->refresh(),
            'provider_issue_confirmed',
            [
                'status' => AccessControlCredential::STATUS_ACTIVE,
                'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_RECONCILED,
            ],
            $requestKey,
            'test.issue.confirmed.'.$credential->id,
            [$device->id],
            now(),
        );

        return $credential;
    }

    private function pendingCredential(
        Site $site,
        AccessControlSchedule $schedule,
        string $label,
        string $reference,
    ): AccessControlCredential {
        return AccessControlCredential::query()->create([
            'site_id' => $site->id,
            'access_schedule_id' => $schedule->id,
            'label' => $label,
            'holder_type' => AccessControlCredential::HOLDER_STAFF,
            'holder_id' => $this->admin->id,
            'reference_key' => $reference,
            'status' => AccessControlCredential::STATUS_PENDING_ISSUE,
            'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_REQUIRED,
            'provider_reconciliation_action' => AccessControlCredential::PROVIDER_ACTION_ISSUE,
            'created_by_user_id' => $this->admin->id,
        ]);
    }

    private function credentialTruthfulnessMigration(): object
    {
        return require database_path('migrations/2026_08_06_000039_make_access_control_credential_lifecycle_truthful.php');
    }

    private function providerEvidenceMigration(): object
    {
        return require database_path('migrations/2026_08_06_000045_govern_access_control_provider_evidence.php');
    }

    private function invokeMigrationMethod(object $migration, string $method): mixed
    {
        $reflection = new \ReflectionMethod($migration, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($migration);
    }
}
