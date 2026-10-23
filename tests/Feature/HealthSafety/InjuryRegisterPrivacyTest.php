<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ReturnToWorkPlan;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkCapacityAssessment;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryAttachment;
use App\Models\WorkplaceInjurySourceVersion;
use App\Services\HealthSafety\InjuryRegisterService;
use App\Services\HealthSafety\RestraintCommandValues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/** Complete real-service source/privacy suite; persisted fixtures do not mock authority. */
class InjuryRegisterPrivacyTest extends InjuryWorkspaceReadTestCase
{
    public function test_factual_register_never_queries_private_sources_or_returns_private_counts(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $this->privateChildren();
        [$dto, $queries] = $this->queries(fn () => $this->service()->index($viewer));
        $this->assertSame(1, $dto['injuries']['total']);
        $this->assertSame('restricted', $dto['medical_access']);
        $row = $dto['injuries']['data'][0];
        $this->assertSame($this->worker->id, $row['worker']['id']);
        $this->assertSame('Factual workplace occurrence', $row['description']);
        foreach (['rtw_count', 'capacity_count', 'attachment_count', 'acc_claim_number', 'medical_treatment_type', 'medical_treatment_label'] as $key) {
            $this->assertNull($row[$key], $key);
        }
        $this->assertArrayNotHasKey('notes', $row);
        $this->assertArrayNotHasKey('immediate_treatment', $row);
        $this->assertNull($dto['hero']['attention']['rtw_review_due']);
        $this->assertNull($dto['options']['treatments']);
        $this->assertFactualQueries($queries);
        foreach (['Private clinical', 'PRIVATE-ACC', 'Medical assessor sentinel', 'private-certificate'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($dto, JSON_THROW_ON_ERROR));
        }
        $this->assertDetached($dto);
    }

    public function test_factual_csv_has_same_source_boundary_and_unavailable_claim_number(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $this->privateChildren();
        $rows = [];
        [$receipt, $queries] = $this->queries(function () use ($viewer, &$rows): array {
            return $this->service()->export($viewer, [], function (array $row) use (&$rows): void {
                $this->assertDetached($row);
                $rows[] = $row;
            });
        });
        $this->assertSame(1, $receipt['count']);
        $this->assertCount(12, $rows[0]);
        $this->assertNull($rows[0][10]);
        $this->assertSame($this->worker->name, $rows[0][1]);
        $this->assertSame('restricted', $receipt['medical_access']);
        $this->assertFactualQueries($queries);
    }

    public function test_wellbeing_only_reader_gets_canonical_private_counts_without_management(): void
    {
        $reader = $this->staff(['hr.wellbeing.view']);
        $this->privateChildren();
        $dto = $this->service()->index($reader);
        $row = $dto['injuries']['data'][0];
        $this->assertSame('readable', $row['medical_access']);
        $this->assertSame('PRIVATE-ACC-123', $row['acc_claim_number']);
        $this->assertSame('specialist', $row['medical_treatment_type']);
        $this->assertSame(1, $row['rtw_count']);
        $this->assertSame(1, $row['capacity_count']);
        $this->assertSame(1, $row['attachment_count']);
        $this->assertSame(1, $dto['hero']['attention']['rtw_review_due']);
        $this->assertFalse($dto['can']['manage']);
        $this->assertFalse($dto['can']['create']);
    }

