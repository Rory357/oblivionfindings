<?php

namespace Tests\Feature\HealthSafety;

use App\Models\FirstAidRecord;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\Permission;
use App\Models\SafeWorkProcedure;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\FirstAidService;
use App\Services\HealthSafety\HsCorrectiveActionWorkspace;
use App\Services\HealthSafety\RiskAssessmentWorkspaceConnectionsService;
use App\Services\HealthSafety\SafeWorkProcedureService;
use App\Services\Sites\HazardWorkspaceConnectionsService;
use App\Services\Tasks\TaskAggregator;
use App\Support\HealthSafety\RiskAssessmentPresenter;
use App\Support\HealthSafety\SafeWorkProcedurePresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Real accepted source facts; unrelated private sources must never be materialized. */
class HsRecordCandidateContextPrivacyTest extends WorkplaceInjurySourceTestCase
{
    public function test_exact_hazard_tuple_does_not_materialize_an_unrelated_accepted_injury(): void
    {
        $this->injury();
        $hazard = SiteHazard::withoutEvents(fn () => SiteHazard::create([
            'site_id' => $this->site->id, 'reference_number' => 'HAZ-BOUNDED', 'hazard_type' => 'slip_trip_fall',
            'severity' => 'low', 'likelihood' => 'rare', 'risk_rating' => 'low', 'description' => 'Loose mat',
            'status' => 'open', 'reported_by_user_id' => $this->manager->id,
        ]));
        $event = $this->ordinary(['source_type' => SiteHazard::class, 'source_id' => $hazard->id, 'event_category' => HsEvent::CATEGORY_HAZARD]);
        $this->ordinary(['source_type' => $hazard->getMorphClass(), 'source_id' => $hazard->id, 'event_category' => HsEvent::CATEGORY_INCIDENT]);
        [$connections, $queries] = $this->queries(fn () => app(HazardWorkspaceConnectionsService::class)->forViewer($hazard, $this->factual));
        $this->assertSame([$event->id], array_column($connections['safety_reviews'], 'id'));
        $this->assertContains('hs_event-'.$event->id, array_column($connections['tasks'], 'id'));
        $this->assertNoInjuryMaterialization($queries);
    }

    public function test_exact_risk_record_and_action_task_share_authority_without_unrelated_source_proof(): void
    {
        $this->injury();
        $event = $this->ordinary();
        $action = $this->action($event);
        $risk = HsRiskAssessment::factory()->forSite($this->site->id)->createQuietly(['hs_event_id' => $event->id]);
        [$connections, $queries] = $this->queries(fn () => app(RiskAssessmentWorkspaceConnectionsService::class)->forViewer($risk, $this->factual));
        $this->assertSame($event->id, $connections['safety_review']['id']);
        $this->assertSame([$action->id], array_column($connections['corrective_actions'], 'id'));
        $this->assertEqualsCanonicalizing(['hs_event-'.$event->id, 'corrective_action-'.$action->id], array_column($connections['tasks'], 'id'));
        $this->assertNoInjuryMaterialization($queries);
        $task = app(TaskAggregator::class)->findItemFor($this->factual, 'corrective_action', $action->id, ['include_done' => true]);
        $this->assertSame($task->title, $connections['corrective_actions'][0]['title']);
        $this->assertSame($task->status, $connections['corrective_actions'][0]['status']);
    }

    public function test_exact_corrective_workspace_preserves_participant_evidence_without_unrelated_source_proof(): void
    {
        $this->injury();
        $event = $this->ordinary();
        $action = $this->action($event);
        [$workspace, $queries] = $this->queries(fn () => app(HsCorrectiveActionWorkspace::class)->forViewer($action, $this->factual));
        $this->assertSame($action->id, $workspace['action']['id']);
        $this->assertSame($event->id, $workspace['review']['id']);
        $this->assertTrue($workspace['can']['complete']);
        $this->assertSame('Participant-only completion evidence', $workspace['action']['evidence']['completion_notes']);
        $this->assertNoInjuryMaterialization($queries);
    }

    public function test_picker_filters_unapproved_requested_site_before_private_discovery(): void
    {
        $otherSite = Site::factory()->create(['is_active' => true]);
        $otherWorker = $this->staff([], $otherSite);
        $this->approveSite($this->manager, $otherSite);
        $this->injury(['site_id' => $otherSite->id, 'user_id' => $otherWorker->id]);
        $ordinary = $this->ordinary();
        [$pickers, $queries] = $this->queries(fn () => RiskAssessmentPresenter::siteScopedPickers([$this->site->id, $otherSite->id], $this->factual));
        $this->assertSame([$this->site->id], $pickers['sites']->pluck('id')->all());
        $this->assertSame([$ordinary->id], $pickers['events']->pluck('id')->all());
        $this->assertNoInjuryMaterialization($queries);
    }

