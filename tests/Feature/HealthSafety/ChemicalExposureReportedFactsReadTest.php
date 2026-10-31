<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use App\Services\HealthSafety\ChemicalExposureReadService;
use App\Services\HealthSafety\ChemicalExposureWorkspaceService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Whole actual reader suite; requires reviewed B Source/ledger200600 and reader patch; UNRUN. */
final class ChemicalExposureReportedFactsReadTest extends ChemicalExposureCommandTestCase
{
    private function reader(): ChemicalExposureReadService { return app(ChemicalExposureReadService::class); }
    private function workspace(): ChemicalExposureWorkspaceService { return app(ChemicalExposureWorkspaceService::class); }
    private function legacy(array $facts = []): SubstanceExposureRecord
    {
        return SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::factory()->create([
            'hazardous_substance_id' => $this->catalogue()->id, 'user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'created_by' => $this->manager->id, 'related_incident_id' => null, 'original_context' => null,
            'source_schema_version' => 0, 'source_revision' => 0, 'exposed_at' => '2026-10-12 00:00:00',
            'medical_attention_sought' => false, 'incident_reported' => false, 'medical_treatment' => null, 'medical_outcome' => null, ...$facts]));
    }
    private function both(int $id): array
    {
        return [$this->reader()->forSubstance($this->manager, SubstanceExposureRecord::findOrFail($id)->hazardous_substance_id),
            $this->workspace()->index($this->manager, ['substance_id' => SubstanceExposureRecord::findOrFail($id)->hazardous_substance_id])];
    }
    public function test_current_reported_yes_without_outcome_is_one_pending_with_exact_original_time_and_closed_fields(): void
    {
        $root = $this->exposure(['medical_attention_sought' => true, 'incident_reported' => false, 'medical_treatment' => 'medical']);
        $version = ChemicalExposureSourceVersion::where('exposure_id', $root->id)->sole();
        foreach ($this->both($root->id) as $dto) {
            $record = $dto['records'][0]; $this->assertSame(true, $record['medical_attention_sought']);
            $this->assertSame(1, $dto['counts']['awaiting_medical_outcome']); $this->assertSame('confirmed', $dto['count_integrity']['awaiting_medical_outcome']);
            $this->assertEqualsCanonicalizing(['medical_attention_sought', 'incident_reported', 'medical_treatment'], array_keys($record['fact_states']));
            $this->assertSame(['state' => 'reported', 'recorded_value' => true, 'reported_at' => $version->recorded_at->copy()->utc()->toIso8601String()], $record['fact_states']['medical_attention_sought']);
            $this->assertSame('yes', $record['response']['reported_attention']); $this->assertSame('unassessed', $record['response']['harm_assessment']);
        }
    }
    public function test_explicit_current_no_is_zero_without_deriving_attention_from_discordant_medical_category(): void
    {
        $root = $this->exposure(['medical_attention_sought' => false, 'medical_treatment' => 'hospitalisation']);
        foreach ($this->both($root->id) as $dto) {
            $this->assertFalse($dto['records'][0]['medical_attention_sought']); $this->assertSame(0, $dto['counts']['awaiting_medical_outcome']);
            $this->assertSame('no', $dto['records'][0]['response']['reported_attention']); $this->assertTrue($dto['records'][0]['response']['care_review_needed']);
            $this->assertSame('high', $dto['records'][0]['response']['response_floor']);
        }
    }
    public function test_capture_omission_is_unanswered_and_does_not_publish_a_false_pending_zero(): void
    {
        $root = $this->exposure();
        foreach ($this->both($root->id) as $dto) {
            $this->assertNull($dto['records'][0]['medical_attention_sought']); $this->assertSame('unanswered', $dto['records'][0]['fact_states']['medical_attention_sought']['state']);
            $this->assertNull($dto['counts']['awaiting_medical_outcome']); $this->assertSame('reported_answer_unknown', $dto['count_integrity']['awaiting_medical_outcome']);
            $this->assertSame(1, $dto['counts']['exposures']);
        }
    }
    public function test_explicit_unknown_retains_reported_unknown_origin_and_is_not_omission_or_no(): void
    {
        $root = $this->exposure(['medical_attention_sought' => null]);
        foreach ($this->both($root->id) as $dto) {
            $entry = $dto['records'][0]['fact_states']['medical_attention_sought']; $this->assertSame('reported_unknown', $entry['state']);
            $this->assertNotNull($entry['reported_at']); $this->assertNull($entry['recorded_value']); $this->assertNull($dto['counts']['awaiting_medical_outcome']);
        }
    }
    public function test_legacy_false_is_privately_retained_unconfirmed_but_current_answer_and_pending_count_remain_unknown(): void
    {
        $root = $this->legacy();
        foreach ($this->both($root->id) as $dto) {
            $this->assertNull($dto['records'][0]['medical_attention_sought']);
            $this->assertSame(['state' => 'legacy_unconfirmed', 'recorded_value' => false, 'reported_at' => null], $dto['records'][0]['fact_states']['medical_attention_sought']);
            $this->assertNull($dto['counts']['awaiting_medical_outcome']);
        }
    }
    public function test_real_narrative_acceptance_does_not_certify_a_legacy_false_as_reported_no(): void
    {
        $root = $this->legacy(); $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'New factual narrative', 'circumstances' => 'Later description']));
        $this->assertSame(1, $root->fresh()->source_revision);
        foreach ($this->both($root->id) as $dto) { $this->assertSame('legacy_unconfirmed', $dto['records'][0]['fact_states']['medical_attention_sought']['state']); $this->assertNull($dto['counts']['awaiting_medical_outcome']); }
    }
    public function test_explicit_same_value_legacy_confirmation_is_real_new_reported_no_with_current_time(): void
    {
        $root = $this->legacy(); $receipt = $this->exposureService()->correct($root, $this->manager,
            $this->privateCommand($root, ['reason' => 'Explicit field confirmation', 'medical_attention_sought' => false]));
        $this->assertTrue($receipt['changed']);
        foreach ($this->both($root->id) as $dto) { $this->assertSame('reported', $dto['records'][0]['fact_states']['medical_attention_sought']['state']); $this->assertFalse($dto['records'][0]['medical_attention_sought']); $this->assertSame(0, $dto['counts']['awaiting_medical_outcome']); }
    }
    public function test_mixed_unknown_attention_with_recorded_nonnull_outcome_does_not_invalidate_real_pending_yes(): void
    {
        $parent = $this->catalogue(); $this->capture(['medical_attention_sought' => null, 'medical_outcome' => 'Recorded outcome'], null, $parent->id);
        $this->capture(['medical_attention_sought' => true], null, $parent->id);
        foreach ([$this->reader()->forSubstance($this->manager, $parent->id), $this->workspace()->index($this->manager, ['substance_id' => $parent->id])] as $dto) {
            $this->assertSame(2, $dto['counts']['exposures']); $this->assertSame(1, $dto['counts']['awaiting_medical_outcome']);
            $this->assertSame('confirmed', $dto['count_integrity']['awaiting_medical_outcome']);
            $this->assertNull($dto['records'][1]['medical_attention_sought']);
        }
    }
    public function test_existing_empty_or_whitespace_outcomes_are_nonnull_without_silently_changing_pending_definition(): void
    {
        foreach (['', ' '] as $outcome) {
            $root = $this->legacy(['medical_outcome' => $outcome]);
            foreach ($this->both($root->id) as $dto) { $this->assertNull($dto['records'][0]['medical_attention_sought']); $this->assertSame(0, $dto['counts']['awaiting_medical_outcome']); $this->assertSame('confirmed', $dto['count_integrity']['awaiting_medical_outcome']); }
        }
    }
    public function test_current_site_change_marks_original_answer_prior_context_until_actual_explicit_re_reporting(): void
    {
        $root = $this->exposure(['medical_attention_sought' => true]);
        foreach ([$this->manager, $this->worker] as $user) { HrEmployeeProfile::where('user_id', $user->id)->update(['secondary_site_ids' => [$this->foreignSite->id]]); }
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Reviewed occurrence Site', 'site_id' => $this->foreignSite->id, 'related_incident_id' => null]));
        foreach ($this->both($root->id) as $dto) { $this->assertSame('prior_context', $dto['records'][0]['fact_states']['medical_attention_sought']['state']); $this->assertNull($dto['records'][0]['medical_attention_sought']); $this->assertNull($dto['counts']['awaiting_medical_outcome']); }
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Actual current answer', 'medical_attention_sought' => true]));
        foreach ($this->both($root->id) as $dto) { $this->assertSame(1, $dto['counts']['awaiting_medical_outcome']); $this->assertSame('reported', $dto['records'][0]['fact_states']['medical_attention_sought']['state']); }
    }
    public function test_actual_child_acceptance_preserves_original_reported_time_in_detail_and_collection(): void
    {
        $root = $this->exposure(['medical_attention_sought' => true]); $original = ChemicalExposureSourceVersion::where('exposure_id', $root->id)->sole()->recorded_at->copy()->utc()->toIso8601String();
        $this->travelTo(Carbon::parse('2026-10-12T01:05:00Z')->utc());
        $this->exposureService()->review($root, $this->manager, $this->privateCommand($root, ['reviewed_at' => '2026-10-12T01:05:00Z', 'outcome' => 'recorded', 'notes' => 'Later actual review']));
        $this->assertSame($original, $this->reader()->detail($this->manager, $root->id)['record']['fact_states']['medical_attention_sought']['reported_at']);
        $this->assertSame($original, $this->workspace()->detail($this->manager, $root->id)['record']['fact_states']['medical_attention_sought']['reported_at']);
    }
    public function test_corrupted_retained_fact_contract_is_repair_not_unknown_or_false_clean_count(): void
    {
        $root = $this->exposure(['medical_attention_sought' => true]); $version = ChemicalExposureSourceVersion::where('exposure_id', $root->id)->sole(); $content = $version->content;
        $content['facts']['reported_facts']['medical_attention_sought']['value'] = false;
        DB::table('chemical_exposure_source_versions')->where('id', $version->id)->update(['content' => json_encode($content, JSON_THROW_ON_ERROR)]);
        foreach ($this->both($root->id) as $dto) {
            $this->assertSame('repair_required', $dto['source_integrity']);
            if (is_array($dto['counts'])) { $this->assertNull($dto['counts']['exposures']); $this->assertNull($dto['counts']['awaiting_medical_outcome']); }
            else { $this->assertNull($dto['counts']); }
        }
    }
    public function test_warmed_private_revoke_restore_precedes_private_projection_sql_and_keeps_exact_answer(): void
    {
        $root = $this->exposure(['medical_attention_sought' => true]); $this->manager->load(['roles.permissions', 'permissionOverrides']); $this->allow($this->manager, 'hr.wellbeing.view', false);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->reader()->index($this->manager)); $this->assertNull($dto['records']);
        foreach ($queries as $query) { $this->assertStringNotContainsString('substance_exposure_records', $query['query']); $this->assertStringNotContainsString('chemical_exposure_source_versions', $query['query']); }
        $this->allow($this->manager, 'hr.wellbeing.view'); $this->assertTrue($this->reader()->detail($this->manager, $root->id)['record']['medical_attention_sought']);
    }
    public function test_same_supplied_current_context_returns_exact_projection_without_refreshing_actor(): void
    {
        $root = $this->exposure(['medical_attention_sought' => false]); $expected = $this->reader()->detail($this->manager, $root->id);
        HealthSafetyReadContext::within($this->manager, ChemicalExposureCommandAccess::PERMISSIONS, function ($context) use ($root, $expected) {
            [$dto, $queries] = $this->capturedQueries(fn () => $this->reader()->detailInContext($this->manager, $root->id, $context)); $this->assertSame($expected, $dto);
            foreach ($queries as $query) { foreach (['permission_user', 'role_user', 'role_has_permissions'] as $table) { $this->assertStringNotContainsString($table, $query['query']); } } return null;
        });
    }
    public function test_genuine_unlinked_legacy_workspace_assignment_flag_matches_actual_source_without_certifying_original_client(): void
    {
        $root = $this->legacy(); $dto = $this->workspace()->detail($this->manager, $root->id);
        $this->assertTrue($dto['can']['assign_followup']); $this->assertNull($root->fresh()->original_context);
        $receipt = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $this->manager->id, 'instruction' => 'Actual independent legacy followup', 'due_at' => '2026-10-13T01:00:00Z']));
        $this->assertTrue($receipt['accepted']); $this->assertSame('followup', $receipt['source_type']);
        $this->assertNull($root->fresh()->related_incident_id); $this->assertNull($root->fresh()->original_context);
        $dto = $this->workspace()->detail($this->manager, $root->id); $this->assertSame($receipt['source_id'], $dto['followups']['items'][0]['id']);
        $this->assertSame('legacy_unconfirmed', $dto['record']['fact_states']['medical_attention_sought']['state']);
        $this->assertNull($dto['record']['medical_attention_sought']); $this->assertTrue($dto['can']['assign_followup']);
    }
    public function test_detail_names_cannot_certify_an_earlier_legacy_snapshot_after_real_same_session_mutation(): void
    {
        $root = $this->legacy(); $changed = false;
        User::retrieved(function ($user) use ($root, &$changed) {
            if (! $changed && (int) $user->id === (int) $this->worker->id && array_keys($user->getAttributes()) === ['id', 'name']) {
                $changed = true; DB::table('substance_exposure_records')->where('id', $root->id)->update(['symptoms' => 'New unversioned actual fact']);
            }
        });
        $this->integrity(fn () => $this->workspace()->detail($this->manager, $root->id)); $this->assertTrue($changed);
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['symptoms' => $root->getRawOriginal('symptoms')]);
        $this->assertSame('legacy_unconfirmed', $this->workspace()->detail($this->manager, $root->id)['record']['fact_states']['medical_attention_sought']['state']);
    }
}
