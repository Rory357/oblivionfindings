<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\CheckRiskAssessmentReviewsJob;
use App\Models\AuditLog;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\HsRiskAssessmentAttachment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsRiskAssessmentService;
use App\Services\HealthSafety\HsSignalService;
use App\Services\UserSiteAccessService;
use Database\Seeders\GovernancePermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HsRiskAssessmentLifecycleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(GovernancePermissionsSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house']);
        $this->writer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->writer->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => []]);
        foreach (['hazards.view', 'hazards.manage', 'governance.view'] as $key) {
            $permission = Permission::where('key', $key)->firstOrFail();
            $this->writer->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }
        $this->actingAs($this->writer);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_approval_saves_note_actor_and_nz_schedule_in_one_immutable_milestone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 11:30:00', 'UTC'));
        $risk = $this->risk(['review_frequency_days' => 30]);
        $this->post($this->url($risk, 'activate'), ['approver_note' => 'Approved with verified controls.'])->assertSessionDoesntHaveErrors();
        $saved = $risk->fresh();
        $this->assertSame('active', $saved->status);
        $this->assertSame($this->writer->id, $saved->approved_by_user_id);
        $this->assertSame('2026-11-08', $saved->review_due_at->toDateString());
        $audit = $this->audit($risk, 'risk_assessment.status_changed');
        $this->assertSame('Approved with verified controls.', $audit->meta['note']);
        $this->assertSame('Approved with verified controls.', $audit->meta['snapshot']['approval_note']);
        $this->assertSame($this->writer->id, $audit->user_id);
        $unknown = $this->risk(['review_frequency_days' => null, 'review_due_at' => null]);
        $this->post($this->url($unknown, 'activate'))->assertSessionDoesntHaveErrors();
        $this->assertNull($unknown->fresh()->review_due_at);
        $this->assertNull($unknown->fresh()->review_frequency_days);
    }

    public function test_residual_recording_preserves_lifecycle_approval_and_schedule_and_unknown_acceptability(): void
    {
        $risk = $this->risk(['status' => 'under_review', 'review_due_at' => '2026-10-08', 'risk_acceptable' => null]);
        $this->post($this->url($risk, 'residual'), ['residual_likelihood' => 1, 'residual_consequence' => 2, 'review_note' => 'Controls inspected.'])->assertSessionDoesntHaveErrors();
        $saved = $risk->fresh();
        $this->assertSame('under_review', $saved->status);
        $this->assertNull($saved->approved_at);
        $this->assertNull($saved->risk_acceptable);
        $this->assertSame('2026-10-08', $saved->review_due_at->toDateString());
        $this->assertSame('Controls inspected.', $saved->last_review_note);
        $this->assertSame(2, $saved->residual_risk_score);
        $this->assertSame('Controls inspected.', $this->audit($risk, 'risk_assessment.residual_recorded')->meta['note']);
        $risk->update(['risk_acceptable' => true]);
        $this->post($this->url($risk, 'residual'), ['residual_likelihood' => 2, 'residual_consequence' => 2])->assertSessionDoesntHaveErrors();
        $this->assertTrue($risk->fresh()->risk_acceptable);
        $this->post($this->url($risk, 'residual'), ['residual_likelihood' => 2, 'residual_consequence' => 2, 'risk_acceptable' => null])->assertSessionDoesntHaveErrors();
        $this->assertNull($risk->fresh()->risk_acceptable);
    }

    public function test_explicit_review_completion_retains_prior_approval_controls_and_false_disposition(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'Pacific/Auckland')->utc());
        $risk = $this->readyForReview(['risk_acceptable' => false]);
        $approval = $risk->approved_at->toISOString();
        $this->post($this->url($risk, 'complete-review'), ['review_note' => 'Further controls remain required; revisit next month.', 'review_due_at' => '2026-11-08'])->assertSessionDoesntHaveErrors();
        $saved = $risk->fresh();
        $this->assertSame('active', $saved->status);
        $this->assertFalse($saved->risk_acceptable);
        $this->assertSame($approval, $saved->approved_at->toISOString());
        $this->assertSame('Original controls.', $saved->existing_controls);
        $this->assertSame(12, $saved->risk_score);
        $this->assertSame('2026-11-08', $saved->review_due_at->toDateString());
        $this->assertNull($saved->review_frequency_days);
        $audit = $this->audit($risk, 'risk_assessment.review_completed');
        $this->assertSame($this->writer->id, $audit->user_id);
        $this->assertFalse($audit->meta['snapshot']['risk_acceptable']);
        $this->assertNotNull($audit->meta['reviewed_at']);
        $before = $saved->getAttributes();
        $this->post($this->url($risk, 'complete-review'), ['review_note' => 'Replay.', 'review_due_at' => '2026-12-08'])->assertSessionHas('error');
        $this->assertSame($before, $risk->fresh()->getAttributes());
        $this->assertSame(1, $this->audits($risk, 'risk_assessment.review_completed')->count());
    }

    public function test_review_completion_requires_existing_approval_residual_pair_and_explicit_acceptability(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'Pacific/Auckland')->utc());
        foreach ([['approved_by_user_id' => null], ['approved_at' => null], ['residual_likelihood' => null], ['residual_consequence' => null], ['risk_acceptable' => null]] as $missing) {
            $risk = $this->readyForReview($missing);
            $before = $risk->fresh()->getAttributes();
            $this->post($this->url($risk, 'complete-review'), ['review_note' => 'Review verified.', 'review_due_at' => '2026-11-08'])->assertSessionHasErrors('review_note');
            $this->assertSame($before, $risk->fresh()->getAttributes());
            $this->assertSame(0, $this->audits($risk, 'risk_assessment.review_completed')->count());
        }
    }

    public function test_review_note_and_next_date_use_nz_calendar_validation_at_utc_date_mismatch(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 11:00:00', 'UTC')); // NZ 9 October midnight.
        $risk = $this->readyForReview();
        $this->post($this->url($risk, 'complete-review'), ['review_note' => 'Verified.', 'review_due_at' => '2026-10-09'])->assertSessionHasErrors('review_due_at');
        $this->post($this->url($risk, 'complete-review'), ['review_note' => '   ', 'review_due_at' => '2026-10-10'])->assertSessionHasErrors('review_note');
        $this->post($this->url($risk, 'complete-review'), ['review_note' => str_repeat('x', 5001), 'review_due_at' => '2026-10-10'])->assertSessionHasErrors('review_note');
        $this->post($this->url($risk, 'complete-review'), ['review_note' => 'Verified.', 'review_due_at' => '2026-10-10'])->assertSessionDoesntHaveErrors();
        $this->assertSame('active', $risk->fresh()->status);
    }

    public function test_finished_records_reject_every_mutation_and_preserve_evidence_files(): void
    {
        Storage::fake('private');
        foreach (['superseded', 'archived'] as $status) {
            $risk = $this->risk(['status' => $status]);
            $attachment = $this->attachment($risk);
            $before = $risk->fresh()->getAttributes();
            foreach ([['activate', []], ['review', []], ['residual', ['residual_likelihood' => 1, 'residual_consequence' => 1]], ['archive', []], ['supersede', $this->payload()], ['complete-review', ['review_note' => 'Not allowed.', 'review_due_at' => '2099-01-01']]] as [$command, $data]) {
                $this->post($this->url($risk, $command), $data)->assertSessionHas('error');
            }
            $this->put($this->url($risk), $this->payload())->assertSessionHas('error');
            $this->post($this->url($risk, 'attachments'), ['file' => UploadedFile::fake()->create('blocked.pdf', 10, 'application/pdf')])->assertSessionHas('error');
            $this->delete($this->url($risk, 'attachments/'.$attachment->id))->assertSessionHas('error');
            $this->assertSame($before, $risk->fresh()->getAttributes());
            Storage::disk('private')->assertExists($attachment->path);
            $this->assertSame(1, $risk->attachments()->count());
            $this->assertSame(0, AuditLog::where('auditable_id', $risk->id)->where('action', 'like', 'risk_assessment.%')->count());
        }
    }

    public function test_stale_bound_source_is_reloaded_before_lifecycle_validation(): void
    {
        $risk = $this->risk();
        $stale = clone $risk;
        $this->post($this->url($risk, 'activate'))->assertSessionDoesntHaveErrors();
        $this->expectException(\InvalidArgumentException::class);
        app(HsRiskAssessmentService::class)->updateDraft($stale, ['title' => 'Stale overwrite', 'likelihood' => 1, 'consequence' => 1], $this->writer);
    }

    public function test_commands_recheck_cached_current_capability_and_lost_site_access(): void
    {
        $risk = $this->risk();
        $this->assertTrue($this->writer->canDo('hazards.manage'));
        app(UserSiteAccessService::class)->accessibleSiteIds($this->writer, ['healthSafety.viewAllSites']);
        $permission = Permission::where('key', 'hazards.manage')->firstOrFail();
        $this->writer->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        try {
            app(HsRiskAssessmentService::class)->activate($risk, [], $this->writer);
            $this->fail('Cached capability must not authorize a command.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->writer->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
        $this->writer->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create()->id]);
        try {
            app(HsRiskAssessmentService::class)->activate($risk, [], $this->writer);
            $this->fail('Cached Site scope must not authorize a command.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame('draft', $risk->fresh()->status);
    }

    public function test_semantic_audit_failure_rolls_back_approval_and_complete_review_notes_and_dates(): void
    {
        foreach (['risk_assessment.status_changed', 'risk_assessment.review_completed'] as $action) {
            $risk = $action === 'risk_assessment.review_completed' ? $this->readyForReview() : $this->risk();
            $before = $risk->fresh()->getAttributes();
            AuditLog::creating(function (AuditLog $audit) use ($action): void {
                if ($audit->action === $action) {
                    throw new \RuntimeException('Synthetic risk history failure.');
                }
            });
            try {
                $command = $action === 'risk_assessment.review_completed' ? 'complete-review' : 'activate';
                $this->post($this->url($risk, $command), ['approver_note' => 'Must roll back.', 'review_note' => 'Must roll back.', 'review_due_at' => '2099-01-01'])->assertStatus(500);
                $this->assertSame($before, $risk->fresh()->getAttributes());
                $this->assertSame(0, $this->audits($risk, $action)->count());
            } finally {
                AuditLog::flushEventListeners();
            }
        }
    }

    public function test_evidence_audit_failure_preserves_removal_and_cleans_failed_upload(): void
    {
        Storage::fake('private');
        $risk = $this->risk();
        $attachment = $this->attachment($risk);
        AuditLog::creating(function (AuditLog $audit): void {
            if (str_starts_with($audit->action, 'risk_assessment.evidence_')) {
                throw new \RuntimeException('Synthetic evidence history failure.');
            }
        });
        try {
            $this->post($this->url($risk, 'attachments'), ['file' => UploadedFile::fake()->create('rollback.pdf', 10, 'application/pdf')])->assertStatus(500);
            $this->assertSame([$attachment->path], Storage::disk('private')->allFiles());
            $this->delete($this->url($risk, 'attachments/'.$attachment->id))->assertStatus(500);
            Storage::disk('private')->assertExists($attachment->path);
            $this->assertNotNull($attachment->fresh());
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_supersession_preserves_dual_source_tuple_and_rejects_context_reassignment(): void
    {
        $event = HsEvent::factory()->create(['site_id' => $this->site->id]);
        $risk = $this->risk(['status' => 'active', 'hs_event_id' => $event->id]);
        $other = Site::factory()->create();
        $this->post($this->url($risk, 'supersede'), $this->payload(['attach_id' => $other->id]))->assertSessionHasErrors('attach_id');
        $this->post($this->url($risk, 'supersede'), $this->payload(['title' => 'Successor']))->assertSessionDoesntHaveErrors();
        $new = HsRiskAssessment::findOrFail($risk->fresh()->superseded_by_id);
        $this->assertSame('draft', $new->status);
        $this->assertSame($event->id, $new->hs_event_id);
        $this->assertSame(Site::class, $new->assessable_type);
        $this->assertSame($this->site->id, $new->assessable_id);
        $this->assertSame('superseded', $risk->fresh()->status);
    }

    public function test_due_scope_model_job_and_report_use_the_same_nz_day_and_preserve_unknown_acceptability(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 11:00:00', 'UTC'));
        $yesterday = $this->risk(['status' => 'active', 'review_due_at' => '2026-10-08', 'risk_acceptable' => false]);
        $today = $this->risk(['status' => 'active', 'review_due_at' => '2026-10-09', 'risk_acceptable' => null]);
        $review = $this->risk(['status' => 'under_review', 'review_due_at' => '2026-10-08', 'risk_acceptable' => true]);
        $this->risk(['status' => 'draft', 'review_due_at' => '2026-10-08']);
        $this->risk(['status' => 'archived', 'review_due_at' => '2026-10-08']);
        $this->risk(['status' => 'active', 'review_due_at' => '2026-10-10']);
        $this->assertEqualsCanonicalizing([$yesterday->id, $today->id, $review->id], HsRiskAssessment::dueForReview()->pluck('id')->all());
        $this->assertTrue($today->isDueForReview());
        $this->assertTrue($review->isDueForReview());
        $this->assertEqualsCanonicalizing([$yesterday->id, $review->id], HsRiskAssessment::overdueForReview()->pluck('id')->all());
        $signal = $this->mock(HsSignalService::class);
        foreach ([$yesterday, $review] as $risk) {
            $signal->shouldReceive('emitRiskReviewOverdue')->once()->withArgs(fn ($id, $ref, $days, $level, $site, $extra) => $id === $risk->id && $days === 1 && $site === $this->site->id && $extra['review_due_at'] === '2026-10-08');
        }
        app(CheckRiskAssessmentReviewsJob::class)->handle($signal, app(UserSiteAccessService::class));
        $report = $this->getJson('/health-safety/reports/risk-assessment-register?tab=due&site_id='.$this->site->id)->assertOk();
        $this->assertSame(3, $report->json('summary.total'));
        $this->assertSame(3, $report->json('summary.due_for_review'));
        $this->assertSame(1, $report->json('summary.unacceptable'));
        $this->assertSame(1, $report->json('summary.acceptability_unknown'));
    }

    public function test_register_metrics_and_export_preserve_facets_and_deny_foreign_report_context(): void
    {
        $matching = $this->risk(['status' => 'active', 'title' => 'Matching chemical risk', 'risk_level' => 'high', 'risk_acceptable' => false]);
        $this->risk(['status' => 'active', 'title' => 'Matching chemical risk', 'risk_level' => 'low', 'risk_acceptable' => false]);
        $this->risk(['status' => 'draft', 'title' => 'Matching chemical risk', 'risk_level' => 'high', 'risk_acceptable' => true]);
        $query = '?tab=active&risk_level=high&risk_acceptable=0&search=chemical&site_id='.$this->site->id;
        $this->get('/health-safety/risk-assessments'.$query)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('assessments.data', 1)->where('assessments.data.0.id', $matching->id)
            ->where('hero.active', 1)->where('tabCounts.active', 1)->where('tabCounts.drafts', 0));
        $this->getJson('/health-safety/reports/risk-assessment-register'.$query)->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.reference_number', $matching->reference_number);
        $foreign = HsEvent::factory()->create(['site_id' => Site::factory()->create()->id]);
        $this->getJson('/health-safety/reports/risk-assessment-register?hs_event_id='.$foreign->id)->assertForbidden();
    }

    public function test_ordinary_draft_edit_preserves_existing_dual_safety_review_provenance(): void
    {
        $event = HsEvent::factory()->create(['site_id' => $this->site->id]);
        $risk = $this->risk(['hs_event_id' => $event->id]);
        $this->put($this->url($risk), $this->payload(['title' => 'Updated controls', 'existing_controls' => 'Revised controls.']))->assertSessionDoesntHaveErrors();
        $saved = $risk->fresh();
        $this->assertSame($event->id, $saved->hs_event_id);
        $this->assertSame($this->site->id, $saved->assessable_id);
        $this->assertSame('Revised controls.', $saved->existing_controls);
    }

    public function test_legacy_assessment_identifier_rejects_arrays_partial_numbers_zero_and_empty_values(): void
    {
        $risk = $this->risk();
        foreach ([$risk->id.'abc', '0', '', '-1'] as $malformed) {
            $this->get('/health-safety/risk-assessments?assessment='.rawurlencode($malformed))->assertNotFound();
        }
        $this->get('/health-safety/risk-assessments?assessment[]=x')->assertNotFound();
    }

    public function test_storage_false_does_not_create_evidence_or_report_success(): void
    {
        $risk = $this->risk();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('private')->andReturn($disk);
        $this->post($this->url($risk, 'attachments'), ['file' => UploadedFile::fake()->create('unstored.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('file')->assertSessionMissing('success');
        $this->assertSame(0, $risk->attachments()->count());
        $this->assertSame(0, $this->audits($risk, 'risk_assessment.evidence_added')->count());
    }

    public function test_explicit_service_actor_owns_approval_history_even_with_an_unrelated_authenticated_user(): void
    {
        $risk = $this->risk();
        $other = User::factory()->create(['approved_at' => now()]);
        $this->actingAs($other);
        app(HsRiskAssessmentService::class)->activate($risk, ['approver_note' => 'Approved by the authorized service actor.'], $this->writer);
        $this->assertSame($this->writer->id, $risk->fresh()->approved_by_user_id);
        $this->assertSame($this->writer->id, $this->audit($risk, 'risk_assessment.status_changed')->user_id);
    }

    public function test_current_staff_identity_rejects_portal_roles_with_lingering_employment_and_manage_override(): void
    {
        foreach (['client', 'next_of_kin'] as $portalRole) {
            foreach (['legacy', 'rbac'] as $source) {
                $risk = $this->risk();
                $role = Role::firstOrCreate(['name' => $portalRole], ['label' => ucwords(str_replace('_', ' ', $portalRole)), 'type' => 'system']);
                if ($source === 'legacy') {
                    $this->writer->forceFill(['role' => $portalRole])->saveQuietly();
                } else {
                    $this->writer->roles()->attach($role);
                }
                try {
                    app(HsRiskAssessmentService::class)->activate($risk, [], $this->writer);
                    $this->fail('Portal identity must not mutate a staff safety assessment.');
                } catch (HttpException $error) {
                    $this->assertSame(403, $error->getStatusCode());
                }
                $this->assertSame('draft', $risk->fresh()->status);
                $this->writer->forceFill(['role' => 'support_worker'])->saveQuietly();
                $this->writer->roles()->detach($role);
            }
        }
        $this->assertSame(0, AuditLog::where('action', 'risk_assessment.status_changed')->count());
    }

    public function test_direct_service_commands_reject_raw_invalid_scores_and_partial_residual_pairs(): void
    {
        $risk = $this->risk();
        $before = $risk->fresh()->getAttributes();
        $service = app(HsRiskAssessmentService::class);
        foreach ([
            fn () => $service->updateResidualRisk($risk, 9, 2, null, $this->writer),
            fn () => $service->updateDraft($risk, ['title' => 'Invalid', 'likelihood' => 0, 'consequence' => 2, 'assessable_type' => Site::class, 'assessable_id' => $this->site->id], $this->writer),
            fn () => $service->updateDraft($risk, ['title' => 'Partial', 'likelihood' => 1, 'consequence' => 2, 'residual_likelihood' => 1, 'assessable_type' => Site::class, 'assessable_id' => $this->site->id], $this->writer),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('An invalid scalar command must not be persisted.');
            } catch (ValidationException) {
                $this->assertSame($before, $risk->fresh()->getAttributes());
            }
        }
        $this->assertSame(0, AuditLog::where('auditable_id', $risk->id)->where('action', 'like', 'risk_assessment.%')->count());
    }

    private function risk(array $attributes = []): HsRiskAssessment
    {
        return HsRiskAssessment::factory()->forSite($this->site->id)->create([
            'likelihood' => 3, 'consequence' => 4, 'risk_score' => 12, 'risk_level' => 'high',
            'existing_controls' => 'Original controls.', 'review_frequency_days' => null, ...$attributes,
        ]);
    }

    private function readyForReview(array $attributes = []): HsRiskAssessment
    {
        return $this->risk(['status' => 'under_review', 'approved_by_user_id' => $this->writer->id,
            'approved_at' => now()->subMonth(), 'residual_likelihood' => 1, 'residual_consequence' => 2,
            'residual_risk_score' => 2, 'residual_risk_level' => 'low', 'risk_acceptable' => true, ...$attributes]);
    }

    private function payload(array $attributes = []): array
    {
        return ['title' => 'Risk draft', 'attach_type' => 'site', 'attach_id' => $this->site->id,
            'likelihood' => 3, 'consequence' => 4, ...$attributes];
    }

    private function attachment(HsRiskAssessment $risk): HsRiskAssessmentAttachment
    {
        $path = 'risk/evidence-'.$risk->id.'.pdf';
        Storage::disk('private')->put($path, 'Synthetic evidence.');

        return $risk->attachments()->create(['uploaded_by' => $this->writer->id, 'disk' => 'private', 'path' => $path, 'original_name' => 'evidence.pdf']);
    }

    private function url(HsRiskAssessment $risk, string $command = ''): string
    {
        return '/health-safety/risk-assessments/'.$risk->id.($command === '' ? '' : '/'.$command);
    }

    private function audits(HsRiskAssessment $risk, string $action)
    {
        return AuditLog::where('auditable_type', $risk->getMorphClass())->where('auditable_id', $risk->id)->where('action', $action);
    }

    private function audit(HsRiskAssessment $risk, string $action): AuditLog
    {
        return $this->audits($risk, $action)->sole();
    }
}
