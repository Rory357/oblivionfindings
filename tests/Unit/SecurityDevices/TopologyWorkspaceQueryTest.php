<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Data\NetworkMapFilters;
use App\Domain\SecurityDevices\Presenters\NetworkItWorkspacePresenter;
use App\Domain\SecurityDevices\Presenters\NetworkMapInventoryQuery;
use App\Domain\SecurityDevices\Presenters\TopologyWorkspacePresenter;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/** Minimal in-memory read-model tables; never boots Laravel or the application schema. */
final class TopologyWorkspaceQueryTest extends TestCase
{
    private Manager $database;

    private Container $previousContainer;

    private $previousResolver;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $container = new Container;
        $container->instance('config', new Repository(['monitoring' => ['runtime' => ['site_topology_stale_seconds' => 3600]]]));
        Container::setInstance($container);
        Paginator::currentPathResolver(static fn (): string => '/');
        Paginator::queryStringResolver(static fn (): array => []);
        CursorPaginator::currentCursorResolver(static fn (): ?Cursor => null);
        $this->database = new Manager($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->bootEloquent();
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('sites', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->softDeletes();
        });
        $schema->create('monitoring_topology_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->integer('site_id');
            $table->string('source');
            $table->string('status');
            $table->dateTime('captured_at');
        });
        $schema->create('monitoring_topology_nodes', function (Blueprint $table): void {
            $table->id();
            $table->integer('topology_snapshot_id');
            $table->integer('canonical_device_id')->nullable();
        });
        $schema->create('monitoring_topology_edges', function (Blueprint $table): void {
            $table->id();
            $table->integer('topology_snapshot_id');
            $table->integer('from_node_id');
            $table->integer('to_node_id');
            $table->string('source');
            $table->string('kind');
            $table->string('local_port')->nullable();
            $table->string('remote_port')->nullable();
            $table->decimal('confidence');
            $table->text('evidence');
            $table->string('edge_hash');
            $table->string('content_hash');
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
        });
        $schema->create('monitoring_topology_changes', function (Blueprint $table): void {
            $table->id();
            $table->integer('current_snapshot_id');
            $table->string('change_type');
            $table->integer('before_edge_id')->nullable();
            $table->integer('after_edge_id')->nullable();
        });
        $this->database->getConnection()->table('sites')->insert(['id' => 7, 'name' => 'Fixture Site']);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver !== null) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_exports_inventory_pages_from_actual_query_and_topology_projections(): void
    {
        $connection = $this->database->getConnection();
        $connection->getSchemaBuilder()->create('devices', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('site_id');
        });
        $connection->table('sites')->insert(['id' => 8, 'name' => 'Second Fixture Site']);
        foreach (range(1, 205) as $id) {
            $connection->table('devices')->insert(['id' => $id, 'name' => $id === 201 ? 'Far %_! switch' : "Device {$id}", 'site_id' => $id > 200 ? 8 : 7]);
        }
        foreach (range(1, 24) as $id) {
            $siteId = $id <= 12 ? 7 : 8;
            $this->snapshot($id, siteId: $siteId, withEdge: false);
            $pairs = $siteId === 7 ? [[1, 2], [101, 102]] : [[201, 202]];
            foreach ($pairs as $index => [$parent, $child]) {
                foreach ([$parent, $child] as $deviceId) {
                    $connection->table('monitoring_topology_nodes')->insert(['id' => $id * 1000 + $deviceId, 'topology_snapshot_id' => $id, 'canonical_device_id' => $deviceId]);
                }
                $connection->table('monitoring_topology_edges')->insert([
                    'id' => $id * 10 + $index, 'topology_snapshot_id' => $id, 'from_node_id' => $id * 1000 + $parent, 'to_node_id' => $id * 1000 + $child,
                    'source' => 'lldp', 'kind' => 'ethernet', 'confidence' => 0.95, 'evidence' => '{}', 'edge_hash' => "fixture-pair-{$index}",
                    'content_hash' => 'fixture-content', 'first_seen_at' => '2026-10-08 00:00:00', 'last_seen_at' => '2026-10-08 00:00:00',
                ]);
            }
        }
        $model = new class extends Model
        {
            protected $table = 'devices';

            public $timestamps = false;
        };
        $project = function (NetworkMapFilters $filters) use ($model): array {
            $scope = $model->newQuery();
            if ($filters->siteId !== null) {
                $scope->where('site_id', $filters->siteId);
            }
            [$rows, $inventory, $effective] = (new NetworkMapInventoryQuery)->page($scope, $filters, collect([(object) ['id' => 7, 'name' => 'Fixture Site'], (object) ['id' => 8, 'name' => 'Second Fixture Site']]));
            $devices = $rows->map(fn ($row): array => ['id' => $row->id, 'name' => $row->name, 'category' => 'network', 'subcategory' => null, 'health' => 'healthy', 'site' => ['id' => $row->site_id, 'name' => $row->site_id === 7 ? 'Fixture Site' : 'Second Fixture Site'], 'href' => "/security-devices/devices/{$row->id}"]);
            $result = (new TopologyWorkspacePresenter)->present($devices, $rows->mapWithKeys(fn ($row): array => [$row->id => $row->site_id]), true, null, $effective);
            if ($result === null) {
                $presenter = (new \ReflectionClass(NetworkItWorkspacePresenter::class))->newInstanceWithoutConstructor();
                $result = (new \ReflectionMethod($presenter, 'topology'))->invoke($presenter, $devices, collect(), false, $effective);
            }
            $result['inventory'] = $inventory;
            if ($inventory['isPartial']) {
                $result['state'] = 'partial';
                $result['label'] = 'Showing topology evidence for a limited device view';
            }

            return $result;
        };
        $first = $project(new NetworkMapFilters);
        parse_str(parse_url($first['inventory']['nextHref'], PHP_URL_QUERY), $secondInput);
        $second = $project(NetworkMapFilters::fromInput($secondInput));
        $site = $project(new NetworkMapFilters(8));
        $search = $project(new NetworkMapFilters(null, '%_!'));
        $empty = $project(new NetworkMapFilters(null, 'No matching device'));
        $reset = $project(new NetworkMapFilters(null, '', new Cursor(['id' => 999])));
        self::assertSame(range(101, 200), $second['nodes']->pluck('id')->all());
        self::assertSame(101, $second['edges'][0]['parentId']);
        self::assertStringContainsString('map_device_cursor=', $second['history']['latestHref']);
        self::assertStringContainsString('map_device_cursor=', $second['history']['nextHref']);
        self::assertSame([201], $search['nodes']->pluck('id')->all());
        self::assertSame([8], $site['snapshots']->pluck('site.id')->unique()->all());
        self::assertSame(0, $empty['nodeCount']);
        self::assertTrue($reset['inventory']['positionReset']);
        self::assertSame('/security-devices/network-it?tab=map', $reset['history']['latestHref']);
        $path = getenv('TOPOLOGY_INVENTORY_DTO_FIXTURE_PATH');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode(compact('first', 'second', 'site', 'search', 'empty', 'reset'), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    private function snapshot(int $id, string $source = 'native:snmp', int $siteId = 7, bool $withEdge = true, string $capturedAt = '2026-10-08 00:00:00'): void
    {
        $connection = $this->database->getConnection();
        $connection->table('monitoring_topology_snapshots')->insert([
            'id' => $id, 'site_id' => $siteId, 'source' => $source, 'status' => 'completed', 'captured_at' => $capturedAt,
        ]);
        if (! $withEdge) {
            return;
        }
        foreach ([1, 2] as $deviceId) {
            $connection->table('monitoring_topology_nodes')->insert(['id' => $id * 10 + $deviceId, 'topology_snapshot_id' => $id, 'canonical_device_id' => $deviceId]);
        }
        $connection->table('monitoring_topology_edges')->insert([
            'id' => $id, 'topology_snapshot_id' => $id, 'from_node_id' => $id * 10 + 1, 'to_node_id' => $id * 10 + 2,
            'source' => 'lldp', 'kind' => 'ethernet', 'confidence' => 0.95, 'evidence' => '{}', 'edge_hash' => 'fixture-edge',
            'content_hash' => 'fixture-content', 'first_seen_at' => '2026-10-08 00:00:00', 'last_seen_at' => '2026-10-08 00:00:00',
        ]);
    }

    private function present(bool $history = true, ?Cursor $cursor = null, ?Collection $sites = null): ?array
    {
        $devices = collect([1, 2, 3])->map(fn (int $id): array => [
            'id' => $id, 'name' => "Device {$id}", 'category' => 'network', 'subcategory' => null,
            'health' => 'healthy', 'site' => ['id' => 7, 'name' => 'Fixture Site'], 'href' => "/security-devices/devices/{$id}",
        ]);

        return (new TopologyWorkspacePresenter)->present($devices, ($sites ?? collect([1 => 7, 2 => 7, 3 => 7])), $history, $cursor);
    }

    public function test_latest_source_selection_uses_id_tiebreaker_and_excludes_internal_and_other_sites(): void
    {
        $this->snapshot(1);
        $this->snapshot(2);
        $this->snapshot(3, 'provider:unifi');
        $this->snapshot(4, 'native:snmp:lldp:device:1');
        $this->snapshot(5, 'native:snmp', 8);
        $row = $this->present();
        self::assertSame([3, 2], $row['snapshots']->pluck('id')->all());
        self::assertSame([3, 2, 1], $row['history']['entries']->pluck('id')->all());
        self::assertSame(1, $row['unlinkedCount']);
        self::assertSame(3, $row['visibleDeviceCount']);
        self::assertSame(3, $row['nodeCount']);
        self::assertSame('not_observed', $row['nodes'][2]['topologyEvidence']);
        self::assertSame('partial', $row['state']);
        self::assertSame(1, $row['history']['entries'][1]['comparison']['previousSnapshotId']);
        self::assertFalse($row['history']['truncated']);
        $fixture = getenv('TOPOLOGY_MAP_DTO_FIXTURE_PATH');
        if (is_string($fixture) && $fixture !== '') {
            file_put_contents($fixture, json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    public function test_eager_changes_omit_hidden_removals_and_projection_denies_hidden_changed_sides(): void
    {
        $this->snapshot(1);
        $this->snapshot(2);
        $connection = $this->database->getConnection();
        $connection->table('monitoring_topology_nodes')->insert(['id' => 14, 'topology_snapshot_id' => 1, 'canonical_device_id' => 4]);
        $connection->table('monitoring_topology_edges')->insert([
            'id' => 1000, 'topology_snapshot_id' => 1, 'from_node_id' => 11, 'to_node_id' => 14,
            'source' => 'lldp', 'kind' => 'ethernet', 'confidence' => 0.95, 'evidence' => '{"secret":"Hidden evidence"}',
            'edge_hash' => 'hidden-edge', 'content_hash' => 'hidden-content',
        ]);
        $connection->table('monitoring_topology_changes')->insert([
            ['id' => 1, 'current_snapshot_id' => 2, 'change_type' => 'changed', 'before_edge_id' => 1, 'after_edge_id' => 2],
            ['id' => 2, 'current_snapshot_id' => 2, 'change_type' => 'removed', 'before_edge_id' => 1000, 'after_edge_id' => null],
            ['id' => 3, 'current_snapshot_id' => 2, 'change_type' => 'changed', 'before_edge_id' => 1000, 'after_edge_id' => 2],
        ]);
        $row = $this->present();
        self::assertSame(['added' => 0, 'removed' => 0, 'changed' => 1], $row['changes']);
        self::assertSame(1, $row['snapshots'][0]['changeCount']);
        self::assertStringNotContainsString('Hidden evidence', json_encode($row));
        self::assertStringNotContainsString('hidden-edge', json_encode($row));
    }

    public function test_current_source_limit_is_explicit_in_the_projection(): void
    {
        foreach (range(1, 101) as $id) {
            $this->snapshot($id, source: 'fixture:'.$id, withEdge: false);
        }
        $row = $this->present(false);
        self::assertSame(100, $row['currentSnapshotLimit']);
        self::assertTrue($row['currentSnapshotsTruncated']);
        self::assertCount(100, $row['snapshots']);
        self::assertSame('partial', $row['state']);
    }

    private function cursorFromHref(string $href): Cursor
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        self::assertSame(['tab', 'topology_cursor'], array_keys($query));
        self::assertSame('/security-devices/network-it', parse_url($href, PHP_URL_PATH));
        self::assertNull(parse_url($href, PHP_URL_HOST));
        self::assertSame('map', $query['tab']);

        return TopologyWorkspacePresenter::decodeCursor($query['topology_cursor']);
    }

    public function test_forward_and_back_pages_are_bounded_and_latest_graph_is_independent(): void
    {
        foreach (range(1, 25) as $id) {
            $this->snapshot($id);
        }
        $first = $this->present();
        self::assertSame(range(25, 16), $first['history']['entries']->pluck('id')->all());
        self::assertSame('latest', $first['history']['pageKey']);
        self::assertFalse($first['history']['isOlderPage']);
        self::assertFalse($first['history']['positionReset']);
        self::assertNull($first['history']['previousHref']);
        $older = $this->present(cursor: $this->cursorFromHref($first['history']['nextHref']));
        self::assertSame(range(15, 6), $older['history']['entries']->pluck('id')->all());
        self::assertTrue($older['history']['isOlderPage']);
        self::assertNotSame($first['history']['pageKey'], $older['history']['pageKey']);
        self::assertSame($first['nodes']->all(), $older['nodes']->all());
        self::assertSame($first['edges']->all(), $older['edges']->all());
        $newer = $this->present(cursor: $this->cursorFromHref($older['history']['previousHref']));
        self::assertSame(range(25, 16), $newer['history']['entries']->pluck('id')->all());
        self::assertSame('latest', $newer['history']['pageKey']);
        self::assertFalse($newer['history']['isOlderPage']);
        $oldest = $this->present(cursor: $this->cursorFromHref($older['history']['nextHref']));
        self::assertSame(range(5, 1), $oldest['history']['entries']->pluck('id')->all());
        self::assertNull($oldest['history']['nextHref']);
        self::assertNotNull($oldest['history']['previousHref']);
        self::assertTrue($oldest['history']['truncated']);
        $reset = $this->present(cursor: new Cursor(['captured_at' => '1000-01-01 00:00:00', 'id' => 1]));
        self::assertTrue($reset['history']['positionReset']);
        self::assertSame('latest', $reset['history']['pageKey']);
        self::assertSame(range(25, 16), $reset['history']['entries']->pluck('id')->all());
        $fixture = getenv('TOPOLOGY_PAGINATION_DTO_FIXTURE_PATH');
        if (is_string($fixture) && $fixture !== '') {
            file_put_contents($fixture, json_encode(compact('first', 'older', 'newer', 'oldest', 'reset'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    public function test_seek_survives_insertions_and_deleted_anchors_without_offset_shifts(): void
    {
        foreach (range(1, 25) as $id) {
            $this->snapshot($id);
        }
        $first = $this->present();
        $cursor = $this->cursorFromHref($first['history']['nextHref']);
        $this->snapshot(26);
        $this->database->getConnection()->table('monitoring_topology_snapshots')->where('id', 16)->delete();
        $older = $this->present(cursor: $cursor);
        self::assertSame(range(15, 6), $older['history']['entries']->pluck('id')->all());
        self::assertFalse($older['history']['positionReset']);
        self::assertSame([], $first['history']['entries']->pluck('id')->intersect($older['history']['entries']->pluck('id'))->all());
        $newer = $this->present(cursor: $this->cursorFromHref($older['history']['previousHref']));
        self::assertSame(range(26, 17), $newer['history']['entries']->pluck('id')->all());
    }

    public function test_seek_preserves_fractional_timestamp_precision_and_date_primary_order(): void
    {
        $this->snapshot(1, capturedAt: '2026-10-08 00:00:00.123457');
        foreach (range(2, 12) as $id) {
            $this->snapshot($id, capturedAt: '2026-10-08 00:00:00.123456');
        }
        $first = $this->present();
        self::assertSame([1, 12, 11, 10, 9, 8, 7, 6, 5, 4], $first['history']['entries']->pluck('id')->all());
        $cursor = $this->cursorFromHref($first['history']['nextHref']);
        self::assertSame('2026-10-08 00:00:00.123456', $cursor->parameter('captured_at'));
        $older = $this->present(cursor: $cursor);
        self::assertSame([3, 2], $older['history']['entries']->pluck('id')->all());
        $newer = $this->present(cursor: $this->cursorFromHref($older['history']['previousHref']));
        self::assertSame([1, 12, 11, 10, 9, 8, 7, 6, 5, 4], $newer['history']['entries']->pluck('id')->all());
    }

    public function test_page_rechecks_scope_and_retains_navigation_when_no_historical_nodes_are_visible(): void
    {
        foreach (range(1, 25) as $id) {
            $this->snapshot($id);
        }
        $first = $this->present();
        $cursor = $this->cursorFromHref($first['history']['nextHref']);
        $restricted = $this->present(cursor: $cursor, sites: collect([1 => 8, 2 => 8, 3 => 7]));
        self::assertSame(range(15, 6), $restricted['history']['entries']->pluck('id')->all());
        self::assertSame(0, $restricted['history']['entries']->sum('nodeCount'));
        self::assertSame(0, $restricted['history']['entries']->sum('edgeCount'));
        self::assertSame([], $restricted['edges']->all());
        self::assertNotNull($restricted['history']['previousHref']);
        self::assertNotNull($restricted['history']['nextHref']);
        self::assertNull($this->present(cursor: $cursor, sites: collect([1 => 8, 2 => 8, 3 => 8])));
        $empty = TopologyWorkspacePresenter::emptyHistory(true);
        self::assertTrue($empty['positionReset']);
        self::assertSame('latest', $empty['pageKey']);
        self::assertSame('/security-devices/network-it?tab=map', $empty['latestHref']);
    }

    public function test_bounded_history_retains_predecessor_comparison_beyond_the_display_limit(): void
    {
        foreach (range(1, 12) as $id) {
            $this->snapshot($id, withEdge: $id < 12);
        }
        $row = $this->present();
        self::assertCount(10, $row['history']['entries']);
        self::assertSame(10, $row['history']['limit']);
        self::assertTrue($row['history']['truncated']);
        self::assertSame(11, $row['history']['entries'][0]['comparison']['previousSnapshotId']);
        self::assertCount(1, $row['history']['entries'][0]['comparison']['removedEdges']);
        self::assertSame(2, $row['history']['entries'][9]['comparison']['previousSnapshotId']);
        self::assertSame(0, $row['edgeCount']);
        self::assertSame([], $this->present(false)['history']['entries']);
    }
}
