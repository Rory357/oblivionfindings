<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ChemicalExposureFollowup;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Services\HealthSafety\ChemicalExposureFollowupDestinationService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/** Whole prospective real-HTTP acceptance; shared consuming patch + genuine source workspace required. UNRUN. */
final class ChemicalExposureNotificationHttpTest extends ChemicalExposureNotificationTestCase
{
    private function header($user): array
    {
        return $this->actingAs($user)->get('/notifications', ['X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'notifications/index', 'X-Inertia-Partial-Data' => 'inbox'])
            ->assertOk()->json('props.inbox.notifications');
    }

    public function test_real_centres_counts_data_and_deferred_header_share_current_private_admission(): void
    {
        [$root, $receiver, $notice] = $this->notified(); $ordinary = $this->ordinary($receiver);
        foreach ([['/notifications', 'unread_count'], ['/portal/notifications', 'unreadCount']] as [$path, $key]) {
            $props = $this->actingAs($receiver)->get($path)->assertOk()->inertiaProps();
            $this->assertSame(2, $props[$key]); $this->assertSame(2, $props['notifications']['total']);
            $this->assertEqualsCanonicalizing([$ordinary->id, $notice->id], array_column($props['notifications']['data'], 'id'));
            $serialized = json_encode($props['notifications'], JSON_THROW_ON_ERROR);
            foreach (['PRIVATE-NOTIFICATION', 'worker_id', 'client_id', 'idempotency_key'] as $private) { $this->assertStringNotContainsString($private, $serialized); }
        }
        $header = $this->header($receiver); $this->assertSame(2, $header['unread_count']);
        $this->assertEqualsCanonicalizing([$ordinary->id, $notice->id], array_column($header['items'], 'id'));
    }

    public function test_warmed_wellbeing_withdraw_restore_removes_all_private_counts_list_and_searchable_body(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::DECIDED, 'Stephan chose to keep follow-up assignment notices off (26 Oct, job 3): the assignee sees the work in /tasks, which applies their current access. No follow-up notice is staged or sent.', 'none unless notices are turned on together with their notification-centre reader.'));
        [$root, $receiver, $notice] = $this->notified(); $ordinary = $this->ordinary($receiver);
        $receiver->load('roles.permissions', 'permissionOverrides'); $this->allow($receiver, 'hr.wellbeing.view', false);
        foreach ([['/notifications', 'unread_count'], ['/portal/notifications', 'unreadCount']] as [$path, $key]) {
            $props = $this->actingAs($receiver)->get($path)->assertOk()->inertiaProps();
            $this->assertSame(1, $props[$key]); $this->assertSame([$ordinary->id], array_column($props['notifications']['data'], 'id'));
            $serialized = json_encode($props['notifications'], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($notice->id, $serialized); $this->assertStringNotContainsString('Assigned follow-up', $serialized);
        }
        $this->assertSame([$ordinary->id], array_column($this->header($receiver)['items'], 'id'));
        $this->allow($receiver, 'hr.wellbeing.view'); $this->assertSame(2, $this->header($receiver)['unread_count']);
    }

    public function test_get_and_deferred_followup_request_cannot_reuse_initial_role_or_site_permission(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::DECIDED, 'Stephan chose to keep follow-up assignment notices off (26 Oct, job 3): the assignee sees the work in /tasks, which applies their current access. No follow-up notice is staged or sent.', 'none unless notices are turned on together with their notification-centre reader.'));
        [$root, $receiver, $notice] = $this->notified(); $ordinary = $this->ordinary($receiver);
        $this->actingAs($receiver)->get('/notifications')->assertOk();
        $receiver->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id]);
        $header = $this->header($receiver); $this->assertSame(1, $header['unread_count']);
        $this->assertSame([$ordinary->id], array_column($header['items'], 'id'));
        $receiver->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
        $this->assertSame(2, $this->header($receiver)['unread_count']);
    }

    public function test_direct_read_acknowledge_and_portal_read_conceal_revoked_or_other_recipient_notice(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::DECIDED, 'Stephan chose to keep follow-up assignment notices off (26 Oct, job 3): the assignee sees the work in /tasks, which applies their current access. No follow-up notice is staged or sent.', 'none unless notices are turned on together with their notification-centre reader.'));
        [$root, $receiver, $notice] = $this->notified();
        $before = (array) DB::table('notifications')->where('id', $notice->id)->first();
        $other = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        foreach ([$other, $receiver] as $viewer) {
            if ($viewer->id === $receiver->id) { $this->allow($receiver, 'hr.wellbeing.view', false); }
            foreach (['/inbox/notifications/'.$notice->id.'/read', '/inbox/notifications/'.$notice->id.'/acknowledge',
                '/portal/notifications/'.$notice->id.'/read'] as $path) {
                $this->actingAs($viewer)->post($path)->assertNotFound();
            }
        }
        $this->assertSame($before, (array) DB::table('notifications')->where('id', $notice->id)->first());
        $this->allow($receiver, 'hr.wellbeing.view');
        $this->actingAs($receiver)->post('/inbox/notifications/'.$notice->id.'/read')->assertRedirect();
        $this->assertNotNull($notice->fresh()->read_at);
    }

    public function test_mark_all_both_routes_changes_only_currently_visible_rows_without_source_completion(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::DECIDED, 'Stephan chose to keep follow-up assignment notices off (26 Oct, job 3): the assignee sees the work in /tasks, which applies their current access. No follow-up notice is staged or sent.', 'none unless notices are turned on together with their notification-centre reader.'));
        [$root, $receiver, $notice, $delivery, $accepted] = $this->notified(); $ordinary = $this->ordinary($receiver);
        $version = $root->fresh()->source_revision; $this->allow($receiver, 'hr.wellbeing.view', false);
        foreach (['/inbox/notifications/read-all', '/portal/notifications/read-all'] as $path) {
            DB::table('notifications')->where('id', $ordinary->id)->update(['read_at' => null]);
            $this->actingAs($receiver)->post($path)->assertRedirect();
            $this->assertNotNull($ordinary->fresh()->read_at); $this->assertNull($notice->fresh()->read_at);
        }
        $this->allow($receiver, 'hr.wellbeing.view');
        $this->actingAs($receiver)->post('/portal/notifications/read-all')->assertRedirect();
        $this->assertNotNull($notice->fresh()->read_at);
        $this->assertSame($version, $root->fresh()->source_revision);
        $this->assertNull(ChemicalExposureFollowup::findOrFail($accepted['source_id'])->completed_at);
    }

    public function test_open_and_no_ack_required_keep_inbox_read_separate_from_named_source_completion(): void
    {
        [$root, $receiver, $notice, $delivery, $accepted] = $this->notified(); $version = $root->fresh()->source_revision;
        $this->actingAs($receiver)->post('/inbox/notifications/'.$notice->id.'/acknowledge')
            ->assertRedirect()->assertSessionHas('info', 'No acknowledgement required for this notification.');
        $this->assertNull($notice->fresh()->acknowledged_at); $this->assertNull($notice->fresh()->read_at);
        $this->actingAs($receiver)->post('/inbox/notifications/'.$notice->id.'/read')->assertRedirect();
        $this->assertNotNull($notice->fresh()->read_at);
        $this->assertNull(ChemicalExposureFollowup::findOrFail($accepted['source_id'])->completed_at);
        $this->assertSame($version, $root->fresh()->source_revision);
        $confirmation = app(ChemicalExposureFollowupDestinationService::class)->confirmationForViewer($receiver, $delivery->id);
        $this->assertTrue($confirmation['read']); $this->assertFalse($confirmation['actioned']);
        $this->assertSame('not_attempted', $confirmation['external_delivery']);
    }

    public function test_existing_filters_pagination_global_unread_counter_and_client_search_input_are_truthful(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::DECIDED, 'Stephan chose to keep follow-up assignment notices off (26 Oct, job 3): the assignee sees the work in /tasks, which applies their current access. No follow-up notice is staged or sent.', 'none unless notices are turned on together with their notification-centre reader.'));
        [$root, $receiver, $notice] = $this->notified();
        for ($i = 0; $i < 51; $i++) { $this->ordinary($receiver, ['title' => 'Searchable ordinary update '.$i]); }
        $this->actingAs($receiver)->get('/notifications?type=operations&filter=unread&page=2')->assertOk()
            ->assertInertia(fn ($page) => $page->where('notifications.total', 51)->where('notifications.current_page', 2)
                ->has('notifications.data', 1)->where('unread_count', 52)
                ->where('filters', ['filter' => 'unread', 'type' => 'operations']));
        $this->actingAs($receiver)->post('/inbox/notifications/'.$notice->id.'/read')->assertRedirect();
        $this->actingAs($receiver)->get('/notifications?filter=read')->assertOk()
            ->assertInertia(fn ($page) => $page->where('notifications.total', 1)->where('notifications.data.0.id', $notice->id));
        // Search is actual client-side title/body/module filtering, not an invented server endpoint.
        $this->allow($receiver, 'hr.wellbeing.view', false);
        $data = $this->actingAs($receiver)->get('/notifications')->assertOk()->inertiaProps('notifications.data');
        $this->assertNotContains($notice->id, array_column($data, 'id'));
        $this->assertStringNotContainsString('PRIVATE-NOTIFICATION', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function test_corrupt_payload_is_absent_from_all_surfaces_and_cannot_be_marked_or_opened(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::DECIDED, 'Stephan chose to keep follow-up assignment notices off (26 Oct, job 3): the assignee sees the work in /tasks, which applies their current access. No follow-up notice is staged or sent.', 'none unless notices are turned on together with their notification-centre reader.'));
        [$root, $receiver, $notice] = $this->notified(); $ordinary = $this->ordinary($receiver);
        $poison = [...$notice->data, 'title' => 'PRIVATE-INJECTED-TITLE', 'url' => '/operations/clients/999', 'ack_required' => true];
        DB::table('notifications')->where('id', $notice->id)->update(['data' => json_encode($poison, JSON_THROW_ON_ERROR)]);
        $this->assertSame([$ordinary->id], array_column($this->header($receiver)['items'], 'id'));
        $this->actingAs($receiver)->get('/notifications')->assertOk()
            ->assertInertia(fn ($page) => $page->where('notifications.total', 1)->where('notifications.data.0.id', $ordinary->id));
        $this->actingAs($receiver)->post('/inbox/notifications/'.$notice->id.'/acknowledge')->assertNotFound();
        $this->actingAs($receiver)->post('/portal/notifications/'.$notice->id.'/read')->assertNotFound();
        $this->assertNull($notice->fresh()->read_at); $this->assertNull($notice->fresh()->acknowledged_at);
    }

    public function test_real_successor_notice_and_old_receiver_http_deny_after_named_transfer(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::DECIDED, 'Stephan chose to keep follow-up assignment notices off (26 Oct, job 3): the assignee sees the work in /tasks, which applies their current access. No follow-up notice is staged or sent.', 'none unless notices are turned on together with their notification-centre reader.'));
        [$root, $receiver, $notice, $delivery, $accepted] = $this->notified();
        $next = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $transfer = $this->exposureService()->reassignFollowup($root, $accepted['source_id'], $this->manager,
            $this->privateCommand($root, ['assigned_to' => $next->id, 'reason' => 'Actual named responsibility transfer']));
        $successor = ChemicalExposureSourceDelivery::where('kind', 'followup')->where('source_version_id', $transfer['source_version_id'])->sole();
        app(ChemicalExposureFollowupDestinationService::class)->deliverForViewer($this->manager, $successor->id);
        $this->actingAs($receiver)->get('/notifications')->assertOk()->assertInertia(fn ($page) => $page->where('unread_count', 0));
        $this->actingAs($receiver)->post('/inbox/notifications/'.$notice->id.'/read')->assertNotFound();
        $header = $this->header($next); $this->assertSame(1, $header['unread_count']);
        $this->assertNotSame($notice->id, $header['items'][0]['id']); $this->assertNull($notice->fresh()->read_at);
    }

    public function test_original_client_permission_revoke_never_hydrates_private_notification_data(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::DECIDED, 'Stephan chose to keep follow-up assignment notices off (26 Oct, job 3): the assignee sees the work in /tasks, which applies their current access. No follow-up notice is staged or sent.', 'none unless notices are turned on together with their notification-centre reader.'));
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::factory()->create(['site_id' => $this->site->id, 'client_id' => $client->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'reported_by' => $this->manager->id]);
        [$root, $receiver, $notice] = $this->notified($incident); $ordinary = $this->ordinary($receiver);
        $this->allow($receiver, 'clients.viewAny', false);
        [$props, $queries] = $this->capturedQueries(fn () => $this->header($receiver));
        $this->assertSame([$ordinary->id], array_column($props['items'], 'id'));
        foreach ($queries as $entry) {
            $sql = strtolower($entry['query']);
            if (str_contains($sql, 'from `notifications`')
                && in_array(\App\Notifications\HealthSafety\ChemicalExposureFollowupAssignedNotification::class, $entry['bindings'], true)) {
                $this->assertStringNotContainsString('select *', $sql);
            }
        }
        $this->allow($receiver, 'clients.viewAny'); $this->assertSame(2, $this->header($receiver)['unread_count']);
    }

    public function test_my_day_digest_and_counter_consume_same_guarded_set_without_fabricated_task_completion(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::DECIDED, 'Stephan chose to keep follow-up assignment notices off (26 Oct, job 3): the assignee sees the work in /tasks, which applies their current access. No follow-up notice is staged or sent.', 'none unless notices are turned on together with their notification-centre reader.'));
        [$root, $receiver, $notice, $delivery, $accepted] = $this->notified(); $ordinary = $this->ordinary($receiver);
        $this->actingAs($receiver)->get('/my-day')->assertOk()->assertInertia(fn ($page) => $page
            ->where('stats.notifications_unread', 2)->has('notifications', 2));
        $this->allow($receiver, 'hr.wellbeing.view', false);
        $this->actingAs($receiver)->get('/my-day')->assertOk()->assertInertia(fn ($page) => $page
            ->where('stats.notifications_unread', 1)->has('notifications', 1)->where('notifications.0.id', $ordinary->id));
        $this->assertNull(ChemicalExposureFollowup::findOrFail($accepted['source_id'])->completed_at);
    }

    public function test_proved_notification_link_requires_actual_guarded_workspace_then_denies_after_current_source_revocation(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::DECIDED, 'Stephan chose to keep follow-up assignment notices off (26 Oct, job 3): the assignee sees the work in /tasks, which applies their current access. No follow-up notice is staged or sent.', 'none unless notices are turned on together with their notification-centre reader.'));
        [$root, $receiver, $notice] = $this->notified();
        // Prospective acceptance requires the real root-owned workspace route; no stub route/service is registered.
        $props = $this->actingAs($receiver)->get('/notifications')->assertOk()->inertiaProps('notifications.data');
        $row = collect($props)->firstWhere('id', $notice->id);
        $this->assertSame('/health-safety/chemical-exposures/'.$root->id.'?section=followups', $row['data']['url']);
        $this->actingAs($receiver)->get($row['data']['url'])->assertOk();
        $this->allow($receiver, 'hr.wellbeing.view', false);
        $this->actingAs($receiver)->get($row['data']['url'])->assertForbidden();
        $this->actingAs($receiver)->post('/inbox/notifications/'.$notice->id.'/read')->assertNotFound();
    }
}
