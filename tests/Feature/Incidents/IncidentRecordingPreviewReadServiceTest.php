<?php

namespace Tests\Feature\Incidents;

use App\Domain\SecurityDevices\Cctv\Services\{CctvContract, CctvRecordingReferences};
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Models\{Device, DeviceAssignment};
use App\Models\{ClientIncident, User};
use App\Services\Incidents\IncidentRecordingPreviewReadService;
use App\Services\Incidents\IncidentEvidenceWindowPolicySettings;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Uses actual command fixture/policy/current Devices authority; all UNRUN. */
final class IncidentRecordingPreviewReadServiceTest extends IncidentRecordingCommandTestCase
{
    private function preview(array $f): array
    {
        return app(IncidentRecordingPreviewReadService::class)->forIncident($this->receiver(), $f['incident']->id);
    }

    public function test_no_new_operation_grant_discloses_no_policy_or_source_choices(): void
    {
        $f = $this->fixture(); $dto = $this->preview($f);
        $this->assertSame(['can_record' => false, 'can_expand' => false, 'can_attach' => false], $dto['permissions']);
        $this->assertSame(['state' => 'unavailable', 'revision' => null, 'rule' => null], $dto['policy']);
        $this->assertSame([], $dto['sources']['items']); $this->assertSame(1, $dto['active_window_revision']);
    }

    public function test_current_rule_has_explicit_utc_base_and_max_and_authorized_source_tuple(): void
    {
        $f = $this->fixture();
        foreach (['incidents.update', 'incidents.evidence.window.record', 'incidents.evidence.window.expand', 'incidents.evidence.attach'] as $permission) { $this->allow($this->manager, $permission); }
        $dto = $this->preview($f);
        $this->assertSame($this->manager->id, $dto['actor_id']); $this->assertSame($f['incident']->id, $dto['incident_id']);
        $this->assertSame(['can_record' => true, 'can_expand' => true, 'can_attach' => true], $dto['permissions']);
        $this->assertSame('2026-10-12T01:59:00Z', $dto['policy']['rule']['default_start_at']);
        $this->assertSame('2026-10-12T02:02:00Z', $dto['policy']['rule']['latest_end_at']);
        $this->assertSame([['camera' => ['id' => $f['camera']->id, 'name' => $f['camera']->name],
            'recorder' => ['id' => $f['recorder']->id, 'name' => $f['recorder']->name], 'site_id' => $this->site->id,
            'references_href' => '/security-devices/devices/'.$f['camera']->id.'/recording-references', 'in_active_window' => true, 'can_record_window' => true]], $dto['sources']['items']);
        $this->assertArrayNotHasKey('policy_snapshot', $dto['policy']);
        $this->assertArrayNotHasKey('provider', $dto['sources']['items'][0]);
    }

    public function test_changed_context_disables_attach_but_current_rule_still_previews_new_window(): void
    {
        $f = $this->fixture();
        foreach (['incidents.update', 'incidents.evidence.window.record', 'incidents.evidence.attach'] as $permission) { $this->allow($this->manager, $permission); }
        ClientIncident::withoutEvents(fn () => $f['incident']->update(['occurred_at' => '2026-10-12 02:10:00.123456']));
        $dto = $this->preview($f);
        $this->assertFalse($dto['permissions']['can_attach']); $this->assertTrue($dto['permissions']['can_record']);
        $this->assertSame('2026-10-12T02:09:00Z', $dto['policy']['rule']['default_start_at']);
    }

    public function test_devices_view_loss_hides_choices_and_attach_but_empty_source_window_remains_authorized(): void
    {
        $f = $this->fixture();
        foreach (['incidents.update', 'incidents.evidence.window.record', 'incidents.evidence.attach'] as $permission) { $this->allow($this->manager, $permission); }
        $this->allow($this->manager, CctvRecordingReferences::VIEW, false);
        $dto = $this->preview($f); $this->assertTrue($dto['permissions']['can_record']);
        $this->assertFalse($dto['permissions']['can_attach']); $this->assertSame([], $dto['sources']['items']);
    }

