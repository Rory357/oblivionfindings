<?php

namespace Database\Factories;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiscoveryScope> */
final class DiscoveryScopeFactory extends Factory
{
    protected $model = DiscoveryScope::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'collector_id' => null,
            'name' => fake()->unique()->words(3, true),
            'cidrs' => ['10.44.0.0/16'],
            'seed_hosts' => [],
            'protocols' => ['icmp', 'tcp', 'dns', 'tls'],
            'snmp_credential_reference' => null,
            'exclusions' => [],
            'port_bounds' => ['tcp' => [22, 53, 80, 443]],
            'max_targets_per_run' => 1024,
            'packets_per_second' => 20,
            'schedule_cron' => null,
            'status' => 'active',
        ];
    }
}
