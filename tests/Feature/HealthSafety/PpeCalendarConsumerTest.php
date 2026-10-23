<?php

namespace Tests\Feature\HealthSafety;

use App\Models\CalendarSyncMapping;
use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Services\HealthSafety\PpeCommandService;
use App\Services\HealthSafety\PpeSiteReadService;
use App\Services\Sites\Calendar\IcsFeedBuilder;
use App\Services\Sites\Calendar\Providers\PpeObligationProvider;
use App\Services\Sites\Calendar\SiteCalendarAggregator;
use App\Services\Sites\SiteCalendarService;
use App\Services\Sites\SiteProfileAttentionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Whole intended composed contract. Deferred calendar patch is UNADOPTED; every runtime check is UNRUN. */
final class PpeCalendarConsumerTest extends PpeReadTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        $this->site->update(['type' => 'house', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->foreignSite->update(['type' => 'house', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        foreach (['calendar.view', 'sites.viewAny', 'sites.type.house.view'] as $key) { $this->allow($this->viewer, $key, true); }
        app()->instance(SiteCalendarAggregator::class, $this->aggregator());
    }
    private function aggregator(): SiteCalendarAggregator
    {
        return new SiteCalendarAggregator(app(SiteCalendarService::class), [new PpeObligationProvider]);
    }
    private function start(): Carbon { return Carbon::parse('2026-10-01T00:00:00+13:00'); }
    private function end(): Carbon { return Carbon::parse('2026-10-31T23:59:59+13:00'); }
    private function rows(array $sites, array $filters = []): array
    {
        return $this->aggregator()->arrayForRange($sites, $this->start(), $this->end(), ['sources' => ['ppe'], ...$filters]);
    }
    private function dated(): void
    {
        $this->item->update(['expiry_date' => '2026-10-20', 'next_inspection_due' => '2026-10-12']);
        $this->foreignItem->update(['expiry_date' => '2026-10-21', 'next_inspection_due' => '2026-10-13']);
    }
    private function typed(array $facts = []): PpeInventory
    {
        $commands = app(PpeCommandService::class);
        $typeReceipt = $commands->createType($this->viewer, ['idempotency_key' => (string) Str::uuid(), 'name' => 'Actual governed catalogue', 'category' => 'eye']);
        $receipt = $commands->createInventory($this->viewer, ['idempotency_key' => (string) Str::uuid(), 'ppe_type_id' => $typeReceipt['root_id'],
            'site_id' => $this->site->id, 'serial_number' => 'GOVERNED-PPE', 'condition' => 'good', 'quantity' => 1,
            'expiry_date' => '2026-10-20', 'next_inspection_due' => '2026-10-12', ...$facts]);
        return PpeInventory::findOrFail($receipt['root_id']);
    }
    private function assertNoPpeSql(array $queries): void
    {
        foreach ($queries as $sql) {
            foreach (['ppe_inventory', 'ppe_types', 'ppe_source_versions', 'ppe_allocations', 'ppe_inspections', 'ppe_attachments'] as $table) {
                $this->assertStringNotContainsString($table, strtolower($sql));
            }
        }
    }
    private function assertReturn(array $row, int $item, int $site): void
    {
        $this->assertSame('/health-safety/ppe/inventory/'.$item, parse_url($row['link'], PHP_URL_PATH));
        parse_str(parse_url($row['link'], PHP_URL_QUERY) ?? '', $query);
        $this->assertSame(['return_to' => '/sites/'.$site.'?tab=ppe'], $query);
        $this->assertFalse($row['editable']); $this->assertNull($row['owner']);
    }
    public function test_legacy_no_actor_provider_and_explicit_null_viewer_deny_ppe_before_any_source_sql(): void
    {
        $this->dated(); $this->actingAs($this->viewer);
        [$rows, $queries] = $this->queries(fn () => (new PpeObligationProvider)->obligations([$this->site->id], $this->start(), $this->end()));
        $this->assertSame([], $rows); $this->assertNoPpeSql($queries);
        [$rows, $queries] = $this->queries(fn () => $this->rows([$this->site->id], ['viewer' => null]));
        $this->assertSame([], $rows); $this->assertNoPpeSql($queries);
    }
    public function test_aggregator_explicit_viewer_overrides_authenticated_request_and_current_sites_intersect_requested_sites(): void
    {
        $this->dated(); $this->actingAs($this->worker);
        $rows = $this->rows([$this->site->id, $this->foreignSite->id], ['viewer' => $this->viewer]);
        $this->assertCount(2, $rows); $this->assertSame([$this->site->id], array_values(array_unique(array_column(array_column($rows, 'site'), 'id'))));
        $this->assertStringNotContainsString('FOREIGN-PRIVATE', json_encode($rows));
        foreach ($rows as $row) { $this->assertReturn($row, $this->item->id, $this->site->id); }
        $this->assertDetached($rows);
    }
    public function test_authenticated_request_fallback_without_explicit_viewer_preserves_only_current_ppe_source_scope(): void
    {
        $this->dated(); $this->actingAs($this->viewer);
        $rows = $this->rows([$this->site->id, $this->foreignSite->id]);
        $this->assertCount(2, $rows); $this->assertStringNotContainsString('FOREIGN-PRIVATE', json_encode($rows));
        $this->actingAs($this->worker);
        [$rows, $queries] = $this->queries(fn () => $this->rows([$this->site->id]));
        $this->assertSame([], $rows); $this->assertNoPpeSql($queries);
    }
    public function test_both_actual_date_occurrences_keep_new_zealand_dates_and_permanent_source_link(): void
    {
        $item = $this->typed(); $rows = $this->rows([$this->site->id], ['viewer' => $this->viewer]);
        $this->assertCount(2, $rows);
        $this->assertSame(['2026-10-12T00:00:00+13:00', '2026-10-20T00:00:00+13:00'], array_column($rows, 'start'));
        foreach ($rows as $row) { $this->assertReturn($row, $item->id, $this->site->id); $this->assertTrue($row['allDay']); $this->assertSame('auto', $row['group']); }
        $ics = app(IcsFeedBuilder::class)->build($this->aggregator()->itemsForRange([$this->site->id], $this->start(), $this->end(), ['sources' => ['ppe'], 'viewer' => $this->viewer]));
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261012', $ics); $this->assertStringContainsString('DTSTART;VALUE=DATE:20261020', $ics);
        $this->assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
    }
    public function test_warmed_grant_revoke_and_restore_are_fresh_without_source_queries_during_denial(): void
    {
        $this->dated(); $this->viewer->load('roles.permissions', 'permissionOverrides');
        $this->assertTrue($this->viewer->canDo('hazards.view')); $this->allow($this->viewer, 'hazards.view', false);
        [$rows, $queries] = $this->queries(fn () => $this->rows([$this->site->id], ['viewer' => $this->viewer]));
        $this->assertSame([], $rows); $this->assertNoPpeSql($queries);
        $this->allow($this->viewer, 'hazards.view', true);
        $this->assertCount(2, $this->rows([$this->site->id], ['viewer' => $this->viewer]));
    }
    public function test_warmed_site_reassignment_intersects_current_scope_before_any_ppe_lookup(): void
    {
        $this->dated(); $this->viewer->load('hrEmployeeProfile');
        $this->assertCount(2, $this->rows([$this->site->id], ['viewer' => $this->viewer]));
        $this->viewer->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => []]);
        [$rows, $queries] = $this->queries(fn () => $this->rows([$this->site->id], ['viewer' => $this->viewer]));
        $this->assertSame([], $rows); $this->assertNoPpeSql($queries);
        $rows = $this->rows([$this->site->id, $this->foreignSite->id], ['viewer' => $this->viewer]);
        $this->assertCount(2, $rows); $this->assertSame('FOREIGN-PRIVATE', $rows[0]['ref']);
        foreach ($rows as $row) { $this->assertReturn($row, $this->foreignItem->id, $this->foreignSite->id); }
    }
    public function test_current_staff_withdrawal_excludes_source_before_ppe_sql_and_restoration_recovers_truthful_entries(): void
    {
        $this->dated(); $this->viewer->load('hrEmployeeProfile');
        $this->viewer->hrEmployeeProfile()->update(['is_active' => false, 'end_date' => '2026-10-11']);
        [$rows, $queries] = $this->queries(fn () => $this->rows([$this->site->id], ['viewer' => $this->viewer]));
        $this->assertSame([], $rows); $this->assertNoPpeSql($queries);
        $this->viewer->hrEmployeeProfile()->update(['is_active' => true, 'end_date' => null]);
        $this->assertCount(2, $this->rows([$this->site->id], ['viewer' => $this->viewer]));
    }
    public function test_repaired_inventory_is_an_undated_notice_without_leaked_serial_or_corrupted_date(): void
    {
        $item = $this->typed(); DB::table('ppe_inventory')->where('id', $item->id)->update(['serial_number' => 'UNPROVEN-SERIAL',
            'expiry_date' => '2099-01-01', 'next_inspection_due' => '2099-02-02', 'status' => 'disposed']);
        $rows = $this->rows([$this->site->id], ['viewer' => $this->viewer]);
        $this->assertCount(1, $rows); $row = $rows[0]; $this->assertSame('ppe-'.$item->id.'-repair', $row['id']);
        $this->assertNull($row['start']); $this->assertNull($row['end']); $this->assertNull($row['ref']);
        $this->assertSame('repair_required', $row['status']); $this->assertReturn($row, $item->id, $this->site->id);
        $this->assertStringNotContainsString('UNPROVEN-SERIAL', json_encode($rows)); $this->assertStringNotContainsString('2099', json_encode($rows));
    }
    public function test_ics_builder_skips_real_null_start_repair_notice_and_does_not_invent_date_or_event(): void
    {
        $item = $this->typed(); DB::table('ppe_inventory')->where('id', $item->id)->update(['serial_number' => 'UNPROVEN-SERIAL']);
        $items = $this->aggregator()->itemsForRange([$this->site->id], $this->start(), $this->end(), ['sources' => ['ppe'], 'viewer' => $this->viewer]);
        $this->assertCount(1, $items); $this->assertNull($items[0]->start);
        $ics = app(IcsFeedBuilder::class)->build($items);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics); $this->assertStringContainsString('END:VCALENDAR', $ics);
        foreach (['BEGIN:VEVENT', 'UID:ppe-', 'DTSTART', 'UNPROVEN-SERIAL', '20261012'] as $absent) { $this->assertStringNotContainsString($absent, $ics); }
    }
    public function test_damaged_type_hides_catalogue_identity_but_keeps_repair_notice_even_with_out_of_range_date(): void
    {
        $item = $this->typed(['expiry_date' => '2099-01-01', 'next_inspection_due' => null]);
        DB::table('ppe_types')->where('id', $item->ppe_type_id)->update(['name' => 'PRIVATE-CORRUPTED-CATALOGUE']);
        $rows = $this->rows([$this->site->id], ['viewer' => $this->viewer]);
        $this->assertCount(1, $rows); $this->assertNull($rows[0]['start']);
        $this->assertStringNotContainsString('PRIVATE-CORRUPTED-CATALOGUE', json_encode($rows));
    }
    public function test_terminal_stock_has_no_live_calendar_obligation_and_retired_type_does_not_erase_due_stock(): void
    {
        $this->dated(); $this->type->update(['is_active' => false]);
        $this->assertCount(2, $this->rows([$this->site->id], ['viewer' => $this->viewer]));
        foreach (['condemned', 'disposed'] as $status) {
            $this->item->update(['status' => $status]); $this->assertSame([], $this->rows([$this->site->id], ['viewer' => $this->viewer]));
        }
    }
    public function test_actual_site_json_adapter_preserves_current_source_privacy_and_record_return(): void
    {
        $this->dated(); $this->actingAs($this->viewer);
        $url = route('sites.calendar.events', ['site' => $this->site->id]).'?start=2026-10-01&end=2026-10-31&sources[]=ppe';
        $response = $this->getJson($url)->assertOk(); $rows = $response->json('events'); $this->assertCount(2, $rows);
        foreach ($rows as $row) { $this->assertReturn($row, $this->item->id, $this->site->id); }
        $this->assertStringNotContainsString('FOREIGN-PRIVATE', $response->getContent());
        $this->allow($this->viewer, 'hazards.view', false);
        [$response, $queries] = $this->queries(fn () => $this->getJson($url));
        $response->assertOk()->assertJsonPath('events', []); $this->assertNoPpeSql($queries);
    }
    public function test_personal_subscription_token_uses_its_owner_source_authority_not_current_request_actor(): void
    {
        $this->dated(); $this->viewer->update(['calendar_feed_token' => 'ppepersonalsource123']); $this->actingAs($this->worker);
        $url = '/calendar/feed/ppepersonalsource123.ics';
        $response = $this->get($url)->assertOk(); $this->assertStringContainsString('OWN-ITEM', $response->getContent());
        $this->assertStringNotContainsString('FOREIGN-PRIVATE', $response->getContent());
        $this->allow($this->viewer, 'hazards.view', false);
        [$response, $queries] = $this->queries(fn () => $this->get($url)); $response->assertOk();
        $this->assertStringNotContainsString('OWN-ITEM', $response->getContent()); $this->assertNoPpeSql($queries);
    }
    public function test_resource_subscription_mapping_and_authenticated_request_grant_no_ppe_source_actor(): void
    {
        $this->dated(); $this->actingAs($this->viewer);
        CalendarSyncMapping::create(['site_id' => $this->site->id, 'provider' => 'google', 'external_calendar_id' => 'ppe-resource@test.invalid',
            'sync_direction' => 'one_way', 'sources' => ['ppe'], 'ical_feed_token' => 'pperesource123', 'is_active' => true]);
        [$response, $queries] = $this->queries(fn () => $this->get('/calendar/site/'.$this->site->id.'/feed/pperesource123.ics'));
        $response->assertOk(); $this->assertStringContainsString('BEGIN:VCALENDAR', $response->getContent());
        $this->assertStringNotContainsString('OWN-ITEM', $response->getContent()); $this->assertStringNotContainsString('BEGIN:VEVENT', $response->getContent());
        $this->assertNoPpeSql($queries);
    }
    public function test_live_site_attention_rechecks_ppe_after_warmed_outer_permission_denial_and_revocation(): void
    {
        $this->dated(); $this->allow($this->viewer, 'hazards.view', false); $this->viewer->load('roles.permissions', 'permissionOverrides');
        $this->assertFalse($this->viewer->canDo('hazards.view')); $this->allow($this->viewer, 'hazards.view', true);
        $detail = app(SiteProfileAttentionService::class)->forSite($this->viewer, $this->site);
        $rows = array_values(array_filter($detail['items'], fn ($row) => $row['source'] === 'ppe'));
        $this->assertCount(1, $rows); $this->assertSame('/health-safety/ppe/inventory/'.$this->item->id, parse_url($rows[0]['href'], PHP_URL_PATH));
        $this->allow($this->viewer, 'hazards.view', false);
        $detail = app(SiteProfileAttentionService::class)->forSite($this->viewer, $this->site);
        $this->assertSame([], array_values(array_filter($detail['items'], fn ($row) => $row['source'] === 'ppe')));
        // Existing mixed-source summary totals are not asserted to be a PPE integrity certificate.
    }
    public function test_site_attention_and_calendar_each_retain_their_independent_linked_return(): void
    {
        $this->dated(); $attention = app(PpeSiteReadService::class)->attentionForSite($this->viewer, $this->site->id);
        $calendar = $this->rows([$this->site->id], ['viewer' => $this->viewer]);
        $this->assertCount(1, $attention); $this->assertCount(2, $calendar);
        $this->assertSame($attention[0]['href'], $calendar[0]['link']); $this->assertSame('2026-10-12', $attention[0]['due_date']);
        $this->assertSame('2026-10-12', Carbon::parse($calendar[0]['start'])->toDateString());
        $this->assertSame('/sites/'.$this->site->id.'?tab=ppe', (function ($href) { parse_str(parse_url($href, PHP_URL_QUERY), $query); return $query['return_to']; })($attention[0]['href']));
    }
}
