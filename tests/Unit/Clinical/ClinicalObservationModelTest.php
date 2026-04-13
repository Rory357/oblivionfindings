<?php

namespace Tests\Unit\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicalObservationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_observation_can_be_created_with_factory(): void
    {
        $obs = ClinicalObservation::factory()->vitals()->create();

        $this->assertDatabaseHas('clinical_observations', ['id' => $obs->id]);
        $this->assertEquals(ObservationType::Vitals, $obs->observation_type);
    }

    public function test_observation_type_is_cast_to_enum(): void
    {
        $obs = ClinicalObservation::factory()->weight()->create();

        $this->assertInstanceOf(ObservationType::class, $obs->observation_type);
        $this->assertEquals(ObservationType::Weight, $obs->observation_type);
    }

    public function test_data_is_cast_to_array(): void
    {
        $obs = ClinicalObservation::factory()->vitals()->create();
        $obs->refresh();

        $this->assertIsArray($obs->data);
        $this->assertArrayHasKey('systolic', $obs->data);
        $this->assertArrayHasKey('diastolic', $obs->data);
    }

    public function test_client_relationship(): void
    {
        $client = Client::factory()->create();
        $obs = ClinicalObservation::factory()->create(['client_id' => $client->id]);

        $this->assertEquals($client->id, $obs->client->id);
    }

    public function test_recorder_relationship(): void
    {
        $user = User::factory()->create();
        $obs = ClinicalObservation::factory()->create(['recorded_by' => $user->id]);

        $this->assertEquals($user->id, $obs->recorder->id);
    }

    public function test_shift_relationship_is_nullable(): void
    {
        $obs = ClinicalObservation::factory()->create(['shift_id' => null]);

        $this->assertNull($obs->shift);
    }

    public function test_shift_relationship_when_set(): void
    {
        $shift = Shift::factory()->create();
        $obs = ClinicalObservation::factory()->forShift($shift->id)->create();

        $this->assertEquals($shift->id, $obs->shift->id);
    }

    public function test_correction_self_referential_relationship(): void
    {
        $original = ClinicalObservation::factory()->vitals()->create();
        $correction = ClinicalObservation::factory()->vitals()->create([
            'client_id' => $original->client_id,
            'correction_of_id' => $original->id,
            'correction_status' => 'pending',
            'correction_reason' => 'Incorrect reading',
        ]);

        $this->assertEquals($original->id, $correction->correctionOf->id);
        $this->assertTrue($correction->isCorrection());
        $this->assertFalse($original->isCorrection());
        $this->assertCount(1, $original->corrections);
    }

    public function test_soft_delete(): void
    {
        $obs = ClinicalObservation::factory()->create();
        $obs->delete();

        $this->assertSoftDeleted($obs);
        $this->assertDatabaseHas('clinical_observations', ['id' => $obs->id]);
    }

    public function test_for_client_scope(): void
    {
        $client = Client::factory()->create();
        ClinicalObservation::factory()->create(['client_id' => $client->id]);
        ClinicalObservation::factory()->create(); // different client

        $this->assertCount(1, ClinicalObservation::forClient($client->id)->get());
    }

    public function test_of_type_scope(): void
    {
        ClinicalObservation::factory()->vitals()->create();
        ClinicalObservation::factory()->weight()->create();
        ClinicalObservation::factory()->vitals()->create();

        $this->assertCount(2, ClinicalObservation::ofType(ObservationType::Vitals)->get());
        $this->assertCount(1, ClinicalObservation::ofType(ObservationType::Weight)->get());
    }

    public function test_flagged_scope(): void
    {
        ClinicalObservation::factory()->create(['is_flagged' => false]);
        ClinicalObservation::factory()->flagged()->create();

        $this->assertCount(1, ClinicalObservation::flagged()->get());
    }

    public function test_recorded_between_scope(): void
    {
        ClinicalObservation::factory()->create(['recorded_at' => now()->subDays(5)]);
        ClinicalObservation::factory()->create(['recorded_at' => now()->subDay()]);
        ClinicalObservation::factory()->create(['recorded_at' => now()->subDays(10)]);

        $results = ClinicalObservation::recordedBetween(now()->subDays(7), now())->get();
        $this->assertCount(2, $results);
    }

    public function test_all_observation_types_have_factory_states(): void
    {
        ClinicalObservation::factory()->vitals()->create();
        ClinicalObservation::factory()->weight()->create();
        ClinicalObservation::factory()->bowel()->create();
        ClinicalObservation::factory()->sleep()->create();
        ClinicalObservation::factory()->fluidIntake()->create();
        ClinicalObservation::factory()->pain()->create();

        $this->assertCount(6, ClinicalObservation::all());
    }

    public function test_protocol_schedule_relationship(): void
    {
        $schedule = ClinicalProtocolSchedule::factory()->create();
        $obs = ClinicalObservation::factory()->create([
            'protocol_schedule_id' => $schedule->id,
        ]);

        $this->assertEquals($schedule->id, $obs->protocolSchedule->id);
    }
}
