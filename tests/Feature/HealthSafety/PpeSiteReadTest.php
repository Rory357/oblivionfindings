<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\PpeInventory;
use App\Models\PpeAllocation;
use App\Models\PpeType;
use App\Services\HealthSafety\PpeCommandService;
use App\Services\HealthSafety\PpeSiteReadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Complete Site-reader source suite. Root-owned attention/calendar mounts are tested after separate integration. */
final class PpeSiteReadTest extends PpeReadTestCase
{
    public function test_site_attention_preserves_due_item_shape_and_canonical_site_back(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-12', 'expiry_date' => '2026-10-20']);
        $rows = $this->read()->attentionForSite($this->viewer, $this->site->id);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('ppe:'.$this->item->id, $row['id']);
        $this->assertSame('Recorded respirator', $row['title']);
        $this->assertSame('2026-10-12', $row['due_date']);
        $this->assertSame('ppe', $row['source']);
        $this->assertSame('ppe', $row['tab']);
        $this->assertSame('legacy_unknown', $row['source_integrity']);
        $this->assertCanonicalReturn($row['href'], $this->item->id, $this->site->id);
        $this->assertDetached($rows);
    }

    public function test_attention_keeps_actual_inspection_today_and_thirty_day_expiry_boundaries(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-13', 'expiry_date' => '2026-11-12']);
        $this->assertSame([], $this->read()->attentionForSite($this->viewer, $this->site->id));
        $this->item->update(['expiry_date' => '2026-11-11']);
        $this->assertCount(1, $this->read()->attentionForSite($this->viewer, $this->site->id));
        $this->item->update(['expiry_date' => null, 'next_inspection_due' => '2026-10-12']);
        $this->assertSame('2026-10-12', $this->read()->attentionForSite($this->viewer, $this->site->id)[0]['due_date']);
        $this->item->update(['next_inspection_due' => null]);
        $this->assertSame([], $this->read()->attentionForSite($this->viewer, $this->site->id));
    }

    public function test_calendar_keeps_two_distinct_date_only_occurrences_without_certifying_release(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-11', 'expiry_date' => '2026-10-12']);
        $rows = $this->obligations();
        $this->assertCount(2, $rows);
        $events = collect($rows)->keyBy('id');
        $inspection = $events['ppe-'.$this->item->id.'-next_inspection_due'];
        $expiry = $events['ppe-'.$this->item->id.'-expiry_date'];
        $this->assertSame('overdue', $inspection['status']);
        $this->assertSame('scheduled', $expiry['status']);
        $this->assertSame('2026-10-12T00:00:00+13:00', $expiry['start']);
        $this->assertTrue($expiry['allDay']);
        $this->assertFalse($expiry['editable']);
        $this->assertSame('OWN-ITEM', $expiry['ref']);
        $this->assertSame('auto', $expiry['group']);
        $this->assertNull($expiry['approvalStatus']);
        $this->assertCanonicalReturn($expiry['link'], $this->item->id, $this->site->id);
        $this->assertDetached($rows);
    }

    public function test_calendar_inclusive_range_uses_nz_days_and_does_not_mutate_requested_range(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-12', 'expiry_date' => '2026-10-13']);
        $start = Carbon::parse('2026-10-11T11:00:00Z');
        $end = Carbon::parse('2026-10-12T11:00:00Z');
        $before = [$start->toIso8601String(), $end->toIso8601String()];
        $rows = $this->read()->obligationsForViewer([$this->site->id], $start, $end, $this->viewer);
        $this->assertCount(2, $rows);
        $this->assertSame($before, [$start->toIso8601String(), $end->toIso8601String()]);
        $rows = $this->read()->obligationsForViewer([$this->site->id], $start, Carbon::parse('2026-10-12T10:59:59Z'), $this->viewer);
        $this->assertCount(1, $rows);
        $this->assertSame('ppe-'.$this->item->id.'-next_inspection_due', $rows[0]['id']);
    }

    public function test_today_and_dst_midnight_are_worker_calendar_facts_independent_of_server_timezone(): void
    {
        config(['app.timezone' => 'UTC']);
        $this->travelTo(Carbon::parse('2026-10-11T11:30:00Z'));
        $this->item->update(['next_inspection_due' => '2026-10-12']);
        $this->assertSame('2026-10-12', $this->read()->attentionForSite($this->viewer, $this->site->id)[0]['due_date']);
        $rows = $this->obligations();
        $this->assertSame('scheduled', $rows[0]['status']);
        $this->assertSame('2026-10-12T00:00:00+13:00', $rows[0]['start']);
        $this->item->update(['next_inspection_due' => '2026-09-27']);
        $rows = $this->read()->obligationsForViewer([$this->site->id], Carbon::parse('2026-09-26T12:00:00Z'), Carbon::parse('2026-09-27T10:59:59Z'), $this->viewer);
        $this->assertSame('2026-09-27T00:00:00+12:00', $rows[0]['start']);
    }

    public function test_non_auckland_worker_zone_controls_today_bounds_and_emitted_midnight_together(): void
    {
        config(['app.worker_timezone' => 'America/Los_Angeles', 'app.timezone' => 'UTC']);
        $this->travelTo(Carbon::parse('2026-10-12T03:30:00Z')->utc());
        $this->item->update(['next_inspection_due' => '2026-10-12', 'expiry_date' => null]);
        $this->assertSame([], $this->read()->attentionForSite($this->viewer, $this->site->id));
        $beforeMidnight = $this->read()->obligationsForViewer([$this->site->id],
            Carbon::parse('2026-10-11T07:00:00Z'), Carbon::parse('2026-10-12T06:59:59Z'), $this->viewer);
        $this->assertSame([], $beforeMidnight);
        $this->travelTo(Carbon::parse('2026-10-12T07:00:00Z')->utc());
        $this->assertCount(1, $this->read()->attentionForSite($this->viewer, $this->site->id));
        $rows = $this->read()->obligationsForViewer([$this->site->id],
            Carbon::parse('2026-10-12T07:00:00Z'), Carbon::parse('2026-10-13T06:59:59Z'), $this->viewer);
        $this->assertCount(1, $rows);
        $this->assertSame('2026-10-12T00:00:00-07:00', $rows[0]['start']);
        $this->assertSame('scheduled', $rows[0]['status']);
        $this->assertCanonicalReturn($rows[0]['link'], $this->item->id, $this->site->id);
    }

