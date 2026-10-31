<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\Permission;
use App\Models\Site;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HealthSafetyReadMaterialisationProof;
use App\Services\HealthSafety\WorkerParticipationRecipientSourceOrigin;
use App\Services\HealthSafety\WorkerParticipationRecipientSourceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Actual original-origin queries/aggregates and live recipient callback; whole suite UNRUN. */
final class WorkerParticipationRecipientSourceReadTest extends RestraintEventCommandTestCase
{
    private HsCommittee $committee;
    private HsCommitteeMeeting $meeting;
    private WorkerParticipationRecipientSourceService $sources;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        // The base class's restraint actor is current staff at this Site; it is not part of this suite's workforce.
        $this->actor->hrEmployeeProfile()->update(['is_active' => false]);
        $this->actor = $this->staff([]);
        $this->committee = HsCommittee::create(['site_id' => $this->site->id, 'name' => 'Own source', 'status' => 'active', 'established_at' => '2026-01-01']);
        $this->meeting = $this->committee->meetings()->create(['scheduled_at' => '2026-10-14 01:00:00', 'location' => 'Original location', 'status' => 'scheduled', 'minutes' => 'Private minutes']);
        $this->meeting->attendeeUsers()->attach($this->actor->id, ['response' => 'invited', 'attended' => false]);
        $this->actingAs($this->actor);
        request()->setUserResolver(fn () => auth()->user());
        $this->sources = new WorkerParticipationRecipientSourceService;
    }

    public function test_own_origin_qualifies_actual_get_count_clone_and_nested_aggregate(): void
    {
        $origin = $this->sources->captureOrigin($this->actor, $this->meeting->id, 'attendee');
        $query = $origin->applySourcePredicate(HsCommitteeMeeting::query());
        $this->assertSame('Original location', (clone $query)->sole(['id', 'location'])->location);
        $this->assertSame(1, (clone $query)->count());
        $outer = DB::table('hs_meeting_attendees')->where('user_id', $this->actor->id)
            ->whereExists($origin->applySourcePredicate(DB::table('hs_committee_meetings'))->whereColumn('hs_committee_meetings.id', 'hs_meeting_attendees.meeting_id')->selectRaw('1'));
        // Embedded child afterQuery is not invoked: actual consumer replays the outer pre-hydration barrier.
        $outer->afterQuery(function ($rows) use ($origin) { $origin->assertOriginal(); return $rows; });
        $this->assertSame(1, $outer->count());
    }

    public function test_contextual_origin_expires_but_same_request_standalone_reuses_original_actor_evidence(): void
    {
        $old = null;
        // Plain closures: an arrow fn would capture $old by value, so the origin would never reach this scope.
        HealthSafetyReadContext::within($this->actor, WorkerParticipationRecipientSourceService::PERMISSIONS,
            function ($context) use (&$old) { return HealthSafetyReadMaterialisationProof::within($context, function () use ($context, &$old): array {
                $old = $this->sources->captureOrigin($this->actor, $this->meeting->id, 'attendee', null, $context);
                $this->assertSame(1, $old->applySourcePredicate(HsCommitteeMeeting::query())->count());
                return [];
            }); });
        $this->logic(fn () => $old->assertOriginal());
        $fresh = $this->sources->captureOrigin($this->actor, $this->meeting->id, 'attendee');
        $this->assertSame(1, $fresh->applySourcePredicate(HsCommitteeMeeting::query())->count());
        $this->logic(fn () => $old->assertOriginal());
    }

    public function test_contextual_then_standalone_cannot_refresh_withdrawn_original_request_actor(): void
    {
        $this->assertFalse($this->sources->hasPreparedReceiver());
        HealthSafetyReadContext::within($this->actor, WorkerParticipationRecipientSourceService::PERMISSIONS,
            fn ($context) => HealthSafetyReadMaterialisationProof::within($context, function () use ($context): array {
                $this->sources->prepareReceiver($this->actor, $context); return [];
            }));
        $this->assertTrue($this->sources->hasPreparedReceiver());
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['primary_site_id' => null]);
        $this->refused(fn () => $this->sources->prepareReceiver($this->actor));
        $replacementOwner = new WorkerParticipationRecipientSourceService;
        $this->assertTrue($replacementOwner->hasPreparedReceiver());
        $this->refused(fn () => $replacementOwner->prepareReceiver($this->actor));
        $this->assertTrue($replacementOwner->hasPreparedReceiver());
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['primary_site_id' => $this->site->id]);
        $this->sources->prepareReceiver($this->actor);
        $this->assertSame(1, $this->sources->captureOrigin($this->actor, $this->meeting->id, 'attendee')->applySourcePredicate(HsCommitteeMeeting::query())->count());
    }

    public function test_expired_contextual_origin_embedded_under_new_actor_reads_fails_before_sql(): void
    {
        $old = null; $predicate = null;
        HealthSafetyReadContext::within($this->actor, WorkerParticipationRecipientSourceService::PERMISSIONS,
            function ($context) use (&$old, &$predicate) { return HealthSafetyReadMaterialisationProof::within($context, function () use ($context, &$old, &$predicate): array {
                $old = $this->sources->captureOrigin($this->actor, $this->meeting->id, 'attendee', null, $context);
                $predicate = $old->applySourcePredicate(DB::table('hs_committee_meetings'))->selectRaw('1');
                return [];
            }); });
        $other = $this->staff([]); $this->actingAs($other);
        $sqlCount = 0; $armed = true;
        DB::connection()->beforeExecuting(function ($sql) use (&$sqlCount, &$armed): void { if ($armed && str_starts_with(strtolower(ltrim($sql)), 'select') && str_contains($sql, 'hs_committee_meetings')) { $sqlCount++; } });
        try {
            $this->logic(fn () => HealthSafetyReadContext::within($other, WorkerParticipationRecipientSourceService::PERMISSIONS,
                fn ($context) => $context->consumeSourceViewer([], fn ($current, $reads) => $reads->query(DB::table('users'))
                    ->whereExists($predicate)->count())));
            $this->assertSame(0, $sqlCount);
        } finally { $armed = false; }
    }

    public function test_original_receiver_and_request_identity_cannot_transfer(): void
    {
        $origin = $this->sources->captureOrigin($this->actor, $this->meeting->id, 'attendee');
        $other = $this->staff([]); $this->actingAs($other);
        $this->logic(fn () => $origin->applySourcePredicate(HsCommitteeMeeting::query())->count());
        $this->actingAs($this->actor);
        $original = request(); $replacement = clone $original; app()->instance('request', $replacement);
        try { $this->logic(fn () => $origin->assertOriginal()); }
        finally { app()->instance('request', $original); }
        $origin->assertOriginal();
    }

    public static function descriptorChanges(): array { return [['site_id'], ['committee_id'], ['scheduled_at'], ['meeting_status'], ['committee_status'], ['location']]; }

    #[DataProvider('descriptorChanges')]
    public function test_wrong_original_descriptor_denies_before_location_body(string $key): void
    {
        $descriptor = $this->descriptor();
        $descriptor[$key] = match ($key) { 'site_id', 'committee_id' => $descriptor[$key] + 100000, 'scheduled_at' => '2026-10-14 02:00:00', 'location' => 'Different location', default => 'different' };
        $this->withoutLocationHydration(fn () => $this->refused(fn () => $this->sources->withSource($this->actor, $this->meeting->id, 'attendee', $descriptor, fn () => [])));
    }

    public function test_actual_body_sql_denies_late_original_location_change_and_restored_source_remains_readable(): void
    {
        $origin = $this->sources->captureOrigin($this->actor, $this->meeting->id, 'attendee');
        $armed = true; $fired = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$armed, &$fired): void {
            if ($armed && ! $fired && preg_match('/^select\s+(.+?)\s+from\s+`hs_committee_meetings`/is', ltrim($sql), $match) && str_contains($match[1], '`location`')) {
                $fired = true; DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->update(['location' => 'Replaced']);
            }
        });
        try { $this->withoutLocationHydration(fn () => $this->refused(fn () => $origin->applySourcePredicate(HsCommitteeMeeting::query())->get(['id', 'location']))); $this->assertTrue($fired); }
        finally { $armed = false; }
        DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->update(['location' => 'Original location']);
        $this->assertSame('Original location', $origin->applySourcePredicate(HsCommitteeMeeting::query())->sole(['location'])->location);
    }

    public function test_original_attached_role_grant_change_denies_count_without_new_baseline(): void
    {
        $role = \App\Models\Role::create(['name' => 'invitation_source_reader', 'description' => 'Synthetic own source role']);
        $permission = Permission::where('key', 'hazards.view')->sole(); $role->permissions()->attach($permission);
        $this->actor->roles()->attach($role);
        $origin = $this->sources->captureOrigin($this->actor, $this->meeting->id, 'attendee');
        $role->permissions()->detach($permission);
        $this->refused(fn () => $origin->applySourcePredicate(HsCommitteeMeeting::query())->count());
        $this->refused(fn () => $this->sources->captureOrigin($this->actor, $this->meeting->id, 'attendee'));
        $role->permissions()->attach($permission);
        $origin->assertOriginal();
    }

    public function test_batch_origins_use_one_original_actor_and_keep_missing_candidates_out(): void
    {
        $second = $this->committee->meetings()->create(['scheduled_at' => '2026-10-15 01:00:00', 'location' => 'Second location', 'status' => 'scheduled']);
        $second->attendeeUsers()->attach($this->actor->id);
        $origins = $this->sources->captureOrigins($this->actor, [['meeting_id' => $this->meeting->id, 'audience' => 'attendee'],
            ['meeting_id' => $second->id, 'audience' => 'attendee'], ['meeting_id' => $second->id + 100000, 'audience' => 'attendee']]);
        $this->assertSame([$this->meeting->id.':attendee', $second->id.':attendee'], array_keys($origins));
        foreach ($origins as $origin) { $origin->assertOriginal(); }
    }

    public function test_live_delivery_callback_can_admit_different_recipient_without_auth_shortcut_or_view_grant(): void
    {
        $recipient = $this->staff([]); $this->meeting->attendeeUsers()->attach($recipient->id);
        $result = $this->sources->withSource($recipient, $this->meeting->id, 'attendee', $this->descriptor(), function ($current, $reads, $context, $proof, $descriptor, $source): array {
            $this->assertFalse($current->canDo('hazards.view'));
            return ['recipient_id' => (int) $current->id, 'present' => (clone $source)->exists()];
        });
        $this->assertSame(['recipient_id' => $recipient->id, 'present' => true], $result);
        $this->logic(fn () => $this->sources->captureOrigin($recipient, $this->meeting->id, 'attendee'));
    }

    public function test_workforce_notice_requires_current_assignment_and_never_grants_uninvited_detail(): void
    {
        $this->meeting->attendeeUsers()->detach($this->actor->id);
        $this->assertSame($this->descriptor(), $this->sources->withSource($this->actor, $this->meeting->id, 'workforce_notice', $this->descriptor(), fn ($current, $reads, $context, $proof, $descriptor) => $descriptor));
        $this->refused(fn () => $this->sources->withSource($this->actor, $this->meeting->id, 'attendee', null, fn () => []));
        $this->actor->hrEmployeeProfile->update(['primary_site_id' => null]);
        foreach (WorkerParticipationRecipientSourceService::PERMISSIONS as $key) { $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => true]]); }
        $this->refused(fn () => $this->sources->withSource($this->actor, $this->meeting->id, 'workforce_notice', null, fn () => []));
        $this->committee->update(['site_id' => null]);
        $this->refused(fn () => $this->sources->withSource($this->actor, $this->meeting->id, 'workforce_notice', null, fn () => []));
    }

    public function test_callback_source_final_barrier_rejects_late_pivot_change_and_model_escape(): void
    {
        $this->refused(fn () => $this->sources->withSource($this->actor, $this->meeting->id, 'attendee', null, function (): array {
            DB::table('hs_meeting_attendees')->where('meeting_id', $this->meeting->id)->where('user_id', $this->actor->id)->update(['response' => 'declined']); return [];
        }));
        $this->logic(fn () => $this->sources->withSource($this->actor, $this->meeting->id, 'attendee', null, fn () => $this->meeting));
    }

    public function test_original_supplied_collector_cannot_be_replaced_by_a_different_collector(): void
    {
        HealthSafetyReadContext::within($this->actor, WorkerParticipationRecipientSourceService::PERMISSIONS, function ($context): array {
            $old = null;
            HealthSafetyReadMaterialisationProof::within($context, function () use ($context, &$old): array {
                $old = $this->sources->captureOrigin($this->actor, $this->meeting->id, 'attendee', null, $context); return [];
            });
            HealthSafetyReadMaterialisationProof::within($context, function () use ($old): array {
                $this->logic(fn () => $old->assertOriginal()); return [];
            });
            return [];
        });
    }

    public function test_original_civil_day_compiler_refuses_changed_timezone_before_actual_sql(): void
    {
        $origin = $this->sources->captureOrigin($this->actor, $this->meeting->id, 'attendee');
        config(['app.worker_timezone' => 'UTC']);
        $this->refused(fn () => $origin->applySourcePredicate(HsCommitteeMeeting::query())->count());
        config(['app.worker_timezone' => 'Pacific/Auckland']); $origin->assertOriginal();
    }

    public function test_approved_manager_discovery_returns_only_current_candidate_ids_and_current_assignment(): void
    {
        $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.manage')->sole()->id => ['allowed' => true]]);
        $secondary = $this->staff([]); $secondary->hrEmployeeProfile->update(['primary_site_id' => null, 'secondary_site_ids' => [$this->site->id]]);
        $retired = $this->staff([]); $retired->hrEmployeeProfile->update(['is_active' => false]); $this->meeting->attendeeUsers()->attach($retired->id);
        $foreignSite = Site::factory()->create(['is_active' => true]); $foreign = $this->staff([], $foreignSite);
        $result = DB::transaction(fn () => $this->sources->candidateRecipients($this->meeting));
        $this->assertSame([['recipient_id' => $this->actor->id, 'audience' => 'attendee'], ['recipient_id' => $secondary->id, 'audience' => 'workforce_notice']], $result);
        $this->assertNotContains($retired->id, array_column($result, 'recipient_id'));
        $this->assertNotContains($foreign->id, array_column($result, 'recipient_id'));
        $this->assertSame([['recipient_id' => $this->actor->id, 'audience' => 'attendee']], DB::transaction(fn () => $this->sources->candidateRecipients($this->meeting, false)));
    }

    public function test_manager_discovery_requires_fresh_existing_manage_permission_and_active_source_site(): void
    {
        $this->refused(fn () => DB::transaction(fn () => $this->sources->candidateRecipients($this->meeting)), 403);
        $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.manage')->sole()->id => ['allowed' => true]]);
        $this->site->update(['archived_at' => now()]);
        $this->refused(fn () => DB::transaction(fn () => $this->sources->candidateRecipients($this->meeting)));
        $this->site->update(['archived_at' => null]);
        $this->assertNotEmpty(DB::transaction(fn () => $this->sources->candidateRecipients($this->meeting)));
    }

    public function test_manager_discovery_cannot_accept_arbitrary_foreign_or_null_site_meeting_without_existing_wide_grant(): void
    {
        $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.manage')->sole()->id => ['allowed' => true]]);
        $foreign = Site::factory()->create(['is_active' => true]);
        foreach ([$foreign->id, null] as $siteId) {
            $this->committee->update(['site_id' => $siteId]);
            $this->refused(fn () => DB::transaction(fn () => $this->sources->candidateRecipients($this->meeting)));
            $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'healthSafety.viewAllSites')->sole()->id => ['allowed' => true]]);
            $this->assertSame([['recipient_id' => $this->actor->id, 'audience' => 'attendee']], DB::transaction(fn () => $this->sources->candidateRecipients($this->meeting)));
            $this->actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'healthSafety.viewAllSites')->sole()->id, ['allowed' => false]);
        }
    }

    public function test_explicit_wide_internal_manager_discovery_does_not_add_employment_or_view_grant(): void
    {
        $recipient = $this->staff([]); $this->meeting->attendeeUsers()->attach($recipient->id);
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->delete();
        foreach (['hazards.manage', 'healthSafety.viewAllSites'] as $key) {
            $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => true]]);
        }
        $this->assertFalse($this->actor->fresh()->canDo('hazards.view'));
        $this->assertSame([['recipient_id' => $recipient->id, 'audience' => 'attendee']], DB::transaction(fn () => $this->sources->candidateRecipients($this->meeting, false)));
        // external_clinical_account is not mass assignable; update() would silently skip it.
        $this->actor->forceFill(['external_clinical_account' => true])->save();
        $this->refused(fn () => DB::transaction(fn () => $this->sources->candidateRecipients($this->meeting)), 403);
    }

    public static function managerMutations(): array { return [['source'], ['assignment'], ['permission']]; }

    #[DataProvider('managerMutations')]
    public function test_manager_actual_callback_body_select_retains_original_source_assignment_and_manage_grant(string $mutation): void
    {
        $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.manage')->sole()->id => ['allowed' => true]]);
        $foreign = Site::factory()->create(['is_active' => true]); $armed = false; $fired = false;
        DB::connection()->beforeExecuting(function ($sql) use ($mutation, $foreign, &$armed, &$fired): void {
            if (! $armed || $fired || ! preg_match('/^select\s+(.+?)\s+from\s+`hs_committee_meetings`/is', ltrim($sql), $match) || ! str_contains($match[1], '`location`')) { return; }
            $fired = true;
            match ($mutation) {
                'source' => DB::table('hs_committees')->where('id', $this->committee->id)->update(['site_id' => $foreign->id]),
                'assignment' => DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['primary_site_id' => $foreign->id]),
                default => DB::table('permission_user')->where('user_id', $this->actor->id)->where('permission_id', Permission::where('key', 'hazards.manage')->sole()->id)->update(['allowed' => false]),
            };
        });
        // Built outside the arrow fn below: an arrow fn would capture $armed by value and never arm the listener.
        $callback = function ($current, $reads, $context, $proof, $descriptor, $source) use (&$armed): array {
            $armed = true;
            $this->withoutLocationHydration(fn () => (clone $source)->get(['id', 'location']));
            return [];
        };
        try {
            $this->refused(fn () => $this->sources->withManagerSource($this->actor, $this->meeting->id, $callback));
            $this->assertTrue($fired);
        } finally { $armed = false; }
    }

    public function test_nested_manager_discovery_reuses_original_context_collector_and_exact_descriptor(): void
    {
        $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.manage')->sole()->id => ['allowed' => true]]);
        $result = DB::transaction(fn () => $this->sources->withManagerSource($this->actor, $this->meeting->id,
            function ($current, $reads, $context, $proof, $descriptor): array {
                $this->assertSame($proof, HealthSafetyReadMaterialisationProof::current($context));
                $selected = $this->sources->candidateRecipients($this->meeting, true, $context, $descriptor);
                $this->assertSame($proof, HealthSafetyReadMaterialisationProof::current($context));
                return $selected;
            }));
        $this->assertSame([['recipient_id' => $this->actor->id, 'audience' => 'attendee']], $result);
    }

    public static function nestedDiscoveryMutations(): array { return [['role'], ['profile'], ['site'], ['location']]; }

    #[DataProvider('nestedDiscoveryMutations')]
    public function test_nested_discovery_actual_location_select_cannot_rebaseline_original_manager_or_source(string $mutation): void
    {
        $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.manage')->sole()->id => ['allowed' => true]]);
        $role = \App\Models\Role::create(['name' => 'late_nested_discovery_role', 'description' => 'Synthetic source equality role']);
        $armed = false; $fired = false; $hydrated = 0;
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsCommitteeMeeting::retrieved(function ($row) use (&$armed, &$hydrated): void { if ($armed && array_key_exists('location', $row->getAttributes())) { $hydrated++; } });
        DB::connection()->beforeExecuting(function ($sql) use ($mutation, $role, &$armed, &$fired): void {
            if (! $armed || $fired || ! preg_match('/^select\s+(.+?)\s+from\s+`hs_committee_meetings`/is', ltrim($sql), $match) || ! str_contains($match[1], '`location`')) { return; }
            $fired = true;
            match ($mutation) {
                'role' => DB::table('role_user')->insert(['role_id' => $role->id, 'user_id' => $this->actor->id]),
                'profile' => DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => false]),
                'site' => DB::table('sites')->where('id', $this->site->id)->update(['archived_at' => now()]),
                default => DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->update(['location' => 'Changed before nested location body']),
            };
        });
        // Built outside the arrow fns below: an arrow fn would capture $armed by value and never arm the listener.
        $callback = function ($current, $reads, $context, $proof, $descriptor) use (&$armed): array {
            $armed = true;
            return $this->sources->candidateRecipients($this->meeting, true, $context, $descriptor);
        };
        try {
            $this->refused(fn () => DB::transaction(fn () => $this->sources->withManagerSource($this->actor, $this->meeting->id, $callback)));
            $this->assertTrue($fired); $this->assertSame(0, $hydrated);
        } finally { $armed = false; Model::setEventDispatcher($dispatcher); }
        // Failed source command rolls back only its injected change; original healthy discovery remains functional.
        $this->assertSame('Original location', $this->meeting->fresh()->location);
        $this->assertSame([['recipient_id' => $this->actor->id, 'audience' => 'attendee']], DB::transaction(fn () => $this->sources->candidateRecipients($this->meeting, false)));
    }

    public function test_nested_discovery_requires_paired_live_original_context_descriptor_and_collector(): void
    {
        $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.manage')->sole()->id => ['allowed' => true]]);
        $escaped = null;
        HealthSafetyReadContext::within($this->actor, [...WorkerParticipationRecipientSourceService::PERMISSIONS, 'hazards.manage'], function ($context) use (&$escaped): array {
            $escaped = $context;
            $this->logic(fn () => $this->sources->candidateRecipients($this->meeting, false, $context, $this->descriptor()));
            return HealthSafetyReadMaterialisationProof::within($context, function () use ($context): array {
                $this->logic(fn () => $this->sources->candidateRecipients($this->meeting, false, $context));
                $this->logic(fn () => $this->sources->candidateRecipients($this->meeting, false, null, $this->descriptor()));
                return [];
            });
        });
        $this->logic(fn () => $this->sources->candidateRecipients($this->meeting, false, $escaped, $this->descriptor()));
    }

    public static function secondaryRepresentations(): array { return [['numeric'], ['decimal_string']]; }

    #[DataProvider('secondaryRepresentations')]
    public function test_current_secondary_assignment_representation_preserves_selection_and_live_recipient_source(string $representation): void
    {
        $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.manage')->sole()->id => ['allowed' => true]]);
        $recipient = $this->staff([]);
        $value = $representation === 'numeric' ? $this->site->id : (string) $this->site->id;
        $recipient->hrEmployeeProfile->update(['primary_site_id' => null, 'secondary_site_ids' => [$value]]);
        $this->assertSame([['recipient_id' => $this->actor->id, 'audience' => 'attendee'], ['recipient_id' => $recipient->id, 'audience' => 'workforce_notice']],
            DB::transaction(fn () => $this->sources->candidateRecipients($this->meeting)));
        $this->assertSame($this->descriptor(), $this->sources->withSource($recipient, $this->meeting->id, 'workforce_notice', $this->descriptor(),
            fn ($current, $reads, $context, $proof, $descriptor) => $descriptor));
        $recipient->hrEmployeeProfile->update(['secondary_site_ids' => [(string) $this->site->id.'x']]);
        $this->assertSame([['recipient_id' => $this->actor->id, 'audience' => 'attendee']], DB::transaction(fn () => $this->sources->candidateRecipients($this->meeting)));
        $this->withoutLocationHydration(fn () => $this->refused(fn () => $this->sources->withSource($recipient, $this->meeting->id, 'workforce_notice', $this->descriptor(), fn () => [])));
    }

    public function test_initial_hr_denial_creates_no_prepared_receiver_frame(): void
    {
        $this->assertFalse($this->sources->hasPreparedReceiver());
        $this->actor->hrEmployeeProfile->update(['is_active' => false]);
        $this->refused(fn () => $this->sources->prepareReceiver($this->actor));
        $this->assertFalse($this->sources->hasPreparedReceiver());
        $this->actor->hrEmployeeProfile->update(['is_active' => true]);
        $this->sources->prepareReceiver($this->actor);
        $this->assertTrue($this->sources->hasPreparedReceiver());
    }

    private function descriptor(): array
    {
        return ['meeting_id' => $this->meeting->id, 'committee_id' => $this->committee->id, 'site_id' => $this->site->id,
            'scheduled_at' => '2026-10-14 01:00:00', 'meeting_status' => 'scheduled', 'committee_status' => 'active', 'location' => 'Original location'];
    }

    private function withoutLocationHydration(callable $operation): void
    {
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher); $count = 0;
        HsCommitteeMeeting::retrieved(function ($row) use (&$count): void { if (array_key_exists('location', $row->getAttributes())) { $count++; } });
        try { $operation(); $this->assertSame(0, $count); } finally { Model::setEventDispatcher($dispatcher); }
    }

    private function logic(callable $operation): void
    {
        try { $operation(); $this->fail('Original receiver/context must refuse transfer.'); }
        catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
    }

    private function refused(callable $operation, int $status = 404): void
    {
        try { $operation(); $this->fail('Current original source must refuse.'); }
        catch (HttpExceptionInterface $error) { $this->assertSame($status, $error->getStatusCode()); }
    }
}
