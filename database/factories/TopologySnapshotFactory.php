<?php

namespace Database\Factories;

use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TopologySnapshot> */
final class TopologySnapshotFactory extends Factory
{
    protected $model = TopologySnapshot::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'snapshot_uuid' => (string) Str::orderedUuid(),
            'source' => 'native:test',
            'source_checkpoint_hash' => hash('sha256', (string) Str::orderedUuid()),
            'source_envelope_id' => null,
            'captured_at' => now(),
            'completed_at' => now(),
            'status' => 'completed',
            'node_count' => 0,
            'edge_count' => 0,
            'change_count' => 0,
            'summary' => [
                'sources' => [],
                'unresolved_nodes' => 0,
                'conflicts' => 0,
                'changes' => ['added' => 0, 'removed' => 0, 'changed' => 0],
            ],
        ];
    }

    public function building(): static
    {
        return $this->state(fn (): array => [
            'completed_at' => null,
            'status' => 'building',
        ]);
    }
}