    public function test_non_auckland_summer_winter_and_dst_transition_dates_keep_their_actual_midnight_offsets(): void
    {
        config(['app.worker_timezone' => 'America/Los_Angeles', 'app.timezone' => 'UTC']);
        foreach ([['2026-01-04', '-08:00'], ['2026-07-04', '-07:00'], ['2026-03-08', '-08:00'],
            ['2026-03-09', '-07:00'], ['2026-11-01', '-07:00'], ['2026-11-02', '-08:00']] as [$day, $offset]) {
            $this->item->update(['next_inspection_due' => $day, 'expiry_date' => null]);
            $midnight = Carbon::parse($day.'T00:00:00'.$offset);
            $rows = $this->read()->obligationsForViewer([$this->site->id], $midnight, $midnight->copy()->addHours(1), $this->viewer);
            $this->assertCount(1, $rows);
            $this->assertSame($day.'T00:00:00'.$offset, $rows[0]['start']);
        }
    }

    public function test_configured_calendar_edges_are_inclusive_and_repair_counts_never_acquire_dates(): void
    {
        config(['app.worker_timezone' => 'America/Los_Angeles', 'app.timezone' => 'UTC']);
        $this->item->update(['next_inspection_due' => '2026-10-12', 'expiry_date' => '2026-10-13']);
        $damaged = $this->typedItem();
        DB::table('ppe_inventory')->where('id', $damaged->id)->update(['source_revision' => 999]);
        $start = Carbon::parse('2026-10-12T07:00:00Z');
        $end = Carbon::parse('2026-10-13T06:59:59Z');
        $before = [$start->toIso8601String(), $end->toIso8601String()];
        $rows = $this->read()->obligationsForViewer([$this->site->id], $start, $end, $this->viewer);
        $this->assertCount(2, $rows);
        $this->assertCount(1, array_filter($rows, fn ($row) => $row['start'] !== null));
        $repair = collect($rows)->firstWhere('source_integrity', 'repair_required');
        $this->assertNull($repair['start']);
        $this->assertNull($repair['end']);
        $this->assertNull($repair['ref']);
        $this->assertCanonicalReturn($repair['link'], $damaged->id, $this->site->id);
        $this->assertSame($before, [$start->toIso8601String(), $end->toIso8601String()]);
        $rows = $this->read()->obligationsForViewer([$this->site->id], $start, Carbon::parse('2026-10-13T07:00:00Z'), $this->viewer);
        $this->assertCount(3, $rows);
        $this->assertCount(2, array_filter($rows, fn ($row) => $row['start'] !== null));
        $outside = $this->read()->obligationsForViewer([$this->site->id], Carbon::parse('2026-10-14T07:00:00Z'), Carbon::parse('2026-10-14T08:00:00Z'), $this->viewer);
        $this->assertCount(1, $outside);
        $this->assertSame('repair_required', $outside[0]['source_integrity']);
        $this->assertNull($outside[0]['start']);
    }

    public function test_timezone_resolver_prefers_worker_then_application_then_utc_when_configuration_is_absent(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-12', 'expiry_date' => null]);
        foreach ([['America/Los_Angeles', 'Pacific/Auckland', '2026-10-12T00:00:00-07:00'],
            [null, 'America/Los_Angeles', '2026-10-12T00:00:00-07:00'], ['', 'UTC', '2026-10-12T00:00:00+00:00'],
            [null, null, '2026-10-12T00:00:00+00:00']] as [$worker, $app, $midnight]) {
            config(['app.worker_timezone' => $worker, 'app.timezone' => $app]);
            $edge = Carbon::parse($midnight);
            $rows = $this->read()->obligationsForViewer([$this->site->id], $edge, $edge->copy()->addHour(), $this->viewer);
            $this->assertCount(1, $rows);
            $this->assertSame($midnight, $rows[0]['start']);
        }
    }

    public function test_attention_uses_application_fallback_today_and_thirty_day_horizon(): void
    {
        config(['app.worker_timezone' => null, 'app.timezone' => 'America/Los_Angeles']);
        $this->travelTo(Carbon::parse('2026-10-12T03:30:00Z')->utc());
        $this->item->update(['next_inspection_due' => '2026-10-12', 'expiry_date' => '2026-11-11']);
        $this->assertSame([], $this->read()->attentionForSite($this->viewer, $this->site->id));
        $this->item->update(['next_inspection_due' => null, 'expiry_date' => '2026-11-10']);
        $this->assertCount(1, $this->read()->attentionForSite($this->viewer, $this->site->id));
        config(['app.worker_timezone' => null, 'app.timezone' => null]);
        $this->item->update(['next_inspection_due' => '2026-10-12', 'expiry_date' => null]);
        $this->assertCount(1, $this->read()->attentionForSite($this->viewer, $this->site->id));
    }

    public function test_invalid_configured_zone_is_explicit_unavailable_without_ppe_hydration_or_silent_fallback(): void
    {
        config(['app.worker_timezone' => 'Not/A_Timezone', 'app.timezone' => 'UTC']);
        [$unused, $queries] = $this->queries(function () {
            foreach ([fn () => $this->read()->attentionForSite($this->viewer, $this->site->id), fn () => $this->obligations()] as $read) {
                try {
                    $read();
                    $this->fail('Invalid configuration must not use a silently substituted calendar day.');
                } catch (\LogicException $unavailable) {
                    $this->assertStringContainsString('timezone is invalid', $unavailable->getMessage());
                }
            }
            return null;
        });
        $this->assertNoPpeSql($queries);
    }

