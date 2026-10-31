<?php

namespace Tests\Feature\HealthSafety;

use App\Models\BehaviourSupportPlan;
use App\Models\Client;
use App\Models\FirstAidRecord;
use App\Models\Permission;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\FirstAidMetricsService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HealthSafetyReadMaterialisationProof;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\RestraintKpiService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Complete native suite; actual sources/queries, no source-policy substitutes. */
class HsSourceMetricsPrivacyTest extends RestraintEventCommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->grant('hazards.view', true);
    }

    public function test_authorised_nonincident_sources_keep_real_counts_and_worklist_fields(): void
    {
        $event = $this->event();
        $this->treatment(['ambulance_called' => true, 'treatment_outcome' => 'sent_to_hospital']);
        $this->assertSame(['treatments' => 1, 'ambulance' => 1, 'hospital' => 1], $this->firstAid()['activity']);
        $summary = $this->restraints();
        $this->assertSame(1, $summary['events_in_period']);
        $this->assertSame(1, $summary['unreviewed']);
        $this->assertSame(1, $summary['clients_no_active_bsp']);
        $row = $this->worklist()[0];
        $this->assertSame($event->id, $row['id']);
        $this->assertSame(trim($this->client->first_name.' '.$this->client->last_name), $row['client']);
        $this->assertSame('physical', $row['restraint_type']);
        $this->assertSame($event->started_at->toIso8601String(), $row['started_at']);
        $this->assertArrayNotHasKey('restraint_description', $row);
    }

    public function test_actorless_metrics_withhold_totals_and_do_not_query_private_sources(): void
    {
        $this->event(); $this->treatment();
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $firstAid = app(FirstAidMetricsService::class)->forViewer(null, now()->subDay(), now());
            $restraints = app(RestraintKpiService::class)->summary(null, now()->subDay(), now());
            $this->assertNull($firstAid['activity']['treatments']);
            $this->assertNull($restraints['events_in_period']);
            $this->assertNull(app(RestraintKpiService::class)->unreviewedWorklist(null));
            $this->assertSame('restricted', $firstAid['source_access']);
            $this->assertSame([], DB::getQueryLog());
        } finally { DB::disableQueryLog(); }
    }

    public function test_hazards_grant_does_not_substitute_for_restraint_read_or_expose_client_worklist(): void
    {
        $this->event(); $this->revoke($this->actor, 'restraints.view');
        [$result, $queries] = $this->queries(fn () => [$this->restraints(), $this->worklist(), $this->breakdowns()]);
        $this->assertNull($result[0]['events_in_period']);
        $this->assertNull($result[1]);
        $this->assertNull($result[2]['by_type']);
        $this->assertNoSourceSelect($queries, 'restraint_events');
        $this->assertNoSourceSelect($queries, 'behaviour_support_plans');
    }

    public function test_restraint_grant_does_not_substitute_for_first_aid_read(): void
    {
        $this->treatment(); $this->grant('hazards.view', false);
        [$result, $queries] = $this->queries(fn () => $this->firstAid());
        $this->assertSame(['treatments' => null, 'ambulance' => null, 'hospital' => null], $result['activity']);
        $this->assertNull($result['by_type']);
        $this->assertNoSourceSelect($queries, 'first_aid_records');
    }

    public function test_warmed_grant_withdrawal_and_restoration_use_current_actor_for_both_families(): void
    {
        $this->event(); $this->treatment();
        $this->actor->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->assertTrue($this->actor->canDo('restraints.view'));
        $this->assertTrue($this->actor->canDo('hazards.view'));
        $this->grant('restraints.view', false); $this->grant('hazards.view', false);
        $this->assertNull($this->restraints()['events_in_period']);
        $this->assertNull($this->firstAid()['activity']['treatments']);
        $this->grant('restraints.view', true); $this->grant('hazards.view', true);
        $this->assertSame(1, $this->restraints()['events_in_period']);
        $this->assertSame(1, $this->firstAid()['activity']['treatments']);
        $this->within(function ($context): array {
            // HR/Site preparation has completed, but the First Aid witness is still lazy.
            $context->consumeViewer(FirstAidAccessService::PERMISSIONS, static fn () => null);
            DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => false]);
            $result = $this->firstAid(null, $context);
            $this->assertSame('unavailable', $result['source_access']);
            $this->assertNull($result['activity']['treatments']);
            DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => true]);
            return [];
        });
        $access = app(FirstAidAccessService::class);
        $this->sourceChanged(fn () => $access->withCurrentViewer($this->actor,
            function ($current, $reads, $sites) use ($access): array {
                DB::table('hr_employee_profiles')->where('user_id', $current->id)->update(['is_active' => false]);
                return $access->readableQuery($current, $reads, $sites)->pluck('id')->all();
            }));
    }

    public function test_current_hr_withdrawal_does_not_manufacture_healthy_zero_and_restore_is_readable(): void
    {
        $this->event(); $this->treatment();
        $this->actor->load('hrEmployeeProfile');
        $this->actor->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertNull($this->restraints()['events_in_period']);
        $this->assertNull($this->firstAid()['activity']['treatments']);
        $this->actor->hrEmployeeProfile()->update(['is_active' => true]);
        $this->assertSame(1, $this->restraints()['events_in_period']);
        $this->assertSame(1, $this->firstAid()['activity']['treatments']);
    }

    public function test_requested_sites_are_intersections_and_empty_authorised_population_is_real_zero(): void
    {
        $this->event(); $this->treatment();
        $foreign = Site::factory()->create(['is_active' => true]);
        $this->assertSame(0, $this->restraints([])['events_in_period']);
        $this->assertSame(0, $this->restraints($foreign->id)['events_in_period']);
        $this->assertSame([], $this->worklist([]));
        $this->assertSame(0, $this->firstAid([])['activity']['treatments']);
        $this->assertSame(0, $this->firstAid($foreign->id)['activity']['treatments']);
        $this->assertSame('readable', $this->restraints([])['source_access']);
    }

    public function test_current_site_reassignment_restricts_original_sources_and_restore_keeps_counts(): void
    {
        $this->event(); $this->treatment();
        $foreign = Site::factory()->create(['is_active' => true]);
        $this->grant('healthSafety.viewAllSites', false);
        $this->grant('clinical.accessAllSites', false); $this->grant('sites.viewAll', false);
        $this->actor->hrEmployeeProfile()->update(['primary_site_id' => $foreign->id, 'secondary_site_ids' => []]);
        $this->assertSame(0, $this->restraints($this->site->id)['events_in_period']);
        $this->assertSame(0, $this->firstAid($this->site->id)['activity']['treatments']);
        $this->actor->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
        $this->assertSame(1, $this->restraints()['events_in_period']);
        $this->assertSame(1, $this->firstAid()['activity']['treatments']);
    }

    public function test_unassigned_client_sources_and_plans_do_not_contribute_to_readable_population(): void
    {
        $visible = $this->event();
        $privateClient = Client::factory()->create(['site_id' => $this->site->id]);
        $this->event(['client_id' => $privateClient->id]);
        $this->plan($privateClient);
        $this->client->supportWorkers()->attach($this->actor->id);
        $this->grant('clients.viewAny', false); $this->grant('clients.viewAssigned', true);
        $this->assertSame(1, $this->restraints()['events_in_period']);
        $this->assertSame(0, $this->restraints()['active_plans']);
        $this->assertSame([$visible->id], array_column($this->worklist(), 'id'));
        $this->assertStringNotContainsString($privateClient->first_name, json_encode($this->worklist(), JSON_THROW_ON_ERROR));
    }

    public function test_malformed_typed_record_is_withheld_instead_of_becoming_zero(): void
    {
        $event = $this->event();
        DB::table('restraint_events')->where('id', $event->id)->update(['recorded_client_id' => $this->client->id + 1000]);
        $this->assertNull($this->restraints()['events_in_period']);
        $this->assertNull($this->worklist());
        $this->assertNull($this->breakdowns()['by_type']);
    }

    public function test_legacy_null_site_requires_existing_named_wide_grant_and_explicit_site_filter_excludes_it(): void
    {
        $legacy = RestraintEvent::factory()->make(['site_id' => $this->site->id, 'client_id' => $this->client->id,
            'stay_id' => null, 'source_schema_version' => null, 'started_at' => now()->subHour(), 'behaviour_support_plan_id' => null]);
        // Factory.afterMaking canonicalises Site from Client; persist genuine
        // legacy null only after that callback, before any typed writer/proof.
        $legacy->site_id = null;
        $legacy->saveQuietly();
        $this->grant('healthSafety.viewAllSites', false);
        $this->assertSame(0, $this->restraints()['events_in_period']);
        $this->grant('healthSafety.viewAllSites', true);
        $this->assertSame([$legacy->id], array_column($this->worklist(), 'id'));
        $this->assertSame(0, $this->restraints($this->site->id)['events_in_period']);
    }

    public function test_period_is_inclusive_but_standing_unreviewed_metric_is_not_period_filtered(): void
    {
        $from = now()->subHours(3); $to = now()->subHour();
        $this->event(['started_at' => $from->toIso8601String(), 'ended_at' => $from->copy()->addMinute()->toIso8601String()]);
        $this->event(['started_at' => $to->toIso8601String(), 'ended_at' => $to->copy()->addMinute()->toIso8601String()]);
        $this->event(['started_at' => $from->copy()->subSecond()->toIso8601String(), 'ended_at' => $from->copy()->addMinute()->toIso8601String()]);
        $this->treatment(['treatment_date' => $from]); $this->treatment(['treatment_date' => $to]);
        $this->treatment(['treatment_date' => $from->copy()->subSecond()]);
        $summary = app(RestraintKpiService::class)->summary(null, $from, $to, $this->actor);
        $this->assertSame(2, $summary['events_in_period']);
        $this->assertSame(3, $summary['unreviewed']);
        $this->assertSame(2, app(FirstAidMetricsService::class)->forViewer($this->actor, $from, $to)['activity']['treatments']);
    }

    public function test_plan_and_no_active_plan_metrics_preserve_unique_clients_and_old_status_semantics(): void
    {
        $this->event(); $this->event();
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $this->event(['client_id' => $other->id]);
        $this->plan($other, ['review_date' => now()->addDays(10)]);
        $this->plan($this->client, ['status' => 'under_review', 'review_date' => now()->subDay()]);
        $summary = $this->restraints();
        $this->assertSame(1, $summary['active_plans']);
        $this->assertSame(2, $summary['plans_review_due']);
        $this->assertSame(1, $summary['clients_no_active_bsp']);
        // The breakdown orders by count only, so equal counts have no defined order: compare by label.
        $byStatus = array_column($this->breakdowns()['by_plan_status'], 'count', 'label'); ksort($byStatus);
        $this->assertSame(['active' => 1, 'under_review' => 1], $byStatus);
    }

    public function test_worklist_limit_order_and_zero_limit_are_preserved(): void
    {
        $old = $this->event(['started_at' => now()->subHours(4)->toIso8601String(), 'ended_at' => now()->subHours(3)->toIso8601String()]);
        $recent = $this->event();
        $this->assertSame([$recent->id], array_column(app(RestraintKpiService::class)->unreviewedWorklist(null, 1, $this->actor), 'id'));
        $this->assertSame([$recent->id, $old->id], array_column($this->worklist(), 'id'));
        $this->assertSame([], app(RestraintKpiService::class)->unreviewedWorklist(null, 0, $this->actor));
    }

    public function test_supplied_context_matches_default_without_nested_viewer_refresh(): void
    {
        $this->event(); $this->treatment();
        $expected = [$this->firstAid(), $this->restraints(), $this->worklist(), $this->breakdowns()];
        $viewerReads = 0;
        $dispatcher = User::getEventDispatcher(); User::setEventDispatcher(clone $dispatcher);
        // A refresh reloads the viewer's evidence. Each restraint metric's authority guard
        // (RestraintKpiService::sourceQueries) selects only users.id to prove the same one row: that is not a refresh.
        User::retrieved(function (User $row) use (&$viewerReads): void {
            if ((int) $row->id === (int) $this->actor->id && array_keys($row->getAttributes()) !== ['id']) { $viewerReads++; }
        });
        try {
            $result = $this->within(function ($context) use (&$viewerReads): array {
                $viewerReads = 0; // The one outer actor preparation is legitimate.
                return HealthSafetyReadMaterialisationProof::within($context, fn () => [
                    $this->firstAid(null, $context), $this->restraints(null, $context), $this->worklist(null, $context), $this->breakdowns(null, $context),
                ]);
            });
            $this->assertSame($expected, $result);
            $this->assertSame(0, $viewerReads);
        } finally { User::setEventDispatcher($dispatcher); }
    }

    public function test_wrong_viewer_is_an_error_without_silent_refresh(): void
    {
        $other = $this->staff(['hazards.view']);
        $this->expectException(LogicException::class);
        $this->within(fn ($context) => app(FirstAidMetricsService::class)->forViewer($other, now()->subDay(), now(), null, $context));
    }

    public function test_missing_preparation_is_an_error_not_an_unavailable_success(): void
    {
        $this->expectException(LogicException::class);
        HealthSafetyReadContext::within($this->actor, [], fn ($context) => $this->restraints(null, $context));
    }

    public function test_expired_context_cannot_be_reused_for_metrics(): void
    {
        $escaped = null;
        $this->within(function ($context) use (&$escaped): array { $escaped = $context; return []; });
        $this->expectException(LogicException::class);
        $this->firstAid(null, $escaped);
    }

    public function test_first_aid_fact_mutation_after_metric_return_invalidates_outer_response(): void
    {
        $record = $this->treatment();
        $this->sourceChanged(fn () => $this->within(fn ($context) => HealthSafetyReadMaterialisationProof::within($context,
            function () use ($context, $record): array {
                $result = $this->firstAid(null, $context);
                DB::table('first_aid_records')->where('id', $record->id)->update(['treatment_outcome' => 'sent_to_hospital']);
                return $result;
            })));
        DB::table('first_aid_records')->where('id', $record->id)->update(['treatment_outcome' => 'returned_to_activity']);
        $this->assertSame(0, $this->firstAid()['activity']['hospital']);

        // Unchanged treatment facts cannot certify authority withdrawn after the metric SELECT.
        foreach (['grant', 'site', 'actor', 'hr'] as $mutation) {
            $this->sourceChanged(fn () => $this->within(fn ($context) => HealthSafetyReadMaterialisationProof::within($context,
                function () use ($context, $mutation): array {
                    $result = $this->firstAid(null, $context);
                    $this->assertSame(1, $result['activity']['treatments']);
                    if ($mutation === 'grant') { $this->grant('hazards.view', false); }
                    elseif ($mutation === 'site') { DB::table('sites')->where('id', $this->site->id)->update(['archived' => true]); }
                    elseif ($mutation === 'actor') { DB::table('users')->where('id', $this->actor->id)->update(['approved_at' => null]); }
                    else { DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => false]); }
                    return $result;
                })));
            if ($mutation === 'grant') { $this->grant('hazards.view', true); }
            elseif ($mutation === 'site') { DB::table('sites')->where('id', $this->site->id)->update(['archived' => false]); }
            $this->assertSame(1, $this->firstAid()['activity']['treatments']);
        }
        $this->within(function ($context): array {
            $this->grant('hazards.view', false);
            $result = $this->firstAid(null, $context);
            $this->assertSame('unavailable', $result['source_access']);
            $this->assertNull($result['activity']['treatments']);
            $this->grant('hazards.view', true);
            return [];
        });
        $unrelatedSite = Site::factory()->create(['is_active' => true]);
        $unchanged = $this->within(fn ($context) => HealthSafetyReadMaterialisationProof::within($context,
            function () use ($context, $unrelatedSite): array {
                $result = $this->firstAid($this->site->id, $context);
                DB::table('sites')->where('id', $unrelatedSite->id)->update(['archived' => true]);
                return $result;
            }));
        $this->assertSame(1, $unchanged['activity']['treatments']);
        $this->grant('clients.viewAny', false);
        $this->grant('clients.viewAssigned', true);
        $this->client->supportWorkers()->syncWithoutDetaching([$this->actor->id]);
        DB::table('first_aid_records')->where('id', $record->id)->update(['treated_person_type' => 'client', 'client_id' => $this->client->id]);
        $this->sourceChanged(fn () => $this->within(fn ($context) => HealthSafetyReadMaterialisationProof::within($context,
            function () use ($context): array {
                $result = $this->firstAid(null, $context);
                $this->assertSame(1, $result['activity']['treatments']);
                DB::table('client_user')->where('user_id', $this->actor->id)->where('client_id', $this->client->id)->delete();
                return $result;
            })));
        $this->assertSame(1, $this->firstAid()['activity']['treatments']);
    }

    public function test_restraint_reassociation_after_metric_return_invalidates_outer_counts(): void
    {
        $event = $this->event(); $foreign = Site::factory()->create(['is_active' => true]);
        $this->sourceChanged(fn () => $this->within(fn ($context) => HealthSafetyReadMaterialisationProof::within($context,
            function () use ($context, $event, $foreign): array {
                $result = $this->restraints(null, $context);
                DB::table('restraint_events')->where('id', $event->id)->update(['site_id' => $foreign->id]);
                return $result;
            })));
        DB::table('restraint_events')->where('id', $event->id)->update(['site_id' => $this->site->id]);
        $this->assertSame(1, $this->restraints()['events_in_period']);
    }

    public function test_client_name_mutation_after_worklist_return_invalidates_outer_body(): void
    {
        $this->event();
        $this->sourceChanged(fn () => $this->within(fn ($context) => HealthSafetyReadMaterialisationProof::within($context,
            function () use ($context): array {
                $result = ['items' => $this->worklist(null, $context)];
                DB::table('clients')->where('id', $this->client->id)->update(['first_name' => 'Changed after projection']);
                return $result;
            })));
    }

    public function test_new_source_after_metric_return_is_not_silently_omitted_from_final_proof(): void
    {
        $this->event();
        $this->sourceChanged(fn () => $this->within(fn ($context) => HealthSafetyReadMaterialisationProof::within($context,
            function () use ($context): array {
                $result = $this->restraints(null, $context);
                RestraintEvent::factory()->createQuietly(['site_id' => $this->site->id, 'client_id' => $this->client->id,
                    'stay_id' => null, 'source_schema_version' => null, 'started_at' => now()->subMinute(), 'behaviour_support_plan_id' => null]);
                return $result;
            })));
    }

    public function test_first_aid_denied_client_subject_discovery_does_not_hydrate_names_or_care_body(): void
    {
        $privateClient = Client::factory()->create(['site_id' => $this->site->id]);
        $this->treatment(['treated_person_type' => 'client', 'client_id' => $privateClient->id]);
        $this->grant('clients.viewAny', false); $this->grant('clients.viewAssigned', true);
        $hydrated = 0;
        $dispatcher = Client::getEventDispatcher(); Client::setEventDispatcher(clone $dispatcher);
        Client::retrieved(function (Client $row) use ($privateClient, &$hydrated): void {
            if ((int) $row->id === (int) $privateClient->id && array_key_exists('first_name', $row->getAttributes())) { $hydrated++; }
        });
        try {
            $this->assertSame(0, $this->firstAid()['activity']['treatments']);
            $this->assertSame(0, $hydrated);
        } finally { Client::setEventDispatcher($dispatcher); }
    }

    public function test_restraint_scalar_metrics_use_client_metadata_without_name_hydration(): void
    {
        $this->event(); $this->plan($this->client);
        $hydrated = 0;
        $dispatcher = Client::getEventDispatcher(); Client::setEventDispatcher(clone $dispatcher);
        Client::retrieved(function (Client $row) use (&$hydrated): void {
            if (array_key_exists('first_name', $row->getAttributes())) { $hydrated++; }
        });
        try {
            $this->assertSame(1, $this->restraints()['events_in_period']);
            $this->assertSame(1, $this->restraints()['active_plans']);
            $this->assertSame(0, $hydrated);
        } finally { Client::setEventDispatcher($dispatcher); }
    }

    public function test_first_aid_capture_picker_retains_admitted_names_after_identity_only_preparation(): void
    {
        $private = Client::factory()->create(['site_id' => $this->site->id]);
        $this->client->supportWorkers()->attach($this->actor->id);
        $this->grant('hazards.manage', true);
        $this->grant('clients.viewAny', false); $this->grant('clients.viewAssigned', true);
        $result = app(FirstAidAccessService::class)->pickerOptions($this->actor, null, $this->site->id);
        $this->assertSame([['id' => $this->client->id, 'name' => trim($this->client->first_name.' '.$this->client->last_name),
            'site_id' => $this->site->id]], $result['clients']);
        $this->assertNotContains($private->id, array_column($result['clients'], 'id'));
    }

    public function test_default_restraint_source_relation_preserves_names_after_metadata_admission(): void
    {
        $event = $this->event();
        $result = app(RestraintAccessService::class)->withCurrentViewer($this->actor,
            function ($current, $reads, $sites) use ($event): array {
                app(RestraintAccessService::class)->assertEvent($event, $current, $reads, $sites);
                return ['name' => $event->client->first_name, 'site_id' => $event->client->site_id];
            });
        $this->assertSame(['name' => $this->client->first_name, 'site_id' => $this->site->id], $result);
    }

    public function test_unsaved_capture_candidate_keeps_current_client_relation_without_claiming_saved_source(): void
    {
        $candidate = new RestraintEvent(['client_id' => $this->client->id, 'site_id' => $this->site->id,
            'stay_id' => null, 'source_schema_version' => 1, 'recorded_client_id' => $this->client->id,
            'recorded_site_id' => $this->site->id, 'recorded_stay_id' => null]);
        $result = app(RestraintAccessService::class)->withCurrentViewer($this->actor,
            function ($current, $reads, $sites) use ($candidate): array {
                app(RestraintAccessService::class)->assertEvent($candidate, $current, $reads, $sites, 'capture');
                return ['name' => $candidate->client->first_name, 'persisted' => $candidate->exists];
            });
        $this->assertSame(['name' => $this->client->first_name, 'persisted' => false], $result);
    }

    public function test_worklist_first_name_select_refuses_late_source_reassociation_before_name_hydration(): void
    {
        $event = $this->event(); $other = Client::factory()->create(['site_id' => $this->site->id]);
        $armed = true; $fired = false; $hydrated = 0;
        $dispatcher = Client::getEventDispatcher(); Client::setEventDispatcher(clone $dispatcher);
        Client::retrieved(function (Client $row) use (&$armed, &$hydrated): void {
            if ($armed && array_key_exists('first_name', $row->getAttributes())) { $hydrated++; }
        });
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired, $event, $other): void {
            if ($armed && ! $fired && str_starts_with(strtolower(ltrim($sql)), 'select')
                && str_contains($sql, '`first_name`') && str_contains($sql, '`restraint_events`')) {
                $fired = true;
                DB::table('restraint_events')->where('id', $event->id)->update(['client_id' => $other->id]);
            }
        });
        try {
            $this->assertNull($this->worklist());
            $this->assertTrue($fired);
            $this->assertSame(0, $hydrated);
        } finally { $armed = false; Client::setEventDispatcher($dispatcher); }
        DB::table('restraint_events')->where('id', $event->id)->update(['client_id' => $this->client->id]);
        $this->assertSame([$event->id], array_column($this->worklist(), 'id'));
    }

    public function test_default_source_client_body_select_binds_original_parent_before_hydration(): void
    {
        $event = $this->event(); $foreign = Site::factory()->create(['is_active' => true]);
        $armed = true; $fired = false; $hydrated = 0;
        $dispatcher = Client::getEventDispatcher(); Client::setEventDispatcher(clone $dispatcher);
        Client::retrieved(function (Client $row) use (&$armed, &$hydrated): void {
            if ($armed && array_key_exists('first_name', $row->getAttributes())) { $hydrated++; }
        });
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired, $event, $foreign): void {
            if ($armed && ! $fired && str_starts_with(strtolower(ltrim($sql)), 'select * from `clients`')
                && str_contains($sql, '`restraint_events`')) {
                $fired = true;
                DB::table('restraint_events')->where('id', $event->id)->update(['site_id' => $foreign->id]);
            }
        });
        try {
            app(RestraintAccessService::class)->withCurrentViewer($this->actor,
                fn ($current, $reads, $sites) => app(RestraintAccessService::class)->assertEvent($event, $current, $reads, $sites));
            $this->fail('Changed original parent must conceal before Client body.');
        } catch (ModelNotFoundException) {
            $this->assertTrue($fired);
            $this->assertSame(0, $hydrated);
        } finally { $armed = false; Client::setEventDispatcher($dispatcher); }
    }

    private function firstAid(int|array|null $sites = null, ?HealthSafetyReadContext $context = null): array
    {
        return app(FirstAidMetricsService::class)->forViewer($this->actor, now()->subDay(), now(), $sites, $context);
    }

    private function restraints(int|array|null $sites = null, ?HealthSafetyReadContext $context = null): array
    {
        return app(RestraintKpiService::class)->summary($sites, now()->subDay(), now(), $this->actor, $context);
    }

    private function worklist(int|array|null $sites = null, ?HealthSafetyReadContext $context = null): ?array
    {
        return app(RestraintKpiService::class)->unreviewedWorklist($sites, 8, $this->actor, $context);
    }

    private function breakdowns(int|array|null $sites = null, ?HealthSafetyReadContext $context = null): array
    {
        return app(RestraintKpiService::class)->breakdowns($sites, now()->subDay(), now(), $this->actor, $context);
    }

    private function within(callable $operation): mixed
    {
        return HealthSafetyReadContext::within($this->actor, array_values(array_unique([
            ...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS,
        ])), $operation);
    }

    private function treatment(array $fields = []): FirstAidRecord
    {
        return FirstAidRecord::factory()->createQuietly(['site_id' => $this->site->id, 'first_aider_id' => $this->actor->id,
            'created_by' => $this->actor->id, 'treated_person_type' => 'staff', 'treated_person_id' => null,
            'client_id' => null, 'treatment_date' => now()->subHour(), 'injury_illness_type' => 'cut',
            'treatment_outcome' => 'returned_to_activity', 'ambulance_called' => false, ...$fields]);
    }

    private function plan(Client $client, array $fields = []): BehaviourSupportPlan
    {
        return BehaviourSupportPlan::factory()->create(['client_id' => $client->id, 'source_schema_version' => null,
            'source_site_id' => null, 'source_stay_id' => null, 'status' => 'active', 'review_date' => now()->addMonths(3), ...$fields]);
    }

    private function grant(string $key, bool $allowed): void
    {
        $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }

    private function queries(callable $operation): array
    {
        DB::enableQueryLog(); DB::flushQueryLog();
        try { $result = $operation(); return [$result, DB::getQueryLog()]; }
        finally { DB::disableQueryLog(); }
    }

    private function assertNoSourceSelect(array $queries, string $table): void
    {
        foreach ($queries as $query) {
            if (str_starts_with(strtolower(ltrim($query['query'])), 'select')) {
                $this->assertStringNotContainsString('`'.$table.'`', $query['query']);
            }
        }
    }

    private function sourceChanged(callable $operation): void
    {
        try { $operation(); $this->fail('Original operands changed before final materialisation.'); }
        catch (HttpException $error) { $this->assertSame(404, $error->getStatusCode()); }
    }
}