    public function test_procedure_foreign_version_is_rejected_before_unrelated_injury_discovery(): void
    {
        $injury = $this->injury();
        $privateEvent = $this->injuryEvent($injury);
        $record = $this->procedure();
        $other = $this->procedure();
        $healthy = $this->reviewRequest($record, $this->ordinary());
        $damaged = $this->reviewRequest($record, $privateEvent);
        DB::table('procedure_review_requests')->where('id', $damaged->id)->update(['requested_version_id' => $other->published_version_id]);
        [$detail, $queries] = $this->queries(fn () => SafeWorkProcedurePresenter::detail($record, $this->manager));
        $this->assertSame([$healthy->id], array_column($detail['review_requests'], 'id'));
        $this->assertTrue($detail['review_requests'][0]['can']['start']);
        $this->assertNoInjuryMaterialization($queries);
        $this->assertSame($other->published_version_id, $damaged->fresh()->requested_version_id);
    }

    public function test_procedure_mismatched_named_action_is_rejected_before_injury_discovery(): void
    {
        $injury = $this->injury();
        $record = $this->procedure();
        $ordinary = $this->ordinary();
        $action = $this->action($ordinary, $this->manager->id);
        $healthy = $this->reviewRequest($record, $ordinary, ['hs_corrective_action_id' => $action->id]);
        $damaged = $this->reviewRequest($record, $this->injuryEvent($injury));
        DB::table('procedure_review_requests')->where('id', $damaged->id)->update(['hs_corrective_action_id' => $action->id]);
        [$detail, $queries] = $this->queries(fn () => SafeWorkProcedurePresenter::detail($record, $this->manager));
        $this->assertSame([$healthy->id], array_column($detail['review_requests'], 'id'));
        $this->assertSame($action->id, $detail['review_requests'][0]['corrective_action']['id']);
        $this->assertNoInjuryMaterialization($queries);
        $this->assertSame($action->id, $damaged->fresh()->hs_corrective_action_id);
    }

    public function test_damaged_confidential_candidate_remains_denied_without_hiding_the_independent_record(): void
    {
        $injury = $this->injury();
        $event = $this->injuryEvent($injury);
        $risk = HsRiskAssessment::factory()->forSite($this->site->id)->createQuietly(['hs_event_id' => $event->id]);
        $ordinary = $this->ordinary();
        $control = HsRiskAssessment::factory()->forSite($this->site->id)->createQuietly(['hs_event_id' => $ordinary->id]);
        $service = app(RiskAssessmentWorkspaceConnectionsService::class);
        $this->assertSame($event->id, $service->forViewer($risk, $this->factual)['safety_review']['id']);
        DB::table('workplace_injury_source_versions')->where('workplace_injury_id', $injury->id)->update(['factual_digest' => str_repeat('0', 64)]);
        $hidden = $service->forViewer($risk, $this->factual);
        $this->assertNull($hidden['safety_review']);
        $this->assertSame([], $hidden['tasks']);
        $this->assertSame($ordinary->id, $service->forViewer($control, $this->factual)['safety_review']['id']);
    }

