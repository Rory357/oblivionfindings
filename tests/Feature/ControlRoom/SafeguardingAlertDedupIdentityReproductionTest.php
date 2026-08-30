<?php

namespace Tests\Feature\ControlRoom;

use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SafeguardingAlertDedupIdentityReproductionTest extends TestCase
{
    use DatabaseTruncation;

    private int $referenceSequence = 0;

    public function test_distinct_same_client_concerns_each_create_their_own_alert(): void
    {
        $this->travelTo(Carbon::parse('2026-08-30 10:00:00'));

        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $reporter = User::factory()->create();
        $firstConcern = $this->createConcernWithoutObservers($reporter, [
            'site_id' => $site->id,
            'subject_type' => Client::class,
            'subject_id' => $client->id,
            'subject_name' => 'Shared client',
        ]);
        $secondConcern = $this->createConcernWithoutObservers($reporter, [
            'site_id' => $site->id,
            'subject_type' => Client::class,
            'subject_id' => $client->id,
            'subject_name' => 'Shared client',
        ]);

        $bridge = app(ComprehensiveAlertBridgeService::class);
        $firstAlert = $bridge->bridgeSafeguardingConcern($firstConcern);
        $this->travel(5)->minutes();
        $secondAlert = $bridge->bridgeSafeguardingConcern($secondConcern);

        $this->assertNotNull($firstAlert);
        $this->assertNotNull($secondAlert, 'A distinct same-client safeguarding concern was suppressed.');
        $this->assertNotSame($firstAlert->id, $secondAlert->id);
        $this->assertSame($firstConcern->id, (int) data_get($firstAlert->context, 'concern_id'));
        $this->assertSame($secondConcern->id, (int) data_get($secondAlert->context, 'concern_id'));
        $this->assertSame($client->id, $firstAlert->client_id);
        $this->assertSame($client->id, $secondAlert->client_id);
        $this->assertSame($site->id, $firstAlert->site_id);
        $this->assertSame($site->id, $secondAlert->site_id);
        $this->assertSame(2, $this->safeguardingAlerts()->count());
    }

    public function test_distinct_personless_concerns_each_create_their_own_alert(): void
    {
        $this->travelTo(Carbon::parse('2026-08-30 11:00:00'));

        $site = Site::factory()->create();
        $reporter = User::factory()->create();
        $firstConcern = $this->createConcernWithoutObservers($reporter, [
            'site_id' => $site->id,
            'subject_type' => null,
            'subject_id' => null,
            'subject_name' => null,
        ]);
        $secondConcern = $this->createConcernWithoutObservers($reporter, [
            'site_id' => $site->id,
            'subject_type' => null,
            'subject_id' => null,
            'subject_name' => null,
        ]);

        $bridge = app(ComprehensiveAlertBridgeService::class);
        $firstAlert = $bridge->bridgeSafeguardingConcern($firstConcern);
        $this->travel(5)->minutes();
        $secondAlert = $bridge->bridgeSafeguardingConcern($secondConcern);

        $this->assertNotNull($firstAlert);
        $this->assertNotNull($secondAlert, 'A distinct personless safeguarding concern was suppressed.');
        $this->assertNotSame($firstAlert->id, $secondAlert->id);
        $this->assertSame($firstConcern->id, (int) data_get($firstAlert->context, 'concern_id'));
        $this->assertSame($secondConcern->id, (int) data_get($secondAlert->context, 'concern_id'));
        $this->assertNull($firstAlert->client_id);
        $this->assertNull($secondAlert->client_id);
        $this->assertSame($site->id, $firstAlert->site_id);
        $this->assertSame($site->id, $secondAlert->site_id);
        $this->assertSame(2, $this->safeguardingAlerts()->count());
    }

    public function test_retrying_the_same_concern_remains_idempotent(): void
    {
        $this->travelTo(Carbon::parse('2026-08-30 12:00:00'));

        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $reporter = User::factory()->create();
        $concern = $this->createConcernWithoutObservers($reporter, [
            'site_id' => $site->id,
            'subject_type' => Client::class,
            'subject_id' => $client->id,
            'subject_name' => 'Retry client',
        ]);

        $bridge = app(ComprehensiveAlertBridgeService::class);
        $firstAlert = $bridge->bridgeSafeguardingConcern($concern);
        $this->travel(5)->minutes();
        $retry = $bridge->bridgeSafeguardingConcern($concern);

        $this->assertNotNull($firstAlert);
        $this->assertNull($retry);
        $this->assertSame(1, ControlRoomAlert::query()
            ->where('source', 'safeguarding')
            ->where('alert_type', 'safeguarding.abuse')
            ->where('context->concern_id', $concern->id)
            ->count());
    }

    public function test_distinct_personless_concerns_do_not_collapse_across_sites(): void
    {
        $this->travelTo(Carbon::parse('2026-08-30 13:00:00'));

        $firstSite = Site::factory()->create();
        $secondSite = Site::factory()->create();
        $reporter = User::factory()->create();
        $firstConcern = $this->createConcernWithoutObservers($reporter, [
            'site_id' => $firstSite->id,
            'subject_type' => null,
            'subject_id' => null,
            'subject_name' => null,
        ]);
        $secondConcern = $this->createConcernWithoutObservers($reporter, [
            'site_id' => $secondSite->id,
            'subject_type' => null,
            'subject_id' => null,
            'subject_name' => null,
        ]);

        $bridge = app(ComprehensiveAlertBridgeService::class);
        $firstAlert = $bridge->bridgeSafeguardingConcern($firstConcern);
        $this->travel(5)->minutes();
        $secondAlert = $bridge->bridgeSafeguardingConcern($secondConcern);

        $this->assertNotNull($firstAlert);
        $this->assertNotNull($secondAlert, 'A distinct cross-Site safeguarding concern was suppressed.');
        $this->assertNotSame($firstAlert->id, $secondAlert->id);
        $this->assertSame($firstSite->id, $firstAlert->site_id);
        $this->assertSame($secondSite->id, $secondAlert->site_id);
        $this->assertSame($firstConcern->id, (int) data_get($firstAlert->context, 'concern_id'));
        $this->assertSame($secondConcern->id, (int) data_get($secondAlert->context, 'concern_id'));
        $this->assertSame(2, $this->safeguardingAlerts()->count());
    }

    public function test_observer_links_each_concern_hs_event_only_to_its_own_alert(): void
    {
        $this->travelTo(Carbon::parse('2026-08-30 14:00:00'));

        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $reporter = User::factory()->create();
        [$firstConcern, $secondConcern] = DB::transaction(function () use ($site, $client, $reporter): array {
            $firstConcern = SafeguardingConcern::factory()->create([
                'reference_number' => 'SG-OBSERVER-001',
                'site_id' => $site->id,
                'reported_by_user_id' => $reporter->id,
                'related_incident_id' => null,
                'concern_type' => 'abuse',
                'abuse_category' => 'physical',
                'severity' => 'high',
                'subject_type' => Client::class,
                'subject_id' => $client->id,
                'subject_name' => 'Observer client',
            ]);
            $this->travel(5)->minutes();
            $secondConcern = SafeguardingConcern::factory()->create([
                'reference_number' => 'SG-OBSERVER-002',
                'site_id' => $site->id,
                'reported_by_user_id' => $reporter->id,
                'related_incident_id' => null,
                'concern_type' => 'abuse',
                'abuse_category' => 'physical',
                'severity' => 'high',
                'subject_type' => Client::class,
                'subject_id' => $client->id,
                'subject_name' => 'Observer client',
            ]);

            return [$firstConcern, $secondConcern];
        });

        $alerts = $this->safeguardingAlerts();

        $this->assertCount(2, $alerts);
        $this->assertNotSame($alerts[0]->id, $alerts[1]->id);

        $eventIds = [];

        foreach ([$firstConcern, $secondConcern] as $concern) {
            $key = HsEvent::buildIdempotencyKey(
                SafeguardingConcern::class,
                $concern->id,
                HsEvent::CATEGORY_SAFEGUARDING,
            );
            $event = HsEvent::query()->where('idempotency_key', $key)->sole();
            $alert = $alerts->first(
                fn (ControlRoomAlert $candidate): bool => (int) data_get($candidate->context, 'concern_id') === $concern->id,
            );

            $this->assertNotNull($alert);
            $this->assertSame('safeguarding', $alert->source);
            $this->assertSame('safeguarding.abuse', $alert->alert_type);
            $this->assertSame(SafeguardingConcern::class, $event->source_type);
            $this->assertSame($concern->id, $event->source_id);
            $this->assertSame(HsEvent::CATEGORY_SAFEGUARDING, $event->event_category);
            $this->assertSame($site->id, $event->site_id);
            $this->assertSame($client->id, $event->client_id);
            $this->assertSame($site->id, $alert->site_id);
            $this->assertSame($client->id, $alert->client_id);
            $this->assertSame($alert->id, $event->control_room_alert_id);
            $eventIds[] = $event->id;
        }

        $this->assertNotSame($eventIds[0], $eventIds[1]);
        $this->assertSame(2, HsEvent::query()
            ->where('source_type', SafeguardingConcern::class)
            ->where('event_category', HsEvent::CATEGORY_SAFEGUARDING)
            ->count());

        $firstEvent = HsEvent::query()
            ->where('idempotency_key', HsEvent::buildIdempotencyKey(
                SafeguardingConcern::class,
                $firstConcern->id,
                HsEvent::CATEGORY_SAFEGUARDING,
            ))
            ->sole();
        $originalAlertId = $firstEvent->control_room_alert_id;
        $this->travel(5)->minutes();

        $this->assertNull(app(ComprehensiveAlertBridgeService::class)
            ->bridgeSafeguardingConcern($firstConcern));
        $this->assertSame($originalAlertId, $firstEvent->fresh()->control_room_alert_id);
        $this->assertSame(1, ControlRoomAlert::query()
            ->where('source', 'safeguarding')
            ->where('context->concern_id', $firstConcern->id)
            ->count());
    }

    private function createConcernWithoutObservers(User $reporter, array $attributes): SafeguardingConcern
    {
        $this->referenceSequence++;

        return SafeguardingConcern::withoutEvents(fn (): SafeguardingConcern => SafeguardingConcern::factory()->create(array_merge([
            'reference_number' => sprintf('SG-DEDUP-%03d', $this->referenceSequence),
            'reported_by_user_id' => $reporter->id,
            'reported_at' => now(),
            'related_incident_id' => null,
            'concern_type' => 'abuse',
            'abuse_category' => 'physical',
            'severity' => 'high',
        ], $attributes)));
    }

    private function safeguardingAlerts(): Collection
    {
        return ControlRoomAlert::query()
            ->where('source', 'safeguarding')
            ->where('alert_type', 'safeguarding.abuse')
            ->orderBy('id')
            ->get();
    }
}
