<?php

namespace Tests\Unit\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Enums\ProtocolFrequency;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicalProtocolModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_protocol_can_be_created_with_factory(): void
    {
        $protocol = ClinicalProtocol::factory()->create();

        $this->assertDatabaseHas('clinical_protocols', ['id' => $protocol->id]);
    }

    public function test_observation_type_is_cast_to_enum(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create();

        $this->assertInstanceOf(ObservationType::class, $protocol->observation_type);
        $this->assertEquals(ObservationType::Weight, $protocol->observation_type);
    }

    public function test_frequency_is_cast_to_enum(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create();

        $this->assertInstanceOf(ProtocolFrequency::class, $protocol->frequency);
        $this->assertEquals(ProtocolFrequency::Daily, $protocol->frequency);
    }

    public function test_threshold_rules_cast_to_array(): void
    {
        $protocol = ClinicalProtocol::factory()->create([
            'threshold_rules' => ['weight_loss_kg_7d' => 2, 'systolic_above' => 160],
        ]);
        $protocol->refresh();

        $this->assertIsArray($protocol->threshold_rules);
        $this->assertEquals(2, $protocol->threshold_rules['weight_loss_kg_7d']);
    }

    public function test_client_relationship(): void
    {
        $client = Client::factory()->create();
        $protocol = ClinicalProtocol::factory()->create(['client_id' => $client->id]);

        $this->assertEquals($client->id, $protocol->client->id);
    }

    public function test_creator_relationship(): void
    {
        $user = User::factory()->create();
        $protocol = ClinicalProtocol::factory()->create(['created_by' => $user->id]);

        $this->assertEquals($user->id, $protocol->creator->id);
    }

    public function test_schedules_relationship(): void
    {
        $protocol = ClinicalProtocol::factory()->create();
        ClinicalProtocolSchedule::factory()->count(3)->create([
            'clinical_protocol_id' => $protocol->id,
        ]);

        $this->assertCount(3, $protocol->schedules);
    }

    public function test_active_scope(): void
    {
        ClinicalProtocol::factory()->create(['is_active' => true]);
        ClinicalProtocol::factory()->inactive()->create();

        $this->assertCount(1, ClinicalProtocol::active()->get());
    }

    public function test_for_client_scope(): void
    {
        $client = Client::factory()->create();
        ClinicalProtocol::factory()->create(['client_id' => $client->id]);
        ClinicalProtocol::factory()->create(); // different client

        $this->assertCount(1, ClinicalProtocol::forClient($client->id)->get());
    }

    public function test_effective_interval_hours_for_standard_frequencies(): void
    {
        $daily = ClinicalProtocol::factory()->create([
            'frequency' => ProtocolFrequency::Daily,
        ]);
        $this->assertEquals(24, $daily->effectiveIntervalHours());

        $weekly = ClinicalProtocol::factory()->create([
            'frequency' => ProtocolFrequency::Weekly,
        ]);
        $this->assertEquals(168, $weekly->effectiveIntervalHours());

        $everyShift = ClinicalProtocol::factory()->create([
            'frequency' => ProtocolFrequency::EveryShift,
        ]);
        $this->assertNull($everyShift->effectiveIntervalHours());
    }

    public function test_effective_interval_hours_for_custom_frequency(): void
    {
        $custom = ClinicalProtocol::factory()->create([
            'frequency' => ProtocolFrequency::Custom,
            'custom_frequency_hours' => 6,
        ]);

        $this->assertEquals(6, $custom->effectiveIntervalHours());
    }

    public function test_is_currently_applicable_when_active_and_no_dates(): void
    {
        $protocol = ClinicalProtocol::factory()->create([
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
        ]);

        $this->assertTrue($protocol->isCurrentlyApplicable());
    }

    public function test_is_currently_applicable_when_inactive(): void
    {
        $protocol = ClinicalProtocol::factory()->inactive()->create();

        $this->assertFalse($protocol->isCurrentlyApplicable());
    }

    public function test_is_currently_applicable_when_within_date_range(): void
    {
        $protocol = ClinicalProtocol::factory()->withDateRange()->create();

        $this->assertTrue($protocol->isCurrentlyApplicable());
    }

    public function test_is_currently_applicable_when_expired(): void
    {
        $protocol = ClinicalProtocol::factory()->expired()->create();

        $this->assertFalse($protocol->isCurrentlyApplicable());
    }

    public function test_is_currently_applicable_when_not_started(): void
    {
        $protocol = ClinicalProtocol::factory()->create([
            'is_active' => true,
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonths(3),
        ]);

        $this->assertFalse($protocol->isCurrentlyApplicable());
    }
}
