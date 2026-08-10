<?php

namespace Database\Factories;

use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DiscoveryCandidate> */
final class DiscoveryCandidateFactory extends Factory
{
    protected $model = DiscoveryCandidate::class;

    public function definition(): array
    {
        $snapshot = [
            'addresses' => ['10.44.0.10'],
            'hostname' => fake()->domainWord(),
            'mac_addresses' => [strtolower(str_replace(':', '', fake()->macAddress()))],
            'provider' => 'snmp',
            'provider_id' => null,
            'serial_number' => null,
        ];

        return [
            'discovery_run_id' => DiscoveryRun::factory(),
            'candidate_uuid' => (string) Str::orderedUuid(),
            'canonical_device_id' => null,
            'decision' => 'proposed',
            'confidence' => 0,
            'reasons' => ['no_existing_identity_match'],
            'evidence_snapshot' => $snapshot,
            'evidence_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
        ];
    }
}
