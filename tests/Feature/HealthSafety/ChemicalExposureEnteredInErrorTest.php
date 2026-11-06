<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalExposureFollowup;
use App\Models\ChemicalExposureReview;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalExposureFollowupDestinationService;
use App\Services\HealthSafety\ChemicalExposureReadService;
use App\Services\HealthSafety\ChemicalExposureWorkspaceService;
use Illuminate\Support\Str;

/**
 * Entered in error (decided 6 Nov 2026): an exposure manager marks a private exposure record that was a mistake, with
 * a required reason. It is kept (never deleted), audited, hidden from every active list and count, still readable on
 * its own page and in its history, and takes no further change. Same authority as managing the exposure.
 */
final class ChemicalExposureEnteredInErrorTest extends ChemicalExposureCommandTestCase
{
    private const BASE = '/health-safety/chemical-exposures';

    private const REASON = 'Recorded against the wrong worker; the real exposure is on the other record.';

    private function mark(SubstanceExposureRecord $root, ?\App\Models\User $actor = null, array $facts = []): array
    {
        return $this->exposureService()->markEnteredInError($root, $actor ?? $this->manager,
            $this->privateCommand($root, ['reason' => self::REASON, ...$facts]));
    }

    private function indexIds(): array
    {
        return collect(app(ChemicalExposureWorkspaceService::class)->index($this->manager)['records'])->pluck('id')->all();
    }

    public function test_marking_keeps_the_record_with_an_audited_reason_and_a_history_entry(): void
    {
        $root = $this->exposure(['symptoms' => 'Kept symptoms']);
        $r = $this->mark($root);

        $this->assertSame('mark_entered_in_error', $r['command']);
        $this->assertSame('review', $r['source_type']);
        $this->assertSame(2, $r['version']);
        $kept = SubstanceExposureRecord::withTrashed()->findOrFail($root->id);
        $this->assertNull($kept->deleted_at);
        $this->assertSame('Kept symptoms', $kept->symptoms);
        $entry = ChemicalExposureReview::findOrFail($r['source_id']);
        $this->assertSame('entered_in_error', $entry->outcome);
        $this->assertSame(self::REASON, $entry->notes);
        $this->assertSame($this->manager->id, $entry->recorded_by);
        $version = ChemicalExposureSourceVersion::findOrFail($r['source_version_id']);
        $this->assertSame(self::REASON, $version->content['facts']['reason']);
        $this->assertSame(1, AuditLog::where('action', 'chemical.exposure.mark_entered_in_error')
            ->where('auditable_id', $root->id)->where('meta->idempotency_key', $r['idempotency_key'])->count());
        // The H&S register hears about the change through the usual delivery, like any exposure change.
        $this->assertSame(1, ChemicalExposureSourceDelivery::where('source_version_id', $version->id)->where('kind', 'health_safety')->count());

        $detail = app(ChemicalExposureWorkspaceService::class)->detail($this->manager, $root->id);
        $this->assertSame('Kept symptoms', $detail['record']['symptoms']);
        $this->assertSame(['reason' => self::REASON, 'at' => $version->recorded_at->toISOString(),
            'by' => ['id' => $this->manager->id, 'name' => $this->manager->name], 'attribution_state' => 'recorded'], $detail['entered_in_error']);
        $this->assertSame(['correct' => false, 'review' => false, 'assign_followup' => false, 'upload_evidence' => false,
            'mark_entered_in_error' => false], $detail['can']);
        $history = collect($detail['history']['items'])->firstWhere('command', 'mark_entered_in_error');
        $this->assertSame(self::REASON, $history['reason']);
        $this->assertSame('entered_in_error', $detail['reviews']['items'][0]['outcome']);

        // The same UUID replays the one original receipt, never a second entry.
        $this->assertSame($r, $this->exposureService()->markEnteredInError($root, $this->manager,
            ['idempotency_key' => $r['idempotency_key'], 'expected_version' => 1, 'reason' => self::REASON]));
        $this->assertSame(1, ChemicalExposureReview::where('exposure_id', $root->id)->count());
    }

