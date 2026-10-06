<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationAlert;
use App\Models\MedicationAlertEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Notifications\MedicationAlertNotification;
use App\Notifications\MedicationSecondPersonConfirmationNotification;
use App\Services\Medication\Alerts\MedicationAlertCatalogue;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MedicationNotificationPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:30:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id, 'first_name' => 'Private', 'last_name' => 'Person', 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function revocations(): array
    {
        return [['medications.view'], ['medications.controlled.view'], ['site'], ['assignment'], ['client_deleted'], ['alert_deleted']];
    }

    #[DataProvider('revocations')]
    public function test_current_revocation_hides_stored_alerts_from_every_inbox_count_and_direct_action(string $revocation): void
    {
        $actor = $this->actor();
        $alert = $this->alert(['controlled' => true]);
        $private = $this->deliver($actor, new MedicationAlertNotification($alert, ackRequired: true));
        $generic = $this->deliver($actor, new AppEventNotification(['title' => 'Ordinary update']));
        $this->assertSame(2, $this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        match ($revocation) {
            'site' => $actor->hrEmployeeProfile->update(['primary_site_id' => Site::factory()->create(['is_active' => true])->id]),
            'assignment' => $actor->assignedClients()->detach($this->client->id),
            'client_deleted' => $this->client->delete(),
            'alert_deleted' => $alert->delete(),
            default => $this->permission($actor, $revocation, false),
        };
        $actor = $actor->fresh();
        $before = (array) DB::table('notifications')->where('id', $private)->first();
        $events = MedicationAlertEvent::count();
        $this->assertOnlyGeneric($actor, $generic);
        $this->assertDirectDenials($actor, $private);
        $this->actingAs($actor)->post('/inbox/notifications/read-all')->assertRedirect();
        $this->assertNotNull(DB::table('notifications')->where('id', $generic)->value('read_at'));
        DB::table('notifications')->where('id', $generic)->update(['read_at' => null]);
        $this->actingAs($actor)->post('/portal/notifications/read-all')->assertRedirect();
        $this->assertNotNull(DB::table('notifications')->where('id', $generic)->value('read_at'));
        $this->assertSame($before, (array) DB::table('notifications')->where('id', $private)->first());
        $this->assertSame($events, MedicationAlertEvent::count());
    }

    public function test_authorized_alert_reader_keeps_payload_and_read_ack_actions_with_json_string_ids(): void
    {
        $actor = $this->actor();
        $alert = $this->alert(['controlled' => true]);
        $id = $this->deliver($actor, new MedicationAlertNotification($alert, ackRequired: true));
        $data = $actor->notifications()->findOrFail($id)->data;
        $data['medication_alert_id'] = (string) $alert->id;
        DB::table('notifications')->where('id', $id)->update(['data' => json_encode($data)]);
        $response = $this->actingAs($actor)->get('/notifications')->assertOk();
        $this->assertSame(1, $response->inertiaProps('unread_count'));
        $this->assertSame('Private Person — controlled medicine dose at the house.', $response->inertiaProps('notifications.data.0.data.message'));
        $this->actingAs($actor)->post('/inbox/notifications/'.$id.'/read')->assertRedirect();
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->actingAs($actor)->post('/inbox/notifications/'.$id.'/acknowledge')->assertRedirect();
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('acknowledged_at'));
        $this->assertSame($data, $actor->notifications()->findOrFail($id)->data);
    }

    public function test_own_nonclinical_competency_and_generic_second_person_notices_survive_without_medication_read_rights(): void
    {
        $actor = $this->actor(['medications.view' => false, 'medications.controlled.view' => false]);
        $own = $this->alert(['type' => MedicationAlertCatalogue::RENEWALS, 'client_id' => null, 'staff_user_id' => $actor->id,
            'controlled' => false, 'message' => 'Your competency renewal is due.']);
        $other = $this->alert(['type' => MedicationAlertCatalogue::RENEWALS, 'client_id' => null, 'staff_user_id' => User::factory()->create()->id,
            'controlled' => false, 'message' => 'Other staff competency information.']);
        $ownId = $this->deliver($actor, new MedicationAlertNotification($own));
        $otherId = $this->deliver($actor, new MedicationAlertNotification($other));
        $genericId = $this->deliver($actor, new MedicationSecondPersonConfirmationNotification(123));
        $ids = collect($this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('notifications.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$ownId, $genericId], $ids);
        $this->assertNotContains($otherId, $ids);
        $this->actingAs($actor)->post('/inbox/notifications/'.$ownId.'/read')->assertRedirect();
        $this->assertDirectDenials($actor, $otherId);
    }

    public function test_controlled_retained_payload_is_hidden_even_if_the_source_controlled_metadata_was_corrected(): void
    {
        $actor = $this->actor(['medications.controlled.view' => false]);
        $alert = $this->alert(['controlled' => false]);
        $id = $this->deliver($actor, new MedicationAlertNotification($alert));
        $data = $actor->notifications()->findOrFail($id)->data;
        $data['controlled'] = true;
        DB::table('notifications')->where('id', $id)->update(['data' => json_encode($data)]);
        $this->assertSame(0, $this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        $this->assertDirectDenials($actor, $id);
    }

    public static function reboundAlertIdentities(): array
    {
        return [['client_id'], ['site_id']];
    }

    #[DataProvider('reboundAlertIdentities')]
    public function test_retained_alert_identity_cannot_follow_a_source_rebound_to_another_readable_identity(string $identity): void
    {
        $actor = $this->actor();
        $alert = $this->alert(['subject' => ['client_id' => (string) $this->client->id, 'site_id' => (string) $this->site->id]]);
        $id = $this->deliver($actor, new MedicationAlertNotification($alert, ackRequired: true));
        $this->assertSame(1, $this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        if ($identity === 'client_id') {
            $other = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
            $actor->assignedClients()->attach($other->id);
            $alert->update(['client_id' => $other->id]);
        } else {
            $other = Site::factory()->create(['is_active' => true]);
            $actor->hrEmployeeProfile->update(['secondary_site_ids' => [$other->id]]);
            $alert->update(['site_id' => $other->id]);
        }
        $actor = $actor->fresh();
        $before = (array) DB::table('notifications')->where('id', $id)->first();
        $this->assertSame(0, $this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        $this->assertDirectDenials($actor, $id);
        $this->actingAs($actor)->post('/inbox/notifications/read-all')->assertRedirect();
        $this->assertSame($before, (array) DB::table('notifications')->where('id', $id)->first());
    }

    public function test_audit_alert_for_a_deleted_person_is_hidden_without_changing_retained_history(): void
    {
        $actor = $this->actor(['medications.view' => false, 'medications.audit.view' => true]);
        $alert = $this->alert(['type' => MedicationAlertCatalogue::BREAKGLASS, 'controlled' => false]);
        $id = $this->deliver($actor, new MedicationAlertNotification($alert));
        $this->assertSame(1, $this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        $this->client->delete();
        $before = (array) DB::table('notifications')->where('id', $id)->first();
        $this->assertSame(0, $this->actingAs($actor->fresh())->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        $this->assertDirectDenials($actor->fresh(), $id);
        $this->assertSame($before, (array) DB::table('notifications')->where('id', $id)->first());
    }

    public static function clinicalEvents(): array
    {
        return [['medication.created'], ['medication.updated'], ['medication.discontinued'], ['medication_stock.updated'],
            ['medication_administration.created'], ['medication_correction_pending_approval.created'], ['controlled_drug_discrepancy.updated']];
    }

    #[DataProvider('clinicalEvents')]
    public function test_legacy_named_clinical_event_transport_rechecks_current_controlled_view(string $event): void
    {
        $actor = $this->actor();
        $order = $this->order(true);
        $source = match ($event) {
            'medication_stock.updated' => ClientMedicationStock::query()->create(['client_medication_id' => $order->id, 'on_hand' => 5]),
            'medication_administration.created', 'medication_correction_pending_approval.created' => ClientMedicationAdministration::query()->create([
                'client_id' => $this->client->id, 'client_medication_id' => $order->id, 'administered_by' => $actor->id,
                'administered_at' => now()->subMinute(), 'status' => 'given',
            ]),
            'controlled_drug_discrepancy.updated' => ClientControlledDrugDiscrepancy::query()->create([
                'client_id' => $this->client->id, 'client_medication_id' => $order->id,
                'reported_by' => $actor->id, 'reported_at' => now(), 'status' => 'closed', 'on_hand_before' => 5, 'on_hand_after' => 4, 'difference' => -1,
            ]),
            default => $order,
        };
        $id = $this->deliver($actor, new AppEventNotification([
            'event_key' => $event, 'client_id' => (string) $this->client->id, 'entity_id' => (string) $source->id,
            'title' => 'Private controlled medicine changed',
        ]));
        $this->assertSame(1, $this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        $this->permission($actor, 'medications.controlled.view', false);
        $actor = $actor->fresh();
        $this->assertSame(0, $this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        $this->assertDirectDenials($actor, $id);
    }

    public function test_legacy_source_identity_cannot_be_rebound_to_another_readable_person(): void
    {
        $actor = $this->actor();
        $order = $this->order(false);
        $other = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $actor->assignedClients()->attach($other->id);
        $id = $this->deliver($actor, new AppEventNotification([
            'event_key' => 'medication.created', 'client_id' => $other->id, 'entity_id' => $order->id,
            'title' => 'Private medicine from a different person',
        ]));
        $actor = $actor->fresh();
        $this->assertTrue($actor->can('viewMedications', $other));
        $this->assertSame(0, $this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        $this->assertDirectDenials($actor, $id);
    }

    public function test_retained_soft_deleted_order_notice_is_readable_only_with_current_person_and_controlled_scope(): void
    {
        $actor = $this->actor();
        $order = $this->order(true);
        $id = $this->deliver($actor, new AppEventNotification([
            'event_key' => 'medication.discontinued', 'client_id' => $this->client->id, 'entity_id' => $order->id, 'title' => 'Retained controlled medicine',
        ]));
        // Historical imports may retain soft-deleted orders; new order deletion is denied.
        DB::table('client_medications')->where('id', $order->id)->update(['deleted_at' => now()]);
        $this->assertSame(1, $this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        $this->permission($actor, 'medications.controlled.view', false);
        $this->assertSame(0, $this->actingAs($actor->fresh())->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        $this->assertDirectDenials($actor->fresh(), $id);
    }

    public function test_record_only_assigned_worker_keeps_ordinary_legacy_administration_notices(): void
    {
        $actor = $this->actor(['medications.view' => false, 'medications.administer.record' => true, 'medications.controlled.view' => false]);
        $order = $this->order(false);
        $administration = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id, 'client_medication_id' => $order->id, 'administered_by' => $actor->id,
            'administered_at' => now()->subMinute(), 'status' => 'given',
        ]);
        $id = $this->deliver($actor, new AppEventNotification([
            'event_key' => 'medication_administration.created', 'client_id' => $this->client->id, 'entity_id' => $administration->id,
            'title' => 'Ordinary medicine recorded',
        ]));
        $this->assertSame(1, $this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        $this->actingAs($actor)->post('/inbox/notifications/'.$id.'/read')->assertRedirect();
    }

    public static function emergencyEvents(): array
    {
        return [['break_glass_access.created'], ['break_glass_access.ended']];
    }

    #[DataProvider('emergencyEvents')]
    public function test_named_emergency_transport_retains_history_purpose_but_rechecks_current_audit_access(string $event): void
    {
        $actor = $this->actor(['medications.view' => false, 'medications.audit.view' => true]);
        $grant = ClientBreakGlassAccess::query()->create([
            'client_id' => $this->client->id, 'user_id' => User::factory()->create()->id, 'reason' => 'Synthetic emergency reason',
            'expires_at' => now()->subHour(), 'ended_at' => now()->subHour(), 'ended_how' => 'revoked',
        ]);
        $grant->delete();
        $id = $this->deliver($actor, new AppEventNotification([
            'kind' => 'medication', 'event_key' => $event, 'access_id' => (string) $grant->id,
            'client_id' => (string) $this->client->id, 'title' => 'Emergency access changed', 'body' => 'Private Person emergency history.',
        ]));
        $this->assertSame(1, $this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        $this->permission($actor, 'medications.audit.view', false);
        $actor = $actor->fresh();
        $this->assertSame(0, $this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        $this->assertDirectDenials($actor, $id);
        $this->assertFalse($grant->fresh()->isRunning());
    }

    public function test_purpose_bound_audit_alerts_remain_available_without_medication_browsing_permission(): void
    {
        $actor = $this->actor(['medications.view' => false, 'medications.audit.view' => true]);
        $id = $this->deliver($actor, new MedicationAlertNotification($this->alert([
            'type' => MedicationAlertCatalogue::BREAKGLASS, 'controlled' => false,
            'subject' => ['emergency_access_review_report' => true],
        ])));
        $this->assertSame(1, $this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        $this->actingAs($actor)->post('/inbox/notifications/'.$id.'/read')->assertRedirect();
    }

    public function test_medication_payload_without_a_surviving_canonical_alert_is_not_readable(): void
    {
        $actor = $this->actor();
        $id = $this->deliver($actor, new AppEventNotification([
            'type' => 'medication_alert', 'medication_alert_id' => '99999999', 'title' => 'Private clinical message',
        ]));
        $this->assertSame(0, $this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        $this->assertDirectDenials($actor, $id);
    }

    public function test_emergency_notification_query_person_cannot_replace_its_canonical_grant_person(): void
    {
        $actor = $this->actor(['medications.view' => false, 'medications.audit.view' => true]);
        $other = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $grant = ClientBreakGlassAccess::query()->create([
            'client_id' => $this->client->id, 'user_id' => $actor->id, 'reason' => 'Synthetic reason', 'expires_at' => now()->addHour(),
        ]);
        $id = $this->deliver($actor, new AppEventNotification([
            'kind' => 'medication', 'event_key' => 'break_glass_access.created', 'access_id' => $grant->id,
            'client_id' => $other->id, 'body' => 'Incorrectly rebound private emergency message',
        ]));
        $this->assertSame(0, $this->actingAs($actor)->get('/notifications')->assertOk()->inertiaProps('unread_count'));
        $this->assertDirectDenials($actor, $id);
    }

    private function assertOnlyGeneric(User $actor, string $id): void
    {
        foreach ([['/notifications', 'notifications.data', 'unread_count'], ['/portal/notifications', 'notifications.data', 'unreadCount']] as [$url, $rows, $count]) {
            $response = $this->actingAs($actor)->get($url)->assertOk();
            $this->assertSame([$id], collect($response->inertiaProps($rows))->pluck('id')->all());
            $this->assertSame(1, $response->inertiaProps($count));
            $this->assertStringNotContainsString('Private Person', json_encode($response->inertiaProps($rows)));
        }
        $myDay = $this->actingAs($actor)->get('/my-day')->assertOk();
        $this->assertSame(1, $myDay->inertiaProps('stats.notifications_unread'));
        $this->assertSame([$id], collect($myDay->inertiaProps('notifications'))->pluck('id')->all());
        $this->actingAs($actor)->get('/notifications', $this->inertiaPartialHeaders('notifications/index', 'inbox'))
            ->assertOk()->assertJsonPath('props.inbox.notifications.unread_count', 1)
            ->assertJsonCount(1, 'props.inbox.notifications.items')
            ->assertJsonPath('props.inbox.notifications.items.0.id', $id);
    }

    private function assertDirectDenials(User $actor, string $id): void
    {
        foreach (['/inbox/notifications/'.$id.'/read', '/inbox/notifications/'.$id.'/acknowledge', '/portal/notifications/'.$id.'/read'] as $url) {
            $this->actingAs($actor)->post($url)->assertNotFound();
        }
    }

    private function deliver(User $actor, object $notification): string
    {
        $notification->id = (string) Str::uuid();
        $actor->notify($notification);

        return $notification->id;
    }

    private function alert(array $attributes = []): MedicationAlert
    {
        return MedicationAlert::query()->create($attributes + [
            'type' => MedicationAlertCatalogue::OVERDUE, 'dedupe_key' => (string) Str::uuid(), 'site_id' => $this->site->id,
            'client_id' => $this->client->id, 'controlled' => false, 'title' => 'Medication alert',
            'message' => 'Private Person — controlled medicine dose at the house.', 'short_message' => 'A dose needs attention.',
            'severity' => 'warning', 'status' => MedicationAlert::STATUS_OPEN, 'raised_at' => now(),
        ]);
    }

    private function order(bool $controlled): ClientMedication
    {
        return ClientMedication::factory()->create([
            'client_id' => $this->client->id, 'name' => 'Private medicine', 'active' => true, 'state' => 'active', 'is_prn' => true,
            'approval_status' => 'verified', 'controlled_drug' => $controlled, 'start_date' => '2026-10-01', 'end_date' => null,
        ]);
    }

    private function actor(array $permissions = []): User
    {
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2026-09-01', 'end_date' => null,
        ]);
        $actor->assignedClients()->attach($this->client->id);
        foreach ($permissions + [
            'clients.viewAssigned' => true, 'clients.viewAny' => false, 'medications.view' => true, 'medications.controlled.view' => true,
            'medications.administer.record' => false, 'medications.audit.view' => false, 'medications.breakglass' => false,
            'medications.stock.update' => false, 'medications.reports.view' => false, 'medications.reports.export' => false,
            'clinical.accessAllSites' => false, 'sites.viewAll' => false,
        ] as $key => $allowed) {
            $this->permission($actor, $key, $allowed);
        }

        return $actor->fresh();
    }

    private function permission(User $actor, string $key, bool $allowed): void
    {
        $permission = Permission::where('key', $key)->firstOrFail();
        $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
    }
}