    public function test_null_viewer_and_empty_site_requests_are_closed_without_any_ppe_lookup(): void
    {
        [$rows, $queries] = $this->queries(fn () => $this->read()->obligationsForViewer([$this->site->id], $this->start(), $this->end(), null));
        $this->assertSame([], $rows);
        $this->assertSame([], $queries);
        [$rows, $queries] = $this->queries(fn () => $this->read()->obligationsForViewer([], $this->start(), $this->end(), $this->viewer));
        $this->assertSame([], $rows);
        $this->assertSame([], $queries);
    }

    public function test_module_permission_is_current_and_denied_before_inventory_or_type_hydration(): void
    {
        $this->viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->item->update(['next_inspection_due' => '2026-10-12']);
        $this->assertCount(1, $this->read()->attentionForSite($this->viewer, $this->site->id));
        $this->allow($this->viewer, 'hazards.view', false);
        [$unused, $queries] = $this->queries(function () {
            $this->denied(fn () => $this->read()->attentionForSite($this->viewer, $this->site->id), 403);
            $this->denied(fn () => $this->obligations(), 403);
            return null;
        });
        $this->assertNoPpeSql($queries);
        $this->allow($this->viewer, 'hazards.view', true);
        $this->assertCount(1, $this->obligations());
    }

    public function test_attention_exact_foreign_site_denies_without_private_source_hydration(): void
    {
        [$unused, $queries] = $this->queries(function () {
            $this->denied(fn () => $this->read()->attentionForSite($this->viewer, $this->foreignSite->id), 404);
            return null;
        });
        $this->assertNoPpeSql($queries);
    }

    public function test_multi_site_calendar_intersects_requested_sites_and_never_hydrates_foreign_source(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-12']);
        $this->foreignItem->update(['next_inspection_due' => '2026-10-12']);
        $rows = $this->read()->obligationsForViewer([$this->site->id, $this->foreignSite->id, $this->site->id], $this->start(), $this->end(), $this->viewer);
        $this->assertCount(1, $rows);
        $this->assertSame('ppe-'.$this->item->id.'-next_inspection_due', $rows[0]['id']);
        $this->assertStringNotContainsString('FOREIGN-PRIVATE', json_encode($rows, JSON_THROW_ON_ERROR));
        [$foreignOnly, $queries] = $this->queries(fn () => $this->read()->obligationsForViewer([$this->foreignSite->id], $this->start(), $this->end(), $this->viewer));
        $this->assertSame([], $foreignOnly);
        $this->assertNoPpeSql($queries);
    }

    public function test_current_site_membership_revoke_and_restore_changes_both_projections(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-12']);
        $this->assertCount(1, $this->obligations());
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->denied(fn () => $this->read()->attentionForSite($this->viewer, $this->site->id), 404);
        $this->assertSame([], $this->obligations());
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertCount(1, $this->read()->attentionForSite($this->viewer, $this->site->id));
        $this->assertCount(1, $this->obligations());
    }