    public function test_new_private_assignment_hides_camera_and_closed_incident_hides_new_operations(): void
    {
        $f = $this->fixture();
        foreach (['incidents.update', 'incidents.evidence.window.record', 'incidents.evidence.attach'] as $permission) { $this->allow($this->manager, $permission); }
        $this->assignToStaff($f['camera'], $this->lead);
        $this->assertSame([], $this->preview($f)['sources']['items']);
        ClientIncident::withoutEvents(fn () => $f['incident']->update(['status' => 'closed']));
        $dto = $this->preview($f); $this->assertFalse($dto['permissions']['can_record']);
        $this->assertFalse($dto['permissions']['can_attach']); $this->assertSame('unavailable', $dto['policy']['state']);
    }

    public function test_historical_active_and_current_recorder_choices_have_distinct_full_tuple_flags(): void
    {
        $f = $this->fixture();
        foreach (['incidents.update', 'incidents.evidence.window.record', 'incidents.evidence.attach'] as $permission) { $this->allow($this->manager, $permission); }
        $next = Device::factory()->security()->create(['name' => 'Current recorder', 'subcategory' => 'nvr', 'provider' => 'unifi', 'external_ref' => ['host_id' => 'console1']]);
        DeviceAssignment::create(['device_id' => $next->id, 'assignable_type' => 'site', 'assignable_id' => $this->site->id,
            'custody_site_id' => $this->site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(), 'assigned_by_user_id' => $this->manager->id]);
        $configuration = $f['source']->configuration; $configuration['recorder_mac'] = $next->mac_address;
        $f['source']->update(['recorder_device_id' => $next->id, 'configuration' => $configuration, 'fingerprint' => CctvContract::hash($configuration)]);
        $items = $this->preview($f)['sources']['items']; $this->assertCount(2, $items);
        $this->assertSame($f['recorder']->id, $items[0]['recorder']['id']);
        $this->assertTrue($items[0]['in_active_window']); $this->assertFalse($items[0]['can_record_window']);
        $this->assertSame($next->id, $items[1]['recorder']['id']);
        $this->assertFalse($items[1]['in_active_window']); $this->assertTrue($items[1]['can_record_window']);
    }

    public function test_private_assignment_added_after_choice_collection_refuses_the_whole_preview(): void
    {
        $f = $this->fixture();
        foreach (['incidents.update', 'incidents.evidence.window.record', 'incidents.evidence.attach'] as $permission) { $this->allow($this->manager, $permission); }
        $events = Model::getEventDispatcher(); Model::setEventDispatcher(clone $events); $armed = false; $revoked = false;
        CctvSource::retrieved(function () use (&$armed): void { $armed = true; });
        User::retrieved(function () use ($f, &$armed, &$revoked): void {
            if (!$armed || $revoked) { return; } $revoked = true;
            $this->assignToStaff($f['camera'], $this->lead);
        });
        try { $this->denial(fn () => $this->preview($f), 404); $this->assertTrue($revoked); }
        finally { Model::setEventDispatcher($events); }
    }

    public function test_sensor_only_current_rule_preserves_historical_camera_attach_without_new_camera_selection(): void
    {
        $f = $this->fixture();
        foreach (['incidents.update', 'incidents.evidence.window.record', 'incidents.evidence.attach'] as $permission) { $this->allow($this->manager, $permission); }
        $this->travel(2)->seconds();
        app(IncidentEvidenceWindowPolicySettings::class)->save($this->manager, ['expected_actor_id' => $this->manager->id,
            'expected_policy_revision' => $f['window']['rule_revision'], 'idempotency_key' => (string) Str::uuid(),
            'reason' => 'Explicit current sensor-only policy decision.', 'policy' => ['schema_version' => 1, 'enabled' => true,
                'rules' => [['identifier' => 'fall-sensor', 'trigger' => 'fall', 'site_id' => $this->site->id,
                    'before_seconds' => 60, 'after_seconds' => 60, 'max_before_seconds' => 120, 'max_after_seconds' => 120, 'source_types' => ['sensor']]]]]);
        $dto = $this->preview($f); $this->assertTrue($dto['permissions']['can_record']); $this->assertTrue($dto['permissions']['can_attach']);
        $this->assertCount(1, $dto['sources']['items']);
        $this->assertTrue($dto['sources']['items'][0]['in_active_window']); $this->assertFalse($dto['sources']['items'][0]['can_record_window']);
    }

