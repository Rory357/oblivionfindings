<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Services\HealthSafety\ChemicalCommandValues;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use Illuminate\Support\Facades\DB;

final class ChemicalExposureHistoricalProofTest extends ChemicalExposureCommandTestCase
{
    private function subject(string $class, int $id, $viewer = null): ?array
    {
        return app(ChemicalExposureCommandAccess::class)->withCurrentViewer($viewer ?? $this->manager,
            fn ($current, $reads, $sites) => $this->exposureService()->sourceProofForAuditSubject($class, $id, $current, $reads, $sites));
    }
    private function incident(): ClientIncident
    {
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($this->manager, $key); }
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        return ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'is_hr_confidential' => false]);
    }

    public function test_real_site_worker_correction_preserves_before_and_canonical_proved_absence_without_relabelling_history(): void
    {
        $root = $this->exposure(['symptoms' => 'Original private occurrence fact']);
        HrEmployeeProfile::where('user_id', $this->manager->id)->firstOrFail()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $newWorker = $this->staff([], $this->foreignSite);
        $r = $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Actual occurrence context correction', 'site_id' => $this->foreignSite->id, 'user_id' => $newWorker->id]));
        $version = ChemicalExposureSourceVersion::findOrFail($r['source_version_id']);
        $this->assertSame($this->site->id, $version->content['root_before']['site_id']);
        $this->assertSame($this->worker->id, $version->content['root_before']['user_id']);
        $this->assertSame($this->foreignSite->id, $root->fresh()->original_context['site_id']);
        $this->assertNull($root->fresh()->original_context['incident_id']); $this->assertSame('recorded', $this->proof($root)['attribution_state']);
    }

    public function test_latest_before_old_site_withdrawal_denies_proof_before_any_full_private_version_select(): void
    {
        $root = $this->exposure(); HrEmployeeProfile::where('user_id', $this->manager->id)->firstOrFail()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $newWorker = $this->staff([], $this->foreignSite);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Actual Site correction', 'site_id' => $this->foreignSite->id, 'user_id' => $newWorker->id]));
        HrEmployeeProfile::where('user_id', $this->manager->id)->firstOrFail()->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => []]);
        DB::enableQueryLog(); DB::flushQueryLog();
        try { $this->denial(fn () => $this->proof($root), 404); $queries = DB::getQueryLog(); }
        finally { DB::disableQueryLog(); }
        foreach ($queries as $q) {
            if (str_contains($q['query'], 'from `chemical_exposure_source_versions`')) {
                $this->assertStringNotContainsString('select *', strtolower($q['query']));
                $this->assertStringNotContainsString('`content`', strtolower($q['query']));
            }
        }
    }

    public function test_unlinked_former_incident_grant_withdrawal_denies_before_latest_before_narrative_hydration(): void
    {
        $incident = $this->incident(); $root = $this->exposure(['related_incident_id' => $incident->id]);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Actually unlink original association', 'related_incident_id' => null]));
        $this->allow($this->manager, 'clients.viewAny', false); $loaded = false;
        ChemicalExposureSourceVersion::retrieved(function ($row) use (&$loaded) { if ($row->getRawOriginal('content') !== null) { $loaded = true; } });
        try { $this->denial(fn () => $this->proof($root), 403); }
        finally { ChemicalExposureSourceVersion::flushEventListeners(); }
        $this->assertFalse($loaded); $this->assertNull($root->fresh()->related_incident_id);
    }

    public function test_historical_receipt_and_intent_require_their_original_site_even_after_later_same_site_revision(): void
    {
        $first = $this->capture(); $root = \App\Models\SubstanceExposureRecord::findOrFail($first['root_id']);
        HrEmployeeProfile::where('user_id', $this->manager->id)->firstOrFail()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $newWorker = $this->staff([], $this->foreignSite);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Site correction', 'site_id' => $this->foreignSite->id, 'user_id' => $newWorker->id]));
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Independent later fact', 'circumstances' => 'Later current Site fact']));
        HrEmployeeProfile::where('user_id', $this->manager->id)->firstOrFail()->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => []]);
        $this->assertSame('recorded', $this->proof($root)['attribution_state']);
        $receipt = ChemicalExposureCommandReceipt::where('idempotency_key', $first['idempotency_key'])->sole();
        foreach ([[ChemicalExposureCommandReceipt::class, $receipt->id], [ChemicalCommandIntent::class, $receipt->intent_id]] as [$class, $id]) {
            $private = false;
            $class::retrieved(function ($row) use (&$private) {
                if ($row->getRawOriginal('accepted_result') !== null || $row->getRawOriginal('actor_snapshot') !== null) { $private = true; }
            });
            try { $this->denial(fn () => $this->subject($class, $id), 404); }
            finally { $class::flushEventListeners(); }
            $this->assertFalse($private);
        }
    }

    public function test_old_delivery_version_rehashed_after_later_advance_remains_bound_to_original_receipt_digest(): void
    {
        $first = $this->capture(); $root = \App\Models\SubstanceExposureRecord::findOrFail($first['root_id']);
        $delivery = ChemicalExposureSourceDelivery::where('source_version_id', $first['source_version_id'])->sole();
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'New actual fact', 'circumstances' => 'Later fact']));
        $old = ChemicalExposureSourceVersion::findOrFail($first['source_version_id']); $content = $old->content;
        $content['root_after']['symptoms'] = 'Privately rehashed substitute'; $content['source_after']['symptoms'] = 'Privately rehashed substitute';
        DB::table('chemical_exposure_source_versions')->where('id', $old->id)->update(['content' => json_encode($content), 'content_digest' => ChemicalCommandValues::digest($content)]);
        $this->assertSame('recorded', $this->proof($root)['attribution_state']);
        $this->assertSame('repair_required', $this->subject(ChemicalExposureSourceDelivery::class, $delivery->id)['attribution_state']);
    }

    public function test_valid_historical_audit_subject_does_not_require_viewer_to_equal_original_recorder(): void
    {
        $r = $this->capture(); $receipt = ChemicalExposureCommandReceipt::where('idempotency_key', $r['idempotency_key'])->sole();
        $other = $this->staff(['hazards.view', 'hr.wellbeing.view']);
        foreach ([[ChemicalExposureCommandReceipt::class, $receipt->id], [ChemicalCommandIntent::class, $receipt->intent_id],
            [ChemicalExposureSourceVersion::class, $r['source_version_id']]] as [$class, $id]) {
            $this->assertSame('recorded', $this->subject($class, $id, $other)['attribution_state']);
        }
    }

    public function test_prepared_permission_union_is_not_a_grant_and_denies_before_any_private_ledger_sql(): void
    {
        $r = $this->capture(); $this->allow($this->manager, 'hr.wellbeing.view', false);
        DB::enableQueryLog(); DB::flushQueryLog();
        try { $this->denial(fn () => $this->subject(ChemicalExposureSourceVersion::class, $r['source_version_id'])); $queries = DB::getQueryLog(); }
        finally { DB::disableQueryLog(); }
        foreach ($queries as $q) { $this->assertStringNotContainsString('chemical_exposure_', strtolower($q['query'])); }
    }

    public function test_unknown_class_public_intent_and_missing_subject_never_admit_private_audit_context(): void
    {
        $public = $this->catalogue(); $intent = ChemicalCommandIntent::where('source_type', 'catalogue')->where('source_id', null)->firstOrFail();
        $this->assertNull($this->subject(ChemicalCommandIntent::class, $intent->id));
        $this->assertNull($this->subject(\App\Models\SafetyDataSheet::class, $public->id));
        $this->assertNull($this->subject(ChemicalExposureSourceVersion::class, 999999));
    }

    public function test_same_active_metadata_helper_rejects_unknown_keys_string_ids_and_contradictory_known_null_tuple(): void
    {
        $root = $this->exposure();
        foreach ([['client_id' => 1], ['site_id' => (string) $this->site->id], ['unproved_client_name' => 'Copied name']] as $bad) {
            $this->integrity(fn () => app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager,
                function ($current, $reads, $sites) use ($root, $bad) {
                    $this->exposureService()->assertOriginalContext([...$root->original_context, ...$bad], $current, $reads, $this->site->id); return null;
                }));
        }
    }
    public function test_actual_noop_remains_original_acceptance_after_worker_site_correction_and_later_advance(): void
    {
        $root = $this->exposure();
        $input = $this->privateCommand($root, ['reason' => 'Reviewed factual values already match']);
        $noop = $this->exposureService()->correct($root, $this->manager, $input);
        $this->assertFalse($noop['changed']); $this->assertNull($noop['source_version_id']); $this->assertSame(1, $noop['version']);
        $receipt = ChemicalExposureCommandReceipt::where('idempotency_key', $noop['idempotency_key'])->sole();
        $this->assertSame($receipt->source_tuple['before'], $receipt->source_tuple['after']);
        $this->assertSame($this->worker->id, $receipt->source_tuple['after']['worker_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $receipt->source_tuple_digest);
        HrEmployeeProfile::where('user_id', $this->manager->id)->firstOrFail()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $newWorker = $this->staff([], $this->foreignSite);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Actual source context correction', 'site_id' => $this->foreignSite->id, 'user_id' => $newWorker->id]));
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Independent current fact', 'symptoms' => 'New Site private fact']));
        $this->assertSame($noop, $this->exposureService()->confirmationForKey($this->manager, $input['idempotency_key'], $root->id));
        $this->assertSame($noop, $this->exposureService()->correct($root, $this->manager, $input));
        $this->assertSame(3, $root->fresh()->source_revision);
        HrEmployeeProfile::where('user_id', $this->manager->id)->firstOrFail()->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => []]);
        $this->assertSame('recorded', $this->proof($root)['attribution_state']);
        $privateBody = false;
        ChemicalExposureCommandReceipt::retrieved(function ($row) use (&$privateBody) {
            if ($row->getRawOriginal('accepted_result') !== null) { $privateBody = true; }
        });
        try { $this->denial(fn () => $this->subject(ChemicalExposureCommandReceipt::class, $receipt->id), 404); }
        finally { ChemicalExposureCommandReceipt::flushEventListeners(); }
        $this->assertFalse($privateBody);
    }

    public function test_noop_original_client_authority_remains_required_after_explicit_unlink_and_later_advance(): void
    {
        $incident = $this->incident(); $root = $this->exposure(['related_incident_id' => $incident->id]);
        $input = $this->privateCommand($root, ['reason' => 'Original facts reviewed unchanged']);
        $noop = $this->exposureService()->correct($root, $this->manager, $input);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Actual later unlink', 'related_incident_id' => null]));
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Current unrelated fact', 'symptoms' => 'Current factual narrative']));
        $this->assertSame($noop, $this->exposureService()->confirmationForKey($this->manager, $input['idempotency_key'], $root->id));
        $this->allow($this->manager, 'clients.viewAny', false);
        $this->assertSame('recorded', $this->proof($root)['attribution_state']);
        $privateBody = false;
        ChemicalExposureCommandReceipt::retrieved(function ($row) use (&$privateBody) {
            if ($row->getRawOriginal('accepted_result') !== null) { $privateBody = true; }
        });
        try { $this->denial(fn () => $this->exposureService()->confirmationForKey($this->manager, $input['idempotency_key'], $root->id), 403); }
        finally { ChemicalExposureCommandReceipt::flushEventListeners(); }
        $this->assertFalse($privateBody);
    }

    public function test_locally_rehashed_noop_tuple_cannot_substitute_the_original_semantic_audit_anchor(): void
    {
        $root = $this->exposure();
        $noop = $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'No factual change']));
        $receipt = ChemicalExposureCommandReceipt::where('idempotency_key', $noop['idempotency_key'])->sole();
        $tuple = $receipt->source_tuple;
        $tuple['before']['worker_id'] = $this->manager->id; $tuple['after']['worker_id'] = $this->manager->id;
        DB::table('chemical_exposure_command_receipts')->where('id', $receipt->id)->update([
            'source_tuple' => json_encode($tuple, JSON_THROW_ON_ERROR), 'source_tuple_digest' => ChemicalCommandValues::digest($tuple)]);
        $privateBody = false;
        ChemicalExposureCommandReceipt::retrieved(function ($row) use (&$privateBody) {
            if ($row->getRawOriginal('accepted_result') !== null) { $privateBody = true; }
        });
        try { $this->integrity(fn () => $this->exposureService()->confirmationForKey($this->manager, $noop['idempotency_key'], $root->id)); }
        finally { ChemicalExposureCommandReceipt::flushEventListeners(); }
        $this->assertFalse($privateBody);
        $this->assertSame('repair_required', $this->subject(ChemicalExposureCommandReceipt::class, $receipt->id)['attribution_state']);
    }

    public function test_changed_version_body_is_not_hydrated_when_locally_rehashed_digest_conflicts_with_original_acceptance(): void
    {
        $first = $this->capture(); $root = \App\Models\SubstanceExposureRecord::findOrFail($first['root_id']);
        $version = ChemicalExposureSourceVersion::findOrFail($first['source_version_id']); $content = $version->content;
        $content['root_after']['symptoms'] = 'Locally substituted'; $content['source_after']['symptoms'] = 'Locally substituted';
        DB::table('chemical_exposure_source_versions')->where('id', $version->id)->update([
            'content' => json_encode($content, JSON_THROW_ON_ERROR), 'content_digest' => ChemicalCommandValues::digest($content)]);
        $loaded = false;
        ChemicalExposureSourceVersion::retrieved(function ($row) use (&$loaded) { if ($row->getRawOriginal('content') !== null) { $loaded = true; } });
        try { $this->assertSame('repair_required', $this->proof($root)['attribution_state']); }
        finally { ChemicalExposureSourceVersion::flushEventListeners(); }
        $this->assertFalse($loaded);
    }

    public function test_create_only_recovery_after_later_private_write_proves_original_acceptance_without_hydrating_latest_private_version(): void
    {
        $first = $this->capture(['symptoms' => 'Reported immediate fact'], $this->reporter);
        $root = \App\Models\SubstanceExposureRecord::findOrFail($first['root_id']);
        $later = $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Authorised subsequent private fact', 'symptoms' => 'Later saved private fact']));
        $loaded = [];
        ChemicalExposureSourceVersion::retrieved(function ($row) use (&$loaded) {
            if ($row->getRawOriginal('content') !== null) { $loaded[] = (int) $row->id; }
        });
        DB::enableQueryLog(); DB::flushQueryLog();
        try { $dto = $this->exposureService()->confirmationForKey($this->reporter, strtoupper($first['idempotency_key'])); $queries = DB::getQueryLog(); }
        finally { ChemicalExposureSourceVersion::flushEventListeners(); DB::disableQueryLog(); }
        foreach ($queries as $query) {
            if (str_contains($query['query'], 'from `substance_exposure_records`')) {
                $this->assertStringNotContainsString('select *', strtolower($query['query']));
                foreach (['symptoms', 'first_aid_given', 'medical_outcome', 'circumstances'] as $column) {
                    $this->assertStringNotContainsString('`'.$column.'`', $query['query']);
                }
            }
        }
        $this->assertSame($first, $dto); $this->assertContains($first['source_version_id'], $loaded);
        $this->assertNotContains($later['source_version_id'], $loaded);
        $this->assertArrayNotHasKey('symptoms', $dto); $this->assertArrayNotHasKey('source_tuple', $dto);
        $this->denial(fn () => $this->proof($root, $this->reporter));
        $this->allow($this->reporter, 'hazards.create', false);
        $this->denial(fn () => $this->exposureService()->confirmationForKey($this->reporter, $first['idempotency_key']));
    }
}
