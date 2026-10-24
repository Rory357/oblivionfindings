<?php

namespace Tests\Feature\Incidents;

use App\Models\AuditLog;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\IncidentSavedCopy;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Incidents\IncidentIndependencePolicySettings as Policy;
use App\Services\Incidents\IncidentSavedCopyService as Copies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Native source/receipt suite UNRUN. Does not certify any destination writer, notice consumer or CR provenance mount. */
final class IncidentSavedCopyTest extends IncidentIndependenceTestCase
{
    private function within(User $actor, callable $operation): mixed
    {
        return HealthSafetyReadContext::within($actor, Copies::PERMISSIONS, $operation);
    }

    private function capture(int $id, ?User $actor = null, ?string $uuid = null, string $target = 'control_room_operator_note', string $key = '81', string $field = 'description'): array
    {
        return $this->within($actor ?? $this->manager, fn ($context) => app(Copies::class)->capture($context, $id, $uuid ?? (string) Str::uuid(), $target, $key, $field));
    }

    private function read(array $copy, ?User $actor = null, array $override = []): array
    {
        $copy = [...$copy, ...$override];
        return $this->within($actor ?? $this->manager, fn ($context) => app(Copies::class)->project($context,
            $copy['copy_uuid'] ?? null, $copy['target_family'] ?? null, $copy['target_key'] ?? null, $copy['field'] ?? null, $copy['source_version'] ?? null, $copy['copy']['text'] ?? null));
    }

    private function unavailable(array $result): void
    {
        $this->assertSame(['available' => false, 'copy' => null], $result);
    }

    public function test_prospective_capture_has_one_mandatory_audit_and_preserves_only_the_original_field_on_read(): void
    {
        $root = $this->incident(); $copy = $this->capture($root->id);
        $row = IncidentSavedCopy::where('copy_uuid', $copy['copy_uuid'])->sole();
        $this->assertSame($this->manager->id, $row->actor_id);
        $this->assertSame($this->reporter->id, $row->source_tuple['reported_by']);
        $this->assertSame($this->site->id, $row->source_tuple['site_id']);
        $this->assertSame($row->source_version, $copy['source_version']);
        $this->assertNotSame($copy['copy_uuid'], $copy['source_version']);
        $this->assertSame(1, AuditLog::where('action', 'incidents.savedCopy.captured')->where('auditable_id', $row->id)->count());
        $this->assertSame(['available' => true, 'copy' => $copy['copy']], $this->read($copy));
        $this->assertSame(['text', 'recorded_at'], array_keys($this->read($copy)['copy']));
    }

    public function test_later_source_edit_does_not_substitute_today_text_for_a_saved_version(): void
    {
        $root = $this->incident(); $copy = $this->capture($root->id);
        DB::table('client_incidents')->where('id', $root->id)->update(['description' => 'Later canonical report text']);
        $this->assertSame('Private synthetic incident narrative', $this->read($copy)['copy']['text']);
        $new = $this->capture($root->id);
        $this->assertNotSame($copy['source_version'], $new['source_version']);
        $this->assertSame('Later canonical report text', $this->read($new)['copy']['text']);
    }

    public function test_replaying_the_same_source_field_actor_and_destination_keeps_one_original_version(): void
    {
        $root = $this->incident(); $uuid = (string) Str::uuid(); $first = $this->capture($root->id, uuid: $uuid);
        $this->assertSame($first, $this->capture($root->id, uuid: $uuid));
        $this->assertSame(1, IncidentSavedCopy::where('copy_uuid', $uuid)->count());
    }

    public function test_replay_never_overwrites_a_changed_source_field_or_foreign_target(): void
    {
        $root = $this->incident(); $copy = $this->capture($root->id);
        DB::table('client_incidents')->where('id', $root->id)->update(['description' => 'Changed report intent']);
        $this->expectException(LogicException::class);
        $this->capture($root->id, uuid: $copy['copy_uuid']);
    }

    #[DataProvider('wrongOriginal')]
    public function test_incorrect_version_destination_field_or_unknown_origin_is_unavailable(array $override): void
    {
        $copy = $this->capture($this->incident()->id); $this->unavailable($this->read($copy, override: $override));
    }

    public static function wrongOriginal(): array
    {
        return ['version' => [['source_version' => '550e8400-e29b-41d4-a716-446655440000']],
            'target' => [['target_key' => '82']], 'family' => [['target_family' => 'incident_notification']],
            'field' => [['field' => 'immediate_action_taken']], 'unknown family' => [['target_family' => 'legacy']],
            'missing origin' => [['copy_uuid' => null]], 'malformed key' => [['target_key' => '../81']],
            'changed saved body' => [['copy' => ['text' => 'Destination text overwritten']]], 'unknown saved body' => [['copy' => null]]];
    }

    public function test_original_site_and_person_cannot_be_inferred_after_source_reassociation(): void
    {
        $root = $this->incident(); $copy = $this->capture($root->id);
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        DB::table('client_incidents')->where('id', $root->id)->update(['client_id' => $other->id]);
        $this->unavailable($this->read($copy));
        DB::table('client_incidents')->where('id', $root->id)->update(['client_id' => $root->client_id]);
        $otherSite = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::where('user_id', $this->manager->id)->firstOrFail()->update(['secondary_site_ids' => [$otherSite->id]]);
        DB::table('client_incidents')->where('id', $root->id)->update(['site_id' => $otherSite->id]);
        $this->unavailable($this->read($copy));
    }

    public function test_current_read_permission_withdrawal_and_restore_uses_fresh_evidence_not_warmed_user(): void
    {
        $copy = $this->capture($this->incident()->id); $warm = $this->manager->fresh()->load(['roles.permissions', 'permissionOverrides']);
        foreach (['incidents.viewAny', 'incidents.viewAssigned'] as $key) { $this->allow($this->manager, $key, false); }
        $this->unavailable($this->read($copy, $warm));
        $this->allow($this->manager, 'incidents.viewAny');
        $this->assertTrue($this->read($copy, $warm)['available']);
    }

