<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Client;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidRecord;
use App\Models\FirstAidSourceDelivery;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\UserNotificationPreference;
use App\Services\HealthSafety\FirstAidDeliveryService;
use App\Services\HealthSafety\HsEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FirstAidSourceFixtures;
use Tests\TestCase;

class FirstAidDeliveryTest extends TestCase
{
    use FirstAidSourceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeFirstAidFixtures();
    }

    private function delivery(): FirstAidDeliveryService
    {
        return app(FirstAidDeliveryService::class);
    }

    private function followup(FirstAidRecord $row, array $extra = []): FirstAidFollowup
    {
        $this->sourceService()->addFollowup($row, $this->manager, $this->reviewed($row, ['notes' => 'Private requested follow-through', 'assigned_to_user_id' => $this->worker->id, 'due_at' => now()->subMinute()->toISOString(), ...$extra]));

        return $row->followups()->latest('id')->firstOrFail();
    }

    public static function significantFacts(): array
    {
        return [[['ambulance_called' => true]], [['treatment_outcome' => 'sent_to_hospital']]];
    }

    #[DataProvider('significantFacts')]
    public function test_significant_source_receipt_is_pending_then_confirms_one_review_without_regulatory_verdict(array $fact): void
    {
        $row = $this->source($fact);
        $intent = FirstAidSourceDelivery::where('purpose', 'source_review')->sole();
        $this->assertSame('pending', $intent->status);
        $this->assertNull($intent->delivered_at);
        $this->assertSame(0, HsEvent::count());
        $this->delivery()->deliver($intent->id);
        $this->delivery()->deliver($intent->id);
        $event = HsEvent::sole();
        $this->assertSame(FirstAidRecord::class, $event->source_type);
        $this->assertSame($row->id, $event->source_id);
        $this->assertSame($row->site_id, $event->site_id);
        $this->assertNull($event->worksafe_notifiable);
        $this->assertNull($event->worksafe_decided_at);
        $this->assertNotSame(HsEvent::STATUS_CLOSED, $event->status);
        $this->assertSame('delivered', $intent->fresh()->status);
        $this->assertSame($event->id, $intent->fresh()->hs_event_id);
        $this->assertSame(1, (int) $intent->fresh()->attempts);
        $this->sourceService()->update($row, $this->manager, $this->reviewed($row, ['first_aider_notes' => 'Unrelated factual annotation']));
        $this->assertSame(1, FirstAidSourceDelivery::where('purpose', 'source_review')->count());
    }

    public function test_review_failure_retains_confirmed_source_and_retries_the_actual_review_once(): void
    {
        $row = $this->source(['ambulance_called' => true]);
        $before = $row->getAttributes();
        $intent = FirstAidSourceDelivery::sole();
        $real = app(HsEventService::class);
        app()->instance(HsEventService::class, new class extends HsEventService
        {
            public function __construct() {}

            public function recordEvent(array $data): ?HsEvent
            {
                throw new RuntimeException('Synthetic local review unavailable');
            }
        });
        try {
            $this->delivery()->deliver($intent->id);
            $this->fail('Delivery failure must remain observable.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic local review unavailable', $error->getMessage());
        } finally {
            app()->instance(HsEventService::class, $real);
        }
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame('failed', $intent->fresh()->status);
        $this->assertSame('delivery_failed', $intent->fresh()->failure_code);
        $this->assertNull($intent->fresh()->delivered_at);
        $this->assertTrue($intent->fresh()->next_attempt_at->isFuture());
        $this->travel(31)->seconds();
        $this->assertSame(1, $this->delivery()->recoverPending());
        $this->assertSame('delivered', $intent->fresh()->status);
        $this->assertSame(2, (int) $intent->fresh()->attempts);
        $this->assertSame(1, HsEvent::count());
    }

    public function test_archived_or_revoked_source_review_intent_is_skipped_without_claiming_delivery(): void
    {
        $row = $this->source(['ambulance_called' => true]);
        $intent = FirstAidSourceDelivery::sole();
        $this->sourceService()->archive($row, $this->manager, $this->reviewed($row));
        $this->delivery()->deliver($intent->id);
        $this->assertSame('skipped', $intent->fresh()->status);
        $this->assertSame('source_inactive', $intent->fresh()->failure_code);
        $this->assertNull($intent->fresh()->delivered_at);
        $this->assertSame(0, HsEvent::count());
    }

    public function test_recipient_revocation_prevents_sensitive_assignment_notification(): void
    {
        $row = $this->source();
        $this->followup($row);
        $intent = FirstAidSourceDelivery::sole();
        $this->worker->hrEmployeeProfile()->update(['is_active' => false]);
        $this->delivery()->deliver($intent->id);
        $this->assertSame('skipped', $intent->fresh()->status);
        $this->assertSame('authority_changed', $intent->fresh()->failure_code);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_transfer_invalidates_old_assignment_and_exact_new_delivery_has_one_neutral_inbox_receipt(): void
    {
        $row = $this->source();
        $child = $this->followup($row);
        $old = FirstAidSourceDelivery::sole();
        $newOwner = $this->sourceStaff(['hazards.view']);
        $this->sourceService()->updateFollowup($row, $child, $this->manager, $this->reviewed($row, ['notes' => 'Different private requested work', 'assigned_to_user_id' => $newOwner->id]));
        $new = FirstAidSourceDelivery::where('recipient_user_id', $newOwner->id)->sole();
        $this->delivery()->deliver($old->id);
        $this->delivery()->deliver($new->id);
        $this->delivery()->deliver($new->id);
        $this->assertSame('skipped', $old->fresh()->status);
        $this->assertSame('assignment_inactive', $old->fresh()->failure_code);
        $this->assertSame('delivered', $new->fresh()->status);
        $notification = DB::table('notifications')->sole();
        $this->assertSame($new->id, $notification->id);
        $this->assertSame($newOwner->id, $notification->notifiable_id);
        $payload = json_decode($notification->data, true);
        $this->assertSame('/health-safety/first-aid/'.$row->id.'?section=followups&followup='.$child->id, $payload['url']);
        $this->assertStringNotContainsString('Different private', $notification->data);
        $this->assertStringNotContainsString('Recorded staff subject', $notification->data);
        $this->assertArrayNotHasKey('client_id', $payload);
    }

    public function test_preference_suppression_is_retained_distinctly_from_delivery(): void
    {
        $row = $this->source();
        $this->followup($row);
        UserNotificationPreference::create(['user_id' => $this->worker->id, 'key' => 'tasks.assigned', 'enabled' => false, 'channel_inapp' => true, 'channel_email' => false]);
        $intent = FirstAidSourceDelivery::sole();
        $this->delivery()->deliver($intent->id);
        $this->assertSame('suppressed', $intent->fresh()->status);
        $this->assertSame('preference_suppressed', $intent->fresh()->failure_code);
        $this->assertNull($intent->fresh()->delivered_at);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_daily_digest_is_exact_current_source_membership_and_once_per_nz_day(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07T12:30:00Z'));
        $row = $this->source();
        $this->followup($row);
        $second = $this->source();
        $this->followup($second);
        $this->assertSame(1, $this->delivery()->stageDueReminders());
        $this->assertSame(0, $this->delivery()->stageDueReminders());
        $intent = FirstAidSourceDelivery::where('purpose', 'followup_reminder')->sole();
        $this->assertSame('2026-10-08', $intent->reminder_day->toDateString());
        $this->delivery()->deliver($intent->id);
        $this->delivery()->deliver($intent->id);
        $notification = DB::table('notifications')->where('id', $intent->id)->sole();
        $payload = json_decode($notification->data, true);
        $this->assertSame(2, $payload['followups_due']);
        $this->assertSame(2, $payload['overdue']);
        $this->assertSame('/health-safety/first-aid?tab=followup&period=all', $payload['url']);
        $this->travel(1)->days();
        $this->assertSame(1, $this->delivery()->stageDueReminders());
        $this->assertSame(2, FirstAidSourceDelivery::where('purpose', 'followup_reminder')->count());
    }

    public function test_multi_record_reminder_destination_retains_old_outstanding_work_and_fresh_client_privacy(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08T00:00:00Z'));
        $first = $this->source(['treatment_date' => now()->subDays(60)->toISOString()]);
        $this->followup($first);
        $second = $this->source(['treatment_date' => now()->subDays(90)->toISOString()]);
        $this->followup($second);
        $clientPermission = Permission::where('key', 'clients.viewAny')->firstOrFail();
        $this->worker->permissionOverrides()->syncWithoutDetaching([$clientPermission->id => ['allowed' => true]]);
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $private = $this->source(['treated_person_type' => 'client', 'client_id' => $client->id, 'treated_person_id' => null,
            'treated_person_name' => 'Private reminder subject', 'treatment_given' => 'Private reminder treatment', 'treatment_date' => now()->subDays(75)->toISOString()]);
        $this->followup($private);
        $this->assertSame(1, $this->delivery()->stageDueReminders());
        $intent = FirstAidSourceDelivery::where('purpose', 'followup_reminder')->sole();
        $this->assertCount(3, $intent->membership);
        $this->worker->permissionOverrides()->syncWithoutDetaching([$clientPermission->id => ['allowed' => false]]);
        $this->delivery()->deliver($intent->id);
        $payload = json_decode(DB::table('notifications')->where('id', $intent->id)->sole()->data, true);
        $this->assertSame('delivered', $intent->fresh()->status);
        $this->assertSame(2, $payload['followups_due']);
        $this->assertSame('/health-safety/first-aid?tab=followup&period=all', $payload['url']);
        $this->actingAs($this->worker)->getJson('/health-safety/first-aid?tab=followup')->assertOk()->assertJsonPath('records.total', 0);
        $response = $this->getJson($payload['url'])->assertOk()->assertJsonPath('filters.period', 'all')->assertJsonPath('tab', 'followup')->assertJsonPath('records.total', 2);
        $ids = array_column($response->json('records.data'), 'id');
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $ids);
        $this->assertNotContains($private->id, $ids);
        $this->assertStringNotContainsString('Private reminder', $response->getContent());
        $this->getJson('/health-safety/first-aid/'.$private->id)->assertNotFound();
    }

    public function test_completed_work_is_removed_from_pending_digest_and_does_not_emit_a_false_success(): void
    {
        $row = $this->source();
        $child = $this->followup($row);
        $this->delivery()->stageDueReminders();
        $intent = FirstAidSourceDelivery::where('purpose', 'followup_reminder')->sole();
        $this->sourceService()->completeFollowup($row, $child, $this->worker, $this->reviewed($row, ['completion_notes' => 'Actual follow-through recorded']));
        $this->delivery()->deliver($intent->id);
        $this->assertSame('skipped', $intent->fresh()->status);
        $this->assertNull($intent->fresh()->delivered_at);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_reminder_window_retains_future_due_work_without_claiming_it_is_overdue(): void
    {
        $row = $this->source();
        $this->followup($row, ['due_at' => now()->addDay()->toISOString()]);
        $this->assertSame(0, $this->delivery()->stageDueReminders());
        $this->assertSame(1, $this->delivery()->stageDueReminders(2));
        $intent = FirstAidSourceDelivery::where('purpose', 'followup_reminder')->sole();
        $this->delivery()->deliver($intent->id);
        $payload = json_decode(DB::table('notifications')->where('id', $intent->id)->sole()->data, true);
        $this->assertSame(1, $payload['followups_due']);
        $this->assertSame(0, $payload['overdue']);
    }

    public function test_reminder_fresh_read_permission_revocation_skips_the_pending_digest(): void
    {
        $row = $this->source();
        $this->followup($row);
        $this->delivery()->stageDueReminders();
        $intent = FirstAidSourceDelivery::where('purpose', 'followup_reminder')->sole();
        $this->worker->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.view')->firstOrFail()->id => ['allowed' => false]]);
        $this->delivery()->deliver($intent->id);
        $this->assertSame('skipped', $intent->fresh()->status);
        $this->assertSame('authority_changed', $intent->fresh()->failure_code);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_old_assignment_source_tuple_is_not_transferred_by_a_legacy_subject_correction(): void
    {
        $row = $this->source();
        $this->followup($row);
        $intent = FirstAidSourceDelivery::where('purpose', 'followup_assignment')->sole();
        // Direct legacy storage remains readable, but it cannot rewrite the accepted source intent.
        $other = $this->sourceStaff();
        $row->forceFill(['treated_person_id' => $other->id])->saveQuietly();
        $this->delivery()->deliver($intent->id);
        $this->assertSame('skipped', $intent->fresh()->status);
        $this->assertNull($intent->fresh()->delivered_at);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_revoked_capture_actor_prevents_a_pending_source_review_without_rolling_back_treatment(): void
    {
        $row = $this->source(['ambulance_called' => true]);
        $intent = FirstAidSourceDelivery::sole();
        $this->manager->hrEmployeeProfile()->update(['is_active' => false]);
        $this->delivery()->deliver($intent->id);
        $this->assertSame('skipped', $intent->fresh()->status);
        $this->assertSame('authority_changed', $intent->fresh()->failure_code);
        $this->assertNull($intent->fresh()->delivered_at);
        $this->assertSame(0, HsEvent::count());
        $this->assertSame(1, FirstAidRecord::count());
    }

    public function test_global_source_view_does_not_replace_current_named_assignment_site_eligibility(): void
    {
        $row = $this->source();
        $this->followup($row);
        $intent = FirstAidSourceDelivery::sole();
        $this->worker->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'healthSafety.viewAllSites')->firstOrFail()->id => ['allowed' => true]]);
        $otherSite = Site::factory()->create(['is_active' => true]);
        $this->worker->hrEmployeeProfile()->update(['primary_site_id' => $otherSite->id, 'secondary_site_ids' => []]);
        $this->delivery()->deliver($intent->id);
        $this->assertSame('skipped', $intent->fresh()->status);
        $this->assertNull($intent->fresh()->delivered_at);
        $this->assertSame(0, DB::table('notifications')->count());
    }
}