    public function test_invalid_context_fallback_rechecks_no_aggregate_after_late_insertion(): void
    {
        $f = $this->fixture(); $f['incident'] = $this->incident(['type' => 'fall', 'source' => 'manual', 'occurred_at' => null]);
        $events = Model::getEventDispatcher(); Model::setEventDispatcher(clone $events);
        $connection = DB::connection(); $queryEvents = $connection->getEventDispatcher(); $connection->setEventDispatcher(clone $queryEvents);
        $armed = false; $inserted = false;
        $connection->listen(function (QueryExecuted $query) use (&$armed): void {
            if (str_contains($query->sql, 'incident_evidence_aggregates')) { $armed = true; }
        });
        User::retrieved(function () use ($f, &$armed, &$inserted): void {
            if (!$armed || $inserted) { return; } $inserted = true;
            DB::table('incident_evidence_aggregates')->insert(['incident_id' => $f['incident']->id,
                'incident_evidence_version' => 1, 'active_window_revision' => null, 'created_by_user_id' => $this->manager->id,
                'updated_by_user_id' => $this->manager->id, 'created_at' => now(), 'updated_at' => now()]);
        });
        try {
            try { $this->preview($f); $this->fail('Late aggregate cannot be presented as absent.'); }
            catch (\Illuminate\Validation\ValidationException $error) { $this->assertNotSame([], $error->errors()); }
            $this->assertTrue($inserted);
        }
        finally { Model::setEventDispatcher($events); $connection->setEventDispatcher($queryEvents); }
    }

    public function test_late_parent_query_private_assignment_cannot_publish_an_earlier_source_choice(): void
    {
        $this->assertLateParentMutationRefuses('private_assignment');
    }

    public function test_late_parent_query_source_identity_change_cannot_publish_an_earlier_choice(): void
    {
        $this->assertLateParentMutationRefuses('source_identity');
    }

