<?php

namespace Tests\Unit\Clinical;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Enums\AlertSeverity;
use App\Models\Client;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicalEventModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_can_be_created_with_factory(): void
    {
        $event = ClinicalEvent::factory()->create();

        $this->assertDatabaseHas('clinical_events', ['id' => $event->id]);
    }

    public function test_event_type_is_cast_to_enum(): void
    {
        $event = ClinicalEvent::factory()->fall()->create();

        $this->assertInstanceOf(ClinicalEventType::class, $event->event_type);
        $this->assertEquals(ClinicalEventType::Fall, $event->event_type);
    }

    public function test_witnesses_cast_to_array(): void
    {
        $event = ClinicalEvent::factory()->create([
            'witnesses' => [1, 2, 3],
        ]);
        $event->refresh();

        $this->assertIsArray($event->witnesses);
        $this->assertCount(3, $event->witnesses);
    }

    public function test_client_relationship(): void
    {
        $client = Client::factory()->create();
        $event = ClinicalEvent::factory()->create(['client_id' => $client->id]);

        $this->assertEquals($client->id, $event->client->id);
    }

    public function test_reporter_relationship(): void
    {
        $user = User::factory()->create();
        $event = ClinicalEvent::factory()->create(['reported_by' => $user->id]);

        $this->assertEquals($user->id, $event->reporter->id);
    }

    public function test_shift_relationship_is_nullable(): void
    {
        $event = ClinicalEvent::factory()->create(['shift_id' => null]);

        $this->assertNull($event->shift);
    }

    public function test_shift_relationship_when_set(): void
    {
        $shift = Shift::factory()->create();
        $event = ClinicalEvent::factory()->forShift($shift->id)->create();

        $this->assertEquals($shift->id, $event->shift->id);
    }

    public function test_reviewer_relationship(): void
    {
        $event = ClinicalEvent::factory()->reviewed()->create();

        $this->assertNotNull($event->reviewer);
        $this->assertEquals('reviewed', $event->status);
    }

    public function test_soft_delete(): void
    {
        $event = ClinicalEvent::factory()->create();
        $event->delete();

        $this->assertSoftDeleted($event);
    }

    public function test_for_client_scope(): void
    {
        $client = Client::factory()->create();
        ClinicalEvent::factory()->create(['client_id' => $client->id]);
        ClinicalEvent::factory()->create(); // different client

        $this->assertCount(1, ClinicalEvent::forClient($client->id)->get());
    }

    public function test_of_type_scope(): void
    {
        ClinicalEvent::factory()->fall()->create();
        ClinicalEvent::factory()->seizure()->create();
        ClinicalEvent::factory()->fall()->create();

        $this->assertCount(2, ClinicalEvent::ofType(ClinicalEventType::Fall)->get());
        $this->assertCount(1, ClinicalEvent::ofType(ClinicalEventType::Seizure)->get());
    }

    public function test_open_scope(): void
    {
        ClinicalEvent::factory()->create(['status' => 'open']);
        ClinicalEvent::factory()->reviewed()->create();
        ClinicalEvent::factory()->create(['status' => 'closed']);

        $this->assertCount(1, ClinicalEvent::open()->get());
    }

    public function test_high_severity_scope(): void
    {
        ClinicalEvent::factory()->create(['severity' => AlertSeverity::LOW]);
        ClinicalEvent::factory()->create(['severity' => AlertSeverity::MEDIUM]);
        ClinicalEvent::factory()->highSeverity()->create();
        ClinicalEvent::factory()->critical()->create();

        $this->assertCount(2, ClinicalEvent::highSeverity()->get());
    }

    public function test_should_link_to_hs_helper(): void
    {
        $fall = ClinicalEvent::factory()->fall()->create();
        $seizure = ClinicalEvent::factory()->seizure()->create();
        $deterioration = ClinicalEvent::factory()->create([
            'event_type' => ClinicalEventType::Deterioration,
        ]);

        $this->assertTrue($fall->shouldLinkToHs());
        $this->assertTrue($seizure->shouldLinkToHs());
        $this->assertFalse($deterioration->shouldLinkToHs());
    }

    public function test_is_high_severity_helper(): void
    {
        $high = ClinicalEvent::factory()->highSeverity()->create();
        $low = ClinicalEvent::factory()->create(['severity' => AlertSeverity::LOW]);

        $this->assertTrue($high->isHighSeverity());
        $this->assertFalse($low->isHighSeverity());
    }

    public function test_event_type_hs_category_mapping(): void
    {
        $this->assertEquals('injury', ClinicalEventType::Fall->hsEventCategory());
        $this->assertEquals('incident', ClinicalEventType::Seizure->hsEventCategory());
        $this->assertEquals('incident', ClinicalEventType::Choking->hsEventCategory());
        $this->assertNull(ClinicalEventType::Deterioration->hsEventCategory());
        $this->assertNull(ClinicalEventType::Other->hsEventCategory());
    }
}