    public function test_its_safety_review_says_so_and_a_person_records_entered_in_error_as_the_worksafe_reason(): void
    {
        // F5 (Stephan, 6 Nov 2026: "Show it, let a person close it"): nothing closes the review by itself.
        $root = $this->exposure();
        $review = \App\Models\HsEvent::factory()->create([
            'source_type' => SubstanceExposureRecord::class, 'source_id' => $root->id, 'site_id' => $this->site->id,
            'status' => 'open', 'worksafe_notifiable' => null, 'worksafe_decided_at' => null,
        ]);
        $this->assertFalse(\App\Services\HealthSafety\HsPrivateSourcePerson::exposureEnteredInError($review));

        $this->mark($root);
        $review->refresh();
        $this->assertTrue(\App\Services\HealthSafety\HsPrivateSourcePerson::exposureEnteredInError($review));
        $this->assertNotSame('closed', $review->status);

        $decided = app(\App\Services\HealthSafety\HsEventService::class)->recordWorksafeDecision(
            $review, false, 'Entered in error: its exposure record was marked entered in error, so there is nothing to tell WorkSafe.', $this->manager);
        $this->assertFalse($decided->worksafe_notifiable);
        $this->assertTrue($decided->hasSignedWorksafeDecision());
        $readiness = collect(app(\App\Services\HealthSafety\HsEventClosureService::class)->readiness($decided)->toArray()['requirements'])
            ->keyBy('key');
        foreach (['worksafe_decision', 'worksafe_notification', 'worksafe_acknowledgement', 'site_preservation'] as $key) {
            $this->assertTrue((bool) $readiness[$key]['complete'], $key);
        }
    }

    public function test_a_marked_record_leaves_every_active_list_and_count(): void
    {
        $wrong = $this->exposure(['medical_attention_sought' => true]);
        $real = $this->exposure(['medical_attention_sought' => true]);
        $substance = $wrong->hazardous_substance_id;
        $before = app(ChemicalExposureWorkspaceService::class)->index($this->manager);
        $this->assertSame(2, $before['counts']['exposures']);
        $this->assertSame(2, $before['counts']['awaiting_medical_outcome']);

        $this->mark($wrong);

        $after = app(ChemicalExposureWorkspaceService::class)->index($this->manager);
        $this->assertSame([$real->id], $this->indexIds());
        $this->assertSame(1, $after['counts']['exposures']);
        $this->assertSame(1, $after['counts']['awaiting_medical_outcome']);
        $read = app(ChemicalExposureReadService::class);
        $this->assertSame([$real->id], collect($read->index($this->manager)['records'])->pluck('id')->all());
        // Each test exposure is on its own chemical: the wrong one's chemical page now lists nothing.
        $this->assertSame([], $read->forSubstance($this->manager, $substance)['records']);
        $this->assertSame(0, $read->forSubstance($this->manager, $substance)['counts']['exposures']);
        $this->assertSame([$real->id], collect($read->forSubstance($this->manager, $real->hazardous_substance_id)['records'])->pluck('id')->all());

        // The chemical register's count for anyone who sees the list.
        $reader = $this->staff(['hazards.view']);
        $this->assertSame(1, $this->actingAs($reader)->getJson('/health-safety/substances')->assertOk()->json('exposure_summary.exposures'));

        // Still readable on its own page by an exposure reader at its Site.
        $this->actingAs($this->manager)->getJson(self::BASE.'/'.$wrong->id)->assertOk();
    }

