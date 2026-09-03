<?php

namespace Tests\Feature\Safeguarding;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\SafetySignalDeliveryRecoveryService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SafeguardingProjectionDurabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_recovery_detects_and_reconciles_missing_hs_event_for_safeguarding_concern(): void
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $reporter = User::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);

        // Create concern directly in database (simulating missed projection during unexpected termination)
        $concern = SafeguardingConcern::withoutEvents(function () use ($site, $reporter, $client) {
            return SafeguardingConcern::create([
                'reference_number' => 'SC-' . strtoupper(Str::random(8)),
                'site_id' => $site->id,
                'subject_type' => Client::class,
                'subject_id' => $client->id,
                'subject_name' => $client->name,
                'concern_type' => 'neglect',
                'severity' => 'high',
                'description' => 'Unprojected concern test.',
                'reported_by_user_id' => $reporter->id,
                'reported_at' => now(),
                'status' => 'reported',
            ]);
        });

        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);

        // Run safety signal recovery
        $recovery = app(SafetySignalDeliveryRecoveryService::class);
        $result = $recovery->recover();

        $this->assertSame(1, $result['reconciled']['safeguarding']);

        // Verify HsEvent was created
        $key = HsEvent::buildIdempotencyKey(get_class($concern), $concern->id, HsEvent::CATEGORY_SAFEGUARDING);
        $hsEvent = HsEvent::where('idempotency_key', $key)->first();
        $this->assertNotNull($hsEvent);
        $this->assertSame(HsEvent::CATEGORY_SAFEGUARDING, $hsEvent->event_category);
        $this->assertSame('high', $hsEvent->severity);
        $this->assertSame($site->id, $hsEvent->site_id);
        $this->assertSame($client->id, $hsEvent->client_id);

        // Verify ControlRoomAlert was created
        $alert = ControlRoomAlert::where('context->concern_id', $concern->id)->first();
        $this->assertNotNull($alert);
        $this->assertSame($site->id, $alert->site_id);
        $this->assertSame($client->id, $alert->client_id);

        // Verify linkage
        $this->assertSame($alert->id, $hsEvent->fresh()->control_room_alert_id);
    }

    public function test_recovery_detects_and_creates_missing_notifiable_incident_for_critical_concern(): void
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $reporter = User::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);

        $concern = SafeguardingConcern::withoutEvents(function () use ($site, $reporter, $client) {
            return SafeguardingConcern::create([
                'reference_number' => 'SC-' . strtoupper(Str::random(8)),
                'site_id' => $site->id,
                'subject_type' => Client::class,
                'subject_id' => $client->id,
                'subject_name' => $client->name,
                'concern_type' => 'physical_abuse',
                'severity' => 'critical',
                'description' => 'Critical unprojected concern test.',
                'reported_by_user_id' => $reporter->id,
                'reported_at' => now(),
                'status' => 'reported',
            ]);
        });

        $this->assertDatabaseCount('notifiable_incidents', 0);

        $recovery = app(SafetySignalDeliveryRecoveryService::class);
        $result = $recovery->recover();

        $this->assertSame(1, $result['reconciled']['safeguarding']);

        $notifiable = NotifiableIncident::where('related_incident_id', $concern->id)->first();
        $this->assertNotNull($notifiable);
        $this->assertSame('safeguarding', $notifiable->incident_type);
        $this->assertSame('health_nz', $notifiable->notification_authority);
        $this->assertSame('pending', $notifiable->status);
    }

    public function test_recovery_is_completely_idempotent(): void
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $reporter = User::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);

        $concern = SafeguardingConcern::withoutEvents(function () use ($site, $reporter, $client) {
            return SafeguardingConcern::create([
                'reference_number' => 'SC-' . strtoupper(Str::random(8)),
                'site_id' => $site->id,
                'subject_type' => Client::class,
                'subject_id' => $client->id,
                'subject_name' => $client->name,
                'concern_type' => 'emotional_abuse',
                'severity' => 'critical',
                'description' => 'Idempotency test concern.',
                'reported_by_user_id' => $reporter->id,
                'reported_at' => now(),
                'status' => 'reported',
            ]);
        });

        $recovery = app(SafetySignalDeliveryRecoveryService::class);

        // Run 1: reconciles missing projections
        $result1 = $recovery->recover();
        $this->assertSame(1, $result1['reconciled']['safeguarding']);
        $this->assertSame(1, HsEvent::count());
        $this->assertSame(1, ControlRoomAlert::count());
        $this->assertSame(1, NotifiableIncident::count());

        // Run 2: idempotent sweep produces 0 changes, 0 duplicate alerts or events
        $result2 = $recovery->recover();
        $this->assertSame(0, $result2['reconciled']['safeguarding']);
        $this->assertSame(1, HsEvent::count());
        $this->assertSame(1, ControlRoomAlert::count());
        $this->assertSame(1, NotifiableIncident::count());
    }
}