    public function test_hr_privacy_withdrawal_hides_saved_source_without_releasing_another_healthy_copy(): void
    {
        $this->allow($this->manager, 'hr.cases.view'); $private = $this->incident(['is_hr_confidential' => true]);
        $copy = $this->capture($private->id); $healthy = $this->capture($this->incident()->id);
        $this->allow($this->manager, 'hr.cases.view', false);
        $this->unavailable($this->read($copy)); $this->assertTrue($this->read($healthy)['available']);
    }

    public function test_current_approved_site_withdrawal_conceals_original_saved_source_and_can_restore(): void
    {
        $copy = $this->capture($this->incident()->id); $foreign = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::where('user_id', $this->manager->id)->firstOrFail()->update(['primary_site_id' => $foreign->id, 'secondary_site_ids' => []]);
        $this->unavailable($this->read($copy));
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertTrue($this->read($copy)['available']);
    }

    public function test_named_independent_handling_hides_saved_copy_through_original_policy_even_after_closure(): void
    {
        $receipt = $this->accept($this->command()); $copy = $this->capture($receipt['incident_id']);
        $this->unavailable($this->read($copy, $this->lead));
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Future incident policy only.']);
        DB::table('client_incidents')->where('id', $receipt['incident_id'])->update(['status' => 'closed', 'closed_at' => now()]);
        $this->unavailable($this->read($copy, $this->lead)); $this->assertTrue($this->read($copy)['available']);
    }

    public function test_notification_copy_uses_same_canonical_independence_boundary_without_granting_ownership(): void
    {
        $receipt = $this->accept($this->command()); $copy = $this->capture($receipt['incident_id'], target: 'incident_notification', key: (string) Str::uuid());
        $this->unavailable($this->read($copy, $this->lead)); $this->assertTrue($this->read($copy)['available']);
    }

    public function test_original_field_capture_refuses_unknown_occurrence_site_without_creating_provenance(): void
    {
        $this->allow($this->manager, 'healthSafety.viewAllSites'); $root = $this->incident(['site_id' => null]);
        try { $this->capture($root->id); $this->fail('Unknown original occurrence cannot become proved through current placement.'); }
        catch (HttpExceptionInterface $error) { $this->assertSame(404, $error->getStatusCode()); }
        $this->assertSame(0, IncidentSavedCopy::count());
    }

    public function test_reclassified_mandatory_audit_claim_and_unaudited_text_changes_are_unavailable(): void
    {
        $copy = $this->capture($this->incident()->id); $row = IncidentSavedCopy::where('copy_uuid', $copy['copy_uuid'])->sole();
        DB::table('audit_logs')->where('id', $row->audit_id)->update(['action' => 'changed']);
        $this->unavailable($this->read($copy));
        DB::table('audit_logs')->where('id', $row->audit_id)->update(['action' => 'incidents.savedCopy.captured']);
        DB::table('incident_saved_copies')->where('id', $row->id)->update(['snapshot_text' => 'Unaudited replacement']);
        $this->unavailable($this->read($copy));
    }