    public function test_open_follow_ups_leave_the_worklist_and_their_unsent_notice_is_superseded(): void
    {
        $root = $this->exposure();
        $assigned = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $this->manager->id, 'instruction' => 'Contact the worker', 'due_at' => '2026-10-13T01:00:00Z']));
        $notice = ChemicalExposureSourceDelivery::where('source_version_id', $assigned['source_version_id'])->where('kind', 'followup')->sole();
        $this->assertSame('pending', $notice->state);
        $this->assertCount(1, app(ChemicalExposureFollowupDestinationService::class)->taskRows($this->manager));

        $this->mark($root);

        $this->assertSame('superseded', $notice->fresh()->state);
        $this->assertSame([], app(ChemicalExposureFollowupDestinationService::class)->taskRows($this->manager));
        $this->assertNull(ChemicalExposureFollowup::findOrFail($assigned['source_id'])->completed_at);
        $this->validation(fn () => $this->exposureService()->completeFollowup($root, $assigned['source_id'], $this->manager,
            $this->privateCommand($root, ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Done'])), 'entered_in_error');
    }

    public function test_a_marked_record_takes_no_further_change(): void
    {
        $root = $this->exposure();
        $this->mark($root);
        $this->validation(fn () => $this->exposureService()->correct($root, $this->manager,
            $this->privateCommand($root, ['reason' => 'Fix', 'symptoms' => 'Changed'])), 'entered_in_error');
        $this->validation(fn () => $this->exposureService()->review($root, $this->manager,
            $this->privateCommand($root, ['reviewed_at' => '2026-10-12T00:30:00Z', 'outcome' => 'recorded', 'notes' => 'Late review'])), 'entered_in_error');
        $this->validation(fn () => $this->exposureService()->assignFollowup($root, $this->manager,
            $this->privateCommand($root, ['assigned_to' => $this->manager->id, 'instruction' => 'More', 'due_at' => '2026-10-13T01:00:00Z'])), 'entered_in_error');
        $this->validation(fn () => $this->mark($root), 'reason');
        $this->assertSame(2, $root->fresh()->source_revision);
        $this->assertSame(1, ChemicalExposureReview::where('exposure_id', $root->id)->count());
    }

    public function test_a_reason_is_required_and_a_stale_page_is_refused(): void
    {
        $root = $this->exposure();
        $this->validation(fn () => $this->mark($root, null, ['reason' => '   ']), 'reason');
        $this->validation(fn () => $this->exposureService()->markEnteredInError($root, $this->manager,
            ['idempotency_key' => (string) Str::uuid(), 'expected_version' => 1]), 'reason');
        $this->validation(fn () => $this->exposureService()->markEnteredInError($root, $this->manager,
            ['idempotency_key' => (string) Str::uuid(), 'expected_version' => 0, 'reason' => self::REASON]), 'expected_version');
        $this->assertSame(1, $root->fresh()->source_revision);
        $this->assertSame(0, ChemicalExposureReview::count());
    }

    public function test_it_needs_the_same_authority_and_site_as_managing_the_exposure(): void
    {
        $root = $this->exposure();
        // Capture-only reporter, an H&S manager without private wellbeing access, and a manager at another Site.
        $this->denial(fn () => $this->mark($root, $this->reporter));
        $generic = $this->staff(['hazards.view', 'hazards.manage']);
        $this->denial(fn () => $this->mark($root, $generic));
        $reader = $this->staff(['hazards.view', 'hr.wellbeing.view']);
        $this->denial(fn () => $this->mark($root, $reader));
        $foreign = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view'], $this->foreignSite);
        $this->denial(fn () => $this->mark($root, $foreign), 404);
        $this->assertFalse(app(ChemicalExposureWorkspaceService::class)->detail($reader, $root->id)['can']['mark_entered_in_error']);
        $this->assertTrue(app(ChemicalExposureWorkspaceService::class)->detail($this->manager, $root->id)['can']['mark_entered_in_error']);
        $this->assertSame(0, ChemicalExposureReview::count());
        $this->assertSame(0, AuditLog::where('action', 'chemical.exposure.mark_entered_in_error')->count());
    }

    public function test_the_http_route_returns_the_exact_receipt(): void
    {
        $root = $this->exposure();
        $this->actingAs($this->manager)->postJson(self::BASE.'/'.$root->id.'/entered-in-error', $this->privateCommand($root, ['reason' => self::REASON]))
            ->assertOk()->assertJsonPath('chemical_exposure_confirmation.command', 'mark_entered_in_error')
            ->assertJsonPath('chemical_exposure_confirmation.source_type', 'review')
            ->assertJsonPath('chemical_exposure_confirmation.version', 2);
        $this->actingAs($this->reporter)->postJson(self::BASE.'/'.$root->id.'/entered-in-error', $this->privateCommand($root, ['reason' => self::REASON]))
            ->assertForbidden();
        $this->actingAs($this->manager)->postJson(self::BASE.'/'.$root->id.'/entered-in-error', $this->privateCommand($root, ['reason' => self::REASON, 'surprise' => 1]))
            ->assertUnprocessable();
    }
}