    public function test_retained_first_aid_risk_uses_exact_candidate_sql_and_canonical_task_parity(): void
    {
        $this->worker->hrEmployeeProfile()->update(['is_first_aider' => true]);
        $payload = ['site_id' => $this->site->id, 'treated_person_type' => 'staff', 'treated_person_id' => $this->worker->id,
            'treated_person_name' => 'Recorded staff subject', 'treatment_date' => now()->subHour()->toISOString(),
            'injury_illness_type' => 'cut', 'injury_illness_description' => 'Private treatment narrative',
            'treatment_given' => 'Private treatment given', 'treatment_outcome' => 'returned_to_activity',
            'first_aider_id' => $this->worker->id, 'ambulance_called' => false, 'incident_reported' => false,
            'idempotency_key' => (string) Str::uuid()];
        $record = app(FirstAidService::class)->create($this->manager, $payload);
        $event = $this->ordinary(['source_type' => $record->getMorphClass(), 'source_id' => $record->id,
            'staff_id' => $this->worker->id, 'event_category' => HsEvent::CATEGORY_INJURY]);
        $other = app(FirstAidService::class)->create($this->manager, [...$payload, 'idempotency_key' => (string) Str::uuid()]);
        $unrelated = $this->ordinary(['source_type' => FirstAidRecord::class, 'source_id' => $other->id,
            'staff_id' => $this->worker->id, 'event_category' => HsEvent::CATEGORY_INJURY]);
        $risk = HsRiskAssessment::factory()->forSite($this->site->id)->createQuietly(['hs_event_id' => $event->id]);
        [$connections, $queries] = $this->queries(fn () => app(RiskAssessmentWorkspaceConnectionsService::class)->forViewer($risk, $this->factual));
        $this->assertSame($event->id, $connections['safety_review']['id']);
        $this->assertSame(['hs_event-'.$event->id], array_column($connections['tasks'], 'id'));
        $this->assertStringNotContainsString('Private treatment', json_encode($connections));
        $proofs = array_values(array_filter($queries, fn ($entry) => str_contains(strtolower($entry['query']), 'first_aid_records')));
        $this->assertNotEmpty($proofs);
        foreach ($proofs as $entry) {
            $sql = strtolower(str_replace('`', '', $entry['query']));
            $this->assertStringContainsString('hs_events.id in (select hs_events.id from hs_events', $sql);
            $this->assertStringContainsString('exists (select 1 from first_aid_records', $sql);
            $this->assertStringNotContainsString('treatment_given', $sql);
            $this->assertStringNotContainsString('injury_illness_description', $sql);
            $this->assertFalse(str_starts_with(explode(' from ', $sql, 2)[1] ?? '', 'first_aid_records'));
        }
        $this->assertDatabaseHas('hs_events', ['id' => $unrelated->id, 'source_id' => $other->id]);
    }
    private function ordinary(array $changes = []): HsEvent
    {
        return HsEvent::factory()->createQuietly(['site_id' => $this->site->id,
            'event_category' => HsEvent::CATEGORY_INCIDENT, 'staff_id' => null, 'client_id' => null, 'asset_id' => null,
            'status' => HsEvent::STATUS_OPEN, 'created_by' => $this->factual->id, ...$changes]);
    }

    private function action(HsEvent $event, ?int $owner = null): HsCorrectiveAction
    {
        return HsCorrectiveAction::factory()->createQuietly(['hs_event_id' => $event->id,
            'assigned_to_user_id' => $owner ?? $this->factual->id, 'created_by' => $this->manager->id,
            'status' => HsCorrectiveAction::STATUS_IN_PROGRESS, 'completion_notes' => 'Participant-only completion evidence']);
    }

    private function injuryEvent(WorkplaceInjury $injury): HsEvent
    {
        return HsEvent::whereIn('source_type', array_unique([WorkplaceInjury::class, $injury->getMorphClass()]))->where('source_id', $injury->id)->sole();
    }

    private function procedure(): SafeWorkProcedure
    {
        foreach (['procedures.view', 'procedures.create', 'procedures.manage', 'procedures.approve'] as $key) {
            $this->manager->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => true]]);
        }
        $service = app(SafeWorkProcedureService::class);
        $record = $service->create($this->manager, ['title' => 'Bounded controlled instructions',
            'reference_number' => 'SWP-BOUNDED-'.Str::uuid(), 'category' => 'manual_handling',
            'steps' => [['description' => 'Use the recorded method.', 'safety_notes' => 'Check the task first.']],
            'applicable_roles' => ['support_worker'], 'applicable_sites' => [$this->site->id],
            'related_training' => [], 'owner_id' => $this->manager->id, 'review_date' => null, 'review_frequency_months' => null]);
        $service->lifecycle($record, $this->manager, 'submit-for-review', $this->procedureGuards($record));
        $service->lifecycle($record, $this->manager, 'approve', [...$this->procedureGuards($record), 'approval_note' => 'Recorded source review approval.']);
        return $record->fresh();
    }

    private function procedureGuards(SafeWorkProcedure $record): array
    {
        $record = $record->fresh();
        return ['expected_version' => $record->current_version, 'expected_lock_version' => $record->lock_version];
    }

    private function reviewRequest(SafeWorkProcedure $record, HsEvent $event, array $extra = []): \App\Models\ProcedureReviewRequest
    {
        return app(SafeWorkProcedureService::class)->requestReview($record, $this->manager, [
            'hs_event_id' => $event->id, 'requested_version_id' => $record->published_version_id,
            'assigned_to_user_id' => $this->manager->id, 'note' => 'Review the current facts.',
            'idempotency_key' => (string) Str::uuid(), ...$extra,
        ]);
    }

    private function queries(callable $read): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try { return [$read(), DB::getQueryLog()]; }
        finally { DB::disableQueryLog(); DB::flushQueryLog(); }
    }

    private function assertNoInjuryMaterialization(array $queries): void
    {
        foreach ($queries as $entry) {
            // A complete identity-only denial subquery is allowed. It is not a
            // standalone materialization of unrelated injury records or proof.
            if (preg_match('/^\s*select\b.*?\bfrom\s+[`"]?([a-z_]+)/is', $entry['query'], $table)) {
                $this->assertNotContains(strtolower($table[1]), ['workplace_injuries', 'workplace_injury_source_versions']);
            }
        }
    }
}