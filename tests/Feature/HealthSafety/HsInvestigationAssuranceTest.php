<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsInvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class HsInvestigationAssuranceTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_review_and_approval_are_three_distinct_authenticated_actions(): void
    {
        $site = $this->activeSite('Three actor assurance');
        $lead = $this->siteActor($site);
        $submitter = $this->siteActor($site);
        $reviewer = $this->siteActor($site);
        $approver = $this->siteActor($site);
        $forged = $this->siteActor($site);
        [$event, $investigation] = $this->underReviewInvestigation($site, $lead, $submitter);
        $path = "/health-safety/events/{$event->id}/investigations/{$investigation->id}/complete";

        $this->actingAs($reviewer)
            ->post($path, ['approved_by_id' => $forged->id])
            ->assertSessionHas('success');

        $investigation->refresh();
        $this->assertSame(HsInvestigation::STATUS_REVIEWED, $investigation->status);
        $this->assertSame($submitter->id, $investigation->submitted_by_id);
        $this->assertSame($reviewer->id, $investigation->reviewed_by_id);
        $this->assertNull($investigation->approved_by_id);
        $this->assertSame(HsEvent::STATUS_INVESTIGATING, $event->fresh()->status);

        $this->actingAs($approver)
            ->post($path, ['approved_by_id' => $forged->id])
            ->assertSessionHas('success');

        $investigation->refresh();
        $this->assertSame(HsInvestigation::STATUS_COMPLETED, $investigation->status);
        $this->assertSame($submitter->id, $investigation->submitted_by_id);
        $this->assertSame($reviewer->id, $investigation->reviewed_by_id);
        $this->assertSame($approver->id, $investigation->approved_by_id);
        $this->assertSame($approver->id, $investigation->updated_by);
        $this->assertNotNull($investigation->submitted_at);
        $this->assertNotNull($investigation->reviewed_at);
        $this->assertNotNull($investigation->approved_at);
        $this->assertSame(HsEvent::STATUS_CORRECTIVE_ACTION, $event->fresh()->status);
        $this->assertSame(1, $this->terminalAuditCount($investigation, 'healthSafety.investigation.reviewed'));
        $this->assertSame(1, $this->terminalAuditCount($investigation, 'healthSafety.investigation.approved'));
        $this->actingAs($approver)
            ->get("/health-safety/events/{$event->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.investigations.0.submitted_by_name', $submitter->name)
                ->where('detail.investigations.0.reviewed_by_name', $reviewer->name)
                ->where('detail.investigations.0.approved_by_name', $approver->name));
        $this->assertSame(0, AuditLog::query()
            ->where('user_id', $forged->id)
            ->where('auditable_id', $investigation->id)
            ->whereIn('action', [
                'healthSafety.investigation.reviewed',
                'healthSafety.investigation.approved',
            ])
            ->count());
    }

    public function test_investigation_team_submitter_and_reviewer_cannot_cross_assurance_roles(): void
    {
        $site = $this->activeSite('Independent roles');
        $lead = $this->siteActor($site);
        $teamMember = $this->siteActor($site);
        $submitter = $this->siteActor($site);
        $reviewer = $this->siteActor($site);
        [$event, $investigation] = $this->underReviewInvestigation(
            $site,
            $lead,
            $submitter,
            [$teamMember->id],
        );
        $path = "/health-safety/events/{$event->id}/investigations/{$investigation->id}/complete";

        foreach ([$lead, $teamMember, $submitter] as $actor) {
            $this->actingAs($actor)->from('/health-safety/events')->post($path)->assertSessionHas('error');
            $this->assertSame(HsInvestigation::STATUS_UNDER_REVIEW, $investigation->fresh()->status);
        }

        $this->actingAs($reviewer)->post($path)->assertSessionHas('success');
        $this->actingAs($reviewer)->from('/health-safety/events')->post($path)->assertSessionHas('error');
        $this->assertSame(HsInvestigation::STATUS_REVIEWED, $investigation->fresh()->status);
        $this->assertNull($investigation->fresh()->approved_by_id);
    }

    public function test_unapproved_and_ended_staff_fail_closed_for_review(): void
    {
        $site = $this->activeSite('Current staff eligibility');
        $lead = $this->siteActor($site);
        $submitter = $this->siteActor($site);
        [$unapprovedEvent, $unapprovedInvestigation] = $this->underReviewInvestigation($site, $lead, $submitter);
        [$endedEvent, $endedInvestigation] = $this->underReviewInvestigation($site, $lead, $submitter);

        $unapproved = $this->siteActor($site);
        $unapproved->forceFill(['approved_at' => null])->save();
        $ended = $this->siteActor($site);
        $ended->hrEmployeeProfile()->update([
            'end_date' => today()->subDay(),
            'is_active' => false,
        ]);

        foreach ([
            [$unapproved, $unapprovedEvent, $unapprovedInvestigation],
            [$ended, $endedEvent, $endedInvestigation],
        ] as [$actor, $event, $investigation]) {
            $this->actingAs($actor)
                ->post("/health-safety/events/{$event->id}/investigations/{$investigation->id}/complete")
                ->assertForbidden();
            $this->assertSame(HsInvestigation::STATUS_UNDER_REVIEW, $investigation->fresh()->status);
            $this->assertNull($investigation->fresh()->reviewed_by_id);
        }
    }

    public function test_review_and_approval_fail_closed_without_complete_prior_provenance(): void
    {
        $site = $this->activeSite('Missing assurance provenance');
        $lead = $this->siteActor($site);
        $reviewer = $this->siteActor($site);
        $approver = $this->siteActor($site);
        [$event, $reviewCandidate] = $this->underReviewInvestigation($site, $lead, $lead);
        $reviewCandidate->update(['submitted_by_id' => null, 'submitted_at' => null]);
        $approvalCandidate = HsInvestigation::factory()->withFindings()->create([
            'hs_event_id' => $event->id,
            'status' => HsInvestigation::STATUS_REVIEWED,
            'submitted_by_id' => $lead->id,
            'submitted_at' => now()->subHour(),
            'reviewed_by_id' => null,
            'reviewed_at' => null,
            'created_by' => $lead->id,
        ]);

        $this->actingAs($reviewer);
        $this->assertInvalidTransition(fn () => app(HsInvestigationService::class)
            ->review($reviewCandidate, $reviewer));
        $this->actingAs($approver);
        $this->assertInvalidTransition(fn () => app(HsInvestigationService::class)
            ->complete($approvalCandidate, $approver));

        $this->assertSame(HsInvestigation::STATUS_UNDER_REVIEW, $reviewCandidate->fresh()->status);
        $this->assertSame(HsInvestigation::STATUS_REVIEWED, $approvalCandidate->fresh()->status);
    }

    public function test_wrong_site_is_concealed_but_explicit_global_hs_authority_can_review_and_approve(): void
    {
        $siteA = $this->activeSite('Local assurance site');
        $siteB = $this->activeSite('Foreign assurance site');
        $localActor = $this->siteActor($siteA);
        $lead = $this->siteActor($siteB);
        $submitter = $this->siteActor($siteB);
        [$event, $investigation] = $this->underReviewInvestigation($siteB, $lead, $submitter);
        $path = "/health-safety/events/{$event->id}/investigations/{$investigation->id}/complete";

        $this->actingAs($localActor)->post($path)->assertNotFound();
        $this->assertSame(HsInvestigation::STATUS_UNDER_REVIEW, $investigation->fresh()->status);

        $globalReviewer = $this->globalActor($siteA);
        $globalApprover = $this->globalActor($siteA);
        $this->actingAs($globalReviewer)->post($path)->assertSessionHas('success');
        $this->actingAs($globalApprover)->post($path)->assertSessionHas('success');

        $investigation->refresh();
        $this->assertSame($globalReviewer->id, $investigation->reviewed_by_id);
        $this->assertSame($globalApprover->id, $investigation->approved_by_id);
    }

    public function test_domain_assurance_decisions_require_the_matching_authenticated_principal(): void
    {
        $site = $this->activeSite('No auth service calls');
        $lead = $this->siteActor($site);
        $submitter = $this->siteActor($site);
        $reviewer = $this->siteActor($site);
        $approver = $this->siteActor($site);
        $reworker = $this->siteActor($site);
        [, $reviewCandidate] = $this->underReviewInvestigation($site, $lead, $submitter);
        [, $reworkCandidate] = $this->underReviewInvestigation($site, $lead, $submitter);
        [, $approvalCandidate] = $this->reviewedInvestigation($site, $lead, $submitter, $reviewer);
        $impostor = $this->siteActor($site);
        $service = app(HsInvestigationService::class);

        $this->actingAs($impostor);
        $this->assertForbiddenServiceCall(fn () => $service->review($reviewCandidate, $reviewer));
        $this->actingAs($reviewer);
        $this->assertForbiddenServiceCall(fn () => $service->complete($approvalCandidate, $approver));

        auth()->logout();
        $this->assertForbiddenServiceCall(fn () => $service->review($reviewCandidate, $reviewer));
        $this->assertForbiddenServiceCall(fn () => $service->returnForRework($reworkCandidate, 'More evidence required.', $reworker));
        $this->assertForbiddenServiceCall(fn () => $service->complete($approvalCandidate, $approver));

        $this->assertSame(HsInvestigation::STATUS_UNDER_REVIEW, $reviewCandidate->fresh()->status);
        $this->assertSame(HsInvestigation::STATUS_UNDER_REVIEW, $reworkCandidate->fresh()->status);
        $this->assertSame(HsInvestigation::STATUS_REVIEWED, $approvalCandidate->fresh()->status);
    }

    public function test_rework_is_not_a_positive_review_and_cannot_follow_an_accepted_review(): void
    {
        $site = $this->activeSite('Review rework separation');
        $lead = $this->siteActor($site);
        $submitter = $this->siteActor($site);
        $reworker = $this->siteActor($site);
        $reviewer = $this->siteActor($site);
        [, $investigation] = $this->underReviewInvestigation($site, $lead, $submitter);
        $service = app(HsInvestigationService::class);

        $this->actingAs($reworker);
        $service->returnForRework($investigation, 'Validate the witness chronology.', $reworker);
        $investigation->refresh();
        $this->assertSame(HsInvestigation::STATUS_IN_PROGRESS, $investigation->status);
        $this->assertNull($investigation->reviewed_by_id);
        $this->assertNull($investigation->reviewed_at);

        $this->actingAs($submitter);
        $service->recordFindings($investigation, [
            'findings_summary' => $investigation->findings_summary,
            'recommendations' => $investigation->recommendations,
        ]);
        $service->submitForReview($investigation, $submitter);
        $this->actingAs($reviewer);
        $service->review($investigation, $reviewer);

        $this->actingAs($reworker);
        try {
            $service->returnForRework($investigation, 'Late rework attempt.', $reworker);
            $this->fail('An accepted review was returned for rework.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $investigation->refresh();
        $this->assertSame(HsInvestigation::STATUS_REVIEWED, $investigation->status);
        $this->assertSame($reviewer->id, $investigation->reviewed_by_id);
        $this->assertSame(1, $this->terminalAuditCount($investigation, 'healthSafety.investigation.returnedForRework'));
        $this->assertSame(1, $this->terminalAuditCount($investigation, 'healthSafety.investigation.reviewed'));
    }

    public function test_approval_audit_failure_rolls_back_and_retry_records_one_terminal_audit_without_stale_projection(): void
    {
        $site = $this->activeSite('Approval retry');
        $lead = $this->siteActor($site);
        $submitter = $this->siteActor($site);
        $reviewer = $this->siteActor($site);
        $approver = $this->siteActor($site);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $incident = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'investigation_status' => 'in_progress',
        ]);
        $event = HsEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->firstOrFail();
        $event->update(['site_id' => $site->id, 'status' => HsEvent::STATUS_INVESTIGATING]);
        $incident->update(['investigation_status' => 'in_progress']);
        $investigation = $this->investigationForEvent($event, $lead, $submitter);
        $service = app(HsInvestigationService::class);

        $this->actingAs($reviewer);
        $service->review($investigation, $reviewer);
        Event::listen('eloquent.creating: '.AuditLog::class, static function (AuditLog $audit): void {
            if ($audit->action === 'healthSafety.investigation.approved') {
                throw new \RuntimeException('Injected investigation approval audit failure.');
            }
        });

        try {
            $this->actingAs($approver);
            $service->complete($investigation, $approver);
            $this->fail('Investigation approval unexpectedly survived an audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected investigation approval audit failure.', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }

        $investigation->refresh();
        $this->assertSame(HsInvestigation::STATUS_REVIEWED, $investigation->status);
        $this->assertSame($reviewer->id, $investigation->reviewed_by_id);
        $this->assertNull($investigation->approved_by_id);
        $this->assertSame(HsEvent::STATUS_INVESTIGATING, $event->fresh()->status);
        $this->assertSame('in_progress', $incident->fresh()->investigation_status);
        $this->assertSame(0, $this->terminalAuditCount($investigation, 'healthSafety.investigation.approved'));

        $this->actingAs($approver);
        $service->complete($investigation, $approver);
        $this->assertSame(HsInvestigation::STATUS_COMPLETED, $investigation->fresh()->status);
        $this->assertSame(HsEvent::STATUS_CORRECTIVE_ACTION, $event->fresh()->status);
        $this->assertSame('completed', $incident->fresh()->investigation_status);
        $this->assertSame(1, $this->terminalAuditCount($investigation, 'healthSafety.investigation.approved'));
    }

    public function test_assurance_locks_parent_then_investigation_before_rechecking_state(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $site = $this->activeSite('Assurance locks');
        $lead = $this->siteActor($site);
        $submitter = $this->siteActor($site);
        $reviewer = $this->siteActor($site);
        [, $investigation] = $this->underReviewInvestigation($site, $lead, $submitter);
        DB::connection()->enableQueryLog();

        $this->actingAs($reviewer);
        app(HsInvestigationService::class)->review($investigation, $reviewer);

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn (string $query): string => strtolower(str_replace(['`', '"'], '', $query)))
            ->values();
        DB::connection()->disableQueryLog();
        $eventLock = $queries->search(fn (string $query): bool => str_contains($query, 'from hs_events')
            && str_contains($query, 'for update'));
        $investigationLock = $queries->search(fn (string $query): bool => str_contains($query, 'from hs_investigations')
            && str_contains($query, 'for update'));

        $this->assertNotFalse($eventLock);
        $this->assertNotFalse($investigationLock);
        $this->assertLessThan($investigationLock, $eventLock);
    }

    public function test_three_actor_concurrency_and_replay_matrix_serializes_review_rework_and_approval(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $site = $this->activeSite('Concurrent assurance');
        $lead = $this->siteActor($site);
        $submitter = $this->siteActor($site);
        $reviewer = $this->siteActor($site);
        $reworker = $this->siteActor($site);
        $approver = $this->siteActor($site);
        [, $investigation] = $this->underReviewInvestigation($site, $lead, $submitter);
        $database = $connection->getDatabaseName();
        $token = Str::uuid()->toString();
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hs-assurance-go-{$token}";
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."hs-assurance-ready-review-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."hs-assurance-ready-rework-{$token}",
        ];
        $processes = [];
        $committed = false;
        $actorIds = [$lead->id, $submitter->id, $reviewer->id, $reworker->id, $approver->id];

        $connection->commit();
        $committed = true;

        try {
            foreach ([
                ['review', $reviewer->id, $readyPaths[0]],
                ['rework', $reworker->id, $readyPaths[1]],
            ] as [$decision, $actorId, $readyPath]) {
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/HsInvestigationDecisionWorker.php'),
                    $database,
                    (string) $investigation->id,
                    (string) $actorId,
                    $decision,
                    $readyPath,
                    $releasePath,
                ]);
                $process->setTimeout(90);
                $process->start();
                $processes[] = $process;
            }

            foreach ($readyPaths as $index => $readyPath) {
                $this->waitForWorker($processes[$index], $readyPath);
            }
            file_put_contents($releasePath, 'go', LOCK_EX);

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'Concurrent investigation decision worker failed.',
                );
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            $this->assertCount(1, collect($results)->where('result', 'accepted'));
            $this->assertCount(1, collect($results)->where('result', 'rejected'));
            $service = app(HsInvestigationService::class);
            $investigation = HsInvestigation::query()->findOrFail($investigation->id);
            if ($investigation->status === HsInvestigation::STATUS_IN_PROGRESS) {
                $this->actingAs($submitter);
                $service->recordFindings($investigation, [
                    'findings_summary' => $investigation->findings_summary,
                    'recommendations' => $investigation->recommendations,
                ]);
                $service->submitForReview($investigation, $submitter);
                $this->actingAs($reviewer);
                $service->review($investigation, $reviewer);
            }

            $this->actingAs($approver);
            $service->complete($investigation, $approver);
            $reviewedAt = $investigation->fresh()->reviewed_at;
            $approvedAt = $investigation->fresh()->approved_at;

            $this->actingAs($reviewer);
            $this->assertInvalidTransition(fn () => $service->review($investigation, $reviewer));
            $this->actingAs($approver);
            $this->assertInvalidTransition(fn () => $service->complete($investigation, $approver));

            $investigation->refresh();
            $this->assertSame(HsInvestigation::STATUS_COMPLETED, $investigation->status);
            $this->assertSame($submitter->id, $investigation->submitted_by_id);
            $this->assertSame($reviewer->id, $investigation->reviewed_by_id);
            $this->assertSame($approver->id, $investigation->approved_by_id);
            $this->assertTrue($reviewedAt->equalTo($investigation->reviewed_at));
            $this->assertTrue($approvedAt->equalTo($investigation->approved_at));
            $this->assertSame(1, $this->terminalAuditCount($investigation, 'healthSafety.investigation.reviewed'));
            $this->assertSame(1, $this->terminalAuditCount($investigation, 'healthSafety.investigation.approved'));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([...$readyPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            if ($committed) {
                try {
                    DB::table('audit_logs')->whereIn('user_id', $actorIds)->delete();
                    DB::table('hs_investigations')->where('id', $investigation->id)->delete();
                    DB::table('hs_events')->where('id', $investigation->hs_event_id)->delete();
                    DB::table('hr_employee_profiles')->whereIn('user_id', $actorIds)->delete();
                    DB::table('permission_user')->whereIn('user_id', $actorIds)->delete();
                    DB::table('users')->whereIn('id', $actorIds)->delete();
                    DB::table('sites')->where('id', $site->id)->delete();
                } finally {
                    $connection->beginTransaction();
                }
            }
        }
    }

    private function activeSite(string $name): Site
    {
        return Site::factory()->create([
            'name' => $name,
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
    }

    private function siteActor(Site $site): User
    {
        $actor = $this->actorWithPermissions(['hazards.manage']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'position_role' => 'coordinator',
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return $actor;
    }

    private function globalActor(Site $profileSite): User
    {
        $actor = $this->actorWithPermissions([
            'hazards.manage',
            'healthSafety.viewAllSites',
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'position_role' => 'coordinator',
            'primary_site_id' => $profileSite->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return $actor;
    }

    /** @param list<string> $permissionKeys */
    private function actorWithPermissions(array $permissionKeys): User
    {
        $actor = User::factory()->create([
            'role' => 'coordinator',
            'approved_at' => now(),
        ]);
        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(
                ['key' => $key],
                ['description' => $key, 'group' => 'health_safety', 'module' => 'health_safety'],
            );
            $actor->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $actor;
    }

    /** @param list<int> $teamMemberIds
     * @return array{HsEvent, HsInvestigation}
     */
    private function underReviewInvestigation(
        Site $site,
        User $lead,
        User $submitter,
        array $teamMemberIds = [],
    ): array {
        $event = HsEvent::factory()->high()->create([
            'site_id' => $site->id,
            'status' => HsEvent::STATUS_INVESTIGATING,
            'created_by' => $lead->id,
        ]);

        return [$event, $this->investigationForEvent($event, $lead, $submitter, $teamMemberIds)];
    }

    /** @param list<int> $teamMemberIds */
    private function investigationForEvent(
        HsEvent $event,
        User $lead,
        User $submitter,
        array $teamMemberIds = [],
    ): HsInvestigation {
        return HsInvestigation::factory()->withFindings()->create([
            'hs_event_id' => $event->id,
            'status' => HsInvestigation::STATUS_UNDER_REVIEW,
            'lead_investigator_id' => $lead->id,
            'team_member_ids' => $teamMemberIds,
            'submitted_by_id' => $submitter->id,
            'submitted_at' => now()->subMinute(),
            'created_by' => $lead->id,
            'updated_by' => $submitter->id,
        ]);
    }

    /** @return array{HsEvent, HsInvestigation} */
    private function reviewedInvestigation(Site $site, User $lead, User $submitter, User $reviewer): array
    {
        [$event, $investigation] = $this->underReviewInvestigation($site, $lead, $submitter);
        $investigation->update([
            'status' => HsInvestigation::STATUS_REVIEWED,
            'reviewed_by_id' => $reviewer->id,
            'reviewed_at' => now(),
            'updated_by' => $reviewer->id,
        ]);

        return [$event, $investigation];
    }

    private function terminalAuditCount(HsInvestigation $investigation, string $action): int
    {
        return AuditLog::query()
            ->where('action', $action)
            ->where('auditable_type', $investigation->getMorphClass())
            ->where('auditable_id', $investigation->id)
            ->count();
    }

    private function assertForbiddenServiceCall(callable $decision): void
    {
        try {
            $decision();
            $this->fail('An unauthenticated investigation assurance decision was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function assertInvalidTransition(callable $decision): void
    {
        try {
            $decision();
            $this->fail('A replayed investigation assurance decision was accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    private function waitForWorker(Process $process, string $readyPath): void
    {
        $deadline = microtime(true) + 60;
        while (! is_file($readyPath)) {
            if (! $process->isRunning()) {
                $this->fail(trim($process->getErrorOutput()) ?: 'Investigation worker exited before becoming ready.');
            }
            if (microtime(true) >= $deadline) {
                $details = trim($process->getErrorOutput().PHP_EOL.$process->getOutput());
                $this->fail('Investigation worker did not reach the concurrency barrier.'
                    .($details === '' ? '' : ' '.$details));
            }
            usleep(20_000);
        }
    }
}
