<?php

namespace Database\Factories;

use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DiscoveryRun> */
final class DiscoveryRunFactory extends Factory
{
    protected $model = DiscoveryRun::class;

    public function definition(): array
    {
        return [
            'discovery_scope_id' => DiscoveryScope::factory(),
            'run_uuid' => (string) Str::orderedUuid(),
            'status' => 'running',
            'trigger' => 'manual:test',
            'scope_snapshot' => [
                'site_id' => 1,
                'cidrs' => ['10.44.0.0/16'],
                'protocols' => ['icmp'],
            ],
            'planned_targets' => 0,
            'started_at' => now(),
        ];
    }
}
