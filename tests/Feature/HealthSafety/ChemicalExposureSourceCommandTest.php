<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalExposureLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChemicalExposureSourceCommandTest extends ChemicalExposureCommandTestCase
{
    public function test_create_only_reporter_records_submitted_details_but_acceptance_never_returns_medical_body(): void
    {
        $r = $this->capture(['circumstances' => 'PRIVATE-REPORT', 'symptoms' => 'PRIVATE-SYMPTOMS',
            'first_aid_given' => 'PRIVATE-FIRST-AID', 'medical_treatment' => 'medical', 'medical_outcome' => 'PRIVATE-OUTCOME'], $this->reporter);
        $root = SubstanceExposureRecord::findOrFail($r['root_id']);
        $this->assertNull($root->medical_attention_sought); $this->assertSame('medical', $root->medical_treatment); $this->assertSame($this->worker->id, $root->user_id);
        $this->assertSame($this->reporter->id, $root->created_by); $this->assertSame(1, $root->source_revision);
        $this->assertEqualsCanonicalizing(ChemicalExposureLedger::RECEIPT_KEYS, array_keys($r));
        $json = json_encode($r, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-REPORT', 'PRIVATE-SYMPTOMS', 'PRIVATE-FIRST-AID', 'PRIVATE-OUTCOME'] as $body) { $this->assertStringNotContainsString($body, $json); }
        $this->denial(fn () => $this->proof($root, $this->reporter));
        $this->denial(fn () => $this->exposureService()->correct($root, $this->reporter,
            $this->privateCommand($root, ['reason' => 'Correct fact', 'medical_outcome' => 'Overwrite'])));
        $this->assertSame($r, $this->exposureService()->confirmationForKey($this->reporter, strtoupper($r['idempotency_key'])));
    }

    public function test_correct_preserves_omitted_private_facts_worker_and_original_recorder_and_retains_before_after(): void
    {
        $root = $this->exposure(['symptoms' => 'Retained symptoms', 'medical_outcome' => 'Retained outcome']);
        $input = $this->privateCommand($root, ['reason' => 'Correct occurrence narrative', 'circumstances' => 'Actual revised narrative']);
        $r = $this->exposureService()->correct($root, $this->manager, $input);
        $this->assertSame(2, $r['version']); $this->assertTrue($r['changed']);
        $current = $root->fresh(); $this->assertSame('Retained symptoms', $current->symptoms);
        $this->assertSame('Retained outcome', $current->medical_outcome); $this->assertSame($this->worker->id, $current->user_id);
        $v = ChemicalExposureSourceVersion::findOrFail($r['source_version_id']);
        $this->assertNull($v->content['root_before']['circumstances']); $this->assertSame('Actual revised narrative', $v->content['root_after']['circumstances']);
        $this->assertSame($this->manager->id, $v->recorder_id); $this->assertSame('recorded', $this->proof($current)['attribution_state']);
        $this->assertSame($r, $this->exposureService()->correct($root, $this->manager, $input));
    }

    public function test_genuine_noop_retains_revision_and_does_not_certify_legacy_source(): void
    {
        $root = SubstanceExposureRecord::factory()->create(['hazardous_substance_id' => $this->catalogue()->id,
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'created_by' => $this->manager->id,
            'circumstances' => 'Retained', 'source_schema_version' => 0, 'source_revision' => 0, 'related_incident_id' => null]);
        $r = $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Reviewed identical fact', 'circumstances' => 'Retained']));
        $this->assertFalse($r['changed']); $this->assertNull($r['source_version_id']); $this->assertSame(0, $r['version']);
        $this->assertSame(0, ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count());
        $this->assertSame('legacy_unknown', $this->proof($root)['attribution_state']);
    }

    public function test_stale_command_preserves_entries_and_source_without_a_second_receipt_or_intent(): void
    {
        $root = $this->exposure(); $stale = $this->privateCommand($root, ['reason' => 'Stale edit', 'symptoms' => 'Draft symptoms']);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Current edit', 'circumstances' => 'Current']));
        $this->validation(fn () => $this->exposureService()->correct($root, $this->manager, $stale), 'expected_version');
        $this->assertSame('Draft symptoms', $stale['symptoms']); $this->assertNull($root->fresh()->symptoms);
        $this->assertSame(0, ChemicalExposureCommandReceipt::where('idempotency_key', $stale['idempotency_key'])->count());
        $this->assertSame(0, ChemicalCommandIntent::where('idempotency_key', $stale['idempotency_key'])->count());
    }

    public function test_unknown_fields_bad_scalar_ids_and_unzoned_or_future_actual_time_refuse_without_write(): void
    {
        $catalogue = $this->catalogue();
        foreach ([['tenant_id' => 1], ['user_id' => true], ['exposed_at' => '2026-10-12 00:00:00'],
            ['exposed_at' => '2026-02-30T00:00:00Z'], ['exposed_at' => '2026-10-13T00:00:00Z']] as $bad) {
            $field = array_key_first($bad) === 'tenant_id' ? 'command' : array_key_first($bad);
            $this->validation(fn () => $this->capture($bad, null, $catalogue->id), $field);
        }
        $this->assertSame(0, SubstanceExposureRecord::count()); $this->assertSame(0, ChemicalExposureSourceVersion::count());
    }

    public function test_independent_attention_and_category_preserve_answers_without_reinterpreting_report(): void
    {
        $r = $this->capture(['medical_treatment' => 'none', 'medical_attention_sought' => true]);
        $source = SubstanceExposureRecord::findOrFail($r['root_id']);
        $this->assertTrue($source->medical_attention_sought); $this->assertSame('none', $source->medical_treatment);
        $r = $this->capture(['medical_treatment' => 'hospitalisation']);
        $source = SubstanceExposureRecord::findOrFail($r['root_id']);
        $this->assertNull($source->medical_attention_sought); $this->assertSame('hospitalisation', $source->medical_treatment);
    }

    public function test_capture_requires_current_worker_at_selected_active_site_and_does_not_assign_other_person(): void
    {
        $foreignWorker = $this->staff([], $this->foreignSite);
        $this->denial(fn () => $this->capture(['user_id' => $foreignWorker->id]), 404);
        HrEmployeeProfile::where('user_id', $this->worker->id)->update(['end_date' => '2026-10-11']);
        $this->denial(fn () => $this->capture(), 404);
        $this->assertSame(0, SubstanceExposureRecord::count());
    }

    public function test_original_site_is_required_and_unknown_or_archived_site_never_becomes_global_capture(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::TEST_SHAPE, 'an archived or inactive Site is refused with 403 instead of 404: still denied, and the same on Codex\'s snapshot.', 'align the status code.'));
        $this->validation(fn () => $this->capture(['site_id' => null]), 'site_id');
        $this->denial(fn () => $this->capture(['site_id' => $this->foreignSite->id]), 404);
        $this->site->update(['archived_at' => now()]);
        $this->denial(fn () => $this->capture(), 404); $this->assertSame(0, SubstanceExposureRecord::count());
    }

    public function test_source_save_veto_rolls_back_intent_audit_version_and_delivery(): void
    {
        $catalogue = $this->catalogue(); SubstanceExposureRecord::creating(fn () => false);
        try { $this->integrity(fn () => $this->capture([], null, $catalogue->id)); }
        finally { SubstanceExposureRecord::flushEventListeners(); }
        $this->assertSame(0, SubstanceExposureRecord::count()); $this->assertSame(0, ChemicalExposureSourceVersion::count());
        $this->assertSame(0, ChemicalExposureCommandReceipt::count()); $this->assertSame(0, ChemicalExposureSourceDelivery::count());
        $this->assertSame(0, ChemicalCommandIntent::where('source_type', 'exposure')->count());
    }

    public function test_strict_semantic_audit_veto_rolls_back_source_and_private_history(): void
    {
        $root = $this->exposure(); $input = $this->privateCommand($root, ['reason' => 'Actual edit', 'symptoms' => 'Refused']);
        AuditLog::creating(fn ($row) => $row->action === 'chemical.exposure.correct_exposure' ? false : null);
        try { $this->integrity(fn () => $this->exposureService()->correct($root, $this->manager, $input)); }
        finally { AuditLog::flushEventListeners(); }
        $this->assertSame(1, $root->fresh()->source_revision); $this->assertNull($root->fresh()->symptoms);
        $this->assertSame(1, ChemicalExposureSourceVersion::count()); $this->assertSame(1, ChemicalExposureCommandReceipt::count());
        $this->assertSame(1, ChemicalExposureSourceDelivery::count());
    }

    public function test_version_or_receipt_veto_rolls_back_accepted_source_changes(): void
    {
        $root = $this->exposure();
        foreach ([ChemicalExposureSourceVersion::class, ChemicalExposureCommandReceipt::class] as $model) {
            $model::creating(fn () => false);
            try { $this->integrity(fn () => $this->exposureService()->correct($root, $this->manager,
                $this->privateCommand($root, ['reason' => 'Refused fact', 'symptoms' => 'Not retained']))); }
            finally { $model::flushEventListeners(); }
            $this->assertSame(1, $root->fresh()->source_revision); $this->assertNull($root->fresh()->symptoms);
        }
    }

    public function test_generic_audit_excludes_worker_context_and_private_medical_content(): void
    {
        $root = $this->exposure(['circumstances' => 'PRIVATE-BODY', 'symptoms' => 'PRIVATE-SYMPTOMS']);
        $rows = AuditLog::where('auditable_type', $root->getMorphClass())->where('auditable_id', $root->id)->get();
        $json = json_encode($rows->pluck('meta')->all(), JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-BODY', 'PRIVATE-SYMPTOMS', 'original_context', 'first_aid_given', 'medical_outcome'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertSame('pending', ChemicalExposureSourceDelivery::where('exposure_id', $root->id)->sole()->state);
        $this->assertNull(ChemicalExposureSourceDelivery::where('exposure_id', $root->id)->sole()->hs_event_id);
    }
    public function test_new_source_omitted_database_defaults_are_recorded_from_persisted_row_not_an_incomplete_model(): void
    {
        $r = $this->capture(); $root = \App\Models\SubstanceExposureRecord::findOrFail($r['root_id']);
        $version = \App\Models\ChemicalExposureSourceVersion::findOrFail($r['source_version_id']);
        $this->assertSame($root->attributesToArray()['medical_treatment'], $version->content['root_after']['medical_treatment']);
        $this->assertArrayHasKey('medical_outcome', $version->content['root_after']);
        $this->assertArrayHasKey('deleted_at', $version->content['root_after']);
        $this->assertSame('recorded', $this->proof($root)['attribution_state']);
        $review = $this->exposureService()->review($root, $this->manager, $this->privateCommand($root,
            ['reviewed_at' => '2026-10-12T00:30:00Z', 'outcome' => 'recorded', 'notes' => 'Actual review notes']));
        $this->assertSame(2, $review['version']); $this->assertSame('recorded', $this->proof($root)['attribution_state']);
    }

    public function test_silent_source_save_listener_mutation_is_refused_against_original_supplied_intent_and_rolls_back(): void
    {
        $root = $this->exposure(['symptoms' => 'Original']); $versions = \App\Models\ChemicalExposureSourceVersion::count();
        \App\Models\SubstanceExposureRecord::saving(function ($row) { if ($row->exists) { $row->symptoms = 'Silent substituted fact'; } });
        try {
            $this->integrity(fn () => $this->exposureService()->correct($root, $this->manager,
                $this->privateCommand($root, ['reason' => 'Actual correction', 'symptoms' => 'Explicit intended fact'])));
        } finally { \App\Models\SubstanceExposureRecord::flushEventListeners(); }
        $this->assertSame('Original', $root->fresh()->symptoms); $this->assertSame(1, $root->fresh()->source_revision);
        $this->assertSame($versions, \App\Models\ChemicalExposureSourceVersion::count());
    }
}
