<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Notifications\BoardPackPublishedNotification;
use App\Domain\Governance\Services\BoardPackAccessService;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\MedicationAlert;
use App\Models\Permission;
use App\Models\User;
use App\Models\WorkerParticipationMeetingDelivery;
use App\Models\WorkerParticipationMeetingEvent;
use App\Notifications\AppEventNotification;
use App\Notifications\HealthSafety\CommitteeMeetingScheduled;
use App\Notifications\HealthSafety\WorkerParticipationMeetingNotice;
use App\Notifications\MedicationAlertNotification;
use App\Services\Medication\Alerts\MedicationAlertCatalogue;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HealthSafetyReadMaterialisationProof;
use App\Services\HealthSafety\WorkerParticipationMeetingDeliveryService;
use App\Services\HealthSafety\WorkerParticipationNotificationReadService;
use App\Services\HealthSafety\WorkerParticipationRecipientSourceService;
use App\Support\SchemaCache;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Real relation SQL plus production HTTP consumers. Scoped shared mount is for review; native UNRUN. */
final class WorkerParticipationNotificationSourceReadTest extends RestraintEventCommandTestCase
{
    private HsCommittee $committee;
    private HsCommitteeMeeting $meeting;
    private array $notice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->staff([]);
        foreach (['hazards.view', 'hazards.manage'] as $key) {
            $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => false]]);
        }
        $this->actingAs($this->actor);
        request()->setUserResolver(fn () => Auth::user());
        $this->committee = HsCommittee::create(['name' => 'Private committee source name', 'site_id' => $this->site->id,
            'meeting_frequency' => 'monthly', 'established_at' => '2026-01-01', 'status' => 'active', 'members' => [$this->actor->id], 'created_by' => $this->actor->id]);
        $this->meeting = $this->meeting();
        $this->notice = $this->delivery($this->meeting);
        SchemaCache::flush();
    }

    public function test_current_named_attendee_reads_only_generic_bound_notice_without_hs_grants(): void
    {
        $this->assertFalse($this->actor->canDo('hazards.view'));
        $rows = $this->scoped()->get(['id', 'type', 'data'])->toArray();
        $this->assertCount(1, $rows);
        $this->assertSame($this->notice['id'], $rows[0]['id']);
        $this->assertSame('/my-day/safety/meetings/'.$this->meeting->id, $rows[0]['data']['url']);
        $json = json_encode($rows, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($this->committee->name, $json);
        $this->assertStringNotContainsString('Private source location', $json);
        $this->assertStringNotContainsString('Private agenda', $json);
        $this->assertFalse($this->actor->fresh()->canDo('hazards.view'));
    }

    public function test_generic_workforce_notice_never_grants_an_uninvited_meeting_link(): void
    {
        $this->clearNotices();
        $this->meeting->attendeeUsers()->detach($this->actor);
        $copy = $this->delivery($this->meeting, 'workforce_notice');
        $row = $this->scoped()->sole();
        $this->assertSame($copy['id'], $row->id);
        $this->assertNull($row->data['url']);
        $this->getJson('/my-day/safety/meetings/'.$this->meeting->id)->assertNotFound();
    }

    public function test_rescheduled_attendee_notice_is_a_legitimate_source_purpose(): void
    {
        $this->clearNotices();
        $copy = $this->delivery($this->meeting, purpose: 'rescheduled');
        $this->assertSame([$copy['id']], $this->scoped()->pluck('id')->all());
    }

    public function test_unchanged_attendee_addition_preserves_prior_notice_and_count(): void
    {
        $this->delivery($this->meeting, purpose: 'attendees_added', epoch: $this->notice['event']->source_epoch_id);
        $this->assertSame(2, $this->scoped()->count());
        $this->assertContains($this->notice['id'], $this->scoped()->pluck('id')->all());
    }

    public function test_source_epoch_a_to_b_to_a_never_revives_the_old_notice(): void
    {
        $original = $this->meeting->getRawOriginal('scheduled_at');
        $this->meeting->update(['scheduled_at' => now()->addDays(3)]);
        $this->delivery($this->meeting->fresh(), purpose: 'rescheduled');
        $this->meeting->update(['scheduled_at' => $original]);
        $last = $this->delivery($this->meeting->fresh(), purpose: 'rescheduled');
        $this->assertSame([$last['id']], $this->scoped()->pluck('id')->all());
    }

    public function test_initially_hr_ineligible_receiver_keeps_ordinary_notifications(): void
    {
        $ordinary = $this->ordinary();
        $plainData = DB::table('notifications')->where('id', $ordinary)->value('data');
        $this->assertArrayNotHasKey('type', json_decode($plainData, true, 512, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('kind', json_decode($plainData, true, 512, JSON_THROW_ON_ERROR));
        $nullMarkers = $this->otherNotice(AppEventNotification::class, ['title' => 'Ordinary null markers', 'type' => null, 'kind' => null]);
        $jsonNull = $this->ordinary();
        // The native column is nonnullable JSON. JSON null is valid; SQL NULL
        // and invalid JSON text cannot be installed without violating its schema.
        DB::table('notifications')->where('id', $jsonNull)->update(['data' => 'null']);
        $jsonScalar = $this->ordinary();
        DB::table('notifications')->where('id', $jsonScalar)->update(['data' => 'true']);
        $this->actor->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertEqualsCanonicalizing([$ordinary, $nullMarkers, $jsonNull, $jsonScalar], $this->scoped()->pluck('id')->all());
    }

    public function test_second_reader_cannot_conceal_withdrawal_of_the_original_receiver_as_empty(): void
    {
        $this->assertSame(1, $this->scoped()->count());
        $this->actor->hrEmployeeProfile()->update(['is_active' => false]);
        $this->unavailable(fn () => $this->scoped()->count());
    }

    public function test_mounted_ordinary_only_reads_survive_withdrawal_without_admitting_a_late_meeting_copy(): void
    {
        $this->clearNotices();
        $ordinary = $this->ordinary();
        $reader = app(BoardPackAccessService::class);
        $this->assertSame([$ordinary], $reader->visibleNotificationQuery($this->actor)->pluck('id')->all());
        $this->assertTrue(app(WorkerParticipationRecipientSourceService::class)->hasPreparedReceiver());
        $this->actor->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertSame([$ordinary], $reader->visibleNotificationQuery($this->actor)->pluck('id')->all());
        $late = $this->delivery($this->meeting);
        $sourceBodies = 0; $noticeBodies = 0;
        HsCommitteeMeeting::retrieved(function ($row) use (&$sourceBodies): void {
            if (array_key_exists('location', $row->getAttributes())) { $sourceBodies++; }
        });
        DatabaseNotification::retrieved(function ($row) use (&$noticeBodies, $late): void {
            if ($row->id === $late['id']) { $noticeBodies++; }
        });
        $rows = $reader->visibleNotificationQuery($this->actor)->get(['id', 'data']);
        $this->assertSame([$ordinary], $rows->pluck('id')->all());
        $this->assertSame(0, $sourceBodies); $this->assertSame(0, $noticeBodies);
        $this->assertTrue(app(WorkerParticipationRecipientSourceService::class)->hasPreparedReceiver());
        $this->unavailable(fn () => app(WorkerParticipationRecipientSourceService::class)->prepareReceiver($this->actor));
    }

    public function test_legacy_copied_names_are_never_admitted_by_current_moved_source(): void
    {
        $ordinary = $this->ordinary();
        $id = (string) Str::uuid();
        $this->actor->notifications()->create(['id' => $id, 'type' => CommitteeMeetingScheduled::class,
            'data' => ['type' => 'hs_committee_meeting', 'meeting_id' => $this->meeting->id, 'title' => 'Unproven private copied committee']]);
        $ids = $this->scoped()->pluck('id')->all();
        $this->assertContains($ordinary, $ids);
        $this->assertNotContains($id, $ids);
    }

    public function test_original_private_identity_cannot_fall_through_after_type_and_marker_replacement(): void
    {
        $id = (string) Str::uuid();
        $this->actor->notifications()->create(['id' => $id, 'type' => CommitteeMeetingScheduled::class,
            'data' => ['type' => 'hs_committee_meeting', 'title' => 'Unproven private copied committee']]);
        $query = $this->scoped();
        DB::table('notifications')->where('id', $id)->update(['type' => AppEventNotification::class, 'data' => json_encode(['title' => 'Unproven private copied committee'])]);
        $this->assertNotContains($id, $query->pluck('id')->all());
    }

    public function test_missing_supplied_collector_withholds_only_participation_and_preserves_ordinary_rows(): void
    {
        $ordinary = $this->ordinary();
        $ids = HealthSafetyReadContext::within($this->actor, WorkerParticipationRecipientSourceService::PERMISSIONS,
            fn ($context) => $this->scoped($context)->pluck('id')->all());
        $this->assertSame([$ordinary], $ids);
    }

    public function test_initial_unprepared_permission_vector_preserves_only_ordinary_family(): void
    {
        $ordinary = $this->ordinary();
        $ids = HealthSafetyReadContext::within($this->actor, [], fn ($context) =>
            HealthSafetyReadMaterialisationProof::within($context, fn () => $this->scoped($context)->pluck('id')->all()));
        $this->assertSame([$ordinary], $ids);
    }

    public function test_unavailable_source_schema_cache_branch_keeps_real_ordinary_sql(): void
    {
        // Inject only the cached unavailable-schema receipt. This is not physical migration/DDL acceptance.
        $ordinary = $this->ordinary();
        SchemaCache::hasTable('notifications');
        $property = new \ReflectionProperty(SchemaCache::class, 'tables');
        $original = $property->getValue(); $missing = $original;
        unset($missing['worker_participation_meeting_events'], $missing['worker_participation_meeting_deliveries']);
        $property->setValue(null, $missing);
        try { $this->assertSame([$ordinary], $this->scoped()->pluck('id')->all()); }
        finally { $property->setValue(null, $original); }
    }

    #[DataProvider('malformedReceiptCases')]
    public function test_malformed_private_receipts_do_not_deny_other_families(string $case): void
    {
        $ordinary = $this->ordinary();
        $event = $this->notice['event'];
        if ($case === 'extra_descriptor') { $event->update(['source_descriptor' => [...$event->source_descriptor, 'copied_name' => 'Forbidden private copy']]); }
        if ($case === 'scalar_membership') { DB::table($event->getTable())->where('id', $event->id)->update(['audience_membership' => 'true']); }
        if ($case === 'audience_digest') { $event->update(['audience_digest' => str_repeat('0', 64)]); }
        if ($case === 'purpose') { $event->update(['purpose' => 'source_changed']); }
        if ($case === 'epoch') { $event->update(['source_epoch_id' => (string) Str::uuid()]); }
        if ($case === 'payload') { DB::table('notifications')->where('id', $this->notice['id'])->update(['data' => json_encode([...$this->notice['payload'], 'copied_name' => 'Forbidden private copy'])]); }
        if ($case === 'null_payload_json') { DB::table('notifications')->where('id', $this->notice['id'])->update(['data' => 'null']); }
        $this->assertSame([$ordinary], $this->scoped()->pluck('id')->all());
    }

    public static function malformedReceiptCases(): array
    {
        return array_map(fn ($case) => [$case], ['extra_descriptor', 'scalar_membership', 'audience_digest', 'purpose', 'epoch', 'payload', 'null_payload_json']);
    }

    public function test_late_profile_withdrawal_during_actual_count_is_not_a_healthy_zero(): void
    {
        $query = $this->scoped(); $fired = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$fired): void {
            if (! $fired && preg_match('/^select count\(\*\) as aggregate from `notifications`/i', $sql)) {
                $fired = true; $this->actor->hrEmployeeProfile()->update(['is_active' => false]);
            }
        });
        $this->unavailable(fn () => $query->count());
        $this->assertTrue($fired);
        $this->actor->hrEmployeeProfile()->update(['is_active' => true]);
        $this->assertSame(1, $this->scoped()->count());
        $preparationRace = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$preparationRace): void {
            if (! $preparationRace && str_contains($sql, 'from `notifications`')
                && str_contains($sql, 'JSON_LENGTH') && str_contains($sql, '`source_delivery`')) {
                $preparationRace = true;
                $this->actor->hrEmployeeProfile()->update(['is_active' => false]);
            }
        });
        $this->unavailable(fn () => $this->scoped()->count());
        $this->assertTrue($preparationRace);
    }

    public function test_late_attendee_removal_before_actual_body_does_not_hydrate_notice(): void
    {
        $query = $this->scoped(); $fired = false; $hydrated = 0;
        DatabaseNotification::retrieved(function ($row) use (&$hydrated): void { if ($row->id === $this->notice['id']) { $hydrated++; } });
        DB::connection()->beforeExecuting(function ($sql) use (&$fired): void {
            if (! $fired && preg_match('/^select `id`, `type`, `data` from `notifications`/i', $sql)) {
                $fired = true; $this->meeting->attendeeUsers()->detach($this->actor);
            }
        });
        $this->unavailable(fn () => $query->get(['id', 'type', 'data']));
        $this->assertTrue($fired); $this->assertSame(0, $hydrated);
    }

    public function test_equal_count_source_replacement_fails_the_original_receipt_proof(): void
    {
        $query = $this->scoped();
        $replacement = $this->meeting();
        DB::table('worker_participation_meeting_events')->where('id', $this->notice['event']->id)->update(['meeting_id' => $replacement->id]);
        $this->unavailable(fn () => $query->count());
    }

    public function test_delivered_receipt_timestamp_replacement_is_not_original_evidence(): void
    {
        $query = $this->scoped();
        DB::table('worker_participation_meeting_deliveries')->where('id', $this->notice['id'])
            ->update(['database_delivered_at' => now()->subDay()]);
        $this->unavailable(fn () => $query->get(['id', 'data']));
    }

    #[DataProvider('actualEpochStatements')]
    public function test_late_a_to_b_to_a_epoch_change_rejects_actual_count_and_body(string $statement): void
    {
        $query = $this->scoped(); $fired = false; $hydrated = 0;
        DatabaseNotification::retrieved(function ($row) use (&$hydrated): void { if ($row->id === $this->notice['id']) { $hydrated++; } });
        DB::connection()->beforeExecuting(function ($sql) use (&$fired, $statement): void {
            $match = $statement === 'count' ? '/^select count\(\*\) as aggregate from `notifications`/i' : '/^select `id`, `type`, `data` from `notifications`/i';
            if (! $fired && preg_match($match, $sql)) {
                $fired = true;
                $original = $this->meeting->getRawOriginal('scheduled_at');
                $this->meeting->update(['scheduled_at' => now()->addDays(3)]);
                $this->delivery($this->meeting->fresh(), purpose: 'rescheduled');
                $this->meeting->update(['scheduled_at' => $original]);
                $this->delivery($this->meeting->fresh(), purpose: 'rescheduled');
            }
        });
        $this->unavailable(fn () => $statement === 'count' ? $query->count() : $query->get(['id', 'type', 'data']));
        $this->assertTrue($fired); $this->assertSame(0, $hydrated);
    }

    public static function actualEpochStatements(): array
    {
        return [['count'], ['body']];
    }

    public function test_actual_epoch_witness_and_negative_are_explicit_current_reads(): void
    {
        $query = $this->scoped(); $statements = [];
        DB::listen(function ($event) use (&$statements): void { $statements[] = $event->sql; });
        $this->assertSame(1, $query->count());
        $actual = array_values(array_filter($statements, fn ($sql) => preg_match('/^select count\(\*\) as aggregate from `notifications`/i', $sql)));
        $this->assertCount(1, $actual);
        $this->assertMatchesRegularExpression('/from `worker_participation_meeting_events` as `newer_epoch`[^)]*for share nowait\)/i', $actual[0]);
        $this->assertMatchesRegularExpression('/from `worker_participation_meeting_events` as `source_epoch`[^)]*for share nowait\)/i', $actual[0]);
        $this->assertStringContainsString('`current_epoch`.`source_epoch_id` = `source_event`.`source_epoch_id`', $actual[0]);
        $this->assertGreaterThanOrEqual(5, substr_count(strtolower($actual[0]), 'for share nowait'));
    }

    public function test_expired_supplied_origin_refuses_before_private_notice_sql(): void
    {
        $captured = null;
        HealthSafetyReadContext::within($this->actor, WorkerParticipationRecipientSourceService::PERMISSIONS,
            function ($context) use (&$captured): array {
                return HealthSafetyReadMaterialisationProof::within($context, function () use ($context, &$captured): array {
                    $captured = $this->scoped($context); return [];
                });
            });
        $this->refusedBeforeNotificationSql(fn () => $captured->count());
    }

    public function test_different_authenticated_receiver_cannot_use_captured_relation(): void
    {
        $query = $this->scoped();
        $this->actingAs($this->staff([]));
        $this->refusedBeforeNotificationSql(fn () => $query->get(['id', 'data']));
    }

    public function test_captured_relation_does_not_survive_request_replacement(): void
    {
        $query = $this->scoped();
        $request = Request::create('/notifications'); $request->setUserResolver(fn () => $this->actor);
        app()->instance('request', $request);
        $this->refusedBeforeNotificationSql(fn () => $query->count());
    }

    public function test_valid_empty_own_inbox_is_zero_without_any_hidden_total(): void
    {
        $this->clearNotices();
        $this->assertSame(0, $this->scoped()->count());
    }

    public function test_production_full_centre_excludes_legacy_copied_title_and_roundtrips_bound_notice(): void
    {
        $legacy = (string) Str::uuid();
        $this->actor->notifications()->create(['id' => $legacy, 'type' => CommitteeMeetingScheduled::class,
            'data' => ['type' => 'hs_committee_meeting', 'title' => 'Forbidden legacy private title']]);
        $response = $this->get('/notifications', ['X-Inertia' => 'true'])->assertOk();
        $response->assertJsonPath('component', 'notifications/index')->assertJsonPath('props.notifications.total', 1)
            ->assertJsonPath('props.notifications.data.0.id', $this->notice['id']);
        $this->assertStringNotContainsString('Forbidden legacy private title', $response->getContent());
    }

    public function test_production_portal_projection_keeps_canonical_notice_url_and_native_pagination(): void
    {
        $response = $this->get('/portal/notifications', ['X-Inertia' => 'true'])->assertOk();
        $response->assertJsonPath('component', 'portal/notifications')->assertJsonPath('props.notifications.data.0.data.url', '/my-day/safety/meetings/'.$this->meeting->id)
            ->assertJsonPath('props.notifications.total', 1)->assertJsonPath('props.unreadCount', 1);
        $this->assertArrayHasKey('next_page_url', $response->json('props.notifications'));
    }

    public function test_production_portal_native_next_page_retains_all_own_rows_and_bound_notice(): void
    {
        $expected = [$this->notice['id']];
        for ($i = 1; $i <= 30; $i++) {
            $id = $this->ordinary(); $expected[] = $id;
            DB::table('notifications')->where('id', $id)->update(['created_at' => now()->addSeconds($i)]);
        }
        $first = $this->get('/portal/notifications', ['X-Inertia' => 'true'])->assertOk();
        $first->assertJsonPath('props.notifications.total', 31)->assertJsonCount(30, 'props.notifications.data');
        $next = $first->json('props.notifications.next_page_url');
        $this->assertIsString($next); $this->assertStringContainsString('page=2', $next);
        $second = $this->get($next, ['X-Inertia' => 'true'])->assertOk();
        $second->assertJsonCount(1, 'props.notifications.data')->assertJsonPath('props.notifications.data.0.id', $this->notice['id']);
        $this->assertNull($second->json('props.notifications.next_page_url'));
        $ids = [...array_column($first->json('props.notifications.data'), 'id'), ...array_column($second->json('props.notifications.data'), 'id')];
        $this->assertEqualsCanonicalizing($expected, $ids);
    }

    public function test_production_direct_read_cannot_open_an_unproven_legacy_copy(): void
    {
        $legacy = (string) Str::uuid();
        $this->actor->notifications()->create(['id' => $legacy, 'type' => CommitteeMeetingScheduled::class,
            'data' => ['type' => 'hs_committee_meeting', 'title' => 'Forbidden legacy private title']]);
        $this->post('/inbox/notifications/'.$legacy.'/read')->assertNotFound();
        $this->assertNull(DB::table('notifications')->where('id', $legacy)->value('read_at'));
    }

    public function test_actual_deferred_header_with_multiple_sources_stays_below_existing_query_ceiling(): void
    {
        for ($i = 0; $i < 3; $i++) { $this->delivery($this->meeting()); }
        $queries = 0; DB::listen(function () use (&$queries): void { $queries++; });
        $response = $this->get('/notifications', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'notifications/index', 'X-Inertia-Partial-Data' => 'inbox'])->assertOk();
        $response->assertJsonPath('props.inbox.notifications.unread_count', 4)->assertJsonCount(4, 'props.inbox.notifications.items');
        $this->assertGreaterThan(0, $queries); $this->assertLessThan(100, $queries);
    }

    public function test_actual_paginated_inbox_with_multiple_sources_stays_below_existing_query_ceiling(): void
    {
        for ($i = 0; $i < 3; $i++) { $this->delivery($this->meeting()); }
        $queries = 0; DB::listen(function () use (&$queries): void { $queries++; });
        $response = $this->get('/portal/notifications', ['X-Inertia' => 'true'])->assertOk();
        $response->assertJsonPath('props.notifications.total', 4)->assertJsonCount(4, 'props.notifications.data')->assertJsonPath('props.unreadCount', 4);
        $this->assertGreaterThan(0, $queries); $this->assertLessThan(100, $queries);
    }

    #[DataProvider('ordinaryFamilyEligibility')]
    public function test_production_board_pack_and_own_medication_notifications_preserve_existing_rules(bool $currentStaff): void
    {
        foreach (['medications.view', 'medications.controlled.view', 'medications.audit.view'] as $key) {
            $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => false]]);
        }
        foreach (['governance.packs.view', 'governance.packs.manage', 'governance.meetings.view'] as $key) {
            $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => true]]);
        }
        $meeting = GovernanceMeeting::create(['meeting_type' => 'full_board', 'title' => 'Board meeting',
            'scheduled_at' => now()->addWeek(), 'duration_minutes' => 60, 'status' => 'scheduled', 'quorum_required' => 50, 'created_by' => $this->actor->id]);
        $pack = BoardPack::create(['governance_meeting_id' => $meeting->id, 'revision_number' => 1, 'build_status' => 'published', 'generated_by' => $this->actor->id]);
        $packId = $this->otherNotice(BoardPackPublishedNotification::class, ['pack_id' => $pack->id, 'title' => 'Board papers']);
        $hiddenPack = $this->otherNotice(BoardPackPublishedNotification::class, ['pack_id' => 99999999, 'title' => 'Unavailable board papers']);
        $medicine = MedicationAlert::create(['type' => MedicationAlertCatalogue::RENEWALS, 'dedupe_key' => (string) Str::uuid(),
            'site_id' => $this->site->id, 'client_id' => null, 'staff_user_id' => $this->actor->id, 'controlled' => false,
            'title' => 'Your competency', 'message' => 'Renew your competency', 'short_message' => 'Renewal', 'severity' => 'warning',
            'status' => MedicationAlert::STATUS_OPEN, 'raised_at' => now()]);
        $medicineId = $this->otherNotice(MedicationAlertNotification::class, ['type' => 'medication_alert', 'medication_alert_id' => $medicine->id, 'title' => 'Your competency']);
        $otherMedicine = $medicine->replicate(); $otherMedicine->staff_user_id = $this->staff([])->id; $otherMedicine->dedupe_key = (string) Str::uuid(); $otherMedicine->save();
        $hiddenMedicine = $this->otherNotice(MedicationAlertNotification::class, ['type' => 'medication_alert', 'medication_alert_id' => $otherMedicine->id, 'title' => 'Other staff competency']);
        $ordinary = $this->ordinary();
        if (! $currentStaff) { $this->actor->hrEmployeeProfile()->update(['is_active' => false]); }
        $expected = [$packId, $medicineId, $ordinary];
        if ($currentStaff) { $expected[] = $this->notice['id']; }
        foreach (['/notifications', '/portal/notifications'] as $path) {
            $response = $this->get($path, ['X-Inertia' => 'true'])->assertOk();
            $ids = array_column($response->json('props.notifications.data'), 'id');
            $this->assertEqualsCanonicalizing($expected, $ids);
            $this->assertNotContains($hiddenPack, $ids); $this->assertNotContains($hiddenMedicine, $ids);
        }
        $this->post('/inbox/notifications/'.$medicineId.'/read')->assertRedirect();
        $this->assertNotNull(DB::table('notifications')->where('id', $medicineId)->value('read_at'));
        $this->post('/inbox/notifications/'.$hiddenMedicine.'/read')->assertNotFound();
        $this->post('/inbox/notifications/'.$hiddenPack.'/read')->assertNotFound();
    }

    public static function ordinaryFamilyEligibility(): array
    {
        return [[true], [false]];
    }

    private function scoped(?HealthSafetyReadContext $context = null): MorphMany
    {
        $query = $this->actor->notifications();
        $source = app(WorkerParticipationNotificationReadService::class);
        $source->prepare($query, $this->actor, $context);
        return $source->scope($query, $this->actor, $context);
    }

    private function ordinary(): string
    {
        $id = (string) Str::uuid();
        $this->actor->notifications()->create(['id' => $id, 'type' => AppEventNotification::class, 'data' => ['title' => 'Ordinary task update', 'body' => 'Unrelated existing work.']]);
        return $id;
    }

    private function otherNotice(string $type, array $data): string
    {
        $id = (string) Str::uuid();
        $this->actor->notifications()->create(['id' => $id, 'type' => $type, 'data' => $data]);
        return $id;
    }

    private function meeting(): HsCommitteeMeeting
    {
        $meeting = $this->committee->meetings()->create(['scheduled_at' => now()->addDay(), 'status' => 'scheduled',
            'location' => 'Private source location', 'agenda_items' => ['Private agenda'], 'created_by' => $this->actor->id]);
        $meeting->attendeeUsers()->attach($this->actor);
        return $meeting;
    }

    private function delivery(HsCommitteeMeeting $meeting, string $audience = 'attendee', string $purpose = 'scheduled', ?string $epoch = null): array
    {
        $eventId = (string) Str::uuid();
        $descriptor = ['meeting_id' => (int) $meeting->id, 'committee_id' => (int) $this->committee->id, 'site_id' => (int) $this->site->id,
            'scheduled_at' => $meeting->getRawOriginal('scheduled_at'), 'meeting_status' => $meeting->status, 'committee_status' => $this->committee->status, 'location' => $meeting->location];
        $membership = [['recipient_id' => (int) $this->actor->id, 'audience' => $audience]];
        $event = WorkerParticipationMeetingEvent::create(['id' => $eventId, 'meeting_id' => $meeting->id,
            'sequence' => ((int) WorkerParticipationMeetingEvent::where('meeting_id', $meeting->id)->max('sequence')) + 1,
            'source_epoch_id' => $epoch ?? $eventId, 'committee_id' => $this->committee->id, 'site_id' => $this->site->id,
            'initiated_by_user_id' => $this->actor->id, 'purpose' => $purpose, 'source_descriptor' => $descriptor,
            'source_digest' => WorkerParticipationMeetingDeliveryService::digest($descriptor), 'audience_membership' => $membership,
            'audience_digest' => WorkerParticipationMeetingDeliveryService::digest($membership)]);
        $id = WorkerParticipationMeetingDeliveryService::notificationId($eventId, (int) $this->actor->id, $audience);
        WorkerParticipationMeetingDelivery::create(['id' => $id, 'event_id' => $eventId, 'recipient_user_id' => $this->actor->id, 'audience' => $audience,
            'database_state' => 'delivered', 'database_delivered_at' => now(), 'mail_state' => $audience === 'attendee' ? 'pending' : 'not_requested']);
        $payload = (new WorkerParticipationMeetingNotice(['delivery_id' => $id, 'event_id' => $eventId, 'source_epoch_id' => $event->source_epoch_id,
            'meeting_id' => (int) $meeting->id, 'committee_id' => (int) $this->committee->id, 'site_id' => (int) $this->site->id,
            'audience' => $audience, 'source_digest' => $event->source_digest]))->toArray($this->actor);
        $this->actor->notifications()->create(['id' => $id, 'type' => WorkerParticipationMeetingNotice::class, 'data' => $payload]);
        return ['id' => $id, 'event' => $event, 'payload' => $payload];
    }

    private function clearNotices(): void
    {
        $this->actor->notifications()->delete();
        WorkerParticipationMeetingDelivery::query()->delete();
        WorkerParticipationMeetingEvent::query()->delete();
    }

    private function unavailable(callable $operation): void
    {
        try { $operation(); $this->fail('Changed original private notice evidence must not become a healthy zero.'); }
        catch (HttpExceptionInterface $denial) { $this->assertSame(404, $denial->getStatusCode()); }
    }

    private function refusedBeforeNotificationSql(callable $operation): void
    {
        $statements = 0;
        DB::connection()->beforeExecuting(function ($sql) use (&$statements): void {
            if (preg_match('/^select .* from `notifications`/i', $sql)) { $statements++; }
        });
        try { $operation(); $this->fail('Expired or different original inbox evidence must be refused.'); }
        catch (LogicException) { $this->assertSame(0, $statements); }
    }
}