    public function test_corrupt_worker_tuple_makes_affected_counts_unavailable_without_hiding_healthy_evidence(): void
    {
        $this->privateChildren();
        $other = $this->staff([]);
        ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $other->id,
            'next_review_date' => now('Pacific/Auckland')->subDay()->toDateString(),
        ]));
        WorkCapacityAssessment::withoutEvents(fn () => WorkCapacityAssessment::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'source_schema_version' => 1,
            'worker_id' => $other->id, 'user_id' => $other->id,
        ]));
        ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $this->worker->id, 'deleted_at' => now(),
        ]));
        WorkplaceInjuryAttachment::factory()->create(['workplace_injury_id' => $this->injury->id, 'deleted_at' => now()]);
        $dto = $this->service()->index($this->viewer);
        $row = $dto['injuries']['data'][0];
        $this->assertNull($row['rtw_count']);
        $this->assertNull($row['capacity_count']);
        $this->assertSame(1, $row['attachment_count']);
        $this->assertSame('repair_required', $row['private_count_integrity']['rtw_count']);
        $this->assertSame('repair_required', $row['private_count_integrity']['capacity_count']);
        $this->assertSame('legacy_unknown', $row['private_count_integrity']['attachment_count']);
        $this->assertNull($dto['hero']['attention']['rtw_review_due']);
        $this->assertSame('repair_required', $dto['hero']['rtw_review_due_integrity']);
        $this->assertSame('unavailable', $dto['hero']['measure_availability']['rtw_review_due']);
    }

    public function test_legacy_capacity_identity_is_retained_without_inventing_current_worker_approval(): void
    {
        WorkCapacityAssessment::withoutEvents(fn () => WorkCapacityAssessment::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'source_schema_version' => 0,
            'user_id' => $this->staff([])->id, 'worker_id' => null,
        ]));
        $dto = $this->service()->index($this->viewer);
        $this->assertSame(1, $dto['injuries']['data'][0]['capacity_count']);
        $this->assertSame('legacy_unknown', $dto['injuries']['data'][0]['private_count_integrity']['capacity_count']);
        $this->assertArrayNotHasKey('fitness_approval', $dto['injuries']['data'][0]);
    }

    public function test_treatment_filter_requires_medical_authority_before_any_injury_query(): void
    {
        $viewer = $this->staff(['hazards.view', 'hazards.manage']);
        [, $queries] = $this->queries(function () use ($viewer): void {
            $this->denied(fn () => $this->service()->index($viewer, ['treatment' => 'specialist']), 403);
            $this->denied(fn () => $this->service()->pickers($viewer, ['treatment' => 'specialist']), 403);
            $this->denied(fn () => $this->service()->export($viewer, ['treatment' => 'specialist'], fn () => $this->fail('No cells may emit.')), 403);
        });
        $this->assertNoInjuryQueries($queries);
    }

    public function test_medical_treatment_filter_has_list_counts_and_export_parity(): void
    {
        $this->record(['medical_treatment_type' => 'hospital', 'description' => 'Other factual injury']);
        $filters = ['treatment' => 'specialist'];
        $dto = $this->service()->index($this->viewer, $filters);
        $rows = [];
        $receipt = $this->service()->export($this->viewer, $filters, function ($row) use (&$rows): void { $rows[] = $row; });
        $this->assertSame([$this->injury->id], array_column($dto['injuries']['data'], 'id'));
        $this->assertSame(1, $dto['tabCounts']['all']);
        $this->assertSame(1, $receipt['count']);
        $this->assertSame('PRIVATE-ACC-123', $rows[0][10]);
    }

    public function test_management_and_create_grants_do_not_grant_register_read(): void
    {
        foreach ([['hazards.manage'], ['hazards.create']] as $permissions) {
            $viewer = $this->staff($permissions);
            $this->denied(fn () => $this->service()->index($viewer), 403);
            $this->denied(fn () => $this->service()->pickers($viewer), 403);
            $this->denied(fn () => $this->service()->options($viewer), 403);
            $this->denied(fn () => $this->service()->export($viewer, [], fn () => $this->fail('No cells may emit.')), 403);
        }
    }

    public function test_fresh_permission_revocation_overrides_deliberately_warmed_caller(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $viewer->load(['roles.permissions', 'permissionOverrides']);
        $this->assertTrue($viewer->canDo('hazards.view'));
        $service = $this->service();
        $this->assertSame(1, $service->index($viewer)['injuries']['total']);
        $this->revoke($viewer, 'hazards.view');
        $this->assertTrue($viewer->canDo('hazards.view'), 'Fixture retains stale caller permission evidence.');
        $this->denied(fn () => $service->index($viewer), 403);
        $this->denied(fn () => $service->export($viewer, [], fn () => $this->fail('No cells may emit.')), 403);
    }

    public function test_separate_read_medical_revocation_removes_counts_claim_and_treatment_options(): void
    {
        $viewer = $this->staff(['hazards.view', 'hr.wellbeing.view']);
        $viewer->load(['roles.permissions', 'permissionOverrides']);
        $service = $this->service();
        $this->assertSame('readable', $service->index($viewer)['medical_access']);
        $this->revoke($viewer, 'hr.wellbeing.view');
        $dto = $service->index($viewer);
        $this->assertSame('restricted', $dto['medical_access']);
        $this->assertNull($dto['injuries']['data'][0]['rtw_count']);
        $this->assertNull($dto['injuries']['data'][0]['acc_claim_number']);
        $this->assertNull($service->options($viewer)['treatments']);
        $this->denied(fn () => $service->index($viewer, ['treatment' => 'specialist']), 403);
    }

    public function test_current_secondary_site_revocation_removes_rows_counts_and_picker(): void
    {
        $foreign = Site::factory()->create(['is_active' => true]);
        $other = $this->record(['site_id' => $foreign->id]);
        $viewer = $this->staff(['hazards.view']);
        $viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$foreign->id]]);
        $viewer->load('hrEmployeeProfile');
        $service = $this->service();
        $this->assertSame(2, $service->index($viewer)['injuries']['total']);
        $viewer->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
        $dto = $service->index($viewer);
        $this->assertSame([$this->injury->id], array_column($dto['injuries']['data'], 'id'));
        $this->assertSame(1, $dto['tabCounts']['all']);
        $this->assertNotContains($foreign->id, array_column($dto['sites'], 'id'));
        $this->denied(fn () => $service->index($viewer, ['site_id' => $foreign->id]), 404);
        $this->assertNotNull($other->fresh());
    }

    #[DataProvider('ineligibleViewers')]
    public function test_current_internal_staff_is_required_for_every_register_read(string $condition): void
    {
        $viewer = $this->staff(['hazards.view']);
        match ($condition) {
            'inactive' => $viewer->hrEmployeeProfile()->update(['is_active' => false]),
            'ended' => $viewer->hrEmployeeProfile()->update(['end_date' => now('Pacific/Auckland')->subDay()->toDateString()]),
            'future' => $viewer->hrEmployeeProfile()->update(['start_date' => now('Pacific/Auckland')->addDay()->toDateString()]),
            'unapproved' => $viewer->update(['approved_at' => null]),
            'external' => $viewer->update(['external_clinical_account' => true]),
            'client' => $viewer->update(['role' => 'client']),
        };
        $this->denied(fn () => $this->service()->index($viewer), 403);
        $this->denied(fn () => $this->service()->pickers($viewer), 403);
        $this->denied(fn () => $this->service()->export($viewer, [], fn () => $this->fail('No cells may emit.')), 403);
    }

    public static function ineligibleViewers(): array
    {
        return array_map(fn ($condition) => [$condition], ['inactive', 'ended', 'future', 'unapproved', 'external', 'client']);
    }

    public function test_inactive_viewer_site_does_not_survive_reused_service(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $service = $this->service();
        $this->assertSame(1, $service->index($viewer)['injuries']['total']);
        $this->site->update(['is_active' => false]);
        $this->denied(fn () => $service->index($viewer), 403);
    }

    public function test_historical_injured_worker_is_readable_after_employment_ends(): void
    {
        $this->worker->hrEmployeeProfile()->update(['is_active' => false, 'end_date' => now('Pacific/Auckland')->subYear()->toDateString()]);
        $dto = $this->service()->index($this->staff(['hazards.view']));
        $this->assertSame($this->worker->id, $dto['injuries']['data'][0]['worker']['id']);
        $this->assertSame($this->worker->name, $dto['injuries']['data'][0]['worker']['name']);
        $this->assertArrayNotHasKey('current_staff', $dto['injuries']['data'][0]);
    }

    public function test_foreign_source_cannot_affect_row_count_lost_days_or_private_reviews(): void
    {
        $this->privateChildren();
        $foreign = Site::factory()->create(['is_active' => true]);
        $hidden = $this->record(['site_id' => $foreign->id, 'lost_time_days' => 100, 'worksafe_notifiable' => true, 'acc_claim_lodged' => true]);
        ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $hidden->id, 'worker_id' => $this->worker->id, 'next_review_date' => '2020-01-01',
        ]));
        $dto = $this->service()->index($this->viewer);
        $this->assertSame(1, $dto['injuries']['total']);
        $this->assertSame(1, $dto['tabCounts']['all']);
        $this->assertSame(0, $dto['hero']['badges']['lost_time_days']);
        $this->assertSame(1, $dto['hero']['attention']['rtw_review_due']);
    }

    public function test_refined_tab_counts_exclude_selected_tab_but_include_search_and_site(): void
    {
        $this->injury->deleteQuietly();
        $first = $this->record(['description' => 'Matched back injury', 'status' => 'reported', 'worksafe_notifiable' => true]);
        $this->record(['description' => 'Matched back injury', 'status' => 'recovered']);
        $this->record(['description' => 'Other factual occurrence', 'status' => 'reported']);
        $filters = ['q' => 'Matched', 'site_id' => $this->site->id, 'tab' => 'worksafe'];
        $dto = $this->service()->index($this->staff(['hazards.view']), $filters);
        $this->assertSame([$first->id], array_column($dto['injuries']['data'], 'id'));
        $this->assertSame(2, $dto['tabCounts']['all']);
        $this->assertSame(1, $dto['tabCounts']['reported']);
        $this->assertSame(1, $dto['tabCounts']['recovered']);
        $this->assertSame(1, $dto['hero']['live']['reported']);
    }

    public function test_all_existing_refinement_filters_have_authorised_count_and_export_parity(): void
    {
        $this->injury->deleteQuietly();
        $target = $this->record(['description' => 'Selected factual transfer injury', 'severity' => 'serious',
            'status' => 'reported', 'injury_type' => 'manual_handling', 'body_part_affected' => 'Left shoulder',
            'medical_treatment_type' => 'hospital', 'acc_claim_lodged' => true, 'worksafe_notifiable' => true,
            'injury_date' => '2026-10-11 10:00:00', 'lost_time_days' => 4]);
        $this->record(['description' => 'Selected factual transfer injury', 'severity' => 'minor']);
        $filters = ['q' => 'Selected', 'site_id' => $this->site->id, 'severity' => 'serious', 'status' => 'reported',
            'type' => 'manual_handling', 'body_part' => 'shoulder', 'treatment' => 'hospital', 'acc_open' => 'true',
            'worksafe' => '1', 'period' => 'year', 'from' => '2026-10-10', 'to' => '2026-10-12', 'tab' => 'acc'];
        $dto = $this->service()->index($this->viewer, $filters);
        $rows = [];
        $receipt = $this->service()->export($this->viewer, $filters, function ($row) use (&$rows): void { $rows[] = $row; });
        $this->assertSame([$target->id], array_column($dto['injuries']['data'], 'id'));
        $this->assertSame(1, $dto['tabCounts']['all']);
        $this->assertSame(4, $dto['hero']['badges']['lost_time_days']);
        $this->assertSame(1, $receipt['count']);
        $this->assertSame(4, $rows[0][8]);
        $this->assertSame($dto['filters'], $receipt['filters']);
    }

    public function test_pagination_is_detached_and_deterministic_and_csv_includes_later_pages(): void
    {
        $this->injury->deleteQuietly();
        $ids = [];
        for ($i = 0; $i < 26; $i++) {
            $ids[] = $this->record(['injury_date' => '2026-10-11 10:00:00'])->id;
        }
        $viewer = $this->staff(['hazards.view']);
        $page1 = $this->service()->index($viewer);
        $page2 = $this->service()->index($viewer, ['page' => 2]);
        $this->assertSame(26, $page1['injuries']['total']);
        $this->assertSame(25, $page1['injuries']['per_page']);
        $this->assertSame(2, $page1['injuries']['last_page']);
        $this->assertSame(array_reverse(array_slice($ids, 1)), array_column($page1['injuries']['data'], 'id'));
        $this->assertSame([$ids[0]], array_column($page2['injuries']['data'], 'id'));
        $emitted = 0;
        $receipt = $this->service()->export($viewer, ['page' => 2], function () use (&$emitted): void { $emitted++; });
        $this->assertSame(26, $emitted);
        $this->assertSame(26, $receipt['count']);
        $this->assertDetached($page1);
    }

    public function test_search_never_uses_private_claim_treatment_assessor_or_evidence_as_fallback(): void
    {
        $this->privateChildren();
        $viewer = $this->staff(['hazards.view']);
        foreach (['PRIVATE-ACC-123', 'Private recovery note', 'Private clinical treatment', 'Medical assessor sentinel', 'private-certificate'] as $term) {
            [$dto, $queries] = $this->queries(fn () => $this->service()->index($viewer, ['q' => $term]));
            $this->assertSame(0, $dto['injuries']['total']);
            $this->assertSame(0, $dto['tabCounts']['all']);
            $this->assertFactualQueries($queries);
        }
    }

    public function test_search_retains_authorised_reference_and_historical_worker_name(): void
    {
        $this->injury->updateQuietly(['reference_number' => 'INJ-REGISTER-QUERY']);
        $viewer = $this->staff(['hazards.view']);
        foreach (['INJ-REGISTER-QUERY', $this->worker->name] as $term) {
            $dto = $this->service()->index($viewer, ['q' => $term]);
            $this->assertSame([$this->injury->id], array_column($dto['injuries']['data'], 'id'));
        }
    }

    public function test_literal_zero_search_is_not_treated_as_an_empty_filter(): void
    {
        $this->injury->deleteQuietly();
        $target = $this->record(['description' => 'Occurrence 0', 'reference_number' => 'INJ-ZERO-TARGET']);
        $this->record(['description' => 'No match', 'reference_number' => 'INJ-OTHER']);
        $dto = $this->service()->index($this->staff(['hazards.view']), ['q' => '0']);
        $this->assertSame([$target->id], array_column($dto['injuries']['data'], 'id'));
    }

    public function test_explicit_nz_day_includes_dst_boundaries_and_csv_uses_local_date(): void
    {
        $this->injury->deleteQuietly();
        $before = $this->record(['injury_date' => '2026-09-26 11:59:59']);
        $start = $this->record(['injury_date' => '2026-09-26 12:00:00']);
        $end = $this->record(['injury_date' => '2026-09-27 10:59:59']);
        $after = $this->record(['injury_date' => '2026-09-27 11:00:00']);
        $filters = ['from' => '2026-09-27', 'to' => '2026-09-27'];
        $viewer = $this->staff(['hazards.view']);
        $dto = $this->service()->index($viewer, $filters);
        $this->assertSame([$end->id, $start->id], array_column($dto['injuries']['data'], 'id'));
        $this->assertNotContains($before->id, array_column($dto['injuries']['data'], 'id'));
        $this->assertNotContains($after->id, array_column($dto['injuries']['data'], 'id'));
        $dates = [];
        $receipt = $this->service()->export($viewer, $filters, function ($row) use (&$dates): void { $dates[] = $row[3]; });
        $this->assertSame(2, $receipt['count']);
        $this->assertSame(['2026-09-27', '2026-09-27'], $dates);
    }

    public function test_period_presets_and_explicit_range_override_use_same_filtered_totals(): void
    {
        $this->injury->deleteQuietly();
        $recent = $this->record(['injury_date' => now()->subDays(2)]);
        $old = $this->record(['injury_date' => now()->subDays(40)]);
        $viewer = $this->staff(['hazards.view']);
        $dto = $this->service()->index($viewer, ['period' => 'week']);
        $this->assertSame([$recent->id], array_column($dto['injuries']['data'], 'id'));
        $this->assertSame(1, $dto['tabCounts']['all']);
        $override = $this->service()->index($viewer, ['period' => 'week', 'from' => now('Pacific/Auckland')->subDays(50)->toDateString()]);
        $this->assertSame(2, $override['tabCounts']['all']);
        $this->assertContains($old->id, array_column($override['injuries']['data'], 'id'));
    }

    public function test_invalid_calendar_or_reversed_range_rejects_before_injury_sql(): void
    {
        foreach ([['from' => '2026-02-30'], ['from' => '2026-10-12', 'to' => '2026-10-11']] as $filters) {
            [, $queries] = $this->queries(function () use ($filters): void {
                try {
                    $this->service()->index($this->viewer, $filters);
                    $this->fail('Malformed calendar range must reject.');
                } catch (ValidationException $error) {
                    $this->assertNotEmpty($error->errors());
                }
            });
            $this->assertNoInjuryQueries($queries);
        }
    }

    public function test_deleted_sources_never_appear_in_totals_or_csv(): void
    {
        $this->injury->deleteQuietly();
        $viewer = $this->staff(['hazards.view']);
        $dto = $this->service()->index($viewer);
        $this->assertSame(0, $dto['injuries']['total']);
        $this->assertSame(0, $dto['tabCounts']['all']);
        $this->assertSame(0, $this->service()->export($viewer, [], fn () => $this->fail('No deleted source cells.'))['count']);
    }

    public function test_register_pickers_are_current_sites_without_capture_staff_or_incident_queries(): void
    {
        $viewer = $this->staff(['hazards.view']);
        [$dto, $queries] = $this->queries(fn () => $this->service()->pickers($viewer));
        $this->assertSame([$this->site->id], array_column($dto['sites'], 'id'));
        $this->assertArrayNotHasKey('workers', $dto);
        $this->assertArrayNotHasKey('incidents', $dto);
        $this->assertNull($dto['options']['treatments']);
        $this->assertNoInjuryQueries($queries);
        foreach ($queries as $entry) {
            $this->assertStringNotContainsString('client_incidents', strtolower($entry['query']));
        }
    }

    public function test_export_reproves_each_source_immediately_before_emitting_even_if_loaded_row_changes(): void
    {
        $this->injury->deleteQuietly();
        $first = $this->record(['injury_date' => '2026-10-11 12:00:00']);
        $second = $this->record(['injury_date' => '2026-10-11 11:00:00']);
        $foreign = Site::factory()->create(['is_active' => true]);
        $emitted = [];
        $viewer = $this->staff(['hazards.view']);
        $this->denied(function () use ($viewer, &$emitted, $second, $foreign): void {
            $this->service()->export($viewer, [], function ($cells) use (&$emitted, $second, $foreign): void {
                $emitted[] = $cells;
                $second->updateQuietly(['site_id' => $foreign->id]);
            });
        }, 404);
        $this->assertCount(1, $emitted);
        $this->assertSame($first->reference_number, $emitted[0][0]);
    }

    public function test_unavailable_rates_remain_null_while_authorised_loss_days_remain_actual(): void
    {
        $this->injury->updateQuietly(['lost_time_days' => 7]);
        $dto = $this->service()->index($this->staff(['hazards.view']));
        $this->assertSame(7, $dto['hero']['badges']['lost_time_days']);
        $this->assertSame(1, $dto['hero']['attention']['lost_time']);
        $this->assertNull($dto['hero']['badges']['ltifr']);
        $this->assertNull($dto['hero']['badges']['trifr']);
        $this->assertSame('unknown', $dto['hero']['measure_availability']['trifr']);
    }

    public function test_empty_factual_register_has_unknown_private_counts_instead_of_false_zero(): void
    {
        $this->injury->deleteQuietly();
        $viewer = $this->staff(['hazards.view']);
        [$dto, $queries] = $this->queries(fn () => $this->service()->index($viewer));
        $this->assertSame(0, $dto['tabCounts']['all']);
        $this->assertNull($dto['hero']['attention']['rtw_review_due']);
        $this->assertFactualQueries($queries);
    }

    public function test_only_canonical_hs_site_bypass_can_expand_current_register_scope(): void
    {
        $foreign = Site::factory()->create(['is_active' => true]);
        $this->record(['site_id' => $foreign->id]);
        $viewer = $this->staff(['hazards.view', 'reports.viewAny', 'hr.employees.viewAllSites']);
        $service = $this->service();
        $this->assertSame(1, $service->index($viewer)['injuries']['total']);
        $this->denied(fn () => $service->pickers($viewer, ['site_id' => $foreign->id]), 404);
        $this->grant($viewer, 'healthSafety.viewAllSites');
        $this->assertSame(2, $service->index($viewer)['injuries']['total']);
        $this->revoke($viewer, 'healthSafety.viewAllSites');
        $this->assertSame(1, $service->index($viewer)['injuries']['total']);
    }

    public function test_missing_typed_plan_receipt_is_unavailable_and_preserves_independent_counts(): void
    {
        $this->privateChildren();
        $plan = $this->typedPlan(false);
        $dto = $this->service()->index($this->viewer);
        $row = $dto['injuries']['data'][0];
        $this->assertNull($row['rtw_count']);
        $this->assertSame('repair_required', $row['private_count_integrity']['rtw_count']);
        $this->assertSame(1, $row['capacity_count']);
        $this->assertSame(1, $row['attachment_count']);
        $this->assertNull($dto['hero']['attention']['rtw_review_due']);
        $this->assertSame('unavailable', $dto['hero']['measure_availability']['rtw_review_due']);
        $this->assertSame('repair_required', $dto['hero']['rtw_review_due_integrity']);
        $this->assertNotNull($plan->fresh());
        $this->assertDetached($dto);
    }

    #[DataProvider('unacceptedPlanChanges')]
    public function test_plan_proof_precedes_due_and_active_filters_so_corruption_cannot_be_zero(array $change): void
    {
        $plan = $this->typedPlan();
        $service = $this->service();
        $before = $service->index($this->viewer);
        $this->assertSame(1, $before['injuries']['data'][0]['rtw_count']);
        $this->assertSame('valid', $before['injuries']['data'][0]['private_count_integrity']['rtw_count']);
        $this->assertSame(1, $before['hero']['attention']['rtw_review_due']);
        $plan->updateQuietly($change);
        $after = $service->index($this->viewer);
        $this->assertNull($after['injuries']['data'][0]['rtw_count']);
        $this->assertNull($after['hero']['attention']['rtw_review_due']);
        $this->assertSame('repair_required', $after['hero']['rtw_review_due_integrity']);
        $this->assertSame(0, $after['injuries']['data'][0]['capacity_count']);
    }

    public static function unacceptedPlanChanges(): array
    {
        return ['inactive mutation' => [['status' => 'completed']],
            'future review mutation' => [['next_review_date' => '2099-01-01']]];
    }

    #[DataProvider('capacityCorruptions')]
    public function test_capacity_count_requires_matching_typed_afterstate_and_immutable_digest(string $corruption): void
    {
        $capacity = $this->typedCapacity();
        $service = $this->service();
        $before = $service->index($this->viewer);
        $this->assertSame(1, $before['injuries']['data'][0]['capacity_count']);
        $this->assertSame('valid', $before['injuries']['data'][0]['private_count_integrity']['capacity_count']);
        if ($corruption === 'afterstate') {
            $capacity->updateQuietly(['capacity_status' => 'unfit_for_work']);
        } elseif ($corruption === 'revision') {
            $capacity->updateQuietly(['source_revision' => (int) $capacity->source_revision + 1]);
        } else {
            $version = WorkplaceInjurySourceVersion::where('source_type', 'capacity_assessment')
                ->where('source_id', $capacity->id)->sole();
            if ($corruption === 'missing receipt') {
                // Deliberate external damage bypasses immutable model commands only in this persisted fixture.
                DB::table('workplace_injury_source_versions')->where('id', $version->id)->delete();
            } else {
                $version->updateQuietly(['content_digest' => str_repeat('0', 64)]);
            }
        }
        $after = $service->index($this->viewer);
        $this->assertNull($after['injuries']['data'][0]['capacity_count']);
        $this->assertSame('repair_required', $after['injuries']['data'][0]['private_count_integrity']['capacity_count']);
        $this->assertSame(0, $after['injuries']['data'][0]['rtw_count']);
        $this->assertSame(0, $after['hero']['attention']['rtw_review_due']);
    }

    public static function capacityCorruptions(): array
    {
        return [['afterstate'], ['digest'], ['revision'], ['missing receipt']];
    }

    #[DataProvider('foreignReceiptTuples')]
    public function test_private_count_does_not_accept_foreign_worker_or_site_receipt(string $field): void
    {
        $capacity = $this->typedCapacity();
        $version = WorkplaceInjurySourceVersion::where('source_type', 'capacity_assessment')
            ->where('source_id', $capacity->id)->sole();
        $foreignId = $field === 'worker_id' ? $this->staff([])->id : Site::factory()->create(['is_active' => true])->id;
        $version->updateQuietly([$field => $foreignId]);
        $dto = $this->service()->index($this->viewer);
        $this->assertNull($dto['injuries']['data'][0]['capacity_count']);
        $this->assertSame('repair_required', $dto['injuries']['data'][0]['private_count_integrity']['capacity_count']);
        $this->assertSame(1, $dto['injuries']['total']);
    }

    public static function foreignReceiptTuples(): array
    {
        return [['worker_id'], ['site_id']];
    }

    public function test_typed_worker_snapshot_is_required_even_when_afterstate_digest_matches(): void
    {
        $capacity = $this->typedCapacity();
        $capacity->updateQuietly(['worker_snapshot' => ['id' => $this->staff([])->id, 'name' => 'Wrong worker']]);
        $version = WorkplaceInjurySourceVersion::where('source_type', 'capacity_assessment')
            ->where('source_id', $capacity->id)->sole();
        $content = $version->content;
        $content['after'] = $capacity->fresh()->attributesToArray();
        $version->updateQuietly(['content' => $content, 'content_digest' => RestraintCommandValues::digest($content)]);
        $dto = $this->service()->index($this->viewer);
        $this->assertNull($dto['injuries']['data'][0]['capacity_count']);
        $this->assertSame('repair_required', $dto['injuries']['data'][0]['private_count_integrity']['capacity_count']);
    }

    public function test_typed_evidence_without_receipt_cannot_downgrade_to_legacy_count(): void
    {
        $attachment = $this->typedEvidence(false);
        $this->assertTrue(Storage::disk('private')->exists($attachment->path));
        $dto = $this->service()->index($this->viewer);
        $this->assertNull($dto['injuries']['data'][0]['attachment_count']);
        $this->assertSame('repair_required', $dto['injuries']['data'][0]['private_count_integrity']['attachment_count']);
        $this->assertSame(0, $dto['injuries']['data'][0]['capacity_count']);
    }

    public function test_typed_evidence_count_rechecks_actual_bytes_and_preserves_healthy_plan_count(): void
    {
        $this->typedPlan();
        $attachment = $this->typedEvidence();
        $service = $this->service();
        $before = $service->index($this->viewer);
        $this->assertSame(1, $before['injuries']['data'][0]['attachment_count']);
        $this->assertSame('valid', $before['injuries']['data'][0]['private_count_integrity']['attachment_count']);
        Storage::disk('private')->put($attachment->path, str_repeat('x', (int) $attachment->size));
        $after = $service->index($this->viewer);
        $this->assertNull($after['injuries']['data'][0]['attachment_count']);
        $this->assertSame('repair_required', $after['injuries']['data'][0]['private_count_integrity']['attachment_count']);
        $this->assertSame(1, $after['injuries']['data'][0]['rtw_count']);
        $this->assertSame(1, $after['hero']['attention']['rtw_review_due']);
    }

    public function test_valid_typed_evidence_with_missing_bytes_counts_readable_metadata_without_download_claim(): void
    {
        $attachment = $this->typedEvidence();
        Storage::disk('private')->delete($attachment->path);
        $dto = $this->service()->index($this->viewer);
        $this->assertSame(1, $dto['injuries']['data'][0]['attachment_count']);
        $this->assertSame('valid', $dto['injuries']['data'][0]['private_count_integrity']['attachment_count']);
        $this->assertArrayNotHasKey('available_evidence', $dto['injuries']['data'][0]);
        $this->assertFalse(Storage::disk('private')->exists($attachment->path));
    }

    public function test_soft_deleted_private_sources_do_not_make_healthy_counts_corrupt(): void
    {
        $this->privateChildren();
        ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $this->staff([])->id, 'deleted_at' => now(),
            'source_schema_version' => 1, 'source_revision' => 99,
        ]));
        WorkplaceInjuryAttachment::factory()->create(['workplace_injury_id' => $this->injury->id,
            'source_schema_version' => 1, 'source_revision' => 100, 'deleted_at' => now()]);
        $dto = $this->service()->index($this->viewer);
        $row = $dto['injuries']['data'][0];
        foreach (['rtw_count', 'capacity_count', 'attachment_count'] as $key) {
            $this->assertSame(1, $row[$key]);
            $this->assertSame('legacy_unknown', $row['private_count_integrity'][$key]);
        }
        $this->assertSame(1, $dto['hero']['attention']['rtw_review_due']);
        $this->assertSame('legacy_unknown', $dto['hero']['rtw_review_due_integrity']);
    }

    public function test_review_integrity_checks_filtered_sources_beyond_current_page_and_excludes_unmatched_sources(): void
    {
        $this->injury->updateQuietly(['description' => 'Integrity target', 'injury_date' => now()->subDays(3)]);
        $this->typedPlan(false);
        for ($i = 0; $i < 25; $i++) {
            $this->record(['description' => 'Healthy other occurrence', 'injury_date' => now()->subDay()]);
        }
        $dto = $this->service()->index($this->viewer);
        $this->assertSame(26, $dto['injuries']['total']);
        $this->assertNotContains($this->injury->id, array_column($dto['injuries']['data'], 'id'));
        $this->assertNull($dto['hero']['attention']['rtw_review_due']);
        $this->assertSame('repair_required', $dto['hero']['rtw_review_due_integrity']);
        foreach ($dto['injuries']['data'] as $row) {
            $this->assertSame(0, $row['rtw_count']);
            $this->assertSame('valid', $row['private_count_integrity']['rtw_count']);
        }
        $filtered = $this->service()->index($this->viewer, ['q' => 'Healthy other']);
        $this->assertSame(25, $filtered['tabCounts']['all']);
        $this->assertSame(0, $filtered['hero']['attention']['rtw_review_due']);
        $this->assertSame('valid', $filtered['hero']['rtw_review_due_integrity']);
    }

    public function test_typed_plan_downgrade_cannot_be_a_legacy_count_or_zero_review_measure(): void
    {
        $plan = $this->typedPlan();
        $this->typedCapacity();
        $before = $this->service()->index($this->viewer);
        $this->assertSame(1, $before['injuries']['data'][0]['rtw_count']);
        $this->assertSame(1, $before['hero']['attention']['rtw_review_due']);
        DB::table('return_to_work_plans')->where('id', $plan->id)->update([
            'source_schema_version' => 0, 'source_revision' => 0, 'status' => 'completed',
        ]);
        $after = $this->service()->index($this->viewer);
        $this->assertNull($after['injuries']['data'][0]['rtw_count']);
        $this->assertSame('repair_required', $after['injuries']['data'][0]['private_count_integrity']['rtw_count']);
        $this->assertSame(1, $after['injuries']['data'][0]['capacity_count']);
        $this->assertSame('valid', $after['injuries']['data'][0]['private_count_integrity']['capacity_count']);
        $this->assertNull($after['hero']['attention']['rtw_review_due']);
        $this->assertSame('repair_required', $after['hero']['rtw_review_due_integrity']);
        $this->assertSame('unavailable', $after['hero']['measure_availability']['rtw_review_due']);
    }

    public function test_typed_capacity_downgrade_does_not_change_only_damaged_count_to_legacy_unknown(): void
    {
        $capacity = $this->typedCapacity();
        $this->typedPlan();
        $before = $this->service()->index($this->viewer);
        $this->assertSame(1, $before['injuries']['data'][0]['capacity_count']);
        DB::table('work_capacity_assessments')->where('id', $capacity->id)->update([
            'source_schema_version' => 0, 'source_revision' => 0,
        ]);
        $after = $this->service()->index($this->viewer);
        $this->assertNull($after['injuries']['data'][0]['capacity_count']);
        $this->assertSame('repair_required', $after['injuries']['data'][0]['private_count_integrity']['capacity_count']);
        $this->assertSame(1, $after['injuries']['data'][0]['rtw_count']);
        $this->assertSame('valid', $after['injuries']['data'][0]['private_count_integrity']['rtw_count']);
        $this->assertSame(1, $after['hero']['attention']['rtw_review_due']);
        $this->assertSame('valid', $after['hero']['rtw_review_due_integrity']);
    }

    public function test_downgraded_typed_children_do_not_trigger_private_proof_queries_for_factual_register_readers(): void
    {
        $plan = $this->typedPlan();
        $capacity = $this->typedCapacity();
        DB::table('return_to_work_plans')->where('id', $plan->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        DB::table('work_capacity_assessments')->where('id', $capacity->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $viewer = $this->staff(['hazards.view']);
        [$dto, $queries] = $this->queries(fn () => $this->service()->index($viewer));
        $this->assertSame('restricted', $dto['medical_access']);
        foreach (['rtw_count', 'capacity_count', 'attachment_count'] as $key) {
            $this->assertNull($dto['injuries']['data'][0][$key]);
            $this->assertSame('restricted', $dto['injuries']['data'][0]['private_count_integrity'][$key]);
        }
        $this->assertNull($dto['hero']['attention']['rtw_review_due']);
        $this->assertFactualQueries($queries);
    }

    public function test_schema_zero_nonzero_revision_has_unavailable_count_while_actual_legacy_zero_is_retained(): void
    {
        $this->privateChildren();
        $before = $this->service()->index($this->viewer);
        $this->assertSame(1, $before['injuries']['data'][0]['capacity_count']);
        $this->assertSame('legacy_unknown', $before['injuries']['data'][0]['private_count_integrity']['capacity_count']);
        DB::table('work_capacity_assessments')->where('workplace_injury_id', $this->injury->id)->update(['source_revision' => 7]);
        $after = $this->service()->index($this->viewer);
        $this->assertNull($after['injuries']['data'][0]['capacity_count']);
        $this->assertSame('repair_required', $after['injuries']['data'][0]['private_count_integrity']['capacity_count']);
        $this->assertSame(1, $after['injuries']['data'][0]['rtw_count']);
        $this->assertSame('legacy_unknown', $after['injuries']['data'][0]['private_count_integrity']['rtw_count']);
    }
    public function test_parent_marker_downgrade_keeps_factual_row_but_makes_all_private_counts_and_review_measure_unavailable(): void
    {
        $this->typedPlan();
        $before = $this->service()->index($this->viewer);
        $this->assertSame('valid', $before['injuries']['data'][0]['source_integrity']);
        $this->assertSame(1, $before['injuries']['data'][0]['rtw_count']);
        DB::table('workplace_injuries')->where('id', $this->injury->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        [$dto, $queries] = $this->queries(fn () => $this->service()->index($this->viewer));
        $row = $dto['injuries']['data'][0];
        $this->assertSame($this->injury->id, $row['id']);
        $this->assertSame('repair_required', $row['source_integrity']);
        foreach (['rtw_count', 'capacity_count', 'attachment_count'] as $key) {
            $this->assertNull($row[$key]);
            $this->assertSame('repair_required', $row['private_count_integrity'][$key]);
        }
        $this->assertNull($row['acc_claim_number']);
        $this->assertNull($dto['hero']['attention']['rtw_review_due']);
        $this->assertSame('unavailable', $dto['hero']['measure_availability']['rtw_review_due']);
        foreach ($queries as $entry) {
            foreach (['return_to_work_plans', 'work_capacity_assessments', 'workplace_injury_attachments'] as $table) {
                $this->assertStringNotContainsString($table, strtolower($entry['query']));
            }
            $this->assertDoesNotMatchRegularExpression('/(?<![a-z_])(?:content|content_digest)(?![a-z_])/i', $entry['query']);
        }
        $cells = [];
        $this->service()->export($this->viewer, [], function (array $row) use (&$cells): void { $cells[] = $row; });
        $this->assertCount(1, $cells);
        $this->assertNull($cells[0][10]);
    }

    public function test_genuine_legacy_parent_keeps_unknown_provenance_and_real_private_counts_without_inventing_recorded_integrity(): void
    {
        $this->privateChildren();
        $dto = $this->service()->index($this->viewer);
        $row = $dto['injuries']['data'][0];
        $this->assertSame('unknown', $row['source_integrity']);
        $this->assertSame(1, $row['rtw_count']);
        $this->assertSame('legacy_unknown', $row['private_count_integrity']['rtw_count']);
        $this->revoke($this->viewer, 'hr.wellbeing.view');
        [$restricted, $queries] = $this->queries(fn () => $this->service()->index($this->viewer));
        $this->assertSame('unknown', $restricted['injuries']['data'][0]['source_integrity']);
        $this->assertNull($restricted['injuries']['data'][0]['rtw_count']);
        $this->assertFactualQueries($queries);
    }

    private function typedPlan(bool $accepted = true): ReturnToWorkPlan
    {
        $revision = max(1, (int) $this->injury->fresh()->source_revision + 1);
        $plan = ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $this->worker->id,
            'source_schema_version' => 1, 'source_revision' => $revision,
            'worker_snapshot' => ['id' => $this->worker->id, 'name' => $this->worker->name],
            'status' => 'active', 'next_review_date' => now('Pacific/Auckland')->subDay()->toDateString(),
        ]));
        if ($accepted) {
            $this->version($plan, 'rtw_plan', $revision);
        }
        return $plan;
    }

    private function typedCapacity(): WorkCapacityAssessment
    {
        $revision = max(1, (int) $this->injury->fresh()->source_revision + 1);
        $capacity = WorkCapacityAssessment::withoutEvents(fn () => WorkCapacityAssessment::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'user_id' => $this->worker->id, 'worker_id' => $this->worker->id,
            'source_schema_version' => 1, 'source_revision' => $revision,
            'worker_snapshot' => ['id' => $this->worker->id, 'name' => $this->worker->name],
            'capacity_status' => 'fit_for_modified_duties',
        ]));
        $this->version($capacity, 'capacity_assessment', $revision);
        return $capacity;
    }

    private function typedEvidence(bool $accepted = true): WorkplaceInjuryAttachment
    {
        $revision = max(1, (int) $this->injury->fresh()->source_revision + 1);
        $bytes = 'Accepted private evidence';
        $path = 'workplace_injury_attachments/'.$this->injury->id.'/register-'.$revision.'.pdf';
        Storage::disk('private')->put($path, $bytes);
        $attachment = WorkplaceInjuryAttachment::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'uploaded_by' => $this->viewer->id,
            'source_schema_version' => 1, 'source_revision' => $revision, 'disk' => 'private',
            'path' => $path, 'original_name' => 'accepted-evidence.pdf', 'mime' => 'application/pdf', 'size' => strlen($bytes),
        ]);
        if ($accepted) {
            $this->version($attachment, 'attachment', $revision, ['command' => 'upload_evidence',
                'content' => ['command_facts' => ['file_evidence' => ['sha256' => hash('sha256', $bytes),
                    'size' => strlen($bytes), 'original_name' => $attachment->original_name, 'mime' => $attachment->mime]]]]);
        }
        return $attachment;
    }

    private function service(): InjuryRegisterService
    {
        return app(InjuryRegisterService::class);
    }

    private function record(array $attributes = []): WorkplaceInjury
    {
        return WorkplaceInjury::withoutEvents(fn () => WorkplaceInjury::factory()->create([
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'created_by' => $this->viewer->id,
            'reference_number' => 'INJ-'.str()->uuid(), 'status' => 'reported', 'severity' => 'minor',
            'injury_type' => 'strain', 'body_part_affected' => 'Shoulder', 'description' => 'Factual occurrence',
            'injury_date' => now()->subDay(), 'lost_time_days' => 0, 'worksafe_notifiable' => false,
            'acc_claim_lodged' => false, 'medical_treatment_type' => 'none', ...$attributes,
        ]));
    }

    private function privateChildren(): void
    {
        ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $this->worker->id,
            'source_schema_version' => 0, 'source_revision' => 0,
            'next_review_date' => now('Pacific/Auckland')->subDay()->toDateString(),
            'medical_clearance_provider' => 'Medical assessor sentinel',
        ]));
        WorkCapacityAssessment::withoutEvents(fn () => WorkCapacityAssessment::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => null, 'source_schema_version' => 0, 'source_revision' => 0,
            'user_id' => $this->worker->id, 'assessor_name' => 'Medical assessor sentinel',
        ]));
        WorkplaceInjuryAttachment::factory()->create(['workplace_injury_id' => $this->injury->id,
            'source_schema_version' => 0, 'source_revision' => 0, 'original_name' => 'private-certificate.pdf']);
    }

    private function queries(callable $read): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = $read();
            return [$result, DB::getQueryLog()];
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    private function assertFactualQueries(array $queries): void
    {
        foreach ($queries as $entry) {
            $sql = strtolower($entry['query']);
            foreach (['return_to_work_plans', 'modified_duties', 'work_capacity_assessments', 'workplace_injury_attachments',
                'audit_logs', 'medical_treatment_type', 'immediate_treatment', 'acc_claim_number'] as $private) {
                $this->assertStringNotContainsString($private, $sql);
            }
            $this->assertDoesNotMatchRegularExpression('/(?<![a-z_])(?:content|content_digest)(?![a-z_])/i', $sql);
            if (str_contains($sql, 'workplace_injury_source_versions')) {
                $this->assertDoesNotMatchRegularExpression('/^\s*select\s+(?:[`"]?workplace_injury_source_versions[`"]?\.)?\*/i', $sql);
            }
            $this->assertDoesNotMatchRegularExpression('/select\s+(?:[`"]?workplace_injuries[`"]?\.)?\*\s+from\s+[`"]?workplace_injuries/i', $sql);
        }
    }

    private function assertNoInjuryQueries(array $queries): void
    {
        foreach ($queries as $entry) {
            $this->assertStringNotContainsString('workplace_injuries', strtolower($entry['query']));
        }
    }

    private function assertDetached(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $nested) {
                $this->assertDetached($nested);
            }
        } else {
            $this->assertTrue($value === null || is_scalar($value), 'No model, query, collection or authority may escape.');
        }
    }
}