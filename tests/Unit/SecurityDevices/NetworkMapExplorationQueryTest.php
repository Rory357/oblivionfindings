<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Data\NetworkMapExplorationPage;
use App\Domain\SecurityDevices\Data\NetworkMapExplorationUnavailable;
use App\Domain\SecurityDevices\Data\NetworkMapFilters;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\Presenters\NetworkItWorkspacePresenter;
use App\Domain\SecurityDevices\Presenters\NetworkMapExplorationQuery;
use App\Domain\SecurityDevices\Presenters\NetworkMapInventoryQuery;
use App\Domain\SecurityDevices\Presenters\TopologyWorkspacePresenter;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/** Real canonical resolver and read queries on isolated, minimal SQLite tables. */
final class NetworkMapExplorationQueryTest extends TestCase
{
    private Manager $database;

    private Container $previousContainer;

    private $previousResolver;

    private $previousFacadeApplication;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('config', new Repository(['monitoring' => ['runtime' => ['site_topology_stale_seconds' => 3600]]]));
        $container->instance('date', new DateFactory);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('date');
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
            $table->boolean('is_active')->default(true);
            $table->boolean('archived')->default(false);
            $table->softDeletes();
        });
        $schema->create('devices', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('domain')->default('it_infrastructure');
            $table->string('category')->default('network');
            $table->string('subcategory')->nullable();
            $table->boolean('visible')->default(true);
            $table->string('status')->default('active');
            $table->string('health_status')->default('healthy');
            $table->softDeletes();
        });
        $schema->create('device_assignments', function (Blueprint $table): void {
            $table->id();
            $table->integer('device_id');
            $table->string('assignable_type');
            $table->integer('assignable_id');
            $table->integer('custody_site_id');
            $table->dateTime('assigned_at');
            $table->dateTime('released_at')->nullable();
        });
        $schema->create('device_asset_links', function (Blueprint $table): void {
            $table->id();
            $table->integer('device_id');
            $table->integer('asset_id');
            $table->dateTime('unlinked_at')->nullable();
        });
        $schema->create('assets', function (Blueprint $table): void {
            $table->id();
            $table->integer('site_id');
            $table->integer('home_site_id');
            $table->integer('client_id')->nullable();
            $table->string('status');
            $table->softDeletes();
        });
        $schema->create('device_relationships', function (Blueprint $table): void {
            $table->id();
            $table->integer('parent_device_id');
            $table->integer('child_device_id');
            $table->string('relationship_type');
            $table->string('port')->nullable();
            $table->dateTime('unlinked_at')->nullable();
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
        $this->database->getConnection()->table('sites')->insert([['id' => 7, 'name' => 'Fixture Site'], ['id' => 8, 'name' => 'Other Fixture Site']]);
    }

    protected function tearDown(): void
    {
        $this->previousResolver === null ? Model::unsetConnectionResolver() : Model::setConnectionResolver($this->previousResolver);
        Facade::clearResolvedInstance('date');
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function device(int $id, int $siteId = 7, bool $visible = true): void
    {
        $db = $this->database->getConnection();
        $db->table('devices')->insert(['id' => $id, 'name' => $id === 150 ? 'Anchor switch' : "Device {$id}", 'visible' => $visible]);
        $db->table('device_assignments')->insert(['device_id' => $id, 'assignable_type' => 'site', 'assignable_id' => $siteId, 'custody_site_id' => $siteId, 'assigned_at' => '2026-01-01 00:00:00']);
    }

    private function snapshot(int $id, string $source = 'native:snmp', int $siteId = 7): void
    {
        $this->database->getConnection()->table('monitoring_topology_snapshots')->insert(['id' => $id, 'site_id' => $siteId, 'source' => $source, 'status' => 'completed', 'captured_at' => '2026-10-08 00:00:00']);
    }

    private function edge(int $id, int $parent, int $child, int $snapshot = 1): void
    {
        $db = $this->database->getConnection();
        foreach ([$parent, $child] as $deviceId) {
            $db->table('monitoring_topology_nodes')->insertOrIgnore(['id' => $snapshot * 10000 + $deviceId, 'topology_snapshot_id' => $snapshot, 'canonical_device_id' => $deviceId]);
        }
        $db->table('monitoring_topology_edges')->insert(['id' => $id, 'topology_snapshot_id' => $snapshot, 'from_node_id' => $snapshot * 10000 + $parent, 'to_node_id' => $snapshot * 10000 + $child, 'source' => 'lldp', 'kind' => 'ethernet', 'local_port' => "port-{$id}", 'remote_port' => 'eth0', 'confidence' => 0.95, 'evidence' => '{}', 'edge_hash' => "edge-{$id}", 'content_hash' => "content-{$id}", 'first_seen_at' => '2026-10-08 00:00:00', 'last_seen_at' => '2026-10-08 00:00:00']);
    }

    private function page(NetworkMapFilters $filters): NetworkMapExplorationPage
    {
        return (new NetworkMapExplorationQuery(new CanonicalDeviceSiteResolver, new TopologyWorkspacePresenter))->page(Device::query()->where('visible', true)->byDomain('it_infrastructure'), $filters, collect([(object) ['id' => 7, 'name' => 'Fixture Site'], (object) ['id' => 8, 'name' => 'Other Fixture Site']]));
    }

    private function filters(string $href): NetworkMapFilters
    {
        parse_str(parse_url($href, PHP_URL_QUERY), $input);

        return NetworkMapFilters::fromInput($input);
    }

    private function project(NetworkMapFilters $filters): array
    {
        $page = $this->page($filters);
        $rows = Device::query()->whereKey($page->deviceIds)->orderBy('id')->get();
        $mapped = $rows->map(fn ($device): array => ['id' => $device->id, 'name' => $device->name, 'category' => $device->category, 'subcategory' => $device->subcategory, 'health' => 'healthy', 'site' => ['id' => $page->metadata['siteId'], 'name' => $page->metadata['siteId'] === 7 ? 'Fixture Site' : 'Other Fixture Site'], 'href' => "/security-devices/devices/{$device->id}"]);
        $sites = $rows->mapWithKeys(fn ($device): array => [$device->id => $page->metadata['siteId']]);
        $result = (new TopologyWorkspacePresenter)->present($mapped, $sites, false, null, $page->filters, $page);
        if ($result === null) {
            $presenter = (new \ReflectionClass(NetworkItWorkspacePresenter::class))->newInstanceWithoutConstructor();
            $result = (new \ReflectionMethod($presenter, 'topology'))->invoke($presenter, $mapped, DeviceRelationship::query()->active()->whereKey($page->edgeIds)->get(), false, $page->filters);
        }
        $result['nodes'] = $result['nodes']->map(fn (array $node): array => [...$node, 'exploreHref' => NetworkMapFilters::exploreHref($node['id'], $page->metadata['siteId'])]);
        $result['state'] = 'partial';
        $result['label'] = 'Showing immediate connections from retained evidence';
        $result['exploration'] = $page->metadata;

        return $result;
    }

    public function test_resolves_full_bounded_candidates_before_paging_and_exports_actual_exploration_views(): void
    {
        foreach (range(1, 120) as $id) {
            $this->device($id, $id === 119 ? 8 : 7, $id !== 118);
        }
        foreach ([140, 150, 200, 201] as $id) {
            $this->device($id);
        }
        $db = $this->database->getConnection();
        $db->table('assets')->insert(['id' => 1, 'site_id' => 8, 'home_site_id' => 8, 'status' => 'active']);
        $db->table('device_asset_links')->insert(['device_id' => 120, 'asset_id' => 1]);
        $this->snapshot(1);
        foreach (range(1, 120) as $id) {
            $this->edge($id, 150, $id);
        }
        $this->edge(121, 40, 140);
        $db->table('device_relationships')->insert(['parent_device_id' => 200, 'child_device_id' => 201, 'relationship_type' => 'uplinks_to']);
        $first = $this->project(new NetworkMapFilters(anchorId: 150));
        self::assertSame(117, $first['exploration']['neighborsTotal']);
        self::assertSame(40, $first['exploration']['neighborsShown']);
        self::assertSame(40, $first['edgeCount']);
        self::assertNull($first['exploration']['previousHref']);
        $second = $this->project($this->filters($first['exploration']['nextHref']));
        self::assertSame(range(41, 80), array_values(array_diff($second['nodes']->pluck('id')->all(), [150])));
        $third = $this->project($this->filters($second['exploration']['nextHref']));
        self::assertContains(101, $third['nodes']->pluck('id')->all());
        self::assertNull($third['exploration']['nextHref']);
        $back = $this->project($this->filters($second['exploration']['previousHref']));
        self::assertSame($first['nodes']->pluck('id')->all(), $back['nodes']->pluck('id')->all());
        $recentered = $this->project(new NetworkMapFilters(7, anchorId: 40));
        self::assertContains(140, $recentered['nodes']->pluck('id')->all());
        self::assertNotContains(140, $first['nodes']->pluck('id')->all());
        self::assertSame(2, $recentered['edgeCount']);
        $reset = $this->project(new NetworkMapFilters(7, anchorId: 150, neighborCursor: new Cursor(['id' => 999])));
        self::assertTrue($reset['exploration']['positionReset']);
        self::assertSame($first['nodes']->pluck('id')->all(), $reset['nodes']->pluck('id')->all());
        $empty = $this->project(new NetworkMapFilters(7, anchorId: 200));
        self::assertSame('runtime_topology', $empty['source']);
        self::assertSame(0, $empty['edgeCount']);
        self::assertSame(0, $empty['exploration']['neighborsTotal']);
        [$rows, $inventoryMeta] = (new NetworkMapInventoryQuery)->page(Device::query()->where('visible', true)->byDomain('it_infrastructure'), new NetworkMapFilters, collect([(object) ['id' => 7, 'name' => 'Fixture Site'], (object) ['id' => 8, 'name' => 'Other Fixture Site']]));
        $mapped = $rows->map(fn ($device): array => ['id' => $device->id, 'name' => $device->name, 'category' => $device->category, 'subcategory' => $device->subcategory, 'health' => 'healthy', 'site' => ['id' => 7, 'name' => 'Fixture Site'], 'href' => "/security-devices/devices/{$device->id}"]);
        $inventory = (new TopologyWorkspacePresenter)->present($mapped, $rows->mapWithKeys(fn ($row): array => [$row->id => 7]), true);
        $inventory['inventory'] = $inventoryMeta;
        // An independent Site with no runtime snapshot uses existing manual evidence.
        $this->device(300, 8);
        $this->device(301, 8);
        $db->table('device_relationships')->insert(['parent_device_id' => 300, 'child_device_id' => 301, 'relationship_type' => 'uplinks_to']);
        $manual = $this->project(new NetworkMapFilters(8, anchorId: 300));
        self::assertSame('manual_relationships', $manual['source']);
        self::assertSame(1, $manual['edgeCount']);
        $path = getenv('TOPOLOGY_EXPLORATION_DTO_FIXTURE_PATH');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode(compact('inventory', 'first', 'second', 'third', 'recentered', 'reset', 'empty', 'manual'), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    public function test_edge_display_cap_preserves_one_exact_incident_edge_per_neighbor(): void
    {
        $this->device(150);
        foreach (range(1, 40) as $id) {
            $this->device($id);
        } $this->snapshot(1);
        foreach (range(1, 600) as $id) {
            $this->edge($id, 150, 1);
        }
        foreach (range(2, 40) as $id) {
            $this->edge(1000 + $id, $id, 150);
        }
        $view = $this->project(new NetworkMapFilters(anchorId: 150));
        self::assertSame(500, $view['edgeCount']);
        self::assertTrue($view['exploration']['edgesTruncated']);
        self::assertSame(range(1, 40), $view['edges']->flatMap(fn (array $edge): array => [$edge['parentId'], $edge['childId']])->unique()->reject(fn (int $id): bool => $id === 150)->sort()->values()->all());
        self::assertSame(0, $view['unlinkedCount']);
    }

    public function test_candidate_budget_rejects_before_canonical_counts_but_after_anchor_validation(): void
    {
        $this->device(150);
        $this->snapshot(1);
        foreach (range(1000, 2000) as $id) {
            $this->device($id);
            $this->edge($id, 150, $id);
        }
        try {
            $this->page(new NetworkMapFilters(anchorId: 150));
            self::fail('Candidate budget accepted');
        } catch (NetworkMapExplorationUnavailable $exception) {
            self::assertSame(7, $exception->siteId);
            self::assertSame('Current connections are unavailable for this view.', $exception->getMessage());
        }
        $this->expectException(ModelNotFoundException::class);
        $this->page(new NetworkMapFilters(8, anchorId: 150));
    }

    public function test_newer_empty_same_source_snapshot_replaces_incident_evidence_without_manual_stitching(): void
    {
        $this->device(150);
        $this->device(1);
        $this->snapshot(1);
        $this->edge(1, 150, 1);
        $this->database->getConnection()->table('device_relationships')->insert(['parent_device_id' => 150, 'child_device_id' => 1, 'relationship_type' => 'uplinks_to']);
        $this->snapshot(2);
        $page = $this->page(new NetworkMapFilters(anchorId: 150));
        self::assertSame([2], $page->snapshots->pluck('id')->all());
        self::assertTrue($page->runtime);
        self::assertSame(0, $page->metadata['neighborsTotal']);
        $view = $this->project(new NetworkMapFilters(anchorId: 150));
        self::assertSame(0, $view['edgeCount']);
        self::assertSame('runtime_topology', $view['source']);
    }

    public function test_deleted_seek_neighbor_and_underfilled_backward_boundary_preserve_existing_neighbors(): void
    {
        $this->device(150);
        $this->snapshot(1);
        foreach (range(1, 45) as $id) {
            $this->device($id);
            $this->edge($id, 150, $id);
        }
        $this->database->getConnection()->table('devices')->where('id', 40)->update(['deleted_at' => '2026-10-08 00:00:00']);
        $page = $this->page(new NetworkMapFilters(anchorId: 150, neighborCursor: new Cursor(['id' => 40])));
        self::assertSame([150, 41, 42, 43, 44, 45], $page->deviceIds->all());
        self::assertSame(44, $page->metadata['neighborsTotal']);
        $back = $this->page($this->filters($page->metadata['previousHref']));
        self::assertSame([150, ...range(1, 39)], $back->deviceIds->all());
        self::assertNotNull($back->filters->neighborCursor);
        self::assertStringContainsString('map_neighbor_cursor=', $back->filters->href());
        self::assertSame([150, 41, 42, 43, 44, 45], $this->page($this->filters($back->metadata['nextHref']))->deviceIds->all());
    }

    public function test_latest_runtime_selection_excludes_internal_sources_and_bounds_sources_independently(): void
    {
        $this->device(150);
        $this->device(1);
        $this->snapshot(1, 'native:snmp:lldp:device:150');
        $this->edge(1, 150, 1);
        $page = $this->page(new NetworkMapFilters(anchorId: 150));
        self::assertFalse($page->runtime);
        foreach (range(2, 102) as $id) {
            $this->snapshot($id, "provider:{$id}");
            $this->edge($id, 150, 1, $id);
        }
        $page = $this->page(new NetworkMapFilters(anchorId: 150));
        self::assertTrue($page->metadata['sourcesTruncated']);
        self::assertCount(100, $page->snapshots);
        self::assertSame(1, $page->metadata['neighborsTotal']);
    }
}
