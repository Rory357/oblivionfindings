<?php

namespace Tests\Feature\ControlRoom;

use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Site;
use App\Services\ControlRoom\ControlRoomAlertProvenanceService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomAlertCanonicalProvenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_hs_handover_accepts_one_exact_client_and_site_tuple(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
        ]);
        $event = HsEvent::factory()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
        ]);

        app(ControlRoomAlertProvenanceService::class)
            ->assertHealthSafetyEventTuple($alert, $event);

        $this->addToAssertionCount(1);
    }

    public function test_hs_handover_rejects_a_different_site(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
        $alert = ControlRoomAlert::factory()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
        ]);
        $event = HsEvent::factory()->create([
            'site_id' => $otherSite->id,
            'client_id' => $otherClient->id,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Client/Site ownership tuple');

        app(ControlRoomAlertProvenanceService::class)
            ->assertHealthSafetyEventTuple($alert, $event);
    }

    public function test_hs_handover_rejects_a_different_client_at_the_same_site(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $otherClient = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
        ]);
        $event = HsEvent::factory()->create([
            'site_id' => $site->id,
            'client_id' => $otherClient->id,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Client/Site ownership tuple');

        app(ControlRoomAlertProvenanceService::class)
            ->assertHealthSafetyEventTuple($alert, $event);
    }
}
