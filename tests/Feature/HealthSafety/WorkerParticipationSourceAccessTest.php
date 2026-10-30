<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\HsConsultation;
use App\Models\HsRepresentative;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HealthSafetyReadMaterialisationProof;
use App\Services\HealthSafety\WorkerParticipationAccessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Actual canonical source SQL/relationships; whole native suite is prepared, UNRUN. */
final class WorkerParticipationSourceAccessTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;
    private Site $foreign;
    private User $actor;
    private User $historical;
    private array $graph;
    private WorkerParticipationAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-13T12:00:00+13:00')->utc());
        $this->seed(RbacSeeder::class);
        $this->service = app(WorkerParticipationAccessService::class);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->foreign = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->actor = $this->viewer(true, false);
        $this->historical = User::factory()->create(['name' => 'Recorded former representative', 'role' => 'support_worker', 'approved_at' => null]);
        $this->graph = $this->records($this->site);
    }

    public function test_approved_wide_reader_without_profile_reads_all_four_actual_families(): void
    {
        $this->assertSame(['representatives' => 1, 'committees' => 1, 'meetings' => 1, 'consultations' => 1], $this->counts());
        $this->assertDatabaseMissing('hr_employee_profiles', ['user_id' => $this->actor->id]);
    }

    public function test_inactive_profile_does_not_override_existing_explicit_wide_authority(): void
    {
        HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->foreign->id, 'is_active' => false]);
        $this->assertSame(1, $this->counts()['meetings']);
    }

    public function test_scoped_reader_counts_and_search_exclude_foreign_parent_bodies_and_names(): void
    {
        $other = $this->records($this->foreign);
        $this->actor = $this->viewer(false, true);
        $result = $this->read(function ($current, $reads, $context): array {
            $access = $this->access();
            return ['count' => $access->query('meetings', $context)->count(),
                'foreign' => $access->query('meetings', $context)->whereHas('committee', fn ($q) => $q->where('name', 'Foreign committee'))->get(['hs_committee_meetings.id'])->modelKeys(),
                'names' => $access->relatedQuery('meetings', 'attendees', $context)->pluck('users.name')->all()];
        });
        $this->assertSame(['count' => 1, 'foreign' => [], 'names' => [$this->historical->name]], $result);
        $this->assertSame($this->foreign->id, $other['committees']->site_id);
    }

    public function test_selected_foreign_site_and_foreign_raw_parent_id_have_no_qualified_rows(): void
    {
        $foreign = $this->records($this->foreign);
        $this->actor = $this->viewer(false, true);
        $result = $this->read(fn ($current, $reads, $context): array => [
            $this->access()->query('meetings', $context, $this->foreign->id)->count(),
            $this->access()->query('meetings', $context)->whereKey($foreign['meetings']->id)->get()->toArray(),
            $this->access()->relatedQuery('meetings', 'attendees', $context, null, $foreign['meetings']->id)->get(['users.id', 'users.name'])->toArray(),
        ]);
        $this->assertSame([0, [], []], $result);
    }

    public function test_initial_foreign_selected_scope_refuses_before_any_participation_metadata_or_body_query(): void
    {
        $this->actor = $this->viewer(false, true); $armed = true; $selects = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$selects): void {
            if ($armed && preg_match('/^select .* from `hs_(representatives|committees|committee_meetings|consultations)`/i', $sql)) { $selects++; }
        });
        try {
            $this->refused(fn () => $this->read(fn () => [], $this->foreign->id), 404);
            $this->assertSame(0, $selects);
        } finally { $armed = false; }
    }

    public function test_fixed_relationship_queries_keep_historical_names_and_exact_parent_membership(): void
    {
        $stranger = User::factory()->create(['name' => 'Unrelated person']);
        $result = $this->read(function ($current, $reads, $context) use ($stranger): array {
            $access = $this->access(); $out = [];
            foreach ([['representatives', 'user'], ['committees', 'members'], ['meetings', 'attendees'], ['consultations', 'workersConsulted']] as [$kind, $relation]) {
                $out[$relation] = $access->relatedQuery($kind, $relation, $context, $this->site->id, $this->graph[$kind]->id)->pluck('users.name')->all();
                $this->assertFalse($access->relatedQuery($kind, $relation, $context, $this->site->id, $this->graph[$kind]->id)->whereKey($stranger->id)->exists());
            }
            $out['committee'] = $access->relatedQuery('meetings', 'committee', $context, $this->site->id, $this->graph['meetings']->id)->pluck('hs_committees.id')->all();
            $out['site'] = $access->relatedQuery('meetings', 'site', $context, $this->site->id, $this->graph['meetings']->id)->pluck('sites.id')->all();
            $out['meeting_count'] = $access->relatedQuery('committees', 'meetings', $context, $this->site->id, $this->graph['committees']->id)->count();
            return $out;
        });
        foreach (['user', 'members', 'attendees', 'workersConsulted'] as $key) { $this->assertSame([$this->historical->name], $result[$key]); }
        $this->assertSame([$this->graph['committees']->id], $result['committee']);
        $this->assertSame([$this->site->id], $result['site']);
        $this->assertSame(1, $result['meeting_count']);
    }

    public function test_creator_initiator_recorder_and_updater_queries_are_parent_correlated(): void
    {
        DB::table('hs_committee_meetings')->where('id', $this->graph['meetings']->id)->update(['recorded_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        $result = $this->read(function ($current, $reads, $context): array {
            $out = [];
            foreach ([['representatives', 'creator'], ['consultations', 'initiator'], ['meetings', 'recorder'], ['meetings', 'updater']] as [$kind, $relation]) {
                $out[] = $this->access()->relatedQuery($kind, $relation, $context, $this->site->id, $this->graph[$kind]->id)->pluck('users.id')->all();
            }
            return $out;
        });
        $this->assertSame(array_fill(0, 4, [$this->actor->id]), $result);
    }

    public function test_current_creation_picker_excludes_former_foreign_and_unapproved_people_without_hiding_historical_attribution(): void
    {
        $eligible = $this->viewer(false, true);
        $outside = $this->viewer(false, true, $this->foreign);
        $result = $this->read(fn ($current, $reads, $context): array => [
            $this->access()->pickerUsers($context, $this->site->id)->pluck('users.id')->all(),
            $this->access()->relatedQuery('representatives', 'user', $context, $this->site->id)->pluck('users.id')->all(),
        ], null, true);
        $this->assertSame([$eligible->id], $result[0]);
        $this->assertNotContains($outside->id, $result[0]);
        $this->assertSame([$this->historical->id], $result[1]);
    }

    public function test_read_only_reader_cannot_use_management_picker(): void
    {
        $this->grant($this->actor, 'hazards.manage', false);
        $this->refused(fn () => $this->read(fn ($current, $reads, $context): array => $this->access()->pickerUsers($context, $this->site->id)->pluck('id')->all(), null, true), 403);
        $this->assertSame(1, $this->counts()['representatives']);
    }

    public function test_picker_subjects_cannot_be_declared_late_after_body_materialisation(): void
    {
        $this->read(function ($current, $reads, $context): array {
            $this->access()->query('meetings', $context)->get()->toArray();
            $this->logic(fn () => $this->access()->pickerUsers($context)->get());
            $this->logic(fn () => $this->access()->sitesQuery($context)->get());
            return [];
        }, $this->site->id);
    }

    public function test_initial_denial_precedes_any_participation_source_select_and_warmed_false_grant_restores(): void
    {
        $this->actor->load(['roles.permissions', 'permissionOverrides']);
        $this->grant($this->actor, 'hazards.view', false);
        $sourceSelects = 0; $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$sourceSelects, &$armed): void {
            if ($armed && preg_match('/^select .* from `hs_(representatives|committees|committee_meetings|consultations)`/i', $sql)) { $sourceSelects++; }
        });
        try { $this->refused(fn () => $this->counts(), 403); $this->assertSame(0, $sourceSelects); }
        finally { $armed = false; }
        $this->grant($this->actor, 'hazards.view', true);
        $this->assertSame(1, $this->counts()['consultations']);
    }

    public static function rootChanges(): array
    {
        return [['representatives', 'site_id'], ['committees', 'site_id'], ['meetings', 'hs_committee_id'], ['consultations', 'site_id']];
    }

    #[DataProvider('rootChanges')]
    public function test_original_parent_reassociation_at_actual_first_body_select_prevents_hydration_and_restores(string $kind, string $column): void
    {
        $foreign = $this->records($this->foreign);
        $root = $this->graph[$kind]; $old = $root->$column;
        $new = $column === 'hs_committee_id' ? $foreign['committees']->id : $this->foreign->id;
        $this->atSql($root->getTable(), '*', fn () => DB::table($root->getTable())->where('id', $root->id)->update([$column => $new]),
            fn () => $this->refused(fn () => $this->read(fn ($current, $reads, $context): array => $this->access()->query($kind, $context, $this->site->id)->get()->toArray()), 404));
        DB::table($root->getTable())->where('id', $root->id)->update([$column => $old]);
        $this->assertSame(1, $this->counts()[$kind]);
    }

    public static function evidenceChanges(): array { return array_map(fn ($key) => [$key], ['grant', 'approval', 'external', 'profile', 'site', 'role']); }

    #[DataProvider('evidenceChanges')]
    public function test_original_actor_and_site_withdrawal_at_actual_aggregate_is_unavailable_not_zero(string $change): void
    {
        $this->actor = $this->viewer(false, true);
        $mutate = function () use ($change): void {
            match ($change) {
                'grant' => $this->grant($this->actor, 'hazards.view', false),
                'approval' => DB::table('users')->where('id', $this->actor->id)->update(['approved_at' => null]),
                'external' => DB::table('users')->where('id', $this->actor->id)->update(['external_clinical_account' => true]),
                'profile' => DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['end_date' => '2026-10-01']),
                'site' => DB::table('sites')->where('id', $this->site->id)->update(['archived' => true]),
                'role' => DB::table('users')->where('id', $this->actor->id)->update(['role' => 'client']),
            };
        };
        $this->atSql('hs_committee_meetings', 'count(*)', $mutate,
            fn () => $this->refused(fn () => $this->counts(), 404));
        $this->grant($this->actor, 'hazards.view', true);
        DB::table('users')->where('id', $this->actor->id)->update(['approved_at' => $this->actor->approved_at, 'external_clinical_account' => false, 'role' => 'support_worker']);
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['end_date' => null]);
        DB::table('sites')->where('id', $this->site->id)->update(['archived' => false]);
        $this->assertSame(1, $this->counts()['meetings']);
    }

    public function test_late_attendee_membership_insertion_before_names_cannot_hydrate_or_become_baseline(): void
    {
        $other = User::factory()->create(['name' => 'Late private attendee']);
        $this->atSql('users', 'users`.`name', fn () => DB::table('hs_meeting_attendees')->insert(['meeting_id' => $this->graph['meetings']->id, 'user_id' => $other->id, 'response' => 'invited', 'attended' => false]),
            fn () => $this->refused(fn () => $this->read(fn ($current, $reads, $context): array => $this->access()->relatedQuery('meetings', 'attendees', $context, $this->site->id)->get(['users.id', 'users.name'])->toArray()), 404));
    }

    public function test_supporting_committee_site_change_before_attendee_names_prevents_name_hydration(): void
    {
        $this->atSql('users', 'users`.`name', fn () => DB::table('hs_committees')->where('id', $this->graph['committees']->id)->update(['site_id' => $this->foreign->id]),
            fn () => $this->refused(fn () => $this->read(fn ($current, $reads, $context): array => $this->access()->relatedQuery('meetings', 'attendees', $context, $this->site->id)->get(['users.id', 'users.name'])->toArray()), 404));
    }

    public function test_new_root_after_metadata_before_count_invalidates_full_source_universe(): void
    {
        $this->atSql('hs_committee_meetings', 'count(*)', fn () => HsConsultation::create(['title' => 'New source', 'consultation_type' => 'procedure_change', 'description' => 'New private body', 'site_id' => $this->site->id, 'initiated_by' => $this->actor->id, 'consultation_date' => now(), 'status' => 'open']),
            fn () => $this->refused(fn () => $this->counts(), 404));
    }

    public function test_same_id_pivot_response_change_before_count_is_not_an_unchanged_identity_certificate(): void
    {
        $this->atSql('hs_committee_meetings', 'count(*)', fn () => DB::table('hs_meeting_attendees')->where('meeting_id', $this->graph['meetings']->id)->update(['response' => 'declined']),
            fn () => $this->refused(fn () => $this->counts(), 404));
    }

    public function test_final_body_or_name_mutation_after_materialisation_is_refused_by_outer_collector(): void
    {
        $this->refused(fn () => $this->read(function ($current, $reads, $context): array {
            $rows = $this->access()->relatedQuery('representatives', 'user', $context, $this->site->id)->get(['users.id', 'users.name'])->toArray();
            DB::table('users')->where('id', $this->historical->id)->update(['name' => 'Changed after read']);
            return $rows;
        }), 404);
    }

    public function test_full_source_stamp_is_deterministic_across_fresh_contexts_and_changes_with_pivot_facts(): void
    {
        $stamp = fn () => $this->read(fn ($current, $reads, $context): array => [$this->access()->sourceStamp($context, $this->site->id)])[0];
        $first = $stamp(); $this->assertSame($first, $stamp());
        DB::table('hs_meeting_attendees')->where('meeting_id', $this->graph['meetings']->id)->update(['attended' => true]);
        $this->assertNotSame($first, $stamp());
        DB::table('hs_meeting_attendees')->where('meeting_id', $this->graph['meetings']->id)->update(['attended' => false]);
        $this->assertSame($first, $stamp());
    }

    public function test_exact_prepared_receiver_reads_and_collector_are_required(): void
    {
        $this->read(function ($current, $reads, $context, $proof): array {
            $access = $this->access();
            $this->logic(fn () => $access->protect(HsRepresentative::query(), 'representatives', $context, clone $current, $reads, $proof));
            $this->logic(fn () => $access->protect(HsRepresentative::query(), 'representatives', $context, $current, $reads, new HealthSafetyReadMaterialisationProof($context)));
            CurrentAuthorizationReads::within(fn ($other) => $this->logic(fn () => $access->protect(HsRepresentative::query(), 'representatives', $context, $current, $other, $proof)));
            $this->logic(fn () => $access->protect(User::query(), 'representatives', $context, $current, $reads, $proof));
            return [];
        });
    }

    public function test_another_live_actor_cannot_receive_an_original_frame_even_before_original_context_expires(): void
    {
        $other = $this->viewer(true, false);
        $this->read(function ($current, $reads, $context, $proof) use ($other): array {
            HealthSafetyReadContext::within($other, WorkerParticipationAccessService::PERMISSIONS, function ($receiverContext) use ($context, $proof): array {
                $receiverContext->consumeSourceViewer(WorkerParticipationAccessService::PERMISSIONS, function ($receiver, $receiverReads) use ($context, $proof): void {
                    $this->logic(fn () => $this->access()->protect(HsRepresentative::query(), 'representatives', $context, $receiver, $receiverReads, $proof));
                });
                return [];
            });
            return [];
        });
    }

    public function test_retained_metadata_resolves_exact_meeting_site_without_loading_path_name_or_minutes(): void
    {
        $siteIds = $this->read(function ($current, $reads, $context): array {
            return [$this->access()->sourceSiteId('meetings', $context, $this->graph['meetings']->id),
                $this->access()->sourceSiteId('consultations', $context, $this->graph['consultations']->id)];
        });
        $this->assertSame([$this->site->id, $this->site->id], $siteIds);
        $this->actor = $this->viewer(false, true);
        $foreign = $this->records($this->foreign);
        $this->refused(fn () => $this->read(fn ($current, $reads, $context): array => [$this->access()->sourceSiteId('meetings', $context, $foreign['meetings']->id)]), 404);
    }

    public function test_scoped_profile_withdrawal_during_initial_preparation_never_becomes_a_zero_source_baseline(): void
    {
        $this->actor = $this->viewer(false, true);
        $armed = true; $checked = false; $fired = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$checked, &$fired): void {
            if (! $armed || $fired) { return; }
            $sql = strtolower(ltrim($sql));
            if (str_starts_with($sql, 'select exists(') && str_contains($sql, '`hr_employee_profiles`')) { $checked = true; }
            if ($checked && str_starts_with($sql, 'select * from `hr_employee_profiles`')) {
                $fired = true;
                DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => false]);
            }
        });
        try { $this->refused(fn () => $this->counts(), 404); $this->assertTrue($fired); }
        finally { $armed = false; }
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => true]);
        $this->assertSame(1, $this->counts()['meetings']);
    }

    public function test_expired_source_builder_cannot_execute_directly_or_embed_under_another_fresh_viewer(): void
    {
        $escaped = null;
        $this->read(function ($current, $reads, $context) use (&$escaped): array { $escaped = $this->access()->query('meetings', $context, $this->site->id); return []; });
        $this->logic(fn () => $escaped->get());
        $other = $this->viewer(true, false);
        HealthSafetyReadContext::within($other, WorkerParticipationAccessService::PERMISSIONS, function ($context) use ($escaped): array {
            $context->consumeSourceViewer(WorkerParticipationAccessService::PERMISSIONS, function ($current, $reads) use ($escaped): void {
                $this->logic(fn () => $reads->query(User::query())->whereExists((clone $escaped)->selectRaw('1')->toBase())->get());
            });
            return [];
        });
    }

    public function test_collector_lifetime_cannot_be_replaced_inside_still_active_context(): void
    {
        $access = $this->access();
        HealthSafetyReadContext::within($this->actor, WorkerParticipationAccessService::PERMISSIONS, function ($context) use ($access): array {
            HealthSafetyReadMaterialisationProof::within($context, fn () => $access->prepare($context));
            HealthSafetyReadMaterialisationProof::within($context, fn () => $this->logic(fn () => $access->query('meetings', $context)));
            return [];
        });
    }

    public function test_unprepared_and_wrong_viewer_contexts_do_not_refresh_or_prepare_new_authority(): void
    {
        HealthSafetyReadContext::within($this->actor, ['hazards.view'], fn ($context) => $this->logic(fn () => $this->access()->materialize($this->actor, fn () => [], $context)));
        $other = $this->viewer(true, false);
        HealthSafetyReadContext::within($other, WorkerParticipationAccessService::PERMISSIONS, fn ($context) => $this->logic(fn () => $this->access()->materialize($this->actor, fn () => [], $context)));
    }

    public function test_materializer_does_not_allow_models_builders_or_closures_to_escape(): void
    {
        foreach ([fn ($context) => $this->access()->query('meetings', $context), fn () => $this->graph['meetings'], fn () => fn () => null] as $value) {
            $this->logic(fn () => $this->read(fn ($current, $reads, $context): array => [$value($context)]));
        }
    }

    public function test_original_timezone_change_before_body_query_cannot_rebaseline_prepared_evidence(): void
    {
        $old = config('app.worker_timezone');
        $resolved = (string) ($old ?: config('app.timezone', 'UTC'));
        try {
            $this->read(function ($current, $reads, $context) use ($old, $resolved): array {
                $query = $this->access()->query('meetings', $context, $this->site->id);
                config(['app.worker_timezone' => $resolved === 'UTC' ? 'Pacific/Auckland' : 'UTC']);
                $this->logic(fn () => $query->get());
                config(['app.worker_timezone' => $old]);
                return [];
            });
        } finally { config(['app.worker_timezone' => $old]); }
    }

    public function test_selected_source_universe_does_not_freeze_unrelated_other_site_records(): void
    {
        $other = $this->records($this->foreign);
        $this->atSql('hs_committee_meetings', 'count(*)', fn () => DB::table('hs_committee_meetings')->where('id', $other['meetings']->id)->update(['recorded_by' => $this->historical->id]),
            fn () => $this->assertSame(1, $this->counts()['meetings']));
    }

    public function test_exact_root_mode_ignores_other_same_site_meeting_metadata_and_has_no_other_family_universe(): void
    {
        $other = $this->graph['committees']->meetings()->create(['scheduled_at' => now(), 'status' => 'scheduled', 'minutes' => 'Other private body']);
        $this->atSql('hs_committee_meetings', '*', fn () => DB::table('hs_committee_meetings')->where('id', $other->id)->update(['recorded_by' => $this->historical->id]), function (): void {
            $rows = $this->access()->materializeSource($this->actor, 'meetings', $this->graph['meetings']->id, fn ($current, $reads, $context): array => $this->access()->query('meetings', $context)->get()->toArray());
            $this->assertCount(1, $rows); $this->assertSame('Private meeting body', $rows[0]['minutes']);
        }, false);
    }

    public function test_root_stamp_for_original_no_site_url_uses_only_that_root_and_exact_support(): void
    {
        $other = $this->records($this->foreign);
        $stamp = fn () => $this->access()->materializeSource($this->actor, 'meetings', $this->graph['meetings']->id, function ($current, $reads, $context): array {
            $site = $this->access()->sourceSiteId('meetings', $context, $this->graph['meetings']->id);
            return [$this->access()->sourceStamp($context, $site)];
        })[0];
        $first = $stamp();
        DB::table('hs_committee_meetings')->where('id', $other['meetings']->id)->update(['recorded_by' => $this->historical->id]);
        $this->assertSame($first, $stamp());
    }

    public function test_human_approved_null_ownership_is_wide_only_unconfirmed_and_excluded_by_selected_site(): void
    {
        $null = $this->records(null);
        $wide = $this->read(function ($current, $reads, $context) use ($null): array {
            $out = [];
            foreach (array_keys($null) as $kind) {
                $out[$kind] = [$this->access()->query($kind, $context)->count(), $this->access()->sourceOwnership($kind, $context, $null[$kind]->id)];
            }
            return $out;
        });
        foreach ($wide as $result) { $this->assertSame([2, 'unconfirmed'], $result); }
        $this->assertSame(1, $this->counts()['meetings']);
        $this->actor = $this->viewer(false, true);
        $this->assertSame(1, $this->counts()['meetings']);
        $this->refused(fn () => $this->access()->materializeSource($this->actor, 'meetings', $null['meetings']->id, fn () => []), 404);
    }

    public function test_unreadable_null_sources_do_not_enter_nonwide_metadata_stamp_or_block_healthy_source_counts(): void
    {
        $this->actor = $this->viewer(false, true);
        $stamp = fn () => $this->read(fn ($current, $reads, $context): array => [$this->access()->sourceStamp($context)])[0];
        $before = $stamp(); $this->records(null);
        $this->assertSame($before, $stamp());
        $this->assertSame(1, $this->counts()['consultations']);
    }

    public function test_eligible_subject_withdrawal_after_register_body_cannot_be_reprepared_before_picker_names(): void
    {
        $eligible = $this->viewer(false, true);
        $this->atSql('users', 'users`.`name', fn () => DB::table('hr_employee_profiles')->where('user_id', $eligible->id)->update(['is_active' => false]),
            fn () => $this->refused(fn () => $this->read(function ($current, $reads, $context): array {
                $this->access()->query('meetings', $context)->get()->toArray();
                return $this->access()->pickerUsers($context)->get()->toArray();
            }, $this->site->id, true), 404));
        DB::table('hr_employee_profiles')->where('user_id', $eligible->id)->update(['is_active' => true]);
        $this->assertSame([$eligible->id], $this->read(fn ($current, $reads, $context): array => $this->access()->pickerUsers($context)->pluck('users.id')->all(), $this->site->id, true));
    }

    public function test_selected_register_keeps_separate_all_approved_creation_choices_and_counts(): void
    {
        $other = $this->records($this->foreign); $worker = $this->viewer(false, true, $this->foreign);
        $result = $this->read(fn ($current, $reads, $context): array => [
            'count' => $this->access()->query('meetings', $context)->count(),
            'committees' => $this->access()->committeeChoices($context),
            'staff' => $this->access()->pickerUsers($context)->pluck('users.id')->all(),
            'sites' => $this->access()->sitesQuery($context)->pluck('sites.id')->all(),
        ], $this->site->id, true);
        $this->assertSame(1, $result['count']);
        $this->assertEqualsCanonicalizing([$this->graph['committees']->id, $other['committees']->id], array_column($result['committees'], 'id'));
        $this->assertSame([1, 1], array_column($result['committees'], 'meetings_count'));
        $this->assertSame([$worker->id], $result['staff']);
        $this->assertEqualsCanonicalizing([$this->site->id, $this->foreign->id], $result['sites']);
    }

    public function test_late_choice_parent_site_rebind_before_name_body_refuses_instead_of_recapturing_choice(): void
    {
        $other = $this->records($this->foreign);
        $this->atSql('hs_committees', 'hs_committees`.`name', fn () => DB::table('hs_committees')->where('id', $other['committees']->id)->update(['site_id' => null]),
            fn () => $this->refused(fn () => $this->read(fn ($current, $reads, $context): array => $this->access()->committeeChoices($context), $this->site->id, true), 404));
    }

    public function test_attached_role_and_role_grant_changes_are_original_stamp_operands_with_fresh_recovery(): void
    {
        $role = \App\Models\Role::where('name', 'health_safety_officer')->sole();
        $this->actor->roles()->attach($role);
        $permission = Permission::where('key', 'hazards.view')->sole();
        $stamp = fn () => $this->read(fn ($current, $reads, $context): array => [$this->access()->sourceStamp($context, $this->site->id)], $this->site->id)[0];
        $first = $stamp(); $this->assertSame($first, $stamp());
        $role->permissions()->detach($permission->id);
        $this->assertNotSame($first, $stamp());
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $this->assertSame($first, $stamp());
    }

    private function access(): WorkerParticipationAccessService { return $this->service; }

    private function read(callable $operation, ?int $selectedSiteId = null, bool $includePickers = false): array { return $this->access()->materialize($this->actor, $operation, null, $selectedSiteId, $includePickers); }

    private function counts(): array
    {
        return $this->read(function ($current, $reads, $context): array {
            $out = []; $access = $this->access();
            foreach (['representatives', 'committees', 'meetings', 'consultations'] as $kind) { $out[$kind] = $access->query($kind, $context, $this->site->id)->count(); }
            return $out;
        }, $this->site->id);
    }

    private function viewer(bool $wide, bool $profile, ?Site $site = null): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        if ($profile) { HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2026-01-01', 'end_date' => null]); }
        foreach (WorkerParticipationAccessService::PERMISSIONS as $key) { $this->grant($user, $key, $key !== 'healthSafety.viewAllSites' || $wide); }
        return $user;
    }

    private function grant(User $user, string $key, bool $allowed): void
    {
        $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }

    private function records(?Site $site): array
    {
        $committee = HsCommittee::create(['name' => $site === null ? 'Unconfirmed ownership committee' : ($site->id === $this->site->id ? 'Safety committee' : 'Foreign committee'), 'site_id' => $site?->id, 'meeting_frequency' => 'monthly', 'established_at' => '2026-01-01', 'status' => 'active', 'members' => [$this->historical->id], 'created_by' => $this->actor->id]);
        $meeting = $committee->meetings()->create(['scheduled_at' => now(), 'status' => 'scheduled', 'minutes' => 'Private meeting body', 'created_by' => $this->actor->id]);
        $meeting->attendeeUsers()->attach($this->historical->id, ['response' => 'invited', 'attended' => false]);
        return ['committees' => $committee, 'meetings' => $meeting,
            'representatives' => HsRepresentative::create(['user_id' => $this->historical->id, 'site_id' => $site?->id, 'election_method' => 'elected', 'elected_at' => '2026-01-01', 'status' => 'active', 'notes' => 'Private representative body', 'created_by' => $this->actor->id]),
            'consultations' => HsConsultation::create(['title' => 'Source consultation', 'consultation_type' => 'procedure_change', 'description' => 'Private consultation body', 'site_id' => $site?->id, 'initiated_by' => $this->actor->id, 'consultation_date' => '2026-10-13', 'workers_consulted' => [$this->historical->id], 'status' => 'open', 'created_by' => $this->actor->id])];
    }

    /** Real connection interleaving plus actual retrieved-body observation, never substituted query results. */
    private function atSql(string $table, string $columns, callable $mutation, callable $read, bool $deniedHydration = true): void
    {
        $armed = true; $fired = false; $hydrated = 0;
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        foreach ([HsRepresentative::class => 'notes', HsCommittee::class => 'name', HsCommitteeMeeting::class => 'minutes', HsConsultation::class => 'description', User::class => 'name'] as $class => $body) {
            $class::retrieved(function (Model $row) use (&$armed, &$fired, &$hydrated, $body): void {
                if ($armed && $fired && array_key_exists($body, $row->getAttributes())) { $hydrated++; }
            });
        }
        DB::connection()->beforeExecuting(function (string $sql) use ($table, $columns, $mutation, &$armed, &$fired): void {
            $sql = strtolower(ltrim($sql));
            if ($armed && ! $fired && preg_match('/^select\s+(.+?)\s+from\s+`([^`]+)`/s', $sql, $match)
                && $match[2] === $table && str_contains($match[1], $columns)) { $fired = true; $mutation(); }
        });
        try { $read(); $this->assertTrue($fired, 'The actual consuming source/body/name SELECT must fire.'); if ($deniedHydration) { $this->assertSame(0, $hydrated, 'Denied private bodies/names must not hydrate.'); } }
        finally { $armed = false; Model::setEventDispatcher($dispatcher); }
    }

    private function refused(callable $operation, int $status): void
    {
        try { $operation(); $this->fail('Canonical source refusal was required.'); }
        catch (HttpExceptionInterface $error) { $this->assertSame($status, $error->getStatusCode()); }
    }

    private function logic(callable $operation): array
    {
        try { $operation(); $this->fail('Original source lifetime/receiver refusal was required.'); }
        catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
        return [];
    }
}