    public function test_inactive_archived_site_and_noncurrent_or_external_actor_never_become_record_readers(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-12']);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => false]);
        $this->denied(fn () => $this->obligations(), 403);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => true]);
        $this->site->update(['is_active' => false]);
        $this->denied(fn () => $this->read()->attentionForSite($this->viewer, $this->site->id), 404);
        $this->assertSame([], $this->obligations());
        $this->site->update(['is_active' => true, 'archived_at' => now()]);
        $this->assertSame([], $this->obligations());
        $this->site->update(['archived_at' => null]);
        $external = $this->staff(['hazards.view']);
        $external->forceFill(['external_clinical_account' => true])->save(); // not mass-assignable on main's User
        $this->denied(fn () => $this->read()->obligationsForViewer([$this->site->id], $this->start(), $this->end(), $external), 403);
        $this->assertCount(1, $this->obligations());
    }

    public function test_invalid_site_selectors_never_infer_staff_site_even_for_a_global_reader(): void
    {
        $this->allow($this->viewer, 'healthSafety.viewAllSites', true);
        // Inventory Site is NOT NULL in the real schema. Exercise invalid selectors without inventing a nullable source.
        [$unused, $queries] = $this->queries(function () {
            foreach ([null, 0, -1, (string) $this->site->id] as $invalid) {
                try {
                    $this->read()->obligationsForViewer([$invalid], $this->start(), $this->end(), $this->viewer);
                    $this->fail('An invalid Site selector must not become an approved Site.');
                } catch (\LogicException $denial) {
                    $this->assertStringContainsString('canonical positive integer', $denial->getMessage());
                }
            }
            $this->denied(fn () => $this->read()->attentionForSite($this->viewer, 0), 404);
            return null;
        });
        $this->assertNoPpeSql($queries);
    }

    public function test_damaged_admitted_root_returns_one_undated_marker_without_raw_type_serial_or_dates(): void
    {
        $item = $this->typedItem();
        DB::table('ppe_inventory')->where('id', $item->id)->update(['status' => 'disposed', 'serial_number' => 'TAMPERED-PRIVATE-SERIAL',
            'expiry_date' => '2099-01-01', 'next_inspection_due' => '2099-02-01']);
        $attention = $this->read()->attentionForSite($this->viewer, $this->site->id);
        $this->assertCount(1, $attention);
        $this->assertSame('repair_required', $attention[0]['source_integrity']);
        $this->assertNull($attention[0]['due_date']);
        $this->assertCanonicalReturn($attention[0]['href'], $item->id, $this->site->id);
        $calendar = $this->obligations();
        $this->assertCount(1, $calendar);
        $this->assertSame('repair_required', $calendar[0]['status']);
        $this->assertNull($calendar[0]['start']);
        $this->assertNull($calendar[0]['end']);
        $this->assertNull($calendar[0]['ref']);
        $this->assertNull($calendar[0]['owner']);
        $this->assertSame($this->site->id, $calendar[0]['site']['id']);
        $this->assertSame($this->site->name, $calendar[0]['site']['name']);
        $this->assertSame('repair_required', $calendar[0]['attribution_state']);
        foreach (['TAMPERED-PRIVATE-SERIAL', 'Recorded respirator', '2099-01-01', '2099-02-01'] as $fact) {
            $this->assertStringNotContainsString($fact, json_encode([$attention, $calendar], JSON_THROW_ON_ERROR));
        }
        $this->assertCanonicalReturn($calendar[0]['link'], $item->id, $this->site->id);
    }

    public function test_full_catalogue_proof_is_required_before_its_label_or_dates_are_projected(): void
    {
        $type = $this->typedType();
        $this->item->update(['ppe_type_id' => $type->id, 'next_inspection_due' => '2026-10-12']);
        DB::table('ppe_types')->where('id', $type->id)->update(['name' => 'TAMPERED-CATALOGUE-NAME']);
        $attention = $this->read()->attentionForSite($this->viewer, $this->site->id);
        $calendar = $this->obligations();
        $this->assertSame('repair_required', $attention[0]['source_integrity']);
        $this->assertSame('repair_required', $calendar[0]['source_integrity']);
        $this->assertNull($calendar[0]['start']);
        $this->assertStringNotContainsString('TAMPERED-CATALOGUE-NAME', json_encode([$attention, $calendar], JSON_THROW_ON_ERROR));
    }

    public function test_typed_marker_downgrade_or_missing_version_cannot_become_legacy_calendar_facts(): void
    {
        $item = $this->typedItem();
        DB::table('ppe_inventory')->where('id', $item->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->assertSame('repair_required', $this->obligations()[0]['source_integrity']);
        DB::table('ppe_inventory')->where('id', $item->id)->update(['source_schema_version' => 1, 'source_revision' => 1]);
        DB::table('ppe_source_versions')->where('root_type', 'inventory')->where('root_id', $item->id)->delete();
        $this->assertSame('repair_required', $this->read()->attentionForSite($this->viewer, $this->site->id)[0]['source_integrity']);
    }

    public function test_source_reparent_and_site_move_are_canonical_not_warmed_old_record_context(): void
    {
        $item = $this->typedItem();
        $this->assertCount(1, $this->obligations());
        DB::table('ppe_inventory')->where('id', $item->id)->update(['site_id' => $this->foreignSite->id]);
        $this->assertSame([], $this->obligations());
        $this->allow($this->viewer, 'healthSafety.viewAllSites', true);
        $rows = $this->read()->obligationsForViewer([$this->foreignSite->id], $this->start(), $this->end(), $this->viewer);
        $this->assertSame('repair_required', $rows[0]['source_integrity']);
        DB::table('ppe_inventory')->where('id', $item->id)->update(['site_id' => $this->site->id, 'ppe_type_id' => PpeType::factory()->create()->id]);
        $this->assertSame('repair_required', $this->obligations()[0]['source_integrity']);
    }

    public function test_historical_terminal_and_retired_catalogue_facts_keep_existing_attention_calendar_parity(): void
    {
        $this->item->update(['status' => 'condemned', 'next_inspection_due' => '2026-10-12']);
        $this->assertCount(1, $this->read()->attentionForSite($this->viewer, $this->site->id));
        $this->assertSame([], $this->obligations());
        $this->item->update(['status' => 'disposed']);
        $this->assertSame([], $this->read()->attentionForSite($this->viewer, $this->site->id));
        $this->item->update(['status' => 'maintenance']);
        $this->type->update(['is_active' => false]);
        $this->assertCount(1, $this->obligations());
        $this->item->delete();
        $this->assertSame([], $this->obligations());
    }

    public function test_true_governed_source_and_type_proof_are_intact_and_missing_type_is_a_repair_marker(): void
    {
        $type = $this->typedType();
        $item = $this->typedItem(['ppe_type_id' => $type->id]);
        $rows = $this->obligations();
        $this->assertSame('intact', $rows[0]['source_integrity']);
        $this->assertSame('recorded', $rows[0]['attribution_state']);
        $type->delete();
        $rows = $this->obligations();
        $this->assertCount(1, $rows);
        $this->assertSame('repair_required', $rows[0]['source_integrity']);
        $this->assertCanonicalReturn($rows[0]['link'], $item->id, $this->site->id);
    }

    public function test_many_item_dates_use_one_current_authority_callback_and_keep_all_repair_markers(): void
    {
        PpeInventory::factory()->count(13)->create(['site_id' => $this->site->id, 'ppe_type_id' => $this->type->id,
            'expiry_date' => null, 'next_inspection_due' => '2026-10-12']);
        $item = $this->typedItem();
        DB::table('ppe_inventory')->where('id', $item->id)->update(['source_revision' => 999]);
        [$rows, $queries] = $this->queries(fn () => $this->obligations());
        $this->assertCount(14, $rows);
        $this->assertCount(1, array_filter($rows, fn ($row) => $row['source_integrity'] === 'repair_required'));
        $this->assertCount(1, array_filter($queries, fn ($sql) => preg_match('/from\s+[`"]?permissions[`"]?\s/i', $sql)));
        $attention = $this->read()->attentionForSite($this->viewer, $this->site->id);
        $this->assertCount(14, $attention);
        $this->assertSame('repair_required', $attention[0]['source_integrity']);
    }

    public function test_profile_retains_existing_fields_and_civil_today_flags_with_canonical_site_back(): void
    {
        $this->item->update(['condition' => 'fair', 'quantity' => 3, 'status' => 'maintenance', 'location' => 'Store A',
            'expiry_date' => '2026-10-12', 'next_inspection_due' => '2026-10-12']);
        $dto = $this->read()->profileForSite($this->viewer, $this->site->id);
        $this->assertFalse($dto['locked']);
        $this->assertCount(1, $dto['items']);
        $row = $dto['items'][0];
        $this->assertSame(['name' => 'Recorded respirator', 'condition' => 'fair', 'quantity' => 3, 'status' => 'maintenance',
            'location' => 'Store A', 'expiry_date' => '2026-10-12', 'next_inspection_due' => '2026-10-12',
            'expired' => false, 'inspection_due' => true], array_intersect_key($row, array_flip([
                'name', 'condition', 'quantity', 'status', 'location', 'expiry_date', 'next_inspection_due', 'expired', 'inspection_due'])));
        $this->assertSame(3, $dto['confirmed_units']);
        $this->assertSame(1, $dto['physical_record_count']);
        $this->assertFalse($dto['truncated']);
        $this->assertCanonicalReturn($row['href'], $this->item->id, $this->site->id);
        $this->assertDetached($dto);
    }

    public function test_profile_proves_all_rows_before_hundred_row_cap_and_places_damaged_terminal_row_first(): void
    {
        PpeInventory::factory()->count(101)->create(['site_id' => $this->site->id, 'ppe_type_id' => $this->type->id,
            'expiry_date' => null, 'next_inspection_due' => null, 'quantity' => 2]);
        $damaged = $this->typedItem();
        DB::table('ppe_inventory')->where('id', $damaged->id)->update(['status' => 'disposed', 'quantity' => 999,
            'location' => 'UNPROVEN-LOCATION', 'expiry_date' => '2099-01-01']);
        $dto = $this->read()->profileForSite($this->viewer, $this->site->id);
        $this->assertCount(100, $dto['items']);
        $this->assertSame(103, $dto['physical_record_count']);
        $this->assertSame(100, $dto['displayed_count']);
        $this->assertTrue($dto['truncated']);
        $this->assertSame(1, $dto['repair_count']);
        $this->assertNull($dto['confirmed_units']);
        $row = $dto['items'][0];
        $this->assertSame($damaged->id, $row['id']);
        foreach (['condition', 'quantity', 'status', 'location', 'expiry_date', 'next_inspection_due', 'expired', 'inspection_due'] as $key) {
            $this->assertNull($row[$key]);
        }
        $this->assertCanonicalReturn($row['href'], $damaged->id, $this->site->id);
        $this->assertStringNotContainsString('UNPROVEN-LOCATION', json_encode($dto, JSON_THROW_ON_ERROR));
    }

    public function test_profile_management_requires_distinct_current_site_update_type_and_assignment_rights(): void
    {
        $this->site->update(['type' => 'house']);
        $this->assertFalse($this->read()->profileForSite($this->viewer, $this->site->id)['can_manage']);
        $this->allow($this->viewer, 'sites.update', true);
        $this->assertTrue($this->read()->profileForSite($this->viewer, $this->site->id)['can_manage']);
        $this->viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->allow($this->viewer, 'sites.update', false);
        $this->assertFalse($this->read()->profileForSite($this->viewer, $this->site->id)['can_manage']);
        $this->allow($this->viewer, 'sites.update', true);
        $this->allow($this->viewer, 'sites.type.facility.view', true);
        $this->assertFalse($this->read()->profileForSite($this->viewer, $this->site->id)['can_manage']);
        $this->allow($this->viewer, 'sites.type.house.view', true);
        $this->assertTrue($this->read()->profileForSite($this->viewer, $this->site->id)['can_manage']);
        $this->allow($this->viewer, 'healthSafety.viewAllSites', true);
        $this->foreignSite->update(['type' => 'house']);
        $this->assertFalse($this->read()->profileForSite($this->viewer, $this->foreignSite->id)['can_manage']);
        $this->allow($this->viewer, 'sites.viewAll', true);
        $this->assertTrue($this->read()->profileForSite($this->viewer, $this->foreignSite->id)['can_manage']);
        $this->allow($this->viewer, 'sites.viewAll', false);
        $this->assertFalse($this->read()->profileForSite($this->viewer, $this->foreignSite->id)['can_manage']);
        $this->allow($this->viewer, 'hazards.manage', false);
        $this->assertFalse($this->read()->profileForSite($this->viewer, $this->site->id)['can_manage']);
    }

    public function test_kpis_retain_strict_overdue_inclusive_sixty_day_expiry_and_actual_active_unacknowledged_counts(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-12', 'expiry_date' => '2026-12-11']);
        PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        PpeAllocation::factory()->acknowledged()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        PpeAllocation::factory()->returned()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        $dto = $this->read()->kpisForViewer($this->viewer);
        $this->assertSame(['ppe_inspections_overdue' => 0, 'ppe_expiring' => 1, 'ppe_unacknowledged' => 1], $dto['counts']);
        $this->assertSame(1, $dto['physical_inventory_count']);
        $this->assertSame(3, $dto['physical_allocation_count']);
        $this->assertSame(array_fill_keys(array_keys($dto['counts']), 'legacy_unknown'), $dto['integrity']);
        $this->item->update(['next_inspection_due' => '2026-10-11', 'expiry_date' => '2026-12-12']);
        $this->assertSame(['ppe_inspections_overdue' => 1, 'ppe_expiring' => 0, 'ppe_unacknowledged' => 1],
            $this->read()->kpisForViewer($this->viewer, [$this->site->id])['counts']);
        $this->assertDetached($dto);
    }

    public function test_kpi_damage_cannot_hide_behind_disposed_future_or_acknowledged_returned_predicates(): void
    {
        $root = $this->typedItem(['next_inspection_due' => null]);
        $receipt = app(PpeCommandService::class)->allocate($root, $this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'expected_version' => $root->source_revision, 'user_id' => $this->worker->id]);
        DB::table('ppe_allocations')->where('id', $receipt['source_id'])->update(['acknowledged' => true, 'returned_at' => now()->utc()]);
        $dto = $this->read()->kpisForViewer($this->viewer);
        $this->assertSame(0, $dto['counts']['ppe_inspections_overdue']);
        $this->assertSame(0, $dto['counts']['ppe_expiring']);
        $this->assertNull($dto['counts']['ppe_unacknowledged']);
        $this->assertSame(1, $dto['repair_counts']['ppe_unacknowledged']);
        DB::table('ppe_inventory')->where('id', $root->id)->update(['status' => 'disposed', 'expiry_date' => '2099-01-01']);
        $dto = $this->read()->kpisForViewer($this->viewer);
        $this->assertSame(array_fill_keys(array_keys($dto['counts']), null), $dto['counts']);
        $this->assertSame(array_fill_keys(array_keys($dto['counts']), 1), $dto['repair_counts']);
        $this->assertSame(2, $dto['physical_inventory_count']);
        $this->assertSame(1, $dto['physical_allocation_count']);
    }

    public function test_catalogue_damage_makes_profile_dates_feed_and_affected_kpis_unconfirmed(): void
    {
        $type = $this->typedType();
        $this->item->update(['ppe_type_id' => $type->id, 'next_inspection_due' => '2026-10-11']);
        PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        DB::table('ppe_types')->where('id', $type->id)->update(['name' => 'FORGED-TYPE-NAME']);
        $profile = $this->read()->profileForSite($this->viewer, $this->site->id);
        $feed = $this->read()->expiringForViewer($this->viewer);
        $kpis = $this->read()->kpisForViewer($this->viewer);
        $this->assertNull($profile['items'][0]['next_inspection_due']);
        $this->assertSame('ppe_repair', $feed['items'][0]['type']);
        $this->assertNull($feed['items'][0]['due_date']);
        $this->assertSame(array_fill_keys(array_keys($kpis['counts']), null), $kpis['counts']);
        $this->assertStringNotContainsString('FORGED-TYPE-NAME', json_encode([$profile, $feed, $kpis], JSON_THROW_ON_ERROR));
    }

    public function test_feed_retains_two_actual_date_items_and_exact_filtered_permanent_returns(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-11', 'expiry_date' => '2026-12-11']);
        $dto = $this->read()->expiringForViewer($this->viewer, $this->site->id);
        $this->assertSame(2, $dto['confirmed_count']);
        $this->assertSame(2, $dto['total']);
        $this->assertFalse($dto['truncated']);
        $rows = collect($dto['items'])->keyBy('type');
        $this->assertSame(-1, $rows['ppe_inspection']['days_until']);
        $this->assertSame(60, $rows['ppe_expiry']['days_until']);
        $this->assertSame('PPE inspection', $rows['ppe_inspection']['type_label']);
        foreach (['ppe_inspection' => 'inv_inspection', 'ppe_expiry' => 'inv_expiring'] as $kind => $tab) {
            $row = $rows[$kind];
            $this->assertSame($this->site->name, $row['site']);
            $this->assertSame($this->site->id, $row['site_id']);
            $this->assertSame('Recorded respirator · OWN-ITEM', $row['label']);
            $this->assertSame('/health-safety/ppe/inventory/'.$this->item->id, parse_url($row['register_url'], PHP_URL_PATH));
            parse_str(parse_url($row['register_url'], PHP_URL_QUERY), $query);
            parse_str(parse_url($query['return_to'], PHP_URL_QUERY), $back);
            $this->assertSame(['tab' => $tab, 'site_id' => (string) $this->site->id], $back);
        }
        $this->assertDetached($dto);
    }

    public function test_feed_repairs_precede_display_limit_without_invented_dates_or_trustworthy_total(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-11', 'expiry_date' => '2026-10-12']);
        $damaged = $this->typedItem();
        DB::table('ppe_inventory')->where('id', $damaged->id)->update(['status' => 'disposed', 'serial_number' => 'UNPROVEN-SERIAL',
            'expiry_date' => '2099-01-01', 'next_inspection_due' => '2099-02-01']);
        $dto = $this->read()->expiringForViewer($this->viewer, null, 60, 1);
        $this->assertCount(1, $dto['items']);
        $this->assertSame('ppe_repair', $dto['items'][0]['type']);
        $this->assertSame(2, $dto['confirmed_count']);
        $this->assertNull($dto['total']);
        $this->assertSame(1, $dto['repair_count']);
        $this->assertTrue($dto['truncated']);
        $this->assertNull($dto['items'][0]['due_date']);
        $this->assertNull($dto['items'][0]['days_until']);
        $this->assertCanonicalReturn($dto['items'][0]['register_url'], $damaged->id, $this->site->id);
        $this->assertStringNotContainsString('UNPROVEN-SERIAL', json_encode($dto, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('2099-', json_encode($dto, JSON_THROW_ON_ERROR));
    }

    public function test_new_projections_deny_warmed_module_and_exact_site_before_source_queries_and_restore_currently(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-11']);
        $this->viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        foreach ($this->newReads() as $read) {
            $read();
            $this->allow($this->viewer, 'hazards.view', false);
            [$unused, $queries] = $this->queries(fn () => $this->denied($read, 403));
            $this->assertNoPpeSql($queries);
            $this->allow($this->viewer, 'hazards.view', true);
            $this->assertDetached($read());
        }
        [$unused, $queries] = $this->queries(fn () => $this->denied(
            fn () => $this->read()->profileForSite($this->viewer, $this->foreignSite->id), 404));
        $this->assertNoPpeSql($queries);
    }

    public function test_kpi_and_feed_requested_sites_intersect_current_scope_null_all_and_empty_none(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-11']);
        $this->foreignItem->update(['next_inspection_due' => '2026-10-11', 'expiry_date' => null]);
        $this->assertSame(1, $this->read()->kpisForViewer($this->viewer, null)['physical_inventory_count']);
        $this->assertSame(1, $this->read()->expiringForViewer($this->viewer, [$this->foreignSite->id, $this->site->id])['confirmed_count']);
        foreach ([[], [$this->foreignSite->id]] as $sites) {
            [$dto, $queries] = $this->queries(fn () => [$this->read()->kpisForViewer($this->viewer, $sites),
                $this->read()->expiringForViewer($this->viewer, $sites)]);
            $this->assertNoPpeSql($queries);
            $this->assertSame(0, $dto[0]['physical_inventory_count']);
            $this->assertSame([], $dto[1]['items']);
        }
        $this->allow($this->viewer, 'healthSafety.viewAllSites', true);
        $this->assertSame(2, $this->read()->kpisForViewer($this->viewer, null)['physical_inventory_count']);
        $this->assertSame(1, $this->read()->kpisForViewer($this->viewer, [$this->site->id])['physical_inventory_count']);
        $this->assertSame(1, $this->read()->expiringForViewer($this->viewer, $this->foreignSite->id)['confirmed_count']);
    }

    public function test_new_projections_revoke_and_restore_current_hr_site_and_inactive_site_truth(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-11']);
        $profile = HrEmployeeProfile::where('user_id', $this->viewer->id)->sole();
        $this->viewer->load('hrEmployeeProfile', 'roles.permissions', 'permissionOverrides');
        $profile->update(['primary_site_id' => $this->foreignSite->id]);
        $this->denied(fn () => $this->read()->profileForSite($this->viewer, $this->site->id), 404);
        $this->assertSame(0, $this->read()->kpisForViewer($this->viewer, [$this->site->id])['physical_inventory_count']);
        $this->assertSame([], $this->read()->expiringForViewer($this->viewer, $this->site->id)['items']);
        $profile->update(['primary_site_id' => $this->site->id, 'is_active' => false]);
        foreach ($this->newReads() as $read) {
            $this->denied($read, 403);
        }
        $profile->update(['is_active' => true]);
        $this->assertCount(1, $this->read()->expiringForViewer($this->viewer)['items']);
        $this->site->update(['is_active' => false]);
        $this->denied(fn () => $this->read()->profileForSite($this->viewer, $this->site->id), 404);
        $this->assertSame(0, $this->read()->kpisForViewer($this->viewer)['physical_inventory_count']);
        $this->site->update(['is_active' => true]);
        $this->assertSame(1, $this->read()->profileForSite($this->viewer, $this->site->id)['physical_record_count']);
    }

    public function test_all_new_readers_use_configured_worker_day_application_fallback_and_utc_absence(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-11', 'expiry_date' => '2026-12-10']);
        $this->travelTo(Carbon::parse('2026-10-12T03:30:00Z')->utc());
        foreach ([['America/Los_Angeles', 'UTC', 0, 0], ['', 'America/Los_Angeles', 0, 0], [null, null, 1, -1]] as [$worker, $app, $overdue, $days]) {
            config(['app.worker_timezone' => $worker, 'app.timezone' => $app]);
            $profile = $this->read()->profileForSite($this->viewer, $this->site->id);
            $this->assertTrue($profile['items'][0]['inspection_due']);
            $this->assertSame($overdue, $this->read()->kpisForViewer($this->viewer)['counts']['ppe_inspections_overdue']);
            $feed = $this->read()->expiringForViewer($this->viewer);
            $this->assertSame($days, collect($feed['items'])->firstWhere('type', 'ppe_inspection')['days_until']);
            $this->assertSame(1, $this->read()->kpisForViewer($this->viewer)['counts']['ppe_expiring']);
        }
    }

    public function test_expiring_day_difference_preserves_whole_civil_days_across_both_dst_transitions(): void
    {
        config(['app.worker_timezone' => 'America/Los_Angeles', 'app.timezone' => 'UTC']);
        foreach ([['2026-03-08T08:00:00Z', '2026-03-09'], ['2026-11-01T07:00:00Z', '2026-11-02']] as [$instant, $day]) {
            $this->travelTo(Carbon::parse($instant)->utc());
            $this->item->update(['next_inspection_due' => $day, 'expiry_date' => null]);
            $dto = $this->read()->expiringForViewer($this->viewer, $this->site->id, 1);
            $this->assertSame(1, $dto['confirmed_count']);
            $this->assertSame(1, $dto['items'][0]['days_until']);
        }
    }

    public function test_profile_and_kpi_retain_terminal_and_retired_historical_parity_after_proof(): void
    {
        $this->item->update(['status' => 'condemned', 'quantity' => 2, 'next_inspection_due' => '2026-10-11']);
        $this->type->update(['is_active' => false]);
        $this->assertSame(2, $this->read()->profileForSite($this->viewer, $this->site->id)['confirmed_units']);
        $this->assertSame(0, $this->read()->kpisForViewer($this->viewer)['counts']['ppe_inspections_overdue']);
        $this->assertSame([], $this->read()->expiringForViewer($this->viewer)['items']);
        $this->item->update(['status' => 'disposed']);
        $dto = $this->read()->profileForSite($this->viewer, $this->site->id);
        $this->assertSame([], $dto['items']);
        $this->assertSame(1, $dto['physical_record_count']);
        $this->item->delete();
        $this->assertSame(0, $this->read()->kpisForViewer($this->viewer)['physical_inventory_count']);
    }

    public function test_new_readers_retain_downgrade_and_site_reparent_damage_without_foreign_source_facts(): void
    {
        $item = $this->typedItem();
        DB::table('ppe_inventory')->where('id', $item->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->assertSame(1, $this->read()->profileForSite($this->viewer, $this->site->id)['repair_count']);
        $this->assertSame(1, $this->read()->expiringForViewer($this->viewer)['repair_count']);
        DB::table('ppe_inventory')->where('id', $item->id)->update(['site_id' => $this->foreignSite->id]);
        $this->assertSame(0, $this->read()->expiringForViewer($this->viewer)['repair_count']);
        $this->allow($this->viewer, 'healthSafety.viewAllSites', true);
        $dto = $this->read()->expiringForViewer($this->viewer, $this->foreignSite->id);
        $this->assertSame(1, $dto['repair_count']);
        $this->assertCanonicalReturn($dto['items'][0]['register_url'], $item->id, $this->foreignSite->id);
    }

    public function test_new_reader_sets_use_one_prepared_authority_catalogue_and_return_no_models(): void
    {
        PpeInventory::factory()->count(4)->create(['site_id' => $this->site->id, 'ppe_type_id' => $this->type->id,
            'next_inspection_due' => '2026-10-11', 'expiry_date' => null]);
        foreach ($this->newReads() as $read) {
            [$dto, $queries] = $this->queries($read);
            $this->assertDetached($dto);
            $this->assertCount(1, array_filter($queries, fn ($sql) => preg_match('/from\s+[`"]?permissions[`"]?\s/i', $sql)));
        }
    }

    public function test_new_reader_invalid_selectors_and_feed_bounds_fail_without_source_sql(): void
    {
        foreach ([fn () => $this->read()->kpisForViewer($this->viewer, ['1']),
            fn () => $this->read()->expiringForViewer($this->viewer, 0),
            fn () => $this->read()->expiringForViewer($this->viewer, null, -1),
            fn () => $this->read()->expiringForViewer($this->viewer, null, 3651),
            fn () => $this->read()->expiringForViewer($this->viewer, null, 60, 0),
            fn () => $this->read()->expiringForViewer($this->viewer, null, 60, 101)] as $read) {
            [$unused, $queries] = $this->queries(function () use ($read) {
                try { $read(); $this->fail('Invalid source selectors or unbounded displays must fail.'); }
                catch (\LogicException) { return null; }
            });
            $this->assertNoPpeSql($queries);
        }
    }

    public function test_governed_type_root_and_allocation_keep_intact_counts_after_real_acknowledgement(): void
    {
        $type = $this->typedType();
        $root = $this->typedItem(['ppe_type_id' => $type->id, 'next_inspection_due' => null, 'expiry_date' => '2026-12-11']);
        $receipt = app(PpeCommandService::class)->allocate($root, $this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'expected_version' => $root->source_revision, 'user_id' => $this->worker->id]);
        $dto = $this->read()->kpisForViewer($this->viewer);
        $this->assertSame(1, $dto['counts']['ppe_expiring']);
        $this->assertSame(1, $dto['counts']['ppe_unacknowledged']);
        $this->assertSame('intact', $dto['integrity']['ppe_unacknowledged']);
        // The separate untouched legacy inventory remains honestly legacy_unknown in aggregate date counts.
        $this->assertSame('legacy_unknown', $dto['integrity']['ppe_expiring']);
        $profile = $this->read()->profileForSite($this->viewer, $this->site->id);
        $this->assertSame('intact', collect($profile['items'])->firstWhere('id', $root->id)['source_integrity']);
        $this->assertSame('intact', $this->read()->expiringForViewer($this->viewer)['items'][0]['source_integrity']);
        app(PpeCommandService::class)->acknowledge($receipt['source_id'], $this->viewer,
            ['idempotency_key' => (string) Str::uuid(), 'expected_version' => $receipt['version']]);
        $dto = $this->read()->kpisForViewer($this->viewer);
        $this->assertSame(0, $dto['counts']['ppe_unacknowledged']);
        $this->assertSame('intact', $dto['integrity']['ppe_unacknowledged']);
    }

    public function test_same_site_allocation_reparent_cannot_be_counted_as_an_intact_child_of_its_new_parent(): void
    {
        $root = $this->typedItem(['next_inspection_due' => null]);
        $receipt = app(PpeCommandService::class)->allocate($root, $this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'expected_version' => $root->source_revision, 'user_id' => $this->worker->id]);
        DB::table('ppe_allocations')->where('id', $receipt['source_id'])->update(['ppe_inventory_id' => $this->item->id]);
        $dto = $this->read()->kpisForViewer($this->viewer);
        $this->assertNull($dto['counts']['ppe_unacknowledged']);
        $this->assertSame('repair_required', $dto['integrity']['ppe_unacknowledged']);
        $this->assertSame(1, $dto['repair_counts']['ppe_unacknowledged']);
        $this->assertSame(1, $dto['physical_allocation_count']);
        $this->assertSame(0, $dto['counts']['ppe_inspections_overdue']);
    }

    public function test_new_readers_refuse_invalid_configured_zone_before_any_ppe_lookup(): void
    {
        config(['app.worker_timezone' => 'Invalid/Zone', 'app.timezone' => 'UTC']);
        foreach ($this->newReads() as $read) {
            [$unused, $queries] = $this->queries(function () use ($read) {
                try { $read(); $this->fail('An invalid configured timezone cannot be substituted.'); }
                catch (\LogicException $failure) {
                    $this->assertStringContainsString('timezone is invalid', $failure->getMessage());
                    return null;
                }
            });
            $this->assertNoPpeSql($queries);
        }
    }

    private function newReads(): array
    {
        return [fn () => $this->read()->profileForSite($this->viewer, $this->site->id),
            fn () => $this->read()->kpisForViewer($this->viewer), fn () => $this->read()->expiringForViewer($this->viewer)];
    }

    private function read(): PpeSiteReadService
    {
        return app(PpeSiteReadService::class);
    }

    private function start(): Carbon
    {
        return Carbon::parse('2026-10-01T00:00:00+13:00');
    }

    private function end(): Carbon
    {
        return Carbon::parse('2026-10-31T23:59:59+13:00');
    }

    private function obligations(): array
    {
        return $this->read()->obligationsForViewer([$this->site->id], $this->start(), $this->end(), $this->viewer);
    }

    private function typedItem(array $facts = []): PpeInventory
    {
        $receipt = app(PpeCommandService::class)->createInventory($this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'ppe_type_id' => $this->type->id, 'site_id' => $this->site->id, 'serial_number' => 'TYPED-PPE',
            'condition' => 'good', 'quantity' => 1, 'expiry_date' => null, 'next_inspection_due' => '2026-10-12', ...$facts]);

        return PpeInventory::findOrFail($receipt['root_id']);
    }

    private function typedType(): PpeType
    {
        $receipt = app(PpeCommandService::class)->createType($this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'name' => 'Governed catalogue definition', 'category' => 'eye']);

        return PpeType::findOrFail($receipt['root_id']);
    }

    private function assertCanonicalReturn(string $href, int $itemId, int $siteId): void
    {
        $this->assertSame('/health-safety/ppe/inventory/'.$itemId, parse_url($href, PHP_URL_PATH));
        parse_str(parse_url($href, PHP_URL_QUERY), $query);
        $this->assertSame(['return_to' => '/sites/'.$siteId.'?tab=ppe'], $query);
    }

    private function assertNoPpeSql(array $queries): void
    {
        foreach ($queries as $sql) {
            foreach (['ppe_inventory', 'ppe_types', 'ppe_source_versions', 'ppe_allocations', 'ppe_inspections'] as $table) {
                $this->assertStringNotContainsString($table, strtolower($sql));
            }
        }
    }
}
