<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\HsClosureException;
use App\Models\HsClosureExceptionDecision;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsEventClosureService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * HS-CLOSE-01: the H&S-owned aggregate is the only terminal mutation path.
 * Statutory and protective work is hard-blocking; only narrow, independently
 * approved, current exceptions can cover the explicitly exceptional gates.
 */
class HsEventClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_clean_event_closes_once_with_immutable_audit_provenance(): void
    {
        $site = Site::factory()->create();
        $actor = $this->roleUser('health_safety_officer', $site);
        $event = $this->cleanEvent($actor, $site);

        $this->actingAs($actor)
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Controls verified and all governed work is complete.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $event->refresh();
        $this->assertSame(HsEvent::STATUS_CLOSED, $event->status);
        $this->assertSame($actor->id, $event->closed_by);
        $this->assertNotNull($event->closed_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'healthSafety.event.closed',
            'auditable_type' => $event->getMorphClass(),
            'auditable_id' => $event->id,
            'user_id' => $actor->id,
        ]);

        try {
            app(HsEventClosureService::class)->closeEvent($event, 'Replay must fail.', $actor);
            $this->fail('A terminal event was closed twice.');
        } catch (\DomainException $exception) {
            $this->assertSame('This event is already closed.', $exception->getMessage());
        }

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'healthSafety.event.closed')
            ->where('auditable_type', $event->getMorphClass())
            ->where('auditable_id', $event->id)
            ->count());
    }

    public function test_worksafe_decision_is_a_hard_non_overridable_blocker(): void
    {
        $site = Site::factory()->create();
        $actor = $this->roleUser('health_safety_officer', $site);
        $event = HsEvent::factory()->worksafeUndecided()->create([
            'site_id' => $site->id,
            'owner_user_id' => $actor->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            'investigation_required' => false,
        ]);

        $this->assertHardBlockerAndFailedClose($event, $actor, 'worksafe_decision');
    }

    public function test_worksafe_notification_is_a_hard_non_overridable_blocker(): void
    {
        $site = Site::factory()->create();
        $actor = $this->roleUser('health_safety_officer', $site);
        $event = HsEvent::factory()->worksafeNotifiable($actor)->create([
            'site_id' => $site->id,
            'owner_user_id' => $actor->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            'investigation_required' => false,
        ]);

        $this->assertHardBlockerAndFailedClose($event, $actor, 'worksafe_notification');
    }

    public function test_worksafe_acknowledgement_is_a_hard_non_overridable_blocker(): void
    {
        $site = Site::factory()->create();
        $actor = $this->roleUser('health_safety_officer', $site);
        $event = HsEvent::factory()->worksafeNotifiable($actor)->create([
            'site_id' => $site->id,
            'owner_user_id' => $actor->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            'investigation_required' => false,
            'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
            'worksafe_notified_at' => now()->subHour(),
            'worksafe_method' => 'online',
            'worksafe_site_preservation_status' => HsEvent::SITE_PRESERVATION_NOT_REQUIRED,
            'worksafe_site_preservation_decided_at' => now()->subHour(),
            'worksafe_site_preservation_decided_by_user_id' => $actor->id,
            'worksafe_site_preservation_decision_reference' => 'H&S review HS-ACK-01',
        ]);

        $this->assertHardBlockerAndFailedClose($event, $actor, 'worksafe_acknowledgement');
    }

    public function test_active_or_unreviewed_site_preservation_is_a_hard_non_overridable_blocker(): void
    {
        $site = Site::factory()->create();
        $actor = $this->roleUser('health_safety_officer', $site);

        foreach ([null, HsEvent::SITE_PRESERVATION_ACTIVE] as $status) {
            $event = HsEvent::factory()->worksafeNotifiable($actor)->create([
                'site_id' => $site->id,
                'owner_user_id' => $actor->id,
                'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
                'investigation_required' => false,
                'worksafe_status' => HsEvent::WORKSAFE_ACKNOWLEDGED,
                'worksafe_notified_at' => now()->subHours(2),
                'worksafe_acknowledged_at' => now()->subHour(),
                'worksafe_method' => 'online',
                'worksafe_site_preserved' => $status === HsEvent::SITE_PRESERVATION_ACTIVE,
                'worksafe_site_preservation_status' => $status,
                'worksafe_site_preservation_decided_at' => $status ? now()->subHours(2) : null,
                'worksafe_site_preservation_decided_by_user_id' => $status ? $actor->id : null,
                'worksafe_site_preservation_decision_reference' => $status ? 'Scene secured HS-SITE-01' : null,
            ]);

            $this->assertHardBlockerAndFailedClose($event, $actor, 'site_preservation');
        }
    }

    public function test_active_linked_alert_is_a_hard_non_overridable_blocker(): void
    {
        $site = Site::factory()->create();
        $actor = $this->roleUser('health_safety_officer', $site);
        $alert = ControlRoomAlert::factory()->open()->create(['site_id' => $site->id]);
        $event = $this->cleanEvent($actor, $site, ['control_room_alert_id' => $alert->id]);

        $this->assertHardBlockerAndFailedClose($event, $actor, 'control_room_alert');
    }

    public function test_active_linked_protective_work_is_a_hard_non_overridable_blocker(): void
    {
        $site = Site::factory()->create();
        $actor = $this->roleUser('health_safety_officer', $site);
        $alert = ControlRoomAlert::factory()->resolved()->create(['site_id' => $site->id]);
        AlertTask::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Keep the loading bay isolated',
            'status' => AlertTask::STATUS_IN_PROGRESS,
            'priority' => 'critical',
            'created_by_user_id' => $actor->id,
        ]);
        $event = $this->cleanEvent($actor, $site, ['control_room_alert_id' => $alert->id]);

        $this->assertHardBlockerAndFailedClose($event, $actor, 'protective_work');
    }

    public function test_transferred_protective_work_stays_hard_blocking_until_the_hs_action_is_verified(): void
    {
        $site = Site::factory()->create();
        $actor = $this->roleUser('health_safety_officer', $site);
        $alert = ControlRoomAlert::factory()->resolved()->create(['site_id' => $site->id]);
        $event = $this->cleanEvent($actor, $site, ['control_room_alert_id' => $alert->id]);
        $task = AlertTask::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Keep the loading bay isolated',
            'status' => AlertTask::STATUS_TRANSFERRED,
            'priority' => 'critical',
            'created_by_user_id' => $actor->id,
            'transferred_at' => now(),
            'transferred_by_user_id' => $actor->id,
        ]);
        $action = HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id,
            'source_control_room_task_id' => $task->id,
            'status' => HsCorrectiveAction::STATUS_IN_PROGRESS,
        ]);
        $task->update(['transferred_to_hs_corrective_action_id' => $action->id]);

        $this->assertHardBlockerAndFailedClose($event, $actor, 'protective_work');

        $action->update([
            'status' => HsCorrectiveAction::STATUS_VERIFIED,
            'verified_at' => now(),
            'verified_by_user_id' => $actor->id,
            'verification_notes' => 'Isolation control independently verified.',
            'effectiveness_confirmed' => true,
        ]);
        $readiness = app(HsEventClosureService::class)->readiness($event->fresh());
        $this->assertTrue(collect($readiness->requirements)->firstWhere('key', 'protective_work')['complete']);
    }

    public function test_linked_alert_from_another_site_is_a_hard_scope_blocker(): void
    {
        $eventSite = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $actor = $this->roleUser('health_safety_officer', $eventSite);
        $alert = ControlRoomAlert::factory()->resolved()->create(['site_id' => $foreignSite->id]);
        $event = $this->cleanEvent($actor, $eventSite, ['control_room_alert_id' => $alert->id]);

        $this->assertHardBlockerAndFailedClose($event, $actor, 'control_room_scope');
    }

    public function test_approved_exception_never_bypasses_a_hard_worksafe_blocker(): void
    {
        $site = Site::factory()->create();
        $requester = $this->roleUser('health_safety_officer', $site);
        $approver = $this->roleUser('compliance_lead', $site);
        $event = HsEvent::factory()->high()->worksafeNotifiable($requester)->create([
            'site_id' => $site->id,
            'owner_user_id' => $requester->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        $exception = $this->approvedInvestigationException($event, $requester, $approver);

        try {
            app(HsEventClosureService::class)->closeEvent(
                $event,
                'A hard statutory blocker cannot be excepted.',
                $requester,
                $exception->id,
            );
            $this->fail('An approved exceptional scope bypassed a hard blocker.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('WorkSafe notification', $exception->getMessage());
        }

        $this->assertSame(HsEvent::STATUS_OPEN, $event->fresh()->status);
    }

    public function test_free_text_alone_cannot_bypass_an_exceptional_blocker(): void
    {
        $site = Site::factory()->create();
        $actor = $this->roleUser('health_safety_officer', $site);
        $event = HsEvent::factory()->high()->worksafeNotNotifiable($actor)->create([
            'site_id' => $site->id,
            'owner_user_id' => $actor->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);

        $this->actingAs($actor)
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Attempting the former free-text path.',
                'override_reason' => 'This text is historical context only and cannot grant authority.',
            ])
            ->assertSessionHas('error');

        $this->assertSame(HsEvent::STATUS_OPEN, $event->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'healthSafety.event.closureOverridden',
            'auditable_id' => $event->id,
        ]);
    }

    public function test_current_independently_approved_narrow_exception_can_cover_exceptional_blocker_only(): void
    {
        $site = Site::factory()->create();
        $requester = $this->roleUser('health_safety_officer', $site);
        $approver = $this->roleUser('compliance_lead', $site);
        $alert = ControlRoomAlert::factory()->resolved()->create(['site_id' => $site->id]);
        $event = HsEvent::factory()->high()->worksafeNotNotifiable($requester)->create([
            'site_id' => $site->id,
            'owner_user_id' => $requester->id,
            'control_room_alert_id' => $alert->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        $investigation = HsInvestigation::factory()->inProgress()->create(['hs_event_id' => $event->id]);
        $verifiedAction = HsCorrectiveAction::factory()->verified()->create(['hs_event_id' => $event->id]);
        $exception = $this->approvedInvestigationException($event, $requester, $approver);

        app(HsEventClosureService::class)->closeEvent(
            $event,
            'Independent exception approved for the open investigation record.',
            $requester,
            $exception->id,
        );

        $this->assertSame(HsEvent::STATUS_CLOSED, $event->fresh()->status);
        $this->assertSame(HsInvestigation::STATUS_IN_PROGRESS, $investigation->fresh()->status);
        $this->assertSame(HsCorrectiveAction::STATUS_VERIFIED, $verifiedAction->fresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->fresh()->status);
        $audit = AuditLog::query()
            ->where('action', 'healthSafety.event.closedWithException')
            ->where('auditable_id', $event->id)
            ->firstOrFail();
        $this->assertSame($exception->id, $audit->meta['exception_id']);
        $this->assertSame($exception->provenance_hash, $audit->meta['exception_provenance_hash']);
    }

    public function test_requester_cannot_decide_their_own_exception_even_with_direct_approver_permission(): void
    {
        $site = Site::factory()->create();
        $requester = $this->roleUser('health_safety_officer', $site);
        $this->grantDirectPermission($requester, 'healthSafety.closureExceptions.approve');
        $event = HsEvent::factory()->high()->worksafeNotNotifiable($requester)->create([
            'site_id' => $site->id,
            'owner_user_id' => $requester->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        $exception = $this->requestInvestigationException($event, $requester);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('cannot approve or reject your own');
        app(HsEventClosureService::class)->decideException(
            $event,
            $exception,
            $requester,
            HsClosureExceptionDecision::DECISION_APPROVED,
            'Self-approval must remain impossible.',
        );
    }

    public function test_ordinary_close_and_exception_decision_are_separate_explicit_authorities(): void
    {
        $site = Site::factory()->create();
        $requester = $this->roleUser('health_safety_officer', $site);
        $approver = $this->roleUser('compliance_lead', $site);
        $admin = $this->roleUser('admin', $site);

        $this->assertTrue($requester->canDo('healthSafety.events.close'));
        $this->assertFalse($requester->canDo('healthSafety.closureExceptions.approve'));
        $this->assertTrue($approver->canDo('healthSafety.closureExceptions.approve'));
        $this->assertFalse($approver->canDo('healthSafety.events.close'));
        $this->assertFalse($admin->canDo('healthSafety.events.close'));
        $this->assertFalse($admin->canDo('healthSafety.closureExceptions.approve'));

        $event = $this->cleanEvent($requester, $site);
        $this->actingAs($approver)
            ->post("/health-safety/events/{$event->id}/close", ['closure_summary' => 'No close authority.'])
            ->assertForbidden();
        $this->actingAs($admin)
            ->post("/health-safety/events/{$event->id}/close", ['closure_summary' => 'Generic admin is insufficient.'])
            ->assertForbidden();
        $this->assertSame(HsEvent::STATUS_OPEN, $event->fresh()->status);
    }

    public function test_rejected_revoked_and_expired_exceptions_cannot_authorise_close(): void
    {
        $site = Site::factory()->create();
        $requester = $this->roleUser('health_safety_officer', $site);
        $approver = $this->roleUser('compliance_lead', $site);
        $service = app(HsEventClosureService::class);

        $rejectedEvent = $this->exceptionalEvent($requester, $site);
        $rejected = $this->requestInvestigationException($rejectedEvent, $requester);
        $service->decideException(
            $rejectedEvent,
            $rejected,
            $approver,
            HsClosureExceptionDecision::DECISION_REJECTED,
            'The evidence does not support this exception.',
        );
        $this->assertCloseRejectedForException($rejectedEvent, $requester, $rejected);

        $revokedEvent = $this->exceptionalEvent($requester, $site);
        $revoked = $this->approvedInvestigationException($revokedEvent, $requester, $approver);
        $service->revokeException(
            $revokedEvent,
            $revoked,
            $approver,
            'New evidence means this authority must be revoked.',
        );
        $this->assertCloseRejectedForException($revokedEvent, $requester, $revoked);

        $expiredEvent = $this->exceptionalEvent($requester, $site);
        $expired = $this->approvedInvestigationException($expiredEvent, $requester, $approver);
        $this->travel(15)->days();
        try {
            $this->assertCloseRejectedForException($expiredEvent, $requester, $expired);
        } finally {
            $this->travelBack();
        }
    }

    public function test_wrong_event_and_wrong_site_exception_ids_cannot_authorise_close(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $requester = $this->roleUser('health_safety_officer', $siteA);
        $approver = $this->roleUser('compliance_lead', $siteA);
        $sourceEvent = $this->exceptionalEvent($requester, $siteA);
        $exception = $this->approvedInvestigationException($sourceEvent, $requester, $approver);

        foreach ([$siteA, $siteB] as $targetSite) {
            $target = $this->exceptionalEvent($requester, $targetSite);
            try {
                app(HsEventClosureService::class)->closeEvent(
                    $target,
                    'A foreign exception must fail.',
                    $requester,
                    $exception->id,
                );
                $this->fail('An exception from another event or Site authorised closure.');
            } catch (\DomainException $domainException) {
                $this->assertStringContainsString('not valid for this event', $domainException->getMessage());
            }
            $this->assertSame(HsEvent::STATUS_OPEN, $target->fresh()->status);
        }
    }

    public function test_foreign_site_direct_object_is_denied_before_validation_or_side_effect(): void
    {
        $visible = Site::factory()->create();
        $hidden = Site::factory()->create();
        $teamLead = $this->roleUser('team_lead', $visible);
        $event = $this->exceptionalEvent($teamLead, $hidden);

        $this->actingAs($teamLead)
            ->post("/health-safety/events/{$event->id}/closure-exceptions", [])
            ->assertNotFound();

        $this->assertDatabaseCount('hs_closure_exceptions', 0);
    }

    public function test_owner_boundary_is_rechecked_and_explicit_global_close_any_is_distinct(): void
    {
        $site = Site::factory()->create();
        $teamLead = $this->roleUser('team_lead', $site);
        $owner = $this->roleUser('team_lead', $site);
        $globalHsLead = $this->roleUser('health_safety_officer');
        $event = $this->cleanEvent($owner, $site);

        $this->actingAs($teamLead)
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'A non-owner cannot close this event.',
            ])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'recorded H&S owner'));
        $this->assertSame(HsEvent::STATUS_OPEN, $event->fresh()->status);

        $this->actingAs($globalHsLead)
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Explicit application-wide H&S lead authority used.',
            ])
            ->assertSessionHas('success');
        $this->assertSame(HsEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    public function test_decision_replay_is_deterministic_and_initial_decision_is_unique(): void
    {
        $site = Site::factory()->create();
        $requester = $this->roleUser('health_safety_officer', $site);
        $approver = $this->roleUser('compliance_lead', $site);
        $event = $this->exceptionalEvent($requester, $site);
        $exception = $this->requestInvestigationException($event, $requester);
        $service = app(HsEventClosureService::class);

        $service->decideException(
            $event,
            $exception,
            $approver,
            HsClosureExceptionDecision::DECISION_APPROVED,
            'The supplied evidence supports this narrow exception.',
        );

        try {
            $service->decideException(
                $event,
                $exception,
                $approver,
                HsClosureExceptionDecision::DECISION_REJECTED,
                'A replay cannot replace the immutable first decision.',
            );
            $this->fail('A second initial decision was accepted.');
        } catch (\DomainException $domainException) {
            $this->assertSame('This closure exception request has already been decided.', $domainException->getMessage());
        }

        $this->assertSame(1, $exception->decisions()->count());
    }

    public function test_close_rechecks_event_dependencies_authority_and_exception_with_row_locks(): void
    {
        $this->requireMysql();
        $site = Site::factory()->create();
        $requester = $this->roleUser('health_safety_officer', $site);
        $approver = $this->roleUser('compliance_lead', $site);
        $event = $this->exceptionalEvent($requester, $site);
        $exception = $this->approvedInvestigationException($event, $requester, $approver);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'for update')) {
                $queries[] = $sql;
            }
        });

        app(HsEventClosureService::class)->closeEvent(
            $event,
            'All close authority was rechecked under row locks.',
            $requester,
            $exception->id,
        );

        foreach (['`hs_events`', '`users`', '`sites`', '`hs_investigations`', '`hs_closure_exceptions`', '`hs_closure_exception_decisions`'] as $table) {
            $this->assertTrue(
                collect($queries)->contains(fn (string $sql): bool => str_contains($sql, $table)),
                "Expected a row lock for {$table}.",
            );
        }
    }

    public function test_audit_failure_rolls_back_terminal_mutation_without_partial_provenance(): void
    {
        $site = Site::factory()->create();
        $actor = $this->roleUser('health_safety_officer', $site);
        $event = $this->cleanEvent($actor, $site);
        Event::listen('eloquent.creating: '.AuditLog::class, static function (): never {
            throw new \RuntimeException('Injected audit storage failure.');
        });

        try {
            app(HsEventClosureService::class)->closeEvent(
                $event,
                'This mutation must roll back with its strict audit.',
                $actor,
            );
            $this->fail('Close unexpectedly survived an audit failure.');
        } catch (\RuntimeException $runtimeException) {
            $this->assertSame('Injected audit storage failure.', $runtimeException->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }

        $event->refresh();
        $this->assertSame(HsEvent::STATUS_OPEN, $event->status);
        $this->assertNull($event->closed_at);
        $this->assertNull($event->closed_by);
        $this->assertNull($event->closure_summary);
    }

    public function test_mysql_guards_terminal_and_exception_provenance_against_mutation(): void
    {
        $this->requireMysql();
        $site = Site::factory()->create();
        $requester = $this->roleUser('health_safety_officer', $site);
        $approver = $this->roleUser('compliance_lead', $site);
        $event = $this->exceptionalEvent($requester, $site);
        $exception = $this->approvedInvestigationException($event, $requester, $approver);
        app(HsEventClosureService::class)->closeEvent(
            $event,
            'Closed with immutable terminal provenance.',
            $requester,
            $exception->id,
        );

        foreach (
            [
                ['hs_events', $event->id, ['closure_summary' => 'Tampered']],
                ['hs_closure_exceptions', $exception->id, ['reason' => 'Tampered']],
                ['hs_closure_exception_decisions', $exception->decisions()->value('id'), ['reason' => 'Tampered']],
            ] as [$table, $id, $attributes]
        ) {
            try {
                DB::table($table)->where('id', $id)->update($attributes);
                $this->fail("The {$table} provenance guard allowed mutation.");
            } catch (QueryException $queryException) {
                $this->assertStringContainsString('immutable', strtolower($queryException->getMessage()));
            }
        }
    }

    private function assertHardBlockerAndFailedClose(HsEvent $event, User $actor, string $key): void
    {
        $readiness = app(HsEventClosureService::class)->readiness($event);
        $requirement = collect($readiness->requirements)->firstWhere('key', $key);
        $this->assertNotNull($requirement);
        $this->assertFalse($requirement['complete']);
        $this->assertSame('hard', $requirement['classification']);

        $this->actingAs($actor)
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Hard-blocked close attempt.',
                'override_reason' => 'Free text can never bypass this hard blocker.',
            ])
            ->assertSessionHas('error');

        $this->assertSame(HsEvent::STATUS_OPEN, $event->fresh()->status);
    }

    private function assertCloseRejectedForException(
        HsEvent $event,
        User $actor,
        HsClosureException $exception,
    ): void {
        try {
            app(HsEventClosureService::class)->closeEvent(
                $event,
                'This exception is not current.',
                $actor,
                $exception->id,
            );
            $this->fail('A non-current exception authorised closure.');
        } catch (\DomainException $domainException) {
            $this->assertStringContainsString('current independently approved exception', $domainException->getMessage());
        }
        $this->assertSame(HsEvent::STATUS_OPEN, $event->fresh()->status);
    }

    private function cleanEvent(User $actor, Site $site, array $attributes = []): HsEvent
    {
        return HsEvent::factory()->worksafeNotNotifiable($actor)->create([
            'site_id' => $site->id,
            'owner_user_id' => $actor->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            'investigation_required' => false,
            ...$attributes,
        ]);
    }

    private function exceptionalEvent(User $owner, Site $site): HsEvent
    {
        return HsEvent::factory()->high()->worksafeNotNotifiable($owner)->create([
            'site_id' => $site->id,
            'owner_user_id' => $owner->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
    }

    private function requestInvestigationException(HsEvent $event, User $requester): HsClosureException
    {
        return app(HsEventClosureService::class)->requestException($event, $requester, [
            'category' => HsClosureException::CATEGORY_INVESTIGATION_RECORD,
            'reason' => 'External evidence is pending but the event record requires a time-limited decision.',
            'evidence_reference' => 'HS-BOARD-2026-0041',
            'scope' => ['hs_investigation'],
            'review_at' => now()->addDays(5)->toIso8601String(),
            'expires_at' => now()->addDays(10)->toIso8601String(),
        ]);
    }

    private function approvedInvestigationException(
        HsEvent $event,
        User $requester,
        User $approver,
    ): HsClosureException {
        $exception = $this->requestInvestigationException($event, $requester);
        app(HsEventClosureService::class)->decideException(
            $event,
            $exception,
            $approver,
            HsClosureExceptionDecision::DECISION_APPROVED,
            'Independent evidence review supports this narrow time-limited exception.',
        );

        return $exception->fresh('latestDecision');
    }

    private function roleUser(string $roleName, ?Site $site = null): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site?->id,
            'secondary_site_ids' => [],
            'position_role' => $roleName,
        ]);

        return $user->fresh();
    }

    private function grantDirectPermission(User $user, string $permissionKey): void
    {
        $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    private function requireMysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('This integrity assertion requires the real MySQL trigger/locking contract.');
        }
    }
}