    public function test_late_duplicate_audit_membership_is_guarded_at_the_actual_body_query(): void
    {
        $copy = $this->capture($this->incident()->id); $row = IncidentSavedCopy::where('copy_uuid', $copy['copy_uuid'])->sole();
        $audit = (array) DB::table('audit_logs')->where('id', $row->audit_id)->first(); unset($audit['id']);
        $armed = true; $fired = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$armed, &$fired, $audit): void {
            if (! $armed || ! str_contains($sql, 'select * from `incident_saved_copies`')) { return; }
            $armed = false; $fired = true; DB::table('audit_logs')->insert($audit);
        });
        try { $this->unavailable($this->read($copy)); $this->assertTrue($fired); }
        finally { $armed = false; }
    }

    public function test_late_copy_metadata_change_does_not_materialise_body_as_an_original_version(): void
    {
        $copy = $this->capture($this->incident()->id); $armed = true; $fired = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$armed, &$fired, $copy): void {
            if (! $armed || ! str_contains($sql, 'select * from `incident_saved_copies`')) { return; }
            $armed = false; $fired = true;
            DB::table('incident_saved_copies')->where('copy_uuid', $copy['copy_uuid'])->update(['target_key' => '82']);
        });
        try { $this->unavailable($this->read($copy)); $this->assertTrue($fired); }
        finally { $armed = false; }
    }

    public function test_late_person_reassociation_is_refused_before_receipt_body_even_with_unchanged_saved_metadata(): void
    {
        $root = $this->incident(); $copy = $this->capture($root->id);
        $other = Client::factory()->create(['site_id' => $this->site->id]); $armed = true; $fired = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$armed, &$fired, $root, $other): void {
            if (! $armed || ! str_contains($sql, 'select * from `incident_saved_copies`')) { return; }
            $armed = false; $fired = true;
            DB::table('client_incidents')->where('id', $root->id)->update(['client_id' => $other->id]);
        });
        try { $this->unavailable($this->read($copy)); $this->assertTrue($fired); }
        finally { $armed = false; }
    }

    public function test_original_reporter_change_is_unavailable_without_rewriting_copy_attribution(): void
    {
        $root = $this->incident(); $copy = $this->capture($root->id);
        DB::table('client_incidents')->where('id', $root->id)->update(['reported_by' => $this->manager->id]);
        $this->unavailable($this->read($copy));
        $this->assertSame($this->reporter->id, IncidentSavedCopy::where('copy_uuid', $copy['copy_uuid'])->sole()->source_tuple['reported_by']);
    }

    #[DataProvider('byteChangedAudit')]
    public function test_case_or_trailing_space_audit_reclassification_is_not_original_capture_proof(string $column, string $value): void
    {
        $copy = $this->capture($this->incident()->id); $row = IncidentSavedCopy::where('copy_uuid', $copy['copy_uuid'])->sole();
        DB::table('audit_logs')->where('id', $row->audit_id)->update([$column => $value]);
        $this->unavailable($this->read($copy));
    }

    public static function byteChangedAudit(): array
    {
        return ['case action' => ['action', 'INCIDENTS.savedCopy.captured'], 'trailing action' => ['action', 'incidents.savedCopy.captured '],
            'case type' => ['auditable_type', 'App\\Models\\incidentsavedcopy'], 'trailing type' => ['auditable_type', IncidentSavedCopy::class.' ']];
    }

    public function test_case_or_trailing_space_source_reassociation_is_not_original_source_proof(): void
    {
        $root = $this->incident(['source' => 'manual']); $copy = $this->capture($root->id);
        foreach (['MANUAL', 'manual '] as $value) {
            DB::table('client_incidents')->where('id', $root->id)->update(['source' => $value]);
            $this->unavailable($this->read($copy));
        }
    }

    public function test_capture_audit_failure_rolls_back_source_copy_rather_than_certifying_partial_evidence(): void
    {
        $root = $this->incident(); $armed = true;
        AuditLog::creating(function (AuditLog $audit) use (&$armed): void {
            if ($armed && $audit->action === 'incidents.savedCopy.captured') { throw new LogicException('Mandatory capture audit refused.'); }
        });
        try { $this->capture($root->id); $this->fail('Mandatory audit failure must propagate.'); }
        catch (LogicException $error) { $this->assertSame('Mandatory capture audit refused.', $error->getMessage()); }
        finally { $armed = false; }
        $this->assertSame(0, IncidentSavedCopy::count());
    }

    public function test_receipt_is_immutable_and_context_cannot_escape_its_callback(): void
    {
        $copy = $this->capture($this->incident()->id); $row = IncidentSavedCopy::where('copy_uuid', $copy['copy_uuid'])->sole();
        try { $row->target_key = '82'; $row->saveOrFail(); $this->fail('Original copy cannot be reassigned.'); }
        catch (LogicException $error) { $this->assertSame('Incident saved-copy evidence is immutable.', $error->getMessage()); }
        $retained = null;
        $this->within($this->manager, function ($context) use (&$retained): array { $retained = $context; return []; });
        $this->expectException(LogicException::class);
        app(Copies::class)->project($retained, $copy['copy_uuid'], $copy['target_family'], $copy['target_key'], $copy['field'], $copy['source_version'], $copy['copy']['text']);
    }

    public function test_rollback_refuses_retained_copy_and_capture_audit_even_if_copy_row_is_removed_outside_its_model(): void
    {
        $copy = $this->capture($this->incident()->id);
        $migration = require base_path('database/migrations/2026_10_12_200900_create_incident_saved_copies.php');
        try { $migration->down(); $this->fail('Rollback cannot discard a retained original copy.'); }
        catch (\RuntimeException $error) { $this->assertSame('Incident saved copies and their provenance must be retained.', $error->getMessage()); }
        $this->assertSame(1, IncidentSavedCopy::where('copy_uuid', $copy['copy_uuid'])->count());
        DB::table('incident_saved_copies')->where('copy_uuid', $copy['copy_uuid'])->delete();
        try { $migration->down(); $this->fail('Retained capture audit is not an empty-table rollback certificate.'); }
        catch (\RuntimeException $error) { $this->assertSame('Incident saved copies and their provenance must be retained.', $error->getMessage()); }
        $this->assertSame(1, AuditLog::where('action', 'incidents.savedCopy.captured')->count());
    }

    #[DataProvider('retainedClaimVariants')]
    public function test_removed_receipt_with_retained_claim_never_recreates_original_identity_and_new_intent_still_works(array $changes): void
    {
        $root = $this->incident(); $copy = $this->capture($root->id); $row = IncidentSavedCopy::where('copy_uuid', $copy['copy_uuid'])->sole();
        DB::table('incident_saved_copies')->where('id', $row->id)->delete();
        if (isset($changes['malformed_meta'])) {
            $meta = AuditLog::findOrFail($row->audit_id)->meta; $meta['source_version'] = false;
            $changes = ['meta' => json_encode($meta)];
        }
        if ($changes !== []) { DB::table('audit_logs')->where('id', $row->audit_id)->update($changes); }
        try { $this->capture($root->id, uuid: $copy['copy_uuid']); $this->fail('Retained accepted intent cannot become a newly generated version.'); }
        catch (LogicException $error) { $this->assertSame('Copy identity has retained original evidence and requires repair.', $error->getMessage()); }
        $this->assertSame(0, IncidentSavedCopy::count()); $this->assertSame(1, AuditLog::whereKey($row->audit_id)->count());
        $new = $this->capture($root->id); $this->assertNotSame($copy['copy_uuid'], $new['copy_uuid']); $this->assertTrue($this->read($new)['available']);
    }

    public static function retainedClaimVariants(): array
    {
        return ['unchanged original' => [[]], 'reclassified action' => [['action' => 'reclassified']],
            'reclassified type' => [['auditable_type' => 'unknown_source_family']], 'malformed retained claim' => [['malformed_meta' => true]]];
    }

    public function test_late_reclassified_claim_for_same_copy_uuid_is_not_a_second_original_receipt(): void
    {
        $copy = $this->capture($this->incident()->id); $row = IncidentSavedCopy::where('copy_uuid', $copy['copy_uuid'])->sole();
        $claim = (array) DB::table('audit_logs')->where('id', $row->audit_id)->first(); unset($claim['id']);
        $claim['action'] = 'reclassified'; $claim['auditable_type'] = 'unknown_source_family'; $claim['auditable_id'] = $row->id + 1;
        $armed = true; $fired = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$armed, &$fired, $claim): void {
            if (! $armed || ! str_contains($sql, 'select * from `incident_saved_copies`')) { return; }
            $armed = false; $fired = true; DB::table('audit_logs')->insert($claim);
        });
        try { $this->unavailable($this->read($copy)); $this->assertTrue($fired); }
        finally { $armed = false; }
    }

    private function alertFieldCopy(string $field = 'description'): array
    {
        $root = $this->incident(['immediate_action_taken' => 'Original immediate control.']);
        return $this->within($this->manager, function ($context) use ($root, $field): array {
            // Fixture destination is real existing storage, not a new CR writer/policy implementation.
            $alert = \App\Models\ControlRoomAlert::withoutEvents(fn () => \App\Models\ControlRoomAlert::factory()->create([
                'source' => 'incident', 'alert_type' => 'incident.injury', 'site_id' => $root->site_id,
                'client_id' => $root->client_id, 'created_by_user_id' => $this->manager->id, 'notes' => null]));
            $copy = app(Copies::class)->capture($context, $root->id, (string) Str::uuid(), Copies::ALERT_NOTES_TARGET, (string) $alert->id, $field);
            DB::table('control_room_alerts')->where('id', $alert->id)->update(['notes' => $copy['copy']['text'],
                'context' => json_encode(['saved_incident_copy' => $this->origin($copy)])]);
            return ['root_id' => (int) $root->id, 'alert_id' => (int) $alert->id, 'site_id' => (int) $root->site_id,
                'client_id' => (int) $root->client_id, 'copy' => $copy];
        });
    }

    private function origin(array $copy): array
    {
        return array_intersect_key($copy, array_flip(['copy_uuid', 'source_family', 'source_version', 'target_family', 'target_key', 'field']));
    }

    private function destination(array $fixture, array $origin, array $copy, \App\Services\CurrentAuthorizationReads $reads, \Illuminate\Database\Eloquent\Builder $predicate, ?callable $beforeSelect = null): array
    {
        // Owning caller independently constrains exact destination/context and full field bytes.
        // This suite proves the source leaf on the actual SELECT; it does not mount/certify the CR exporter.
        $query = \App\Models\ControlRoomAlert::query()->whereKey($fixture['alert_id'])
            ->where('site_id', $fixture['site_id'])->where('client_id', $fixture['client_id'])
            ->whereRaw('BINARY control_room_alerts.notes = BINARY ?', [$copy['text']]);
        foreach ($origin as $key => $value) {
            $query->whereRaw('BINARY JSON_UNQUOTE(JSON_EXTRACT(control_room_alerts.context, ?)) = BINARY ?', ['$.saved_incident_copy.'.$key, $value]);
        }
        if ($beforeSelect !== null) { $query->beforeQuery($beforeSelect); }
        $row = $reads->query($query->whereExists((clone $predicate)->reorder()->select('incident_saved_copies.id')->toBase()))->first(['id', 'notes']);
        return ['notes_access' => $row === null ? 'unavailable' : 'readable', 'notes' => $row?->notes];
    }

    private function liveCopy(array $fixture, ?User $actor = null, ?callable $materializer = null, array $override = []): array
    {
        $origin = [...$this->origin($fixture['copy']), ...$override];
        return $this->within($actor ?? $this->manager, fn ($context) => app(Copies::class)->consumeProjectedCopy($context, $origin,
            $materializer ?? fn ($copy, $reads, $predicate) => $this->destination($fixture, $origin, $copy, $reads, $predicate)));
    }

    private function unavailableMaterialisation(array $result): void
    {
        $this->assertSame(['available' => false, 'result' => null], $result);
    }

    /** Count real notes hydration, not only the final result or a special alias. */
    private function atDestination(callable $mutate, callable $consume): void
    {
        $state = (object) ['armed' => true, 'fired' => false, 'notes_hydrated' => 0];
        $dispatcher = \Illuminate\Database\Eloquent\Model::getEventDispatcher();
        \Illuminate\Database\Eloquent\Model::setEventDispatcher(clone $dispatcher);
        \App\Models\ControlRoomAlert::retrieved(function ($row) use ($state): void {
            if ($state->fired && array_key_exists('notes', $row->getAttributes())) { $state->notes_hydrated++; }
        });
        DB::connection()->beforeExecuting(function ($sql) use ($state, $mutate): void {
            if (! $state->armed || ! str_starts_with($sql, 'select ') || ! str_contains($sql, 'from '.DB::connection()->getQueryGrammar()->wrapTable('control_room_alerts'))) { return; }
            $state->armed = false; $state->fired = true; $mutate();
        });
        try {
            $consume(); $this->assertTrue($state->fired, 'Actual destination body SELECT must be intercepted.');
            $this->assertSame(0, $state->notes_hydrated, 'Private target notes must not hydrate after proof-used evidence changes.');
        } finally { $state->armed = false; \Illuminate\Database\Eloquent\Model::setEventDispatcher($dispatcher); }
    }

    #[DataProvider('alertCopyFields')]
    public function test_exact_prospective_alert_field_copy_live_materialises_and_replay_retains_original_version(string $field): void
    {
        $fixture = $this->alertFieldCopy($field); $copy = $fixture['copy'];
        $this->assertSame(['available' => true, 'result' => ['notes_access' => 'readable', 'notes' => $copy['copy']['text']]], $this->liveCopy($fixture));
        $this->assertSame($copy, $this->capture($fixture['root_id'], uuid: $copy['copy_uuid'], target: Copies::ALERT_NOTES_TARGET, key: (string) $fixture['alert_id'], field: $field));
        $this->assertSame(1, IncidentSavedCopy::where('copy_uuid', $copy['copy_uuid'])->count());
    }

    public static function alertCopyFields(): array
    {
        return ['description' => ['description'], 'immediate control' => ['immediate_action_taken']];
    }

    #[DataProvider('unknownLiveOrigin')]
    public function test_unknown_wrong_target_field_or_version_never_invokes_materializer(array $override): void
    {
        $fixture = $this->alertFieldCopy(); $calls = 0;
        $this->unavailableMaterialisation($this->liveCopy($fixture, materializer: function () use (&$calls): array { $calls++; return []; }, override: $override));
        $this->assertSame(0, $calls);
    }

    public static function unknownLiveOrigin(): array
    {
        return ['UUID absent' => [['copy_uuid' => null]], 'unknown family' => [['source_family' => 'other']],
            'target changed' => [['target_key' => '99999999']], 'version changed' => [['source_version' => '550e8400-e29b-41d4-a716-446655440000']],
            'field changed' => [['field' => 'immediate_action_taken']], 'unexpected copied body' => [['text' => 'Caller supplied prose']],
            'alert UUID target refused' => [['target_key' => '550e8400-e29b-41d4-a716-446655440000']]];
    }

    public function test_prefix_suffix_case_and_trailing_target_bytes_are_not_certified_by_an_exact_source_field(): void
    {
        $fixture = $this->alertFieldCopy(); $original = $fixture['copy']['copy']['text'];
        foreach (['New incident reported - '.$original, $original.' Handed off to Fleet', strtoupper($original), $original.' '] as $composite) {
            DB::table('control_room_alerts')->where('id', $fixture['alert_id'])->update(['notes' => $composite]);
            $result = $this->liveCopy($fixture);
            $this->assertTrue($result['available']); // Source copy is still valid; destination match is independently unavailable.
            $this->assertSame(['notes_access' => 'unavailable', 'notes' => null], $result['result']);
            $this->unavailable($this->read($fixture['copy'], override: ['copy' => ['text' => $composite]]));
        }
        DB::table('control_room_alerts')->where('id', $fixture['alert_id'])->update(['notes' => $original]);
        $this->assertSame($original, $this->liveCopy($fixture)['result']['notes']);
    }

    public function test_live_denied_source_has_zero_callbacks_and_exact_grant_restoration(): void
    {
        $fixture = $this->alertFieldCopy(); $calls = 0; $warm = $this->manager->fresh()->load(['roles.permissions', 'permissionOverrides']);
        foreach (['incidents.viewAny', 'incidents.viewAssigned'] as $key) { $this->allow($this->manager, $key, false); }
        $this->unavailableMaterialisation($this->liveCopy($fixture, $warm, function () use (&$calls): array { $calls++; return []; }));
        $this->assertSame(0, $calls);
        $this->allow($this->manager, 'incidents.viewAny');
        $this->assertSame($fixture['copy']['copy']['text'], $this->liveCopy($fixture, $warm)['result']['notes']);
    }

    public function test_live_source_independence_denial_keeps_callback_zero_and_healthy_neighbour_readable(): void
    {
        $fixture = $this->alertFieldCopy(); $root = \App\Models\ClientIncident::findOrFail($fixture['root_id']);
        DB::table('client_incidents')->where('id', $root->id)->update(['client_id' => $this->client->id,
            'reported_by' => $this->reporter->id, 'shift_id' => null, 'source' => 'manual', 'report_request_uuid' => (string) Str::uuid()]);
        // Capture after the genuine original tuple is settled; prior fixture copy correctly became unavailable.
        $copy = $this->capture($root->id, target: Copies::ALERT_NOTES_TARGET, key: (string) $fixture['alert_id']);
        $fixture['copy'] = $copy; $fixture['client_id'] = $this->client->id;
        DB::table('control_room_alerts')->where('id', $fixture['alert_id'])->update(['client_id' => $this->client->id, 'notes' => $copy['copy']['text'],
            'context' => json_encode(['saved_incident_copy' => $this->origin($copy)])]);
        $freshRoot = $root->fresh();
        $this->acceptForExisting($freshRoot, $this->command(['report_request_uuid' => $freshRoot->report_request_uuid]));
        $calls = 0;
        $this->unavailableMaterialisation($this->liveCopy($fixture, $this->lead, function () use (&$calls): array { $calls++; return []; }));
        $this->assertSame(0, $calls);
        $healthy = $this->alertFieldCopy();
        $this->assertSame($healthy['copy']['copy']['text'], $this->liveCopy($healthy, $this->lead)['result']['notes']);
    }

    public function test_damaged_live_copy_is_unavailable_without_callback_and_healthy_neighbour_survives(): void
    {
        $damaged = $this->alertFieldCopy(); $healthy = $this->alertFieldCopy(); $calls = 0;
        $row = IncidentSavedCopy::where('copy_uuid', $damaged['copy']['copy_uuid'])->sole(); $digest = $row->content_digest;
        DB::table('incident_saved_copies')->where('id', $row->id)->update(['content_digest' => str_repeat('0', 64)]);
        $this->unavailableMaterialisation($this->liveCopy($damaged, materializer: function () use (&$calls): array { $calls++; return []; }));
        $this->assertSame(0, $calls); $this->assertTrue($this->liveCopy($healthy)['available']);
        DB::table('incident_saved_copies')->where('id', $row->id)->update(['content_digest' => $digest]);
        $this->assertTrue($this->liveCopy($damaged)['available']);
    }

    public function test_actual_destination_select_rejects_late_original_client_reassociation_before_notes(): void
    {
        $fixture = $this->alertFieldCopy(); $other = Client::factory()->create(['site_id' => $this->site->id]);
        $this->atDestination(fn () => DB::table('client_incidents')->where('id', $fixture['root_id'])->update(['client_id' => $other->id]),
            fn () => $this->unavailableMaterialisation($this->liveCopy($fixture)));
        DB::table('client_incidents')->where('id', $fixture['root_id'])->update(['client_id' => $fixture['client_id']]);
        $this->assertTrue($this->liveCopy($fixture)['available']);
    }

    public function test_actual_destination_select_rejects_late_privacy_and_independence_graph_mutation_before_notes(): void
    {
        $accepted = $this->accept($this->command());
        $root = \App\Models\ClientIncident::findOrFail($accepted['incident_id']);
        $fixture = $this->alertFieldCopy();
        // A genuine accepted declaration and its capture audit/ledger remain canonical source evidence.
        $copy = $this->capture($root->id, target: Copies::ALERT_NOTES_TARGET, key: (string) $fixture['alert_id']);
        $fixture['root_id'] = (int) $root->id; $fixture['client_id'] = (int) $root->client_id; $fixture['copy'] = $copy;
        DB::table('control_room_alerts')->where('id', $fixture['alert_id'])->update(['client_id' => $root->client_id,
            'notes' => $copy['copy']['text'], 'context' => json_encode(['saved_incident_copy' => $this->origin($copy)])]);
        $this->atDestination(fn () => DB::table('client_incidents')->where('id', $root->id)->update(['is_hr_confidential' => true]),
            fn () => $this->unavailableMaterialisation($this->liveCopy($fixture)));
        DB::table('client_incidents')->where('id', $root->id)->update(['is_hr_confidential' => false]);
        $declaration = \App\Models\IncidentIndependentHandlingDeclaration::where('incident_id', $root->id)->sole();
        $handling = $declaration->getRawOriginal('handling');
        $this->atDestination(fn () => DB::table('incident_independent_handling_declarations')->where('id', $declaration->id)
            ->update(['handling' => json_encode(['identification' => 'named', 'involved_user_ids' => [(int) $this->manager->id]])]),
            fn () => $this->unavailableMaterialisation($this->liveCopy($fixture)));
        DB::table('incident_independent_handling_declarations')->where('id', $declaration->id)->update(['handling' => $handling]);
        $this->assertSame($copy['copy']['text'], $this->liveCopy($fixture)['result']['notes']);
        $healthy = $this->alertFieldCopy(); $this->assertTrue($this->liveCopy($healthy)['available']);
    }
    public function test_actual_destination_select_rejects_late_override_withdrawal_before_notes(): void
    {
        $fixture = $this->alertFieldCopy();
        $this->atDestination(fn () => $this->allow($this->manager, 'incidents.viewAny', false),
            fn () => $this->unavailableMaterialisation($this->liveCopy($fixture)));
        $this->allow($this->manager, 'incidents.viewAny');
        $this->assertTrue($this->liveCopy($fixture)['available']);
    }

    public function test_actual_destination_select_rejects_late_role_permission_and_assignment_withdrawals_before_notes(): void
    {
        $fixture = $this->alertFieldCopy();
        $permission = \App\Models\Permission::where('key', 'incidents.viewAny')->sole();
        $this->manager->permissionOverrides()->detach($permission->id);
        $role = \App\Models\Role::create(['name' => 'saved_copy_source_reader_'.Str::lower(Str::random(8)), 'description' => 'Synthetic current read role']);
        $role->permissions()->attach($permission->id); $this->manager->roles()->attach($role->id);
        $this->atDestination(fn () => $role->permissions()->detach($permission->id),
            fn () => $this->unavailableMaterialisation($this->liveCopy($fixture)));
        $role->permissions()->attach($permission->id); $this->assertTrue($this->liveCopy($fixture)['available']);
        $this->atDestination(fn () => $this->manager->roles()->detach($role->id),
            fn () => $this->unavailableMaterialisation($this->liveCopy($fixture)));
        $this->manager->roles()->attach($role->id); $this->assertTrue($this->liveCopy($fixture)['available']);
    }

    public function test_actual_destination_select_rejects_new_deny_and_new_external_role_membership_before_notes(): void
    {
        $fixture = $this->alertFieldCopy(); $permission = \App\Models\Permission::where('key', 'incidents.viewAny')->sole();
        $this->manager->permissionOverrides()->detach($permission->id);
        $role = \App\Models\Role::create(['name' => 'saved_copy_reader_'.Str::lower(Str::random(8)), 'description' => 'Synthetic read role']);
        $role->permissions()->attach($permission->id); $this->manager->roles()->attach($role->id);
        $this->atDestination(fn () => $this->allow($this->manager, 'incidents.viewAny', false),
            fn () => $this->unavailableMaterialisation($this->liveCopy($fixture)));
        $this->manager->permissionOverrides()->detach($permission->id);
        $external = \App\Models\Role::firstOrCreate(['name' => 'external_clinician'], ['description' => 'External account role']);
        $this->atDestination(fn () => $this->manager->roles()->attach($external->id),
            fn () => $this->unavailableMaterialisation($this->liveCopy($fixture)));
        $this->manager->roles()->detach($external->id); $this->assertTrue($this->liveCopy($fixture)['available']);
    }

    public function test_actual_destination_select_rejects_site_profile_and_original_site_state_changes_before_notes(): void
    {
        $fixture = $this->alertFieldCopy(); $profile = HrEmployeeProfile::where('user_id', $this->manager->id)->sole();
        $foreign = Site::factory()->create(['is_active' => true]);
        $this->atDestination(fn () => DB::table('hr_employee_profiles')->where('id', $profile->id)->update(['primary_site_id' => $foreign->id]),
            fn () => $this->unavailableMaterialisation($this->liveCopy($fixture)));
        DB::table('hr_employee_profiles')->where('id', $profile->id)->update(['primary_site_id' => $this->site->id]);
        $this->atDestination(fn () => DB::table('sites')->where('id', $this->site->id)->update(['is_active' => false]),
            fn () => $this->unavailableMaterialisation($this->liveCopy($fixture)));
        DB::table('sites')->where('id', $this->site->id)->update(['is_active' => true]);
        $this->assertTrue($this->liveCopy($fixture)['available']);
    }

    public static function emptyWorkerTimezones(): array
    {
        return ['null worker timezone' => [null], 'empty worker timezone' => ['']];
    }

    /** Auckland has reached the next day, while the canonical fallback UTC date is still eligible. */
    #[DataProvider('emptyWorkerTimezones')]
    public function test_live_copy_uses_resolved_app_timezone_for_empty_worker_timezone_at_day_boundary(?string $workerTimezone): void
    {
        config(['app.worker_timezone' => $workerTimezone, 'app.timezone' => 'UTC']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-12T12:00:01Z'));
        DB::table('hr_employee_profiles')->where('user_id', $this->manager->id)->update(['end_date' => '2026-10-12']);
        $fixture = $this->alertFieldCopy(); $calls = 0;
        $result = $this->liveCopy($fixture, materializer: function ($copy, $reads, $predicate) use ($fixture, &$calls): array {
            $calls++;
            return $this->destination($fixture, $this->origin($fixture['copy']), $copy, $reads, $predicate);
        });
        $this->assertSame(1, $calls);
        $this->assertTrue($result['available']);
        $this->assertSame('readable', $result['result']['notes_access']);
        $this->assertSame($fixture['copy']['copy']['text'], $result['result']['notes']);
    }

    #[DataProvider('emptyWorkerTimezones')]
    public function test_actual_destination_select_refuses_changed_resolved_timezone_before_compilation(?string $workerTimezone): void
    {
        foreach (['app.timezone', 'app.worker_timezone'] as $changedKey) {
            config(['app.worker_timezone' => $workerTimezone, 'app.timezone' => 'UTC']);
            $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-12T12:00:01Z'));
            $fixture = $this->alertFieldCopy(); $calls = 0; $fired = false; $hydrated = 0;
            $dispatcher = \Illuminate\Database\Eloquent\Model::getEventDispatcher();
            \Illuminate\Database\Eloquent\Model::setEventDispatcher(clone $dispatcher);
            \App\Models\ControlRoomAlert::retrieved(function ($row) use (&$hydrated): void {
                if (array_key_exists('notes', $row->getAttributes())) { $hydrated++; }
            });
            try {
                $this->liveCopy($fixture, materializer: function ($copy, $reads, $predicate) use ($fixture, $changedKey, &$calls, &$fired): array {
                    $calls++;
                    return $this->destination($fixture, $this->origin($fixture['copy']), $copy, $reads, $predicate,
                        function () use ($changedKey, &$fired): void { $fired = true; config([$changedKey => 'Pacific/Auckland']); });
                });
                $this->fail('Changed resolved timezone must refuse the actual destination SELECT before notes.');
            } catch (LogicException $error) {
                $this->assertSame('Copy authorization date evidence changed during materialisation.', $error->getMessage());
                $this->assertSame(1, $calls); $this->assertTrue($fired); $this->assertSame(0, $hydrated);
            } finally {
                \Illuminate\Database\Eloquent\Model::setEventDispatcher($dispatcher);
                config(['app.worker_timezone' => $workerTimezone, 'app.timezone' => 'UTC']);
            }
            $this->assertTrue($this->liveCopy($fixture)['available']);
        }
    }

    #[DataProvider('emptyWorkerTimezones')]
    public function test_callback_final_refuses_changed_resolved_timezone_and_withholds_detached_result(?string $workerTimezone): void
    {
        foreach (['app.timezone', 'app.worker_timezone'] as $changedKey) {
            config(['app.worker_timezone' => $workerTimezone, 'app.timezone' => 'UTC']);
            $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-12T12:00:01Z'));
            $fixture = $this->alertFieldCopy(); $calls = 0; $readable = false; $returned = false;
            try {
                $this->liveCopy($fixture, materializer: function ($copy, $reads, $predicate) use ($fixture, $changedKey, &$calls, &$readable): array {
                    $calls++;
                    $data = $this->destination($fixture, $this->origin($fixture['copy']), $copy, $reads, $predicate);
                    $readable = $data['notes_access'] === 'readable';
                    config([$changedKey => 'Pacific/Auckland']);
                    return $data;
                });
                $returned = true;
                $this->fail('Changed resolved timezone must withhold the final detached result.');
            } catch (LogicException $error) {
                $this->assertSame('Copy authorization date evidence changed during materialisation.', $error->getMessage());
                $this->assertSame(1, $calls); $this->assertTrue($readable); $this->assertFalse($returned);
            } finally { config(['app.worker_timezone' => $workerTimezone, 'app.timezone' => 'UTC']); }
            $this->assertTrue($this->liveCopy($fixture)['available']);
        }
    }
    public function test_actual_destination_select_rejects_hr_end_date_and_account_withdrawal_before_notes(): void
    {
        $fixture = $this->alertFieldCopy(); $profile = HrEmployeeProfile::where('user_id', $this->manager->id)->sole();
        $this->atDestination(fn () => DB::table('hr_employee_profiles')->where('id', $profile->id)->update(['end_date' => '2026-10-11']),
            fn () => $this->unavailableMaterialisation($this->liveCopy($fixture)));
        DB::table('hr_employee_profiles')->where('id', $profile->id)->update(['end_date' => null]);
        $this->atDestination(fn () => DB::table('users')->where('id', $this->manager->id)->update(['approved_at' => null]),
            fn () => $this->unavailableMaterialisation($this->liveCopy($fixture)));
        DB::table('users')->where('id', $this->manager->id)->update(['approved_at' => now()]);
        $this->assertTrue($this->liveCopy($fixture)['available']);
    }

    public function test_actual_destination_select_rejects_late_assigned_client_membership_withdrawal_before_notes(): void
    {
        $fixture = $this->alertFieldCopy(); $client = Client::findOrFail($fixture['client_id']); $client->supportWorkers()->attach($this->manager->id);
        $this->allow($this->manager, 'incidents.viewAny', false); $this->allow($this->manager, 'incidents.viewAssigned');
        $this->atDestination(fn () => $client->supportWorkers()->detach($this->manager->id),
            fn () => $this->unavailableMaterialisation($this->liveCopy($fixture)));
        $client->supportWorkers()->attach($this->manager->id); $this->assertTrue($this->liveCopy($fixture)['available']);
    }

    public function test_actual_destination_select_rejects_late_audit_and_competing_uuid_claim_before_notes(): void
    {
        $fixture = $this->alertFieldCopy(); $row = IncidentSavedCopy::where('copy_uuid', $fixture['copy']['copy_uuid'])->sole();
        $this->atDestination(fn () => DB::table('audit_logs')->where('id', $row->audit_id)->update(['action' => 'incidents.savedCopy.captured ']),
            fn () => $this->unavailableMaterialisation($this->liveCopy($fixture)));
        DB::table('audit_logs')->where('id', $row->audit_id)->update(['action' => 'incidents.savedCopy.captured']);
        $claim = (array) DB::table('audit_logs')->where('id', $row->audit_id)->sole(); unset($claim['id']);
        $claim['action'] = 'reclassified'; $claim['auditable_type'] = 'other'; $claim['auditable_id'] = $row->id + 1;
        $this->atDestination(fn () => DB::table('audit_logs')->insert($claim),
            fn () => $this->unavailableMaterialisation($this->liveCopy($fixture)));
    }

    public function test_callback_final_mutation_cannot_rebuild_changed_metadata_as_a_new_original_baseline(): void
    {
        $fixture = $this->alertFieldCopy();
        $result = $this->liveCopy($fixture, materializer: function ($copy, $reads, $predicate) use ($fixture): array {
            $data = $this->destination($fixture, $this->origin($fixture['copy']), $copy, $reads, $predicate);
            DB::table('incident_saved_copies')->where('copy_uuid', $fixture['copy']['copy_uuid'])->update(['target_key' => '99999999']);
            return $data;
        });
        $this->unavailableMaterialisation($result);
        DB::table('incident_saved_copies')->where('copy_uuid', $fixture['copy']['copy_uuid'])->update(['target_key' => (string) $fixture['alert_id']]);
        $this->assertTrue($this->liveCopy($fixture)['available']);
    }

    public function test_callback_final_authority_change_withholds_detached_values_and_preserves_accepted_copy(): void
    {
        $fixture = $this->alertFieldCopy();
        $result = $this->liveCopy($fixture, materializer: function ($copy, $reads, $predicate) use ($fixture): array {
            $data = $this->destination($fixture, $this->origin($fixture['copy']), $copy, $reads, $predicate);
            $this->allow($this->manager, 'incidents.viewAny', false);
            return $data;
        });
        $this->unavailableMaterialisation($result);
        $this->assertSame(1, IncidentSavedCopy::where('copy_uuid', $fixture['copy']['copy_uuid'])->count());
        $this->allow($this->manager, 'incidents.viewAny'); $this->assertTrue($this->liveCopy($fixture)['available']);
    }

    public function test_live_materializer_rejects_models_handles_and_expired_predicate_execution(): void
    {
        $fixture = $this->alertFieldCopy();
        foreach (['model', 'reads', 'predicate', 'closure'] as $kind) {
            try {
                $this->liveCopy($fixture, materializer: function ($copy, $reads, $predicate) use ($kind): array {
                    return ['invalid' => match ($kind) { 'model' => IncidentSavedCopy::firstOrFail(), 'reads' => $reads, 'predicate' => $predicate, 'closure' => fn () => true }];
                });
                $this->fail('Only detached destination results may leave the source callback.');
            } catch (LogicException $error) { $this->assertSame('Routing callbacks may return detached values only.', $error->getMessage()); }
        }
        $retained = null; $retainedReads = null;
        $this->liveCopy($fixture, materializer: function ($copy, $reads, $predicate) use (&$retained, &$retainedReads): array {
            $retained = $predicate; $retainedReads = $reads; return [];
        });
        try { $retainedReads->assertActive(); $this->fail('Reads cannot escape.'); } catch (LogicException) {}
        $this->expectException(LogicException::class);
        $retained->get();
    }

    public function test_expired_copy_subquery_cannot_be_embedded_in_new_reads_even_for_another_viewer(): void
    {
        $fixture = $this->alertFieldCopy(); $expired = null;
        $this->liveCopy($fixture, materializer: function ($copy, $reads, $predicate) use (&$expired): array {
            $expired = clone $predicate; return [];
        });
        $attemptedBody = 0; $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$attemptedBody, &$armed): void {
            if ($armed && str_contains(strtolower($sql), 'from `control_room_alerts`')) { $attemptedBody++; }
        });
        try {
            foreach ([$this->manager, $this->lead] as $actor) {
                $refused = false;
                try {
                    $this->within($actor, function ($context) use ($fixture, $expired): array {
                        return $context->consumeSourceViewer(Copies::PERMISSIONS, function ($current, $freshReads) use ($fixture, $expired): array {
                            // A fresh outer handle must not revive the old actor's embedded predicate.
                            $row = $freshReads->query(\App\Models\ControlRoomAlert::query()->whereKey($fixture['alert_id'])
                                ->whereExists((clone $expired)->select('incident_saved_copies.id')->toBase()))->first(['id', 'notes']);
                            return ['notes' => $row?->notes];
                        });
                    });
                } catch (LogicException $error) {
                    $this->assertSame('Current authorization evidence has left its transaction scope.', $error->getMessage());
                    $refused = true;
                }
                $this->assertTrue($refused, 'A new viewer or lifetime must not revive an expired private-copy predicate.');
            }
            $this->assertSame(0, $attemptedBody, 'Expiry must refuse before the actual destination SQL is sent.');
        } finally { $armed = false; }
        $this->assertSame($fixture['copy']['copy']['text'], $this->liveCopy($fixture)['result']['notes']);
    }
    public function test_live_copy_requires_complete_preparation_and_rejects_expired_context_without_callback(): void
    {
        $fixture = $this->alertFieldCopy(); $calls = 0; $expired = null;
        $this->within($this->manager, function ($context) use (&$expired): array { $expired = $context; return []; });
        try {
            app(Copies::class)->consumeProjectedCopy($expired, $this->origin($fixture['copy']), function () use (&$calls): array { $calls++; return []; });
            $this->fail('Expired context must be rejected.');
        } catch (LogicException) {}
        try {
            HealthSafetyReadContext::within($this->manager, ['incidents.viewAny'], fn ($context) =>
                app(Copies::class)->consumeProjectedCopy($context, $this->origin($fixture['copy']), function () use (&$calls): array { $calls++; return []; }));
            $this->fail('Missing preparation must be rejected.');
        } catch (LogicException) {}
        $this->assertSame(0, $calls);
    }
}