    private function assertLateParentMutationRefuses(string $mutation): void
    {
        $f = $this->fixture();
        foreach (['incidents.update', 'incidents.evidence.window.record', 'incidents.evidence.attach'] as $permission) { $this->allow($this->manager, $permission); }
        $modelEvents = Model::getEventDispatcher(); Model::setEventDispatcher(clone $modelEvents);
        $connection = DB::connection(); $queryEvents = $connection->getEventDispatcher(); $connection->setEventDispatcher(clone $queryEvents);
        $armed = false; $mutated = false; $published = null;
        CctvSource::retrieved(function () use (&$armed): void { $armed = true; });
        $connection->listen(function (QueryExecuted $query) use ($f, $mutation, &$armed, &$mutated): void {
            if (!$armed || $mutated || !str_contains($query->sql, 'client_incidents')) { return; }
            $mutated = true;
            if ($mutation === 'private_assignment') {
                $this->assignToStaff($f['camera'], $this->lead);
            } else { $f['source']->update(['verified_at' => now()->addSecond()]); }
        });
        try {
            try { $published = $this->preview($f); $this->fail('A late-mutated choice must never leave the final owner verifier.'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { $this->assertSame(404, $error->getStatusCode()); }
            $this->assertTrue($mutated); $this->assertNull($published);
        } finally { Model::setEventDispatcher($modelEvents); $connection->setEventDispatcher($queryEvents); }
    }

    public function test_hr_query_after_physical_parent_read_cannot_close_incident_behind_preview(): void
    {
        $this->assertLateHrMutationRefuses('incident');
    }

    public function test_hr_query_after_evidence_tail_cannot_change_current_aggregate_behind_preview(): void
    {
        $this->assertLateHrMutationRefuses('evidence');
    }

    public function test_hr_query_result_cannot_hide_late_current_staff_profile_revocation(): void
    {
        $this->assertLateHrMutationRefuses('profile');
    }

    public function test_fixed_final_hs_and_devices_reads_restore_original_dispatchers_on_success(): void
    {
        $f = $this->fixture();
        foreach (['incidents.update', 'incidents.evidence.window.record', 'incidents.evidence.attach'] as $permission) { $this->allow($this->manager, $permission); }
        $modelEvents = Model::getEventDispatcher(); $connection = DB::connection(); $queryEvents = $connection->getEventDispatcher();
        $dto = $this->preview($f); $this->assertTrue($dto['permissions']['can_record']); $this->assertCount(1, $dto['sources']['items']);
        $this->assertSame($modelEvents, Model::getEventDispatcher()); $this->assertSame($queryEvents, $connection->getEventDispatcher());
    }

    public function test_readonly_final_policy_equality_refuses_missing_mutex_without_recreating_it(): void
    {
        $this->fixture(); $policy = app(IncidentEvidenceWindowPolicySettings::class);
        $policy->withLockedPolicy(function (array $original) use ($policy): array {
            return CurrentAuthorizationReads::within(function ($reads) use ($policy, $original): array {
                DB::table('app_settings')->where('key', IncidentEvidenceWindowPolicySettings::REVISION_KEY)->delete();
                $this->denial(fn () => $policy->assertLockedSnapshot($original, $reads), 404);
                $this->assertFalse(DB::table('app_settings')->where('key', IncidentEvidenceWindowPolicySettings::REVISION_KEY)->exists());
                return [];
            });
        });
    }

    private function assertLateHrMutationRefuses(string $mutation): void
    {
        $f = $this->fixture();
        foreach (['incidents.update', 'incidents.evidence.window.record', 'incidents.evidence.attach'] as $permission) { $this->allow($this->manager, $permission); }
        $modelEvents = Model::getEventDispatcher(); Model::setEventDispatcher(clone $modelEvents);
        $connection = DB::connection(); $queryEvents = $connection->getEventDispatcher(); $connection->setEventDispatcher(clone $queryEvents);
        $expectedModels = Model::getEventDispatcher(); $expectedQueries = $connection->getEventDispatcher();
        $seenSource = false; $parentTailRead = false; $mutated = false; $published = null;
        CctvSource::retrieved(function () use (&$seenSource): void { $seenSource = true; });
        $connection->listen(function (QueryExecuted $query) use ($f, $mutation, &$seenSource, &$parentTailRead, &$mutated): void {
            if ($seenSource && str_starts_with(strtolower(ltrim($query->sql)), 'select * from `client_incidents`')) { $parentTailRead = true; }
            if (!$parentTailRead || $mutated || !str_contains($query->sql, 'hr_employee_profiles')) { return; }
            // Actual QueryExecuted runs AFTER SQL returned the earlier accepted HR result.
            $mutated = true;
            if ($mutation === 'incident') { DB::table('client_incidents')->where('id', $f['incident']->id)->update(['status' => 'closed']); }
            elseif ($mutation === 'evidence') { DB::table('incident_evidence_aggregates')->where('incident_id', $f['incident']->id)->update(['active_window_revision' => null]); }
            else { DB::table('hr_employee_profiles')->where('user_id', $this->manager->id)->update(['is_active' => false]); }
        });
        try {
            try { $published = $this->preview($f); $this->fail('Late H&S evidence mutation must never publish a stale preview.'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { $this->assertSame(404, $error->getStatusCode()); }
            $this->assertTrue($parentTailRead); $this->assertTrue($mutated); $this->assertNull($published);
            $this->assertSame($expectedModels, Model::getEventDispatcher()); $this->assertSame($expectedQueries, $connection->getEventDispatcher());
        } finally { Model::setEventDispatcher($modelEvents); $connection->setEventDispatcher($queryEvents); }
    }
}
