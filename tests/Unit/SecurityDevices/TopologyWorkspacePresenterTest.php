<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\Monitoring\Topology\Models\TopologyChange;
use App\Domain\Monitoring\Topology\Models\TopologyEdge;
use App\Domain\Monitoring\Topology\Models\TopologyNode;
use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use App\Domain\SecurityDevices\Presenters\TopologyWorkspacePresenter;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class TopologyWorkspacePresenterTest extends TestCase
{
    private function devices(): Collection
    {
        return collect([1, 2, 3])->map(fn (int $id): array => [
            'id' => $id, 'name' => ['Gateway', 'Switch', 'Access point'][$id - 1],
            'category' => 'network', 'subcategory' => 'switch', 'health' => 'healthy',
            'site' => ['id' => 7, 'name' => 'Fixture Site'], 'href' => "/security-devices/devices/{$id}",
        ]);
    }

    private function snapshot(int $id, array $edges, string $capturedAt = '2026-10-08 00:00:00'): TopologySnapshot
    {
        $snapshot = new TopologySnapshot;
        $snapshot->setDateFormat('Y-m-d H:i:s');
        $snapshot->setRawAttributes(['id' => $id, 'site_id' => 7, 'source' => 'native:snmp', 'captured_at' => $capturedAt,
            'node_count' => 999, 'edge_count' => 999, 'change_count' => 999]);
        $site = new Site;
        $site->setRawAttributes(['id' => 7, 'name' => 'Fixture Site']);
        $nodes = collect([1, 2, 3, 4])->map(function (int $deviceId): TopologyNode {
            $node = new TopologyNode;
            $node->setRawAttributes(['id' => $deviceId, 'canonical_device_id' => $deviceId]);

            return $node;
        });
        $models = collect($edges)->map(function (array $row, int $index) use ($nodes, $id): TopologyEdge {
            $edge = new TopologyEdge;
            $edge->setDateFormat('Y-m-d H:i:s');
            $edge->setRawAttributes([
                'id' => $id * 10 + $index, 'source' => 'lldp', 'kind' => 'ethernet',
                'confidence' => 0.98, 'evidence' => '{}', 'local_port' => $row[4] ?? '1', 'remote_port' => 'eth0',
                'edge_hash' => $row[2], 'content_hash' => $row[3],
                'first_seen_at' => '2026-10-07 20:00:00', 'last_seen_at' => '2026-10-08 00:00:00',
            ]);
            $edge->setRelation('fromNode', $nodes[$row[0] - 1]);
            $edge->setRelation('toNode', $nodes[$row[1] - 1]);

            return $edge;
        });
        $changes = $models->map(function (TopologyEdge $edge): TopologyChange {
            $change = new TopologyChange;
            $change->setRawAttributes(['change_type' => 'added']);
            $change->setRelation('beforeEdge', null)->setRelation('afterEdge', $edge);

            return $change;
        });

        return $snapshot->setRelation('site', $site)->setRelation('nodes', $nodes)
            ->setRelation('edges', $models)->setRelation('changes', $changes);
    }

    public function test_history_uses_current_names_and_sites_and_recomputes_visible_counts(): void
    {
        $snapshot = $this->snapshot(10, [[1, 2, 'allowed', 'old'], [2, 3, 'moved', 'old'], [1, 4, 'hidden', 'old']]);
        $row = (new TopologyWorkspacePresenter)->project($snapshot, $this->devices(), collect([1 => 7, 2 => 7, 3 => 8]), CarbonImmutable::parse('2026-10-08 00:30:00'), 3600);

        self::assertSame(2, $row['nodeCount']);
        self::assertSame(1, $row['edgeCount']);
        self::assertSame(1, $row['changeCount']);
        self::assertSame('Gateway', $row['edges'][0]['parentName']);
        self::assertSame('current_device_state', $row['nodes'][0]['healthBasis']);
        self::assertSame('currently_visible_canonical_devices', $row['countBasis']);
        self::assertStringNotContainsString('hidden', json_encode($row));
        self::assertStringNotContainsString('999', json_encode($row));
    }

    public function test_comparison_includes_removed_connections_and_changed_ports_without_hidden_edges(): void
    {
        $before = $this->snapshot(10, [[1, 2, 'stable', 'old', '1'], [2, 3, 'removed', 'old'], [1, 4, 'hidden', 'old']]);
        $after = $this->snapshot(11, [[1, 2, 'stable', 'new', '2'], [1, 3, 'added', 'new'], [2, 4, 'hidden-new', 'new']]);
        $presenter = new TopologyWorkspacePresenter;
        $sites = collect([1 => 7, 2 => 7, 3 => 7]);
        $time = CarbonImmutable::parse('2026-10-08 00:30:00');
        $comparison = $presenter->compare($before, $after, $this->devices(), $sites, $time, 3600);

        self::assertSame('compared', $comparison['state']);
        self::assertCount(1, $comparison['addedEdges']);
        self::assertCount(1, $comparison['removedEdges']);
        self::assertCount(1, $comparison['changedEdges']);
        self::assertSame('1 → eth0', $comparison['changedEdges'][0]['before']['port']);
        self::assertSame('2 → eth0', $comparison['changedEdges'][0]['after']['port']);
        self::assertSame(2, $comparison['removedEdges'][0]['parentId']);
        self::assertSame(3, $comparison['removedEdges'][0]['childId']);

        $fixture = getenv('TOPOLOGY_DTO_FIXTURE_PATH');
        if (is_string($fixture) && $fixture !== '') {
            $row = $presenter->project($after, $this->devices(), $sites, $time, 3600);
            $row['comparison'] = $comparison;
            $row['changes'] = ['added' => 1, 'removed' => 1, 'changed' => 1];
            $row['changeCount'] = 3;
            file_put_contents($fixture, json_encode(['limit' => 10, 'truncated' => false, 'entries' => [$row]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    public function test_moved_device_is_excluded_from_both_sides_and_other_sources_cannot_be_compared(): void
    {
        $before = $this->snapshot(10, [[1, 3, 'moved', 'old']]);
        $after = $this->snapshot(11, []);
        $presenter = new TopologyWorkspacePresenter;
        $time = CarbonImmutable::parse('2026-10-08 00:30:00');
        $comparison = $presenter->compare($before, $after, $this->devices(), collect([1 => 7, 2 => 7, 3 => 8]), $time, 3600);
        self::assertSame([], $comparison['removedEdges']);
        $before->source = 'provider:unifi';
        $comparison = $presenter->compare($before, $after, $this->devices(), collect([1 => 7]), $time, 3600);
        self::assertSame('unavailable', $comparison['state']);
        self::assertNull($comparison['previousSnapshotId']);
    }

    public function test_hidden_removed_and_changed_edges_do_not_contribute_to_counts(): void
    {
        $before = $this->snapshot(10, [[1, 2, 'allowed', 'old'], [1, 4, 'hidden', 'old']]);
        $after = $this->snapshot(11, [[1, 2, 'allowed', 'new']]);
        $changes = collect([
            ['removed', $before->edges[0], null],
            ['removed', $before->edges[1], null],
            ['changed', $before->edges[0], $after->edges[0]],
            ['changed', $before->edges[1], $after->edges[0]],
        ])->map(function (array $row): TopologyChange {
            $change = new TopologyChange;
            $change->setRawAttributes(['change_type' => $row[0]]);

            return $change->setRelation('beforeEdge', $row[1])->setRelation('afterEdge', $row[2]);
        });
        $after->setRelation('changes', $changes);
        $row = (new TopologyWorkspacePresenter)->project($after, $this->devices(), collect([1 => 7, 2 => 7, 3 => 7]), CarbonImmutable::parse('2026-10-08 00:30:00'), 3600);
        self::assertSame(['added' => 0, 'removed' => 1, 'changed' => 1], $row['changes']);
        self::assertSame(2, $row['changeCount']);
    }

    public function test_evidence_age_preserves_exact_times_and_does_not_claim_link_health(): void
    {
        $snapshot = $this->snapshot(10, [[1, 2, 'allowed', 'old']]);
        $presenter = new TopologyWorkspacePresenter;
        $sites = collect([1 => 7, 2 => 7, 3 => 7]);
        $row = $presenter->project($snapshot, $this->devices(), $sites, CarbonImmutable::parse('2026-10-08 02:00:00'), 3600);
        self::assertSame('stale', $row['edges'][0]['freshness']['state']);
        self::assertSame('topology_evidence_age', $row['edges'][0]['freshness']['basis']);
        self::assertSame('2026-10-08T00:00:00+00:00', $row['edges'][0]['lastSeenAt']);
        self::assertSame('2026-10-07T20:00:00+00:00', $row['edges'][0]['firstSeenAt']);
        self::assertSame('retained_edge_timestamp', $row['edges'][0]['observationTimeBasis']);
        self::assertSame('inferred', $row['edges'][0]['reviewState']);
        $snapshot->edges[0]->last_seen_at = null;
        $row = $presenter->project($snapshot, $this->devices(), $sites, CarbonImmutable::parse('2026-10-08 02:00:00'), 3600);
        self::assertSame('unknown', $row['edges'][0]['freshness']['state']);
        self::assertNull($row['edges'][0]['lastSeenAt']);
    }
}
