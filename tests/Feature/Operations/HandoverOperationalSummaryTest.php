<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HandoverOperationalSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00', 'UTC'));
        Queue::fake();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_empty_handover_summary_keeps_true_zero_and_no_record_evidence(): void
    {
        $actor = $this->worker($this->site(), ['handovers.viewAny']);
        $handovers = $this->props($actor, '/operations/handovers');
        $this->assertSame(0, $handovers['summary']['total']);
        $this->assertSame(0, $handovers['summary']['draft']);
        $this->assertSame(0, $handovers['summary']['submitted']);
        $this->assertSame(0, $handovers['summary']['acknowledged']);
        $this->assertSame(0, $handovers['summary']['openIncoming']);
        $this->assertSame('no_records', $handovers['evidence']['state']);
        $this->assertTrue($handovers['evidence']['complete']);
        $this->assertFalse($handovers['lists']['handovers']['truncated']);
        $this->assertSame(0, $handovers['lists']['handovers']['shown']);
        $this->assertSame(300, $handovers['lists']['handovers']['limit']);
        $this->assertSame(0, $handovers['handoverPagination']['total']);
    }

    public function test_handover_totals_include_pending_records_beyond_the_cap_and_exclude_hidden_sites(): void
    {
        $site = $this->site();
        $actor = $this->worker($site, ['handovers.viewAny']);
        $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null]);
        $pending = $this->handover($site, $actor, $client, '2026-10-05 00:15', ['status' => 'submitted']);
        $pending->timestamps = false;
        $pending->forceFill(['created_at' => '2026-10-03 00:00:00'])->save();
        foreach (range(1, 300) as $index) {
            $this->handover($site, $actor, $client, '2026-10-05 01:00', ['status' => 'draft']);
        }
        $hiddenSite = $this->site();
        $hiddenWorker = $this->worker($hiddenSite);
        $hiddenClient = Client::factory()->create(['site_id' => $hiddenSite->id, 'service_context_id' => null]);
        $hidden = $this->handover($hiddenSite, $hiddenWorker, $hiddenClient, '2026-10-05 02:00', ['status' => 'submitted']);
        $props = $this->props($actor, '/operations/handovers');
        $this->assertSame(301, $props['summary']['total']);
        $this->assertSame(1, $props['summary']['submitted']);
        $this->assertSame(300, $props['summary']['draft']);
        $this->assertSame(301, $props['summary']['openIncoming']);
        $this->assertSame(301, $props['lists']['handovers']['total']);
        $this->assertCount(300, $props['handovers']);
        $this->assertTrue($props['lists']['handovers']['truncated']);
        $loadedIds = collect($props['handovers'])->pluck('id')->all();
        $this->assertNotContains($pending->id, $loadedIds);
        $this->assertNotContains($hidden->id, $loadedIds);
        $next = $this->props($actor, '/operations/handovers?week=2026-10-05&page=2');
        $this->assertSame(301, $next['summary']['total']);
        $this->assertSame(1, $next['summary']['submitted']);
        $this->assertSame(2, $next['handoverPagination']['current_page']);
        $this->assertSame(301, $next['handoverPagination']['total']);
        $this->assertCount(1, $next['handovers']);
        $this->assertSame($pending->id, $next['handovers'][0]['id']);
        $this->assertStringContainsString('week=2026-10-05', $next['handoverPagination']['links'][0]['url']);
        $awaiting = $this->props($actor, $this->indexUrl(['status' => 'submitted']));
        $this->assertSame([$pending->id], collect($awaiting['handovers'])->pluck('id')->all());
        $this->assertSame(301, $awaiting['summary']['total']);
        $this->assertSame(300, $awaiting['summary']['draft']);
        $this->assertSame(1, $awaiting['summary']['submitted']);
        $this->assertSame(1, $awaiting['handoverPagination']['total']);
        $this->assertSame(1, $awaiting['lists']['handovers']['total']);
        $this->assertFalse($awaiting['lists']['handovers']['truncated']);
        $staleAwaitingPage = $this->props($actor, $this->indexUrl(['status' => 'submitted', 'page' => 99]));
        $this->assertSame([$pending->id], collect($staleAwaitingPage['handovers'])->pluck('id')->all());
        $this->assertSame(1, $staleAwaitingPage['filters']['page']);
        $this->assertSame(1, $staleAwaitingPage['handoverPagination']['current_page']);
        $this->assertSame(1, $staleAwaitingPage['handoverPagination']['from']);
        $this->assertSame(1, $staleAwaitingPage['handoverPagination']['to']);
        $staleAllPage = $this->props($actor, $this->indexUrl(['page' => 99]));
        $this->assertSame([$pending->id], collect($staleAllPage['handovers'])->pluck('id')->all());
        $this->assertSame(2, $staleAllPage['filters']['page']);
        $this->assertSame(2, $staleAllPage['handoverPagination']['current_page']);
        $this->assertSame(301, $staleAllPage['handoverPagination']['from']);
        $this->assertSame(301, $staleAllPage['handoverPagination']['to']);

        $combined = $this->props($actor, $this->indexUrl(['q' => '  Recorded summary fixture  ',
            'staff' => $actor->id, 'client' => $client->id, 'site' => $site->id, 'status' => 'submitted']));
        $this->assertSame([$pending->id], collect($combined['handovers'])->pluck('id')->all());
        $this->assertSame(301, $combined['summary']['total'], 'Search scans every permitted matching record, not the first chunk or display page.');
        $this->assertSame(300, $combined['summary']['draft']);
        $this->assertSame(1, $combined['handoverPagination']['total']);
        $this->assertSame('Recorded summary fixture', $combined['filters']['q']);
        $this->assertSame('week_search_staff_client_site_before_status', $combined['evidence']['filter_basis']);
        $this->assertSame('same_cohort_with_selected_status', $combined['evidence']['list_basis']);

        $searchPage = $this->props($actor, $this->indexUrl(['q' => ' Recorded summary fixture ',
            'staff' => $actor->id, 'client' => $client->id, 'site' => $site->id, 'page' => 2]));
        $this->assertSame(301, $searchPage['summary']['total']);
        $this->assertSame([$pending->id], collect($searchPage['handovers'])->pluck('id')->all());
        parse_str(parse_url($searchPage['handoverPagination']['links'][0]['url'], PHP_URL_QUERY), $linkFilters);
        $this->assertSame('2026-10-05', $linkFilters['week']);
        $this->assertSame('Recorded summary fixture', $linkFilters['q']);
        $this->assertSame((string) $actor->id, $linkFilters['staff']);
        $this->assertSame((string) $client->id, $linkFilters['client']);
        $this->assertSame((string) $site->id, $linkFilters['site']);
        $this->assertSame('all', $linkFilters['status']);
        $this->assertSame('1', $linkFilters['page']);

    }

    public function test_handover_effective_week_and_own_relationship_use_local_half_open_edges(): void
    {
        $site = $this->site();
        $actor = $this->worker($site, ['shifts.viewAssigned']);
        $other = $this->worker($site);
        $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null]);
        $included = $this->handover($site, $actor, $client, '2026-10-05 00:00');
        $included->timestamps = false;
        $included->forceFill(['created_at' => '2026-09-01 00:00:00'])->save();
        $this->handover($site, $actor, $client, '2026-10-04 23:59');
        $this->handover($site, $actor, $client, '2026-10-12 00:00');
        $this->handover($site, $other, $client, '2026-10-05 01:00');
        $props = $this->props($actor, '/operations/handovers?week=2026-10-05');
        $this->assertSame(1, $props['summary']['total']);
        $this->assertSame($included->id, $props['handovers'][0]['id']);
        $this->assertSame('own_related_records_in_permitted_sites', $props['evidence']['scope']);
        $this->assertSame('2026-10-04T11:00:00+00:00', $props['evidence']['period_start']);
        $this->assertSame('2026-10-11T11:00:00+00:00', $props['evidence']['period_end_exclusive']);
    }

    public function test_acknowledged_and_open_incoming_counts_preserve_current_recipient_integrity(): void
    {
        $site = $this->site();
        $actor = $this->worker($site, ['handovers.viewAny']);
        $submittedIncoming = $this->worker($site);
        $currentIncoming = $this->worker($site);
        $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null]);

        $assignedShift = $this->shift($site, $submittedIncoming, $client, '2026-10-05 02:15');
        $assigned = $this->handover($site, $actor, $client, '2026-10-05 00:15', [
            'incoming_shift_id' => $assignedShift->id,
            'incoming_staff_id' => $submittedIncoming->id,
            'status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $actor->id,
        ]);
        // Retained submit-time staff evidence is not rewritten after reassignment.
        DB::table('shifts')->where('id', $assignedShift->id)->update(['user_id' => $currentIncoming->id]);

        $acknowledgedShift = $this->shift($site, $currentIncoming, $client, '2026-10-05 04:15');
        $acknowledged = $this->handover($site, $actor, $client, '2026-10-05 02:15', [
            'incoming_shift_id' => $acknowledgedShift->id,
            'incoming_staff_id' => $currentIncoming->id,
            'status' => 'acknowledged', 'submitted_at' => now(), 'submitted_by' => $actor->id,
            'acknowledged_at' => now(), 'acknowledged_by' => $currentIncoming->id,
        ]);

        $unassignedShift = $this->shift($site, $submittedIncoming, $client, '2026-10-05 06:15');
        $nowUnassigned = $this->handover($site, $actor, $client, '2026-10-05 04:15', [
            'incoming_shift_id' => $unassignedShift->id,
            'incoming_staff_id' => $submittedIncoming->id,
            'status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $actor->id,
        ]);
        // Simulate retained source drift; the unchanged intrinsic read guard must withhold it.
        DB::table('shifts')->where('id', $unassignedShift->id)->update(['user_id' => null]);
        $unassignedBaseline = $nowUnassigned->fresh()->getRawOriginal();

        $legacyAssigned = $this->handover($site, $actor, $client, '2026-10-05 06:15', [
            'incoming_staff_id' => $submittedIncoming->id,
            'status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $actor->id,
        ]);
        $legacyUnbound = $this->handover($site, $actor, $client, '2026-10-05 08:15');

        $props = $this->props($actor, '/operations/handovers?week=2026-10-05');
        $rows = collect($props['handovers'])->keyBy('id');
        $this->assertSame(4, $props['summary']['total']);
        $this->assertSame(1, $props['summary']['draft']);
        $this->assertSame(2, $props['summary']['submitted']);
        $this->assertSame(1, $props['summary']['acknowledged']);
        $this->assertSame(1, $props['summary']['openIncoming']);
        $this->assertSame(4, $props['handoverPagination']['total']);
        $this->assertFalse($rows->has($nowUnassigned->id));
        $this->assertTrue($rows->has($acknowledged->id));
        $this->assertTrue($rows->has($legacyAssigned->id));
        $this->assertNull($rows->get($legacyUnbound->id)['incoming_staff']);
        $this->assertSame($currentIncoming->id, $rows->get($assigned->id)['current_incoming_staff']['id']);
        $this->assertSame($submittedIncoming->id, $rows->get($assigned->id)['submitted_incoming_staff']['id']);
        $this->assertSame($submittedIncoming->id, $assigned->fresh()->incoming_staff_id);
        $this->assertSame($unassignedBaseline, $nowUnassigned->fresh()->getRawOriginal());
        $this->assertNull($unassignedShift->fresh()->user_id);
    }

    public function test_staff_filter_and_search_follow_current_recipient_and_acknowledger_without_rewriting_history(): void
    {
        $site = $this->site();
        $actor = $this->worker($site, ['handovers.viewAny']);
        $submitted = $this->worker($site);
        $submitted->update(['name' => 'Submit recipient unique']);
        $current = $this->worker($site);
        $current->update(['name' => 'Current recipient unique']);
        $acknowledger = $this->worker($site);
        $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null]);
        $incoming = $this->shift($site, $submitted, $client, '2026-10-05 02:00');
        $bound = $this->handover($site, $actor, $client, '2026-10-05 00:00', [
            'incoming_shift_id' => $incoming->id, 'incoming_staff_id' => $submitted->id,
            'status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $actor->id,
        ]);
        DB::table('shifts')->where('id', $incoming->id)->update(['user_id' => $current->id]);
        $legacy = $this->handover($site, $actor, $client, '2026-10-05 04:00', [
            'incoming_staff_id' => $submitted->id, 'status' => 'submitted',
        ]);
        $acknowledgedIncoming = $this->shift($site, $acknowledger, $client, '2026-10-05 08:00');
        $acknowledged = $this->handover($site, $actor, $client, '2026-10-05 06:00', [
            'incoming_shift_id' => $acknowledgedIncoming->id, 'incoming_staff_id' => $acknowledger->id,
            'status' => 'acknowledged', 'acknowledged_by' => $acknowledger->id, 'acknowledged_at' => now(),
        ]);
        $before = $this->recordedState();

        foreach ([
            [$current->id, [$bound->id]],
            [$submitted->id, [$legacy->id]],
            [$acknowledger->id, [$acknowledged->id]],
            [$actor->id, [$bound->id, $legacy->id, $acknowledged->id]],
        ] as [$staffId, $expectedIds]) {
            $props = $this->props($actor, $this->indexUrl(['staff' => $staffId]));
            $this->assertEqualsCanonicalizing($expectedIds, collect($props['handovers'])->pluck('id')->all());
            $this->assertSame(count($expectedIds), $props['summary']['total']);
            $this->assertSame($staffId, $props['filters']['staff']);
        }
        $oldSearch = $this->props($actor, $this->indexUrl(['q' => $submitted->name]));
        $this->assertSame([$legacy->id], collect($oldSearch['handovers'])->pluck('id')->all());
        $newSearch = $this->props($actor, $this->indexUrl(['q' => $current->name, 'status' => 'submitted']));
        $this->assertSame([$bound->id], collect($newSearch['handovers'])->pluck('id')->all());
        $this->assertSame($submitted->id, $newSearch['handovers'][0]['submitted_incoming_staff']['id']);
        $this->assertSame($current->id, $newSearch['handovers'][0]['current_incoming_staff']['id']);
        $this->assertSame($before, $this->recordedState());
    }

    public function test_identity_and_search_filters_cannot_rescue_hidden_or_invalid_records_from_the_original_own_scope(): void
    {
        $site = $this->site();
        $actor = $this->worker($site, ['shifts.viewAssigned']);
        $colleague = $this->worker($site);
        $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null]);
        $otherClient = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null]);
        $own = $this->handover($site, $actor, $client, '2026-10-05 00:00', ['handover_notes' => 'Shared readable phrase']);
        $this->handover($site, $actor, $otherClient, '2026-10-05 02:00', ['handover_notes' => 'Shared readable phrase']);
        $this->handover($site, $colleague, $client, '2026-10-05 04:00', ['handover_notes' => 'Private colleague phrase']);
        $hiddenSite = $this->site();
        $hiddenWorker = $this->worker($hiddenSite);
        $hiddenClient = Client::factory()->create(['site_id' => $hiddenSite->id, 'service_context_id' => null]);
        $this->handover($hiddenSite, $hiddenWorker, $hiddenClient, '2026-10-05 06:00', ['handover_notes' => 'Foreign readable phrase']);
        $invalid = $this->handover($site, $actor, $client, '2026-10-05 08:00', ['handover_notes' => 'Contradictory source phrase']);
        DB::table('shift_handovers')->where('id', $invalid->id)->update(['client_id' => $hiddenClient->id]);
        $before = $this->recordedState();

        $props = $this->props($actor, $this->indexUrl(['q' => 'Shared readable', 'staff' => $actor->id,
            'client' => $client->id, 'site' => $site->id, 'status' => 'draft']));
        $this->assertSame([$own->id], collect($props['handovers'])->pluck('id')->all());
        $this->assertSame(1, $props['summary']['total']);
        $this->assertSame(1, $props['handoverPagination']['total']);
        foreach ([
            ['client' => $hiddenClient->id],
            ['site' => $hiddenSite->id],
            ['staff' => $hiddenWorker->id],
            ['staff' => $colleague->id, 'q' => 'Private colleague'],
            ['q' => 'Foreign readable'],
            ['q' => 'Contradictory source'],
        ] as $filter) {
            $denied = $this->props($actor, $this->indexUrl($filter));
            $this->assertSame(0, $denied['summary']['total']);
            $this->assertSame(0, $denied['handoverPagination']['total']);
            $this->assertCount(0, $denied['handovers']);
            foreach (['client', 'site', 'staff'] as $key) {
                if (isset($filter[$key])) {
                    $this->assertSame($filter[$key], $denied['filters'][$key]);
                }
            }
            $this->assertNotContains($hiddenClient->id, collect($denied['catalogue']['clients'])->pluck('id')->all());
        }
        $this->assertSame($before, $this->recordedState());
    }

    public function test_search_uses_only_displayed_strings_with_literal_unicode_and_structured_list_fallback_parity(): void
    {
        $site = $this->site();
        $site->update(['name' => 'Whare Tahi']);
        $actor = $this->worker($site, ['handovers.viewAny']);
        $actor->update(['name' => 'Alex Day']);
        $incomingStaff = $this->worker($site);
        $incomingStaff->update(['name' => 'Sam Night']);
        $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null,
            'first_name' => 'Mere', 'last_name' => 'Whānau']);
        $privateClient = Client::factory()->create(['site_id' => $this->site()->id, 'service_context_id' => null]);
        $handover = $this->handover($site, $actor, $client, '2026-10-05 00:00', [
            'incoming_staff_id' => $incomingStaff->id,
            'handover_notes' => str_repeat('ā', 100).' Literal 100% _ checklist',
            'client_mood' => 'Settled',
            'incidents_to_note' => ['Kākāriki incident',
                ['label' => 'Visible structured label', 'internal' => 'SuppressedSiblingNeedle'],
                ['first' => 'Fallback first', 'second' => 'fallback second', 'number' => 42],
                ['nested' => ['note' => 'NestedObjectNeedle']]],
            'follow_up_items' => [['description' => 'Call coordinator']],
            'tasks_pending' => ['Collect notebook', '0'],
            'worker_notes' => ['shared_notes' => 'WorkerSharedNeedle', 'people' => [[
                'client_id' => $privateClient->id, 'notes' => 'PrivatePersonNeedle',
                'no_updates' => false, 'follow_up_needed' => true, 'not_supported' => false,
            ]]],
            'observations_summary' => ['description' => 'ObservationDetailNeedle'],
        ]);
        $before = $this->recordedState();
        foreach ([str_repeat('ā', 100), '100% _', 'MERE WHĀNAU', 'alex day', 'sam night', 'whare tahi', 'settled',
            'KĀKĀRIKI', 'Visible structured label', 'Fallback first fallback second',
            'Call coordinator', 'Collect notebook', '0', 'checklist Mere Whānau'] as $search) {
            $props = $this->props($actor, $this->indexUrl(['q' => '  '.$search.'  ']));
            $this->assertSame([$handover->id], collect($props['handovers'])->pluck('id')->all(), $search);
            $this->assertSame(1, $props['summary']['total']);
            $this->assertSame($search, $props['filters']['q']);
        }
        foreach (['SuppressedSiblingNeedle', 'NestedObjectNeedle', 'WorkerSharedNeedle',
            'PrivatePersonNeedle', 'ObservationDetailNeedle', 'this%is_not_a_wildcard'] as $search) {
            $props = $this->props($actor, $this->indexUrl(['q' => $search]));
            $this->assertSame(0, $props['summary']['total'], $search);
            $this->assertCount(0, $props['handovers']);
        }
        $unfiltered = $this->props($actor, $this->indexUrl());
        $this->assertStringNotContainsString('PrivatePersonNeedle', json_encode($unfiltered['handovers']));
        $this->assertSame($before, $this->recordedState());
    }

    public function test_medication_search_requires_the_exact_controlled_reader_and_never_searches_hidden_siblings_or_verification(): void
    {
        $site = $this->site();
        $plain = $this->worker($site, ['handovers.viewAny', 'medications.view']);
        $controlled = $this->worker($site, ['handovers.viewAny', 'medications.controlled.view']);
        $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null]);
        $handover = $this->handover($site, $plain, $client, '2026-10-05 00:00', [
            'medications_due' => [['label' => 'MedicationVisibleNeedle', 'internal' => 'MedicationSiblingNeedle']],
            'cd_required' => true, 'cd_verification' => ['notes' => 'VerificationPrivateNeedle'],
        ]);
        $before = $this->recordedState();
        foreach (['MedicationVisibleNeedle', 'MedicationSiblingNeedle', 'VerificationPrivateNeedle'] as $search) {
            $props = $this->props($plain, $this->indexUrl(['q' => $search]));
            $this->assertSame(0, $props['summary']['total']);
            $this->assertCount(0, $props['handovers']);
        }
        $visible = $this->props($controlled, $this->indexUrl(['q' => 'MedicationVisibleNeedle']));
        $this->assertSame([$handover->id], collect($visible['handovers'])->pluck('id')->all());
        $this->assertSame(['MedicationVisibleNeedle'], $visible['handovers'][0]['medications_due']);
        foreach (['MedicationSiblingNeedle', 'VerificationPrivateNeedle'] as $search) {
            $props = $this->props($controlled, $this->indexUrl(['q' => $search]));
            $this->assertSame(0, $props['summary']['total']);
            $this->assertCount(0, $props['handovers']);
        }
        $this->assertSame($before, $this->recordedState());
    }

    public function test_filtered_period_uses_configured_new_york_half_open_bounds_across_the_fall_clock_change(): void
    {
        config(['app.worker_timezone' => 'America/New_York']);
        Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00', 'UTC'));
        $site = $this->site();
        $actor = $this->worker($site, ['handovers.viewAny']);
        $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null]);
        $included = $this->handover($site, $actor, $client, '2026-10-26 00:00', ['handover_notes' => 'Boundary visible']);
        $this->handover($site, $actor, $client, '2026-10-25 23:59', ['handover_notes' => 'Boundary visible']);
        $this->handover($site, $actor, $client, '2026-11-02 00:00', ['handover_notes' => 'Boundary visible']);
        $props = $this->props($actor, $this->indexUrl(['week' => '2026-10-28', 'q' => 'Boundary', 'status' => 'draft']));
        $this->assertSame([$included->id], collect($props['handovers'])->pluck('id')->all());
        $this->assertSame(1, $props['summary']['total']);
        $this->assertSame('2026-10-26', $props['filters']['week']);
        $this->assertSame('America/New_York', $props['evidence']['timezone']);
        $this->assertSame('2026-10-26T04:00:00+00:00', $props['evidence']['period_start']);
        $this->assertSame('2026-11-02T05:00:00+00:00', $props['evidence']['period_end_exclusive']);
    }

    #[DataProvider('invalidIndexFilters')]
    public function test_invalid_filters_are_rejected_without_record_or_audit_changes(array $filters, string $field): void
    {
        $site = $this->site();
        $actor = $this->worker($site, ['handovers.viewAny']);
        $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null]);
        $this->handover($site, $actor, $client, '2026-10-05 00:00');
        $before = $this->recordedState();
        $this->actingAs($actor)->getJson($this->indexUrl($filters))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $this->recordedState());
    }

    public static function invalidIndexFilters(): array
    {
        return [
            'unknown status' => [['status' => 'ready'], 'status'],
            'too many characters' => [['q' => str_repeat('ā', 101)], 'q'],
            'array search' => [['q' => ['hidden']], 'q'],
            'zero staff' => [['staff' => 0], 'staff'],
            'negative client' => [['client' => -1], 'client'],
            'invalid Site' => [['site' => 'other'], 'site'],
            'zero page' => [['page' => 0], 'page'],
            'invalid week' => [['week' => 'not-a-date'], 'week'],
        ];
    }

    private function indexUrl(array $filters = []): string
    {
        return route('operations.handovers.index', $filters + ['week' => '2026-10-05']);
    }

    private function recordedState(): array
    {
        return collect(['shift_handovers', 'shifts', 'audit_logs'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
            ->all();
    }

    private function props(User $actor, string $url): array
    {
        $response = $this->actingAs($actor)->get($url)->assertOk();

        return $response->viewData('page')['props'];
    }

    private function site(): Site
    {
        return Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    }

    private function worker(Site $site, array $permissions = []): User
    {
        $worker = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'start_date' => '2025-01-01', 'end_date' => null, 'is_active' => true]);
        if ($permissions !== []) {
            $role = Role::create(['name' => 'summary-'.str()->uuid(), 'label' => 'Summary regression', 'type' => 'custom', 'level' => 10]);
            $role->permissions()->sync(collect($permissions)->map(fn (string $key) => Permission::firstOrCreate(['key' => $key],
                ['description' => $key, 'group' => 'Workforce', 'module' => 'operations'])->id));
            $worker->roles()->attach($role);
        }

        return $worker;
    }

    private function shift(Site $site, User $worker, Client $client, string $start, array $attributes = []): Shift
    {
        $startsAt = Carbon::parse($start, config('app.worker_timezone', 'Pacific/Auckland'))->utc();

        return Shift::factory()->create(['site_id' => $site->id, 'client_id' => $client->id, 'user_id' => $worker->id,
            'service_context_id' => null, 'status' => 'scheduled', 'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(2), 'expected_break_minutes' => 0, ...$attributes]);
    }

    private function handover(Site $site, User $worker, Client $client, string $start, array $attributes = []): ShiftHandover
    {
        $shift = $this->shift($site, $worker, $client, $start);

        return ShiftHandover::create(['outgoing_shift_id' => $shift->id, 'client_id' => $client->id,
            'outgoing_staff_id' => $worker->id, 'status' => 'draft', 'handover_notes' => 'Recorded summary fixture', ...$attributes]);
    }
}
