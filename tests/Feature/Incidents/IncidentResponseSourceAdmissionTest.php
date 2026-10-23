<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\IncidentIndependentHandlingDeclaration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\Incidents\IncidentResponseJourneyReadContext as SourceContext;
use App\Services\Incidents\IncidentResponseEscalationService as Response;
use App\Services\Incidents\IncidentIndependencePolicySettings as Policy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Complete native source suite prepared; every case remains UNRUN. */
final class IncidentResponseSourceAdmissionTest extends IncidentIndependenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([$this->manager, $this->lead, $this->cover] as $actor) { $this->allow($actor, 'controlRoom.alerts.create'); }
    }

    private function root(array $attributes = []): ClientIncident
    {
        return $this->incident(['severity' => 'low', 'immediate_action_taken' => 'The person is safe and coordination was requested.', ...$attributes]);
    }

    private function send(ClientIncident $root, ?User $actor = null, string $severity = 'high'): ControlRoomAlert
    {
        return app(Response::class)->escalate($actor ?? $this->manager, (int) $root->id, 'Coordinate support without changing ownership.', $severity);
    }

    /** Actual first incident full body, excluding identity-only/canonical metadata reads. */
    private function atFirstBody(callable $mutation, callable $operation, int $expectedHydrated = 0): string
    {
        $state = (object) ['armed' => true, 'fired' => false, 'hydrated' => 0, 'sql' => null];
        $original = Model::getEventDispatcher(); Model::setEventDispatcher(clone $original);
        ClientIncident::retrieved(function (ClientIncident $row) use ($state): void {
            if ($state->fired && array_key_exists('description', $row->getAttributes())) { $state->hydrated++; }
        });
        DB::connection()->beforeExecuting(function (string $sql) use ($state, $mutation): void {
            if (! $state->armed || ! preg_match('/\Aselect (?:\*|`client_incidents`\.\*) from `client_incidents` /', $sql)) { return; }
            $state->armed = false; $state->fired = true; $state->sql = $sql; $mutation();
        });
        try {
            $operation(); $this->assertTrue($state->fired, 'The actual owned first narrative SELECT must fire.');
            $this->assertSame($expectedHydrated, $state->hydrated, 'Count actual private narrative retrieval, not rollback/final output.');
            return $state->sql;
        } finally { $state->armed = false; Model::setEventDispatcher($original); }
    }

    private function withoutNarrative(callable $operation): void
    {
        $original = Model::getEventDispatcher(); Model::setEventDispatcher(clone $original); $hydrated = 0;
        ClientIncident::retrieved(function (ClientIncident $row) use (&$hydrated): void {
            if (array_key_exists('description', $row->getAttributes())) { $hydrated++; }
        });
        try { $operation(); $this->assertSame(0, $hydrated); }
        finally { Model::setEventDispatcher($original); }
    }

    private function refuse(callable $operation): void
    {
        $refused = false;
        try { $operation(); }
        catch (HttpExceptionInterface $error) { $this->assertSame(403, $error->getStatusCode()); $refused = true; }
        $this->assertTrue($refused, 'The source must be refused, not accepted and rolled back after disclosure.');
    }

    private function acceptedSource(): ClientIncident
    {
        $receipt = $this->accept($this->command());
        $root = ClientIncident::findOrFail($receipt['incident_id']);
        ClientIncident::withoutEvents(fn () => $root->forceFill(['severity' => 'low', 'immediate_action_taken' => 'The person is safe.'])->saveOrFail());
        return $root;
    }

    public function test_public_capability_rechecks_loaded_false_and_true_grants_without_private_narrative(): void
    {
        $root = $this->root(); $this->allow($this->manager, 'controlRoom.alerts.create', false);
        $warm = $this->manager->fresh()->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $this->withoutNarrative(fn () => $this->assertFalse(app(Response::class)->canEscalate($warm, $root)));
        $this->allow($this->manager, 'controlRoom.alerts.create');
        $this->withoutNarrative(fn () => $this->assertTrue(app(Response::class)->canEscalate($warm, $root)));
        $this->allow($this->manager, 'incidents.viewAny', false);
        $this->withoutNarrative(fn () => $this->assertFalse(app(Response::class)->canEscalate($warm, $root)));
        $this->allow($this->manager, 'incidents.viewAny');
        $this->assertTrue(app(Response::class)->canEscalate($warm, $root));
    }

    public function test_supplied_current_reads_are_reused_and_expired_reads_are_errors(): void
    {
        $root = $this->root(); $escaped = null;
        DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($root, &$escaped): void {
            $escaped = $reads; $this->assertTrue(app(Response::class)->canEscalate($this->manager, $root, $reads));
        }));
        $this->expectException(LogicException::class);
        app(Response::class)->canEscalate($this->manager, $root, $escaped);
    }

    public function test_original_block_policy_denies_named_actor_before_first_body_and_healthy_neighbour_survives(): void
    {
        $root = $this->acceptedSource();
        $this->withoutNarrative(function () use ($root): void {
            $this->assertFalse(app(Response::class)->canEscalate($this->lead, $root));
            $this->refuse(fn () => $this->send($root, $this->lead));
        });
        $this->assertSame(0, ControlRoomAlert::count());
        $this->assertSame(0, HsEvent::count());
        $healthy = $this->root(); $alert = $this->send($healthy, $this->lead);
        $this->assertSame($this->site->id, $alert->site_id);
    }

    public function test_assignment_only_policy_preserves_read_response_for_named_actor_without_owner_assignment(): void
    {
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Explicit assignment-only handling policy review.']);
        $root = $this->acceptedSource();
        $this->assertTrue(app(Response::class)->canEscalate($this->lead, $root));
        $first = $this->send($root, $this->lead); $second = $this->send($root, $this->lead, 'critical');
        $this->assertSame($first->id, $second->id);
        $this->assertSame('critical', $second->severity);
        $this->assertNull($root->fresh()->incident_owner_user_id);
        $this->assertSame(1, ControlRoomAlert::count()); $this->assertSame(1, HsEvent::count());
    }

    public function test_reused_response_keeps_original_block_policy_after_today_policy_changes(): void
    {
        $root = $this->acceptedSource(); $first = $this->send($root);
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Later prospective policy change.']);
        $before = $first->getRawOriginal();
        $this->withoutNarrative(fn () => $this->refuse(fn () => $this->send($root, $this->lead, 'critical')));
        $this->assertSame($before, $first->fresh()->getRawOriginal());
    }

    public static function commandGrants(): array { return [['controlRoom.alerts.create'], ['incidents.viewAny']]; }
    #[DataProvider('commandGrants')]
    public function test_current_grant_withdrawal_restoration_protects_initial_and_reused_response(string $permission): void
    {
        $root = $this->root(); $warm = $this->manager->fresh()->load(['roles.permissions', 'permissionOverrides']);
        $this->allow($this->manager, $permission, false);
        $this->withoutNarrative(fn () => $this->refuse(fn () => $this->send($root, $warm)));
        $this->allow($this->manager, $permission); $first = $this->send($root, $warm);
        $this->allow($this->manager, $permission, false); $before = $first->getRawOriginal();
        $this->withoutNarrative(fn () => $this->refuse(fn () => $this->send($root, $warm, 'critical')));
        $this->assertSame($before, $first->fresh()->getRawOriginal());
        $this->allow($this->manager, $permission); $this->assertSame($first->id, $this->send($root, $warm)->id);
    }

    public function test_current_hr_site_and_privacy_withdrawal_restoration_conceals_body(): void
    {
        $root = $this->root(); $warm = $this->manager->fresh()->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $profile = HrEmployeeProfile::where('user_id', $this->manager->id)->sole();
        foreach (['is_active' => false, 'end_date' => '2026-10-11'] as $column => $value) {
            $old = $profile->getRawOriginal($column); DB::table('hr_employee_profiles')->where('id', $profile->id)->update([$column => $value]);
            $this->withoutNarrative(fn () => $this->refuse(fn () => $this->send($root, $warm)));
            DB::table('hr_employee_profiles')->where('id', $profile->id)->update([$column => $old]);
        }
        DB::table('sites')->where('id', $this->site->id)->update(['is_active' => false]);
        $this->withoutNarrative(fn () => $this->refuse(fn () => $this->send($root, $warm)));
        DB::table('sites')->where('id', $this->site->id)->update(['is_active' => true]);
        DB::table('client_incidents')->where('id', $root->id)->update(['is_hr_confidential' => true]);
        $this->withoutNarrative(fn () => $this->refuse(fn () => $this->send($root, $warm)));
        $this->allow($this->manager, 'hr.cases.view'); $this->assertSame($this->site->id, $this->send($root, $warm)->site_id);
    }

    public function test_independent_destination_create_and_response_site_scope_are_not_source_grants(): void
    {
        $root = $this->root(); $this->allow($this->manager, 'controlRoom.alerts.create', false);
        $this->withoutNarrative(fn () => $this->refuse(fn () => $this->send($root)));
        $this->allow($this->manager, 'controlRoom.alerts.create'); $this->allow($this->manager, 'healthSafety.viewAllSites');
        $foreign = $this->root(['site_id' => Site::factory()->create(['is_active' => true])->id]);
        $this->withoutNarrative(fn () => $this->refuse(fn () => $this->send($foreign)));
        $this->allow($this->manager, 'reports.viewAny'); $this->assertSame($foreign->site_id, $this->send($foreign)->site_id);
    }

    public static function explicitSiteGrantProfiles(): array
    {
        return ['no profile' => ['absent'], 'inactive retained profile' => ['inactive'], 'ended retained profile' => ['ended']];
    }

    #[DataProvider('explicitSiteGrantProfiles')]
    public function test_both_explicit_site_grants_keep_existing_authority_without_current_employment(string $profileState): void
    {
        $root = $this->root(); $this->allow($this->manager, 'healthSafety.viewAllSites'); $this->allow($this->manager, 'reports.viewAny');
        if ($profileState === 'absent') { DB::table('hr_employee_profiles')->where('user_id', $this->manager->id)->delete(); }
        else { DB::table('hr_employee_profiles')->where('user_id', $this->manager->id)
            ->update($profileState === 'inactive' ? ['is_active' => false] : ['end_date' => '2026-10-11']); }
        $warm = $this->manager->fresh()->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $this->withoutNarrative(fn () => $this->assertTrue(app(Response::class)->canEscalate($warm, $root)));
        $first = $this->send($root, $warm); $second = $this->send($root, $warm, 'critical');
        $this->assertSame($first->id, $second->id); $this->assertSame($this->site->id, $second->site_id);
        $this->assertSame('critical', $second->severity); $this->assertSame(1, ControlRoomAlert::count());
        $this->assertNull($root->fresh()->incident_owner_user_id);
    }

    public static function oneSiteBypassOnly(): array
    {
        return ['source Site only' => ['healthSafety.viewAllSites'], 'response Site only' => ['reports.viewAny']];
    }

    #[DataProvider('oneSiteBypassOnly')]
    public function test_one_explicit_site_grant_cannot_replace_the_other_scope_without_a_profile(string $permission): void
    {
        $root = $this->root(); DB::table('hr_employee_profiles')->where('user_id', $this->manager->id)->delete();
        $this->allow($this->manager, $permission);
        $this->withoutNarrative(function () use ($root): void {
            $this->assertFalse(app(Response::class)->canEscalate($this->manager, $root));
            $this->refuse(fn () => $this->send($root));
        });
        $this->assertSame(0, ControlRoomAlert::count()); $this->assertSame(0, HsEvent::count());
        $missing = $permission === 'reports.viewAny' ? 'healthSafety.viewAllSites' : 'reports.viewAny';
        $this->allow($this->manager, $missing); $this->assertSame($this->site->id, $this->send($root)->site_id);
    }

    public function test_explicit_site_authority_still_requires_current_internal_approved_actor_and_source_read(): void
    {
        $root = $this->root(); DB::table('hr_employee_profiles')->where('user_id', $this->manager->id)->delete();
        foreach (['healthSafety.viewAllSites', 'reports.viewAny'] as $permission) { $this->allow($this->manager, $permission); }
        DB::table('users')->where('id', $this->manager->id)->update(['approved_at' => null]);
        $this->withoutNarrative(fn () => $this->refuse(fn () => $this->send($root)));
        DB::table('users')->where('id', $this->manager->id)->update(['approved_at' => now()]);
        DB::table('users')->where('id', $this->manager->id)->update(['external_clinical_account' => true]);
        $this->withoutNarrative(fn () => $this->refuse(fn () => $this->send($root)));
        DB::table('users')->where('id', $this->manager->id)->update(['external_clinical_account' => false]);
        $this->allow($this->manager, 'incidents.viewAny', false);
        $this->withoutNarrative(fn () => $this->refuse(fn () => $this->send($root)));
        $this->allow($this->manager, 'incidents.viewAny'); $this->assertSame($this->site->id, $this->send($root)->site_id);
    }
    public function test_assigned_client_membership_is_current_for_initial_and_reused_response(): void
    {
        $root = $this->root(); $client = Client::findOrFail($root->client_id);
        $this->allow($this->manager, 'incidents.viewAny', false); $this->allow($this->manager, 'incidents.viewAssigned');
        $client->supportWorkers()->attach($this->manager->id); $first = $this->send($root);
        $client->supportWorkers()->detach($this->manager->id);
        $this->withoutNarrative(fn () => $this->refuse(fn () => $this->send($root, severity: 'critical')));
        $client->supportWorkers()->attach($this->manager->id); $this->assertSame($first->id, $this->send($root)->id);
    }

    public function test_first_private_select_rejects_late_source_client_reassociation_before_narrative(): void
    {
        $root = $this->root(); $other = Client::factory()->create(['site_id' => $this->site->id]);
        $sql = $this->atFirstBody(fn () => DB::table('client_incidents')->where('id', $root->id)->update(['client_id' => $other->id]),
            fn () => $this->refuse(fn () => $this->send($root)));
        $this->assertStringEndsWith('for update nowait', strtolower($sql));
        $this->assertSame(0, ControlRoomAlert::count());
        $this->assertSame($this->site->id, $this->send($root)->site_id);
    }

    public function test_reused_first_private_select_rejects_late_site_reassociation_before_narrative_and_promotion(): void
    {
        $root = $this->root(); $first = $this->send($root); $before = $first->getRawOriginal();
        $foreign = Site::factory()->create(['is_active' => true]);
        $this->atFirstBody(fn () => DB::table('client_incidents')->where('id', $root->id)->update(['site_id' => $foreign->id]),
            fn () => $this->refuse(fn () => $this->send($root, severity: 'critical')));
        $this->assertSame($before, $first->fresh()->getRawOriginal());
        $this->assertSame($first->id, $this->send($root)->id);
    }

    public static function bodyGrantModes(): array
    {
        return ['first destination grant' => ['controlRoom.alerts.create', false], 'reused destination grant' => ['controlRoom.alerts.create', true],
            'first source grant' => ['incidents.viewAny', false], 'reused source grant' => ['incidents.viewAny', true]];
    }

    #[DataProvider('bodyGrantModes')]
    public function test_actual_first_body_sql_rejects_late_grant_withdrawal_and_can_restore(string $permission, bool $reused): void
    {
        $root = $this->root(); $first = $reused ? $this->send($root) : null; $before = $first?->getRawOriginal();
        $this->atFirstBody(fn () => $this->allow($this->manager, $permission, false),
            fn () => $this->refuse(fn () => $this->send($root, severity: 'critical')));
        $this->assertSame($reused ? 1 : 0, ControlRoomAlert::count());
        if ($first !== null) { $this->assertSame($before, $first->fresh()->getRawOriginal()); }
        $this->assertSame($this->site->id, $this->send($root)->site_id);
    }

    public function test_actual_first_body_sql_rejects_late_role_assignment_before_narrative(): void
    {
        $root = $this->root(); $permission = Permission::where('key', 'controlRoom.alerts.create')->sole();
        $this->manager->permissionOverrides()->detach($permission->id);
        $role = Role::create(['name' => 'response_source_reader_'.Str::lower(Str::random(8)), 'description' => 'Synthetic response read role']);
        $role->permissions()->attach($permission->id); $this->manager->roles()->attach($role->id);
        $this->atFirstBody(fn () => $this->manager->roles()->detach($role->id), fn () => $this->refuse(fn () => $this->send($root)));
        $this->assertSame($this->site->id, $this->send($root)->site_id);
    }

    public function test_actual_first_body_sql_rejects_late_hr_profile_site_and_privacy_changes(): void
    {
        $root = $this->root(); $profile = HrEmployeeProfile::where('user_id', $this->manager->id)->sole();
        $foreign = Site::factory()->create(['is_active' => true]);
        foreach ([fn () => DB::table('hr_employee_profiles')->where('id', $profile->id)->update(['primary_site_id' => $foreign->id]),
            fn () => DB::table('hr_employee_profiles')->where('id', $profile->id)->update(['is_active' => false]),
            fn () => DB::table('client_incidents')->where('id', $root->id)->update(['is_hr_confidential' => true])] as $mutation) {
            $this->atFirstBody($mutation, fn () => $this->refuse(fn () => $this->send($root)));
        }
        $this->assertSame($this->site->id, $this->send($root)->site_id);
    }

    public function test_actual_first_body_sql_rejects_late_retained_independence_graph_change(): void
    {
        $root = $this->acceptedSource(); $declaration = IncidentIndependentHandlingDeclaration::where('incident_id', $root->id)->sole();
        $this->atFirstBody(fn () => DB::table('incident_independent_handling_declarations')->where('id', $declaration->id)
            ->update(['handling' => json_encode(['identification' => 'named', 'involved_user_ids' => [$this->manager->id]])]),
            fn () => $this->refuse(fn () => $this->send($root)));
        $this->assertSame(0, ControlRoomAlert::count()); $this->assertSame($this->site->id, $this->send($root)->site_id);
    }

    public function test_owned_first_body_preserves_exclusive_command_lock_after_current_read_callbacks(): void
    {
        $root = $this->root(); $firstSql = null; $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$firstSql, &$armed): void {
            if ($armed && preg_match('/\Aselect (?:\*|`client_incidents`\.\*) from `client_incidents` /', $sql)) { $armed = false; $firstSql = $sql; }
        });
        try { $this->send($root); } finally { $armed = false; }
        $this->assertNotNull($firstSql); $this->assertStringEndsWith('for update nowait', strtolower($firstSql));
        $this->assertStringContainsString('for share nowait', strtolower($firstSql), 'Nested source/evidence blocks keep explicit current shared locks.');
    }

    public function test_missing_and_draft_sources_preserve_existing_denial_and_recoverable_error(): void
    {
        $missing = (new ClientIncident)->forceFill(['id' => 99999999]);
        $this->withoutNarrative(fn () => $this->refuse(fn () => $this->send($missing)));
        $draft = $this->root(['status' => 'draft', 'submitted_at' => null]);
        $this->validation(fn () => $this->send($draft), 'source_id');
        $this->assertSame(0, ControlRoomAlert::count());
    }

    public function test_audit_failure_preserves_source_and_does_not_accept_response_or_severity_links(): void
    {
        $root = $this->root(); $before = $root->getRawOriginal(); $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(function (AuditLog $row): void { if ($row->action === 'controlRoom.alert.createFromIncident') { throw new \RuntimeException('Synthetic mandatory response audit refusal.'); } });
        try {
            try { $this->send($root); $this->fail('No audit acceptance must roll back.'); }
            catch (\RuntimeException $error) { $this->assertSame('Synthetic mandatory response audit refusal.', $error->getMessage()); }
        } finally { Model::setEventDispatcher($dispatcher); }
        $this->assertSame($before, $root->fresh()->getRawOriginal());
        $this->assertSame(0, ControlRoomAlert::count()); $this->assertSame(0, HsEvent::count());
    }

    public function test_final_owned_check_refuses_late_audit_observer_source_change_without_claiming_prebody_closure(): void
    {
        $root = $this->root(); $before = $root->getRawOriginal(); $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        AuditLog::created(function (AuditLog $row) use ($root): void {
            if ($row->action === 'controlRoom.alert.createFromIncident') { DB::table('client_incidents')->where('id', $root->id)->update(['reported_by' => $this->cover->id]); }
        });
        try { $this->refuse(fn () => $this->send($root)); }
        finally { Model::setEventDispatcher($dispatcher); }
        $this->assertSame($before, $root->fresh()->getRawOriginal()); $this->assertSame(0, ControlRoomAlert::count());
    }

    public function test_genuine_legacy_null_site_recovers_from_client_with_explicit_reviewed_provenance(): void
    {
        $root = $this->root(['site_id' => null, 'shift_id' => null]);
        $this->assertTrue(app(Response::class)->canEscalate($this->manager, $root));
        $first = $this->send($root); $fresh = $root->fresh();
        $this->assertSame($this->site->id, $fresh->site_id); $this->assertSame($this->site->id, $first->site_id);
        $audit = AuditLog::where('action', 'incidents.response.siteRecovered')->sole();
        $this->assertNull($audit->meta['before_site_id']); $this->assertSame('current_client_review', $audit->meta['recovery_kind']);
        $this->assertSame((int) $root->client_id, $audit->meta['reference_id']);
        $this->assertSame(0, $fresh->independence_schema_version); $this->assertSame(0, $fresh->independence_revision);
        $this->assertSame($first->id, $this->send($fresh)->id); $this->assertSame(1, AuditLog::where('action', 'incidents.response.siteRecovered')->count());
    }

    /** Observe a real nested SELECT and actual newly hydrated private columns after mutation. */
    private function atBody(string $table, int $ordinal, callable $mutation, callable $operation, int $expectedHydrated = 0): string
    {
        $state = (object) ['active' => true, 'seen' => 0, 'fired' => false, 'hydrated' => 0, 'sql' => null];
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        $class = $table === 'client_incidents' ? ClientIncident::class : HsEvent::class;
        $body = $table === 'client_incidents' ? 'description' : 'worksafe_decision_reason';
        $class::retrieved(function (Model $row) use ($state, $body): void {
            if ($state->active && $state->fired && array_key_exists($body, $row->getAttributes())) { $state->hydrated++; }
        });
        DB::connection()->beforeExecuting(function (string $sql) use ($state, $table, $ordinal, $mutation): void {
            if (! $state->active || $state->fired || ! preg_match('/\Aselect (?:\*|`'.$table.'`\.\*) from `'.$table.'` /', $sql)) { return; }
            if (++$state->seen !== $ordinal) { return; }
            $state->fired = true; $state->sql = $sql; $mutation();
        });
        try {
            $operation(); $this->assertTrue($state->fired, 'The actual selected private query must fire.');
            $this->assertSame($expectedHydrated, $state->hydrated, 'Count real body columns, not final rollback.');
            return $state->sql;
        } finally { $state->active = false; Model::setEventDispatcher($dispatcher); }
    }

    private function protectedSource(ClientIncident $root, callable $operation): mixed
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($root, $operation): mixed {
            $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($this->manager,
                ['controlRoom.alerts.create', 'incidents.viewAny', 'incidents.viewAssigned', 'healthSafety.viewAllSites', 'hr.cases.view', 'reports.viewAny']);
            $admitted = (new \ReflectionMethod(Response::class, 'admittedSource'))->invoke(app(Response::class), (int) $root->id, $current, $reads);
            $this->assertNotNull($admitted['identity']);
            return SourceContext::within($reads, $admitted['query'], $admitted['identity'], $current, $admitted['sites'],
                fn ($source) => $operation($source, $reads, $current));
        }));
    }

    private function canonicalEvent(ClientIncident $root, array $overrides = []): HsEvent
    {
        return HsEvent::factory()->create(['source_type' => ClientIncident::class, 'source_id' => $root->id,
            'event_category' => HsEvent::CATEGORY_INCIDENT, 'idempotency_key' => HsEvent::buildIdempotencyKey(ClientIncident::class, $root->id, HsEvent::CATEGORY_INCIDENT),
            'client_id' => $root->client_id, 'site_id' => $this->site->id, 'control_room_alert_id' => null, ...$overrides]);
    }

    public function test_null_site_prefers_exact_canonical_hse_site_over_current_client_placement(): void
    {
        $root = $this->root(['site_id' => null, 'shift_id' => null]); $event = $this->canonicalEvent($root);
        DB::table('client_incidents')->where('id', $root->id)->update(['hs_event_id' => $event->id]);
        DB::table('clients')->where('id', $root->client_id)->update(['site_id' => Site::factory()->create(['is_active' => true])->id]);
        $alert = $this->send($root); $audit = AuditLog::where('action', 'incidents.response.siteRecovered')->sole();
        $this->assertSame($this->site->id, $alert->site_id); $this->assertSame($event->id, $root->fresh()->hs_event_id);
        $this->assertSame('canonical_hs_source', $audit->meta['recovery_kind']); $this->assertSame((int) $event->id, $audit->meta['reference_id']);
    }

    public static function nestedIncidentBodies(): array
    {
        return ['initial mutex' => [2, false], 'initial final refetch' => [3, false], 'reused mutex' => [2, true], 'reused final refetch' => [3, true]];
    }
    #[DataProvider('nestedIncidentBodies')]
    public function test_nested_incident_body_and_refetch_retain_original_source_before_hydration(int $ordinal, bool $reuse): void
    {
        $root = $this->root(); $alert = $reuse ? $this->send($root) : null;
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $sql = $this->atBody('client_incidents', $ordinal,
            fn () => DB::table('client_incidents')->where('id', $root->id)->update(['client_id' => $other->id]),
            fn () => $this->refuse(fn () => $this->send($root, severity: 'critical')));
        $this->assertSame($root->client_id, $root->fresh()->client_id); $this->assertSame($reuse ? 1 : 0, ControlRoomAlert::count());
        if ($ordinal === 2) { $this->assertStringEndsWith('for update nowait', strtolower($sql)); }
        if ($alert !== null) { $this->assertSame($alert->id, $this->send($root)->id); }
        else { $this->assertSame($this->site->id, $this->send($root)->site_id); }
    }

    public static function nestedEvidenceWithdrawals(): array
    {
        return ['grant' => ['grant'], 'profile Site' => ['profile'], 'source privacy' => ['privacy'], 'retained D05' => ['d05']];
    }
    #[DataProvider('nestedEvidenceWithdrawals')]
    public function test_nested_mutex_keeps_original_actor_site_privacy_and_d05_evidence(string $kind): void
    {
        $root = $kind === 'd05' ? $this->acceptedSource() : $this->root();
        $mutation = match ($kind) {
            'grant' => fn () => $this->allow($this->manager, 'controlRoom.alerts.create', false),
            'profile' => fn () => DB::table('hr_employee_profiles')->where('user_id', $this->manager->id)->update(['is_active' => false]),
            'privacy' => fn () => DB::table('client_incidents')->where('id', $root->id)->update(['is_hr_confidential' => true]),
            'd05' => fn () => DB::table('incident_independent_handling_declarations')->where('incident_id', $root->id)
                ->update(['handling' => json_encode(['identification' => 'named', 'involved_user_ids' => [$this->manager->id]])]),
        };
        $this->atBody('client_incidents', 2, $mutation, fn () => $this->refuse(fn () => $this->send($root)));
        $this->assertSame(0, ControlRoomAlert::count()); $this->assertSame($this->site->id, $this->send($root)->site_id);
    }

    public function test_recovered_site_final_body_preserves_original_shadow_and_inner_current_locks(): void
    {
        $root = $this->root(['site_id' => null, 'shift_id' => null]); $sql = null; $armed = true;
        DB::connection()->beforeExecuting(function (string $candidate) use (&$sql, &$armed): void {
            if ($armed && str_starts_with($candidate, 'select * from `client_incidents` ') && str_contains($candidate, '`response_physical`')) { $sql = $candidate; $armed = false; }
        });
        try { $this->send($root); } finally { $armed = false; }
        $this->assertNotNull($sql); $this->assertStringContainsString('as `client_incidents`', $sql);
        $this->assertStringContainsString('for share nowait', strtolower($sql));
        $this->assertStringContainsString('? as `site_id`', $sql, 'Original NULL is shadowed only as metadata, not recaptured as authority.');
    }

    public function test_recovered_site_final_body_refuses_client_placement_change_and_rolls_back_recovery(): void
    {
        $root = $this->root(['site_id' => null, 'shift_id' => null]); $other = Site::factory()->create(['is_active' => true]);
        $this->atBody('client_incidents', 3, fn () => DB::table('clients')->where('id', $root->client_id)->update(['site_id' => $other->id]),
            fn () => $this->refuse(fn () => $this->send($root)));
        $this->assertNull($root->fresh()->site_id); $this->assertSame(0, AuditLog::where('action', 'incidents.response.siteRecovered')->count());
        $this->assertSame(0, ControlRoomAlert::count()); $this->assertSame($this->site->id, $this->send($root)->site_id);
    }

    public function test_accepted_d05_source_with_damaged_null_site_cannot_use_legacy_repair(): void
    {
        $root = $this->acceptedSource(); DB::table('client_incidents')->where('id', $root->id)->update(['site_id' => null]);
        $this->withoutNarrative(fn () => $this->refuse(fn () => $this->send($root)));
        $this->assertSame(0, AuditLog::where('action', 'incidents.response.siteRecovered')->count()); $this->assertSame(0, ControlRoomAlert::count());
    }

    public function test_recovery_audit_failure_cannot_accept_site_event_or_alert_links(): void
    {
        $root = $this->root(['site_id' => null, 'shift_id' => null]); $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(function (AuditLog $row): void { if ($row->action === 'incidents.response.siteRecovered') { throw new \RuntimeException('Synthetic recovery audit refusal.'); } });
        try { try { $this->send($root); $this->fail('Recovery requires its actual audit.'); }
            catch (\RuntimeException $error) { $this->assertSame('Synthetic recovery audit refusal.', $error->getMessage()); } }
        finally { Model::setEventDispatcher($dispatcher); }
        $this->assertNull($root->fresh()->site_id); $this->assertSame(0, HsEvent::count()); $this->assertSame(0, ControlRoomAlert::count());
    }

    public static function badHsClaims(): array
    {
        return ['wrong literal' => ['source_type', 'App\\Models\\ForeignIncident'], 'wrong category' => ['event_category', HsEvent::CATEGORY_NEAR_MISS],
            'wrong key' => ['idempotency_key', 'not-the-canonical-key'], 'no Site' => ['site_id', null]];
    }
    #[DataProvider('badHsClaims')]
    public function test_recovery_rejects_conflicting_hse_metadata_without_loading_hse_body(string $column, mixed $value): void
    {
        $root = $this->root(['site_id' => null, 'shift_id' => null]); $event = $this->canonicalEvent($root, [$column => $value]);
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher); $body = 0;
        HsEvent::retrieved(function (HsEvent $row) use (&$body): void { if (array_key_exists('worksafe_decision_reason', $row->getAttributes())) { $body++; } });
        try { try { $this->send($root); $this->fail('Conflicting claims cannot be adopted.'); }
            catch (\DomainException $error) { $this->assertNotSame('', $error->getMessage()); } }
        finally { Model::setEventDispatcher($dispatcher); }
        $this->assertSame(0, $body); $this->assertNull($root->fresh()->site_id); $this->assertSame(0, ControlRoomAlert::count());
        $this->assertSame($event->id, HsEvent::sole()->id);
    }

    public function test_soft_deleted_direct_hse_cannot_be_replaced_by_new_event(): void
    {
        $root = $this->root(); $event = $this->canonicalEvent($root);
        DB::table('client_incidents')->where('id', $root->id)->update(['hs_event_id' => $event->id]);
        DB::table('hs_events')->where('id', $event->id)->update(['deleted_at' => now()]);
        $this->refuse(fn () => $this->send($root)); $this->assertSame(1, HsEvent::withTrashed()->count()); $this->assertSame(0, ControlRoomAlert::count());
    }

    public function test_multiple_literal_hse_claims_refuse_before_any_event_body_or_site_recovery(): void
    {
        $root = $this->root(['site_id' => null, 'shift_id' => null]); $this->canonicalEvent($root);
        $this->canonicalEvent($root, ['event_category' => HsEvent::CATEGORY_NEAR_MISS,
            'idempotency_key' => HsEvent::buildIdempotencyKey(ClientIncident::class, $root->id, HsEvent::CATEGORY_NEAR_MISS)]);
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher); $body = 0;
        HsEvent::retrieved(function (HsEvent $row) use (&$body): void { if (array_key_exists('worksafe_decision_reason', $row->getAttributes())) { $body++; } });
        try { try { $this->send($root); $this->fail('Two literal claimants cannot be selected arbitrarily.'); }
            catch (\DomainException $error) { $this->assertStringContainsString('multiple H&S events', $error->getMessage()); } }
        finally { Model::setEventDispatcher($dispatcher); }
        $this->assertSame(0, $body); $this->assertNull($root->fresh()->site_id); $this->assertSame(0, ControlRoomAlert::count());
    }

    public function test_canonical_hse_recovery_site_does_not_grant_independent_response_site_authority(): void
    {
        $root = $this->root(['site_id' => null, 'shift_id' => null]); $foreign = Site::factory()->create(['is_active' => true]);
        $event = $this->canonicalEvent($root, ['site_id' => $foreign->id]);
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher); $body = 0;
        HsEvent::retrieved(function (HsEvent $row) use (&$body): void { if (array_key_exists('worksafe_decision_reason', $row->getAttributes())) { $body++; } });
        try { $this->refuse(fn () => $this->send($root)); }
        finally { Model::setEventDispatcher($dispatcher); }
        $this->assertSame(0, $body); $this->assertNull($root->fresh()->site_id); $this->assertSame(0, ControlRoomAlert::count());
        foreach (['healthSafety.viewAllSites', 'reports.viewAny'] as $permission) { $this->allow($this->manager, $permission); }
        $this->assertSame($foreign->id, $this->send($root)->site_id); $this->assertSame($event->id, $root->fresh()->hs_event_id);
    }

    public static function hsBodyMutations(): array
    {
        return ['known Site deletion' => ['deleted', false], 'recovered Site deletion' => ['deleted', true],
            'known Site tuple rewrite' => ['client', false], 'recovered Site tuple rewrite' => ['client', true]];
    }
    #[DataProvider('hsBodyMutations')]
    public function test_hse_first_body_retains_nondeleted_metadata_and_original_parent(string $kind, bool $nullSite): void
    {
        $root = $this->root(['site_id' => $nullSite ? null : $this->site->id, 'shift_id' => null]); $event = $this->canonicalEvent($root);
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $this->atBody('hs_events', 1, fn () => DB::table('hs_events')->where('id', $event->id)
            ->update($kind === 'deleted' ? ['deleted_at' => now()] : ['client_id' => $other->id]),
            fn () => $this->refuse(fn () => $this->send($root)));
        $this->assertSame(0, ControlRoomAlert::count()); $this->assertSame($this->site->id, $this->send($root)->site_id);
    }

    public function test_created_hse_body_refetch_rejects_source_change_before_hydration(): void
    {
        $root = $this->root();
        $this->atBody('hs_events', 1, fn () => DB::table('client_incidents')->where('id', $root->id)->update(['reported_by' => $this->cover->id]),
            fn () => $this->refuse(fn () => $this->send($root)));
        $this->assertSame(0, HsEvent::count()); $this->assertSame(0, ControlRoomAlert::count()); $this->assertSame($this->site->id, $this->send($root)->site_id);
    }

    public function test_late_foreign_direct_incident_claim_prevents_hse_body_hydration(): void
    {
        $root = $this->root(); $other = $this->root(); $event = $this->canonicalEvent($root);
        $this->atBody('hs_events', 1, fn () => DB::table('client_incidents')->where('id', $other->id)->update(['hs_event_id' => $event->id]),
            fn () => $this->refuse(fn () => $this->send($root)));
        $this->assertNull($other->fresh()->hs_event_id); $this->assertSame(0, ControlRoomAlert::count());
        $this->assertSame($this->site->id, $this->send($root)->site_id);
    }

    public function test_recovered_shadow_cannot_hide_a_malicious_physical_hse_link_replacement(): void
    {
        $root = $this->root(['site_id' => null, 'shift_id' => null]);
        $other = $this->root(); $foreign = $this->canonicalEvent($other);
        $this->atBody('client_incidents', 3, fn () => DB::table('client_incidents')->where('id', $root->id)->update(['hs_event_id' => $foreign->id]),
            fn () => $this->refuse(fn () => $this->send($root)));
        $this->assertNull($root->fresh()->site_id); $this->assertNull($root->fresh()->hs_event_id); $this->assertSame(0, ControlRoomAlert::count());
        $this->assertSame($this->site->id, $this->send($root)->site_id);
    }

    public function test_final_recovered_incident_body_refuses_retained_hse_soft_delete_before_narrative(): void
    {
        $root = $this->root(['site_id' => null, 'shift_id' => null]); $event = $this->canonicalEvent($root);
        $this->atBody('client_incidents', 3, fn () => DB::table('hs_events')->where('id', $event->id)->update(['deleted_at' => now()]),
            fn () => $this->refuse(fn () => $this->send($root)));
        $this->assertNull($root->fresh()->site_id); $this->assertSame(0, ControlRoomAlert::count());
    }

    public function test_live_context_refuses_other_actor_or_record_and_cannot_be_serialized(): void
    {
        $root = $this->root(); $other = $this->root();
        $this->protectedSource($root, function ($source) use ($root, $other): void {
            foreach ([fn () => $source->assertFor($other), fn () => $source->assertFor($root, $this->cover), fn () => serialize($source)] as $operation) {
                try { $operation(); $this->fail('Authority cannot be transferred.'); } catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
            }
            $this->assertSame($root->id, $source->incidentQuery(ClientIncident::query())->sole(['id'])->id);
        });
    }

    public function test_expired_context_refuses_direct_and_embedded_query_under_another_viewers_fresh_reads(): void
    {
        $root = $this->root(); $escaped = null;
        $this->protectedSource($root, function ($source) use (&$escaped): void { $escaped = $source->incidentQuery(ClientIncident::query()); });
        DB::transaction(fn () => CurrentAuthorizationReads::within(function ($fresh) use ($escaped): void {
            app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($this->cover, ['incidents.viewAny']);
            foreach ([fn () => (clone $escaped)->exists(), fn () => $fresh->query(ClientIncident::query()->whereExists((clone $escaped)->select('client_incidents.id')->toBase()))->exists()] as $operation) {
                try { $operation(); $this->fail('Embedded compilation retains the ORIGINAL expired handle.'); }
                catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
            }
        }));
    }

    public static function outerCompilerChanges(): array
    {
        return ['known timezone' => [false, 'timezone'], 'recovered timezone' => [true, 'timezone'],
            'known day' => [false, 'day'], 'recovered day' => [true, 'day']];
    }
    #[DataProvider('outerCompilerChanges')]
    public function test_outer_before_query_reconsumes_original_date_guard_before_body(bool $recover, string $change): void
    {
        $root = $this->root(['site_id' => $recover ? null : $this->site->id, 'shift_id' => null]);
        $worker = config('app.worker_timezone'); $app = config('app.timezone'); $instant = now()->copy();
        try {
            config(['app.worker_timezone' => null, 'app.timezone' => 'Pacific/Auckland']);
            $this->protectedSource($root, function ($source) use ($root, $recover, $change): void {
                if ($recover) { $source->recoverSite($root, $source->discoverHsMetadata()); }
                $query = $source->incidentQuery(ClientIncident::query());
                $query->getQuery()->beforeQuery(function () use ($change): void {
                    if ($change === 'timezone') { config(['app.worker_timezone' => 'UTC', 'app.timezone' => 'UTC']); }
                    else { $this->travelTo(now()->addDay()->utc()); }
                });
                $this->withoutNarrative(function () use ($query): void {
                    try { $query->first(); $this->fail('Actual outer compilation must retain original civil-date evidence.'); }
                    catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
                });
            });
        } finally { config(['app.worker_timezone' => $worker, 'app.timezone' => $app]); $this->travelTo($instant->utc()); }
    }

    #[DataProvider('outerCompilerChanges')]
    public function test_guarded_update_outer_compiler_rechecks_original_date_after_derived_sql_compilation(bool $recover, string $change): void
    {
        $root = $this->root(['site_id' => $recover ? null : $this->site->id, 'shift_id' => null]);
        $worker = config('app.worker_timezone'); $app = config('app.timezone'); $instant = now()->copy();
        $global = ClientIncident::getAllGlobalScopes(); $state = (object) ['armed' => false, 'fired' => false];
        try {
            config(['app.worker_timezone' => null, 'app.timezone' => 'Pacific/Auckland']);
            $this->protectedSource($root, function ($source) use ($root, $recover, $change, $state): void {
                if ($recover) { $source->recoverSite($root, $source->discoverHsMetadata()); }
                // A real Eloquent scope appends a callback on the actual target UPDATE builder.
                // It does not replace current authorization or a source provider.
                ClientIncident::addGlobalScope('response_compiler_test', function ($builder) use ($state, $change): void {
                    $builder->getQuery()->beforeQuery(function ($compiled) use ($state, $change): void {
                        if (! $state->armed || count($compiled->wheres ?? []) !== 2 || ($compiled->wheres[0]['type'] ?? null) !== 'In'
                            || ! (($compiled->wheres[0]['values'][0] ?? null) instanceof \Illuminate\Database\Query\Expression)) { return; }
                        $state->armed = false; $state->fired = true;
                        if ($change === 'timezone') { config(['app.worker_timezone' => 'UTC', 'app.timezone' => 'UTC']); }
                        else { $this->travelTo(now()->addDay()->utc()); }
                    });
                });
                $state->armed = true;
                try { $source->persist($root, ['metadata' => ['journey' => ['test' => 'Actual protected update.']]]); $this->fail('Original guard must be recompiled on the actual UPDATE.'); }
                catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
                $this->assertTrue($state->fired, 'The actual outer target callback must fire after early derived compilation.');
            });
        } finally {
            $state->armed = false; ClientIncident::setAllGlobalScopes($global);
            config(['app.worker_timezone' => $worker, 'app.timezone' => $app]); $this->travelTo($instant->utc());
        }
        $this->assertNull(data_get($root->fresh()->metadata, 'journey.test'));
    }

    public function test_default_and_protected_known_site_keep_existing_severity_and_reuse_contract(): void
    {
        $unprotected = $this->root(); $protected = $this->root();
        $default = app(IncidentJourneyService::class)->ensureAlertForIncident($unprotected, $this->manager, 'Coordinate support.', 'high');
        $alert = $this->send($protected); $second = $this->send($protected, severity: 'critical');
        $this->assertSame('high', $default->alert->severity); $this->assertSame('high', $alert->severity);
        $this->assertSame($alert->id, $second->id); $this->assertSame('critical', $second->severity);
        $this->assertSame(2, HsEvent::count()); $this->assertSame(2, ControlRoomAlert::count());
        $this->assertSame($default->alert->id, app(IncidentJourneyService::class)->ensureAlertForIncident($unprotected->fresh(), $this->manager)->alert->id);
    }
}
