<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalExposureHsProjection;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\HsEvent;
use App\Models\HsEventReviewFlag;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalExposureDeliveryProblems;
use App\Services\HealthSafety\ChemicalExposureHsDestinationRules;
use App\Services\HealthSafety\ChemicalExposureSystemDeliveryService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsEventService;
use App\Services\Tasks\Providers\ChemicalExposureDeliveryProvider;
use App\Services\Tasks\Providers\HsEventReviewFlagProvider;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use Illuminate\Support\Facades\DB;

/**
 * Job 3 (Stephan, 26 Oct): every saved exposure reaches the H&S register automatically, straight after the save, with
 * Codex's projection, response floor, marker and audit. Failures are kept and retried (30 s doubling to 15 min); an
 * integrity refusal waits for a manager. Stuck deliveries show to exposure managers only. A correction after a signed
 * WorkSafe decision, or a severity rise after close, flags the event for review and never changes the decision or
 * reopens the event.
 */
final class ChemicalExposureAutomaticDeliveryTest extends ChemicalExposureCommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['health_safety.exposure_automatic_delivery' => true]);
    }

    private function system(): ChemicalExposureSystemDeliveryService
    {
        return app(ChemicalExposureSystemDeliveryService::class);
    }

    private function eventFor(SubstanceExposureRecord $root): ?HsEvent
    {
        return HsEvent::query()->where('source_type', SubstanceExposureRecord::class)->where('source_id', $root->id)->first();
    }

    private function delivery(SubstanceExposureRecord $root, int $revision): ChemicalExposureSourceDelivery
    {
        return ChemicalExposureSourceDelivery::query()->where('exposure_id', $root->id)->where('kind', 'health_safety')
            ->where('membership->source_revision', $revision)->sole();
    }

    private function correct(SubstanceExposureRecord $root, array $facts): SubstanceExposureRecord
    {
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Correct the recorded facts.', ...$facts]));

        return $root->fresh();
    }

    /** Fixture only: the closure guard trigger admits this one close, as HsEventClosureService does. */
    private function close(HsEvent $event): void
    {
        DB::statement('SET @hs_canonical_close_event_id = '.(int) $event->id);
        try { $event->forceFill(['status' => HsEvent::STATUS_CLOSED, 'closed_at' => now()])->saveQuietly(); }
        finally { DB::statement('SET @hs_canonical_close_event_id = NULL'); }
    }

    public function test_a_reporter_without_exposure_read_still_reaches_the_register_straight_after_the_save(): void
    {
        $receipt = $this->capture(['medical_treatment' => 'medical', 'symptoms' => 'PRIVATE-SYMPTOMS'], $this->reporter);
        $root = SubstanceExposureRecord::findOrFail($receipt['root_id']);

        $event = $this->eventFor($root);
        $this->assertNotNull($event);
        $this->assertSame(HsEvent::SEVERITY_HIGH, $event->severity);
        $this->assertTrue((bool) $event->investigation_required);
        $this->assertNull($event->worksafe_notifiable);
        $this->assertSame($this->reporter->id, (int) $event->created_by);
        $this->assertSame($this->site->id, (int) $event->site_id);
        $this->assertSame($this->worker->id, (int) $event->staff_id);
        $row = $this->delivery($root, 1);
        $this->assertSame('delivered', $row->state);
        $this->assertSame(1, $row->attempts);
        $this->assertSame($event->id, $row->hs_event_id);
        $marker = ChemicalExposureHsProjection::query()->where('exposure_id', $root->id)->sole();
        $this->assertSame($this->reporter->id, $marker->recorded_by);
        $audit = AuditLog::query()->findOrFail($marker->audit_log_id);
        $this->assertSame(ChemicalExposureHsDestinationRules::AUTOMATIC_AUDIT_ACTION, $audit->action);
        $this->assertSame($this->reporter->id, (int) $audit->user_id);
        $this->assertStringNotContainsString('PRIVATE-', json_encode([$marker->projection, $audit->meta, DB::table('hs_events')->where('id', $event->id)->first()], JSON_THROW_ON_ERROR));
    }

    public function test_repeat_attempts_never_create_a_second_event_or_marker(): void
    {
        $root = $this->exposure(['medical_treatment' => 'none']);
        $this->system()->afterCommit($root->id);
        $this->travel(2)->minutes();
        $this->artisan('chemical-exposures:recover-deliveries')->assertSuccessful();

        $this->assertSame(1, HsEvent::query()->where('source_type', SubstanceExposureRecord::class)->where('source_id', $root->id)->count());
        $this->assertSame(1, ChemicalExposureHsProjection::query()->where('exposure_id', $root->id)->count());
        $this->assertSame(1, $this->delivery($root, 1)->attempts);
    }

    public function test_a_correction_escalates_the_same_event_and_keeps_its_workflow(): void
    {
        $root = $this->exposure(['medical_treatment' => 'none']);
        $event = $this->eventFor($root);
        $event->updateQuietly(['owner_user_id' => $this->manager->id, 'status' => HsEvent::STATUS_INVESTIGATING]);
        $this->assertSame(HsEvent::SEVERITY_LOW, $event->fresh()->severity);

        $root = $this->correct($root, ['medical_treatment' => 'hospitalisation', 'medical_attention_sought' => true]);

        $event->refresh();
        $this->assertSame(HsEvent::SEVERITY_HIGH, $event->severity);
        $this->assertTrue((bool) $event->investigation_required);
        $this->assertSame(HsEvent::STATUS_INVESTIGATING, $event->status);
        $this->assertSame($this->manager->id, (int) $event->owner_user_id);
        $this->assertNull($event->worksafe_notifiable);
        $this->assertSame(2, ChemicalExposureHsProjection::query()->where('exposure_id', $root->id)->count());
        $this->assertSame('delivered', $this->delivery($root, 2)->state);
        $this->assertSame(0, HsEventReviewFlag::query()->count());
    }

    public function test_a_correction_never_lowers_the_event(): void
    {
        $root = $this->exposure(['medical_treatment' => 'hospitalisation']);
        $this->correct($root, ['medical_treatment' => 'first_aid']);

        $event = $this->eventFor($root);
        $this->assertSame(HsEvent::SEVERITY_HIGH, $event->severity);
        $this->assertTrue((bool) $event->investigation_required);
    }

    public function test_a_correction_after_a_signed_worksafe_decision_flags_it_and_never_changes_the_decision(): void
    {
        $root = $this->exposure(['medical_treatment' => 'first_aid']);
        $event = $this->eventFor($root);
        app(HsEventService::class)->recordWorksafeDecision($event, false, 'First aid only: not a notifiable illness at the time.', $this->manager);
        $decided = array_intersect_key($event->fresh()->getAttributes(), array_flip(['worksafe_notifiable', 'worksafe_decided_at', 'worksafe_decided_by_user_id', 'worksafe_decision_reason', 'worksafe_status']));
        $this->travel(5)->minutes();

        $this->correct($root, ['medical_treatment' => 'hospitalisation', 'medical_attention_sought' => true]);

        $event->refresh();
        $this->assertSame($decided, array_intersect_key($event->getAttributes(), $decided));
        $this->assertSame(HsEvent::SEVERITY_HIGH, $event->severity);
        $flag = HsEventReviewFlag::query()->where('hs_event_id', $event->id)->sole();
        $this->assertSame(HsEventReviewFlag::REASON_WORKSAFE_REVIEW, $flag->reason);
        $this->assertSame([HsEvent::SEVERITY_MEDIUM, HsEvent::SEVERITY_HIGH], [$flag->from_severity, $flag->to_severity]);
        $this->assertSame(2, $flag->source_revision);
        $this->assertNull($flag->resolved_at);
    }

    public function test_a_review_after_a_signed_decision_raises_no_flag(): void
    {
        $root = $this->exposure(['medical_treatment' => 'first_aid']);
        $event = $this->eventFor($root);
        app(HsEventService::class)->recordWorksafeDecision($event, false, 'First aid only: not a notifiable illness at the time.', $this->manager);
        $this->travel(5)->minutes();

        $this->exposureService()->review($root, $this->manager, $this->privateCommand($root,
            ['reviewed_at' => now()->toIso8601String(), 'outcome' => 'recorded', 'notes' => 'Reviewed.']));

        $this->assertSame('delivered', $this->delivery($root, 2)->state);

        $this->assertSame(0, HsEventReviewFlag::query()->count());
    }

    public function test_an_escalation_after_close_is_flagged_and_the_event_stays_closed(): void
    {
        $root = $this->exposure(['medical_treatment' => 'none']);
        $event = $this->eventFor($root);
        $this->close($event);

        $this->correct($root, ['medical_treatment' => 'hospitalisation', 'medical_attention_sought' => true]);

        $event->refresh();
        $this->assertSame(HsEvent::STATUS_CLOSED, $event->status);
        $this->assertSame(HsEvent::SEVERITY_HIGH, $event->severity);
        $flag = HsEventReviewFlag::query()->where('hs_event_id', $event->id)->sole();
        $this->assertSame(HsEventReviewFlag::REASON_ESCALATED_AFTER_CLOSE, $flag->reason);
        $this->assertSame([HsEvent::SEVERITY_LOW, HsEvent::SEVERITY_HIGH], [$flag->from_severity, $flag->to_severity]);
    }

    public function test_an_unproved_event_copy_stops_the_delivery_for_a_manager_and_shows_only_to_exposure_managers(): void
    {
        config(['health_safety.exposure_automatic_delivery' => false]);
        $root = $this->exposure(['medical_treatment' => 'medical']);
        HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['source_type' => SubstanceExposureRecord::class, 'source_id' => $root->id,
            'event_category' => HsEvent::CATEGORY_EXPOSURE, 'site_id' => $root->site_id, 'staff_id' => $root->user_id,
            'idempotency_key' => HsEvent::buildIdempotencyKey(SubstanceExposureRecord::class, $root->id, HsEvent::CATEGORY_EXPOSURE)]));
        config(['health_safety.exposure_automatic_delivery' => true]);

        $this->system()->afterCommit($root->id);

        $row = $this->delivery($root, 1);
        $this->assertSame('pending', $row->state);
        $this->assertSame('integrity', $row->failure_code);
        $this->assertTrue($row->needs_manager);
        $this->assertNull($row->next_attempt_at);
        $this->assertSame(1, $row->failed_attempts);
        $this->assertSame(0, ChemicalExposureHsProjection::query()->where('exposure_id', $root->id)->count());
        // Integrity refusals are not retried automatically.
        $this->travel(30)->minutes();
        $this->assertSame([], $this->system()->recoverDue());

        $problems = app(ChemicalExposureDeliveryProblems::class);
        $this->assertSame([['exposure_id' => $root->id, 'site_id' => $this->site->id, 'site_name' => $this->site->name,
            'state' => 'needs_manager', 'since' => $row->created_at->toIso8601String()]], $problems->rows($this->manager));
        $this->assertSame([], $problems->rows($this->staff(['hazards.view', 'hr.wellbeing.view'])));
        $this->assertSame([], $problems->rows($this->reporter));
        $count = HealthSafetyReadContext::within($this->manager, ChemicalExposureCommandAccess::PERMISSIONS,
            fn (HealthSafetyReadContext $context) => $problems->countFor($this->manager, $context));
        $this->assertSame(1, $count);

        $tasks = (new ChemicalExposureDeliveryProvider)->authorizedTasks($this->manager);
        $this->assertCount(1, $tasks);
        $this->assertSame('/health-safety/chemical-exposures/'.$root->id.'?section=connections', $tasks[0]->link);
        $this->assertSame('Needs a manager', $tasks[0]->displayState);
        $this->assertNull($tasks[0]->client);
        $this->assertSame([], (new ChemicalExposureDeliveryProvider)->authorizedTasks($this->reporter));

        $this->actingAs($this->manager)->getJson('/health-safety/chemical-exposures/'.$root->id)->assertOk()
            ->assertJsonPath('delivery_alert.state', 'needs_manager');
        $reader = $this->staff(['hazards.view', 'hr.wellbeing.view']);
        $this->actingAs($reader)->getJson('/health-safety/chemical-exposures/'.$root->id)->assertOk()
            ->assertJsonPath('delivery_alert', null);
    }

    public function test_an_error_is_retried_on_the_backoff_then_delivered_by_the_recovery_command(): void
    {
        $fail = true;
        HsEvent::creating(function () use (&$fail) {
            if ($fail) { throw new \RuntimeException('Synthetic register outage.'); }
        });
        $root = $this->exposure(['medical_treatment' => 'first_aid']);

        $row = $this->delivery($root, 1);
        $this->assertSame('pending', $row->state);
        $this->assertSame('error', $row->failure_code);
        $this->assertFalse($row->needs_manager);
        $this->assertSame(now()->addSeconds(30)->format('Y-m-d H:i:s'), $row->next_attempt_at->format('Y-m-d H:i:s'));
        $this->assertNull($this->eventFor($root));
        $this->assertSame('failed', app(ChemicalExposureDeliveryProblems::class)->rows($this->manager)[0]['state']);

        // Not due yet: the recovery leaves it.
        $this->travel(10)->seconds();
        $this->assertSame([], $this->system()->recoverDue());
        $this->travel(25)->seconds();
        $this->assertSame([$row->id => 'failed'], $this->system()->recoverDue());
        $this->assertSame(2, $row->fresh()->failed_attempts);
        $this->assertSame(now()->addSeconds(60)->format('Y-m-d H:i:s'), $row->fresh()->next_attempt_at->format('Y-m-d H:i:s'));

        $fail = false;
        $this->travel(61)->seconds();
        $this->artisan('chemical-exposures:recover-deliveries')->assertSuccessful();

        $row->refresh();
        $this->assertSame('delivered', $row->state);
        $this->assertNull($row->failure_code);
        $this->assertNull($row->next_attempt_at);
        $this->assertSame(HsEvent::SEVERITY_MEDIUM, $this->eventFor($root)->severity);
        $this->assertSame([], app(ChemicalExposureDeliveryProblems::class)->rows($this->manager));
    }

    public function test_the_backoff_doubles_from_30_seconds_to_a_15_minute_cap(): void
    {
        $this->assertSame([30, 60, 120, 240, 480, 900, 900],
            array_map(fn ($n) => ChemicalExposureSystemDeliveryService::backoffSeconds($n), range(1, 7)));
    }

    public function test_a_delivery_the_inline_attempt_never_reached_is_overdue_after_10_minutes_and_recovered(): void
    {
        config(['health_safety.exposure_automatic_delivery' => false]);
        $root = $this->exposure(['medical_treatment' => 'none']);
        config(['health_safety.exposure_automatic_delivery' => true]);
        $this->assertSame([], app(ChemicalExposureDeliveryProblems::class)->rows($this->manager));

        $this->travel(11)->minutes();
        $this->assertSame('overdue', app(ChemicalExposureDeliveryProblems::class)->rows($this->manager)[0]['state']);

        $this->artisan('chemical-exposures:recover-deliveries')->assertSuccessful();
        $this->assertSame('delivered', $this->delivery($root, 1)->state);
        $this->assertNotNull($this->eventFor($root));
    }

    public function test_an_older_pending_revision_is_superseded_not_sent(): void
    {
        config(['health_safety.exposure_automatic_delivery' => false]);
        $root = $this->exposure(['medical_treatment' => 'none']);
        $root = $this->correct($root, ['medical_treatment' => 'medical']);
        config(['health_safety.exposure_automatic_delivery' => true]);

        $this->system()->afterCommit($root->id);

        $this->assertSame('superseded', $this->delivery($root, 1)->state);
        $this->assertSame('delivered', $this->delivery($root, 2)->state);
        $this->assertSame(HsEvent::SEVERITY_HIGH, $this->eventFor($root)->severity);
        $this->assertSame(1, ChemicalExposureHsProjection::query()->where('exposure_id', $root->id)->count());
    }

    public function test_a_follow_up_assignment_sends_no_notice_and_never_counts_as_stuck(): void
    {
        $root = $this->exposure();
        $receiver = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $receiver->id, 'instruction' => 'Check in with the worker.', 'due_at' => '2026-10-13T01:00:00Z']));
        $this->travel(30)->minutes();
        $this->artisan('chemical-exposures:recover-deliveries')->assertSuccessful();

        $followup = ChemicalExposureSourceDelivery::query()->where('exposure_id', $root->id)->where('kind', 'followup')->sole();
        $this->assertSame('pending', $followup->state);
        $this->assertSame(0, $followup->attempts);
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $receiver->id)->count());
        $this->assertSame('delivered', $this->delivery($root, 2)->state);
        $this->assertSame([], app(ChemicalExposureDeliveryProblems::class)->rows($this->manager));
    }

    public function test_switched_off_the_save_stages_a_pending_delivery_and_creates_no_event(): void
    {
        config(['health_safety.exposure_automatic_delivery' => false]);
        $root = $this->exposure();

        $this->assertSame('pending', $this->delivery($root, 1)->state);
        $this->assertNull($this->eventFor($root));
    }

    public function test_review_flags_show_in_tasks_and_a_manager_marks_them_reviewed(): void
    {
        $root = $this->exposure(['medical_treatment' => 'none']);
        $event = $this->eventFor($root);
        $this->close($event);
        $this->correct($root, ['medical_treatment' => 'medical']);
        $flag = HsEventReviewFlag::query()->where('hs_event_id', $event->id)->sole();

        $tasks = (new HsEventReviewFlagProvider)->authorizedTasks($this->manager);
        $this->assertSame(['hs_event_review-'.$event->id], array_map(fn ($task) => $task->id, $tasks));
        $this->assertSame('Severity rose after the event was closed', $tasks[0]->title);
        $this->assertSame([], (new HsEventReviewFlagProvider)->authorizedTasks($this->staff(['hazards.view'])));

        $url = '/health-safety/events/'.$event->id.'/review-flags/'.$flag->id.'/resolve';
        $this->actingAs($this->staff(['hazards.view']))->post($url, ['note' => 'Checked the corrected treatment.'])->assertForbidden();
        $this->actingAs($this->manager)->post($url, ['note' => 'short'])->assertSessionHasErrors('note');
        $this->assertNull($flag->fresh()->resolved_at);
        $this->actingAs($this->manager)->post($url, ['note' => 'Checked the corrected treatment; no reopening needed.'])->assertRedirect();

        $flag->refresh();
        $this->assertNotNull($flag->resolved_at);
        $this->assertSame($this->manager->id, $flag->resolved_by);
        $this->assertSame(HsEvent::STATUS_CLOSED, $event->fresh()->status);
        $this->assertSame([], (new HsEventReviewFlagProvider)->authorizedTasks($this->manager));
    }

    public function test_adoption_takes_over_an_exactly_matching_observer_event_and_delivers_later_corrections(): void
    {
        config(['health_safety.exposure_automatic_delivery' => false]);
        $root = $this->exposure(['medical_treatment' => 'none']);
        $first = \App\Models\ChemicalExposureSourceVersion::query()->where('exposure_id', $root->id)->where('aggregate_revision', 1)->sole();
        $projection = ChemicalExposureHsDestinationRules::projection($first, $first);
        $observerEvent = HsEvent::withoutEvents(fn () => HsEvent::factory()->create([...$projection, 'severity' => HsEvent::SEVERITY_LOW,
            'status' => HsEvent::STATUS_OPEN, 'investigation_required' => false]));
        $root = $this->correct($root, ['medical_treatment' => 'hospitalisation', 'medical_attention_sought' => true]);
        config(['health_safety.exposure_automatic_delivery' => true]);

        $this->artisan('chemical-exposures:adopt-hs-events', ['--dry-run' => true])
            ->expectsOutputToContain('would_adopt')->assertSuccessful();
        $this->assertSame(0, ChemicalExposureHsProjection::query()->where('exposure_id', $root->id)->count());

        $this->artisan('chemical-exposures:adopt-hs-events')->expectsOutputToContain('adopted')->assertSuccessful();

        $markers = ChemicalExposureHsProjection::query()->where('exposure_id', $root->id)->orderBy('source_revision')->get();
        $this->assertSame([1, 2], $markers->pluck('source_revision')->all());
        $this->assertSame(ChemicalExposureHsDestinationRules::ADOPTION_AUDIT_ACTION, AuditLog::query()->findOrFail($markers[0]->audit_log_id)->action);
        $observerEvent->refresh();
        $this->assertSame(HsEvent::SEVERITY_HIGH, $observerEvent->severity);
        $this->assertTrue((bool) $observerEvent->investigation_required);
        $this->assertSame('delivered', $this->delivery($root, 2)->state);
        $this->assertSame(1, HsEvent::query()->where('source_type', SubstanceExposureRecord::class)->where('source_id', $root->id)->count());
    }

    public function test_adoption_lists_a_mismatched_event_for_repair_and_leaves_it_alone(): void
    {
        config(['health_safety.exposure_automatic_delivery' => false]);
        $root = $this->exposure(['medical_treatment' => 'none']);
        $first = \App\Models\ChemicalExposureSourceVersion::query()->where('exposure_id', $root->id)->where('aggregate_revision', 1)->sole();
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->create([...ChemicalExposureHsDestinationRules::projection($first, $first),
            'site_id' => $this->foreignSite->id, 'severity' => HsEvent::SEVERITY_LOW]));
        config(['health_safety.exposure_automatic_delivery' => true]);
        $before = $event->fresh()->getAttributes();

        $this->artisan('chemical-exposures:adopt-hs-events')->expectsOutputToContain('needs_repair')->assertSuccessful();

        $this->assertSame($before, $event->fresh()->getAttributes());
        $this->assertSame(0, ChemicalExposureHsProjection::query()->where('exposure_id', $root->id)->count());
    }
}
