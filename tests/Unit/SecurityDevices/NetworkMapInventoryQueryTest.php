<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Data\NetworkMapFilters;
use App\Domain\SecurityDevices\Presenters\NetworkMapInventoryQuery;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\Cursor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NetworkMapInventoryQueryTest extends TestCase
{
    private Manager $database;

    private Container $previousContainer;

    private $previousResolver;

    private Model $model;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $container = new Container;
        Container::setInstance($container);
        $this->database = new Manager($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->bootEloquent();
        $this->database->getConnection()->getSchemaBuilder()->create('devices', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('site_id');
            $table->boolean('visible');
            $table->string('domain');
        });
        $this->model = new class extends Model
        {
            protected $table = 'devices';

            public $timestamps = false;
        };
        foreach (range(1, 205) as $id) {
            $this->database->getConnection()->table('devices')->insert(['id' => $id, 'name' => $id === 201 ? 'Far %_! switch' : "Device {$id}", 'site_id' => $id > 200 ? 8 : 7, 'visible' => true, 'domain' => 'it_infrastructure']);
        }
        $this->database->getConnection()->table('devices')->insert([
            ['id' => 206, 'name' => 'Hidden %_! switch', 'site_id' => 7, 'visible' => false, 'domain' => 'it_infrastructure'],
            ['id' => 207, 'name' => 'Healthcare %_! switch', 'site_id' => 7, 'visible' => true, 'domain' => 'healthcare'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->previousResolver === null ? Model::unsetConnectionResolver() : Model::setConnectionResolver($this->previousResolver);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function page(NetworkMapFilters $filters = new NetworkMapFilters): array
    {
        $scope = $this->model->newQuery()->where('visible', true)->where('domain', 'it_infrastructure');
        if ($filters->siteId !== null) {
            $scope->where('site_id', $filters->siteId);
        }

        return (new NetworkMapInventoryQuery)->page($scope, $filters, collect([(object) ['id' => 7, 'name' => 'Approved Site'], (object) ['id' => 8, 'name' => 'Other Site']]));
    }

    private function filters(string $href): NetworkMapFilters
    {
        parse_str(parse_url($href, PHP_URL_QUERY), $input);

        return NetworkMapFilters::fromInput($input);
    }

    public function test_pages_full_authorized_scope_and_roundtrips_without_offsets(): void
    {
        [$first, $meta] = $this->page();
        self::assertSame(range(1, 100), $first->pluck('id')->all());
        self::assertSame(205, $meta['total']);
        self::assertSame(100, $meta['shown']);
        self::assertTrue($meta['isPartial']);
        self::assertNull($meta['previousHref']);
        [$second, $secondMeta] = $this->page($this->filters($meta['nextHref']));
        self::assertSame(range(101, 200), $second->pluck('id')->all());
        self::assertSame(205, $secondMeta['total']);
        [$back, $backMeta, $effective] = $this->page($this->filters($secondMeta['previousHref']));
        self::assertSame(range(1, 100), $back->pluck('id')->all());
        self::assertSame('latest', $backMeta['pageKey']);
        self::assertNull($effective->deviceCursor);
        [$last, $lastMeta] = $this->page($this->filters($secondMeta['nextHref']));
        self::assertSame(range(201, 205), $last->pluck('id')->all());
        self::assertNull($lastMeta['nextHref']);
    }

    public function test_literal_search_and_site_narrow_full_scope_before_bounding_and_counting(): void
    {
        [$rows, $meta] = $this->page(NetworkMapFilters::fromInput(['map_search' => '  %_!  ']));
        self::assertSame([201], $rows->pluck('id')->all());
        self::assertSame(1, $meta['total']);
        self::assertSame('%_!', $meta['search']);
        self::assertTrue($meta['isPartial']);
        [$rows, $meta] = $this->page(new NetworkMapFilters(8));
        self::assertSame(range(201, 205), $rows->pluck('id')->all());
        self::assertSame(5, $meta['total']);
        self::assertFalse($meta['isPartial']);
        [$rows, $meta] = $this->page(new NetworkMapFilters(7, '%_!'));
        self::assertCount(0, $rows);
        self::assertSame(0, $meta['total']);
    }

    public function test_underfilled_backward_page_preserves_exact_history_scope_and_deleted_anchor(): void
    {
        $filters = new NetworkMapFilters(null, '', new Cursor(['id' => 20], false));
        [$rows, $meta, $effective] = $this->page($filters);
        self::assertSame(range(1, 19), $rows->pluck('id')->all());
        self::assertNotSame('latest', $meta['pageKey']);
        self::assertSame($filters->deviceCursor->encode(), $effective->deviceCursor->encode());
        self::assertSame(range(1, 19), $this->page($this->filters($effective->href(new Cursor(['captured_at' => '2026-10-08 00:00:00', 'id' => 1]))))[0]->pluck('id')->all());
        $this->database->getConnection()->table('devices')->where('id', 100)->delete();
        self::assertSame(range(101, 200), $this->page(new NetworkMapFilters(null, '', new Cursor(['id' => 100])))[0]->pluck('id')->all());
        $this->database->getConnection()->table('devices')->insert(['id' => 208, 'name' => 'Inserted', 'site_id' => 7, 'visible' => true, 'domain' => 'it_infrastructure']);
        self::assertSame(range(101, 200), $this->page(new NetworkMapFilters(null, '', new Cursor(['id' => 100])))[0]->pluck('id')->all());
    }

    public function test_exhausted_position_resets_and_links_keep_only_effective_filters(): void
    {
        [$rows, $meta, $effective] = $this->page(new NetworkMapFilters(8, 'Device', new Cursor(['id' => 999])));
        self::assertSame(range(202, 205), $rows->pluck('id')->all());
        self::assertTrue($meta['positionReset']);
        self::assertNull($effective->deviceCursor);
        self::assertSame('/security-devices/network-it?tab=map&map_site_id=8&map_search=Device', $effective->href());
        self::assertSame('/security-devices/network-it?tab=map', $meta['resetHref']);
    }

    public function test_filters_strictly_reject_invalid_input_without_echo(): void
    {
        $bad = [['map_site_id' => '01'], ['map_site_id' => '0'], ['map_site_id' => '-1'], ['map_site_id' => []], ['map_site_id' => null], ['map_site_id' => true], ['map_site_id' => '999999999999999999999'], ['map_search' => []], ['map_search' => true], ['map_search' => "a\nb"], ['map_search' => "\xFF"], ['map_search' => str_repeat('ā', 201)], ['map_device_cursor' => ''], ['map_device_cursor' => null], ['map_device_cursor' => []], ['map_device_cursor' => str_repeat('a', 129)], ['map_anchor_id' => '01'], ['map_anchor_id' => []], ['map_neighbor_cursor' => 'bad'], ['map_anchor_id' => '1', 'map_search' => 'name'], ['map_anchor_id' => '1', 'map_device_cursor' => null], ['map_anchor_id' => '1', 'topology_cursor' => null], ['map_anchor_id' => '1', 'map_neighbor_cursor' => null]];
        foreach ([['id' => '1', '_pointsToNextItems' => true], ['id' => 0, '_pointsToNextItems' => true], ['id' => 1, '_pointsToNextItems' => 1], ['_pointsToNextItems' => true, 'id' => 1], ['id' => 1, '_pointsToNextItems' => true, 'extra' => 1]] as $payload) {
            $bad[] = ['map_device_cursor' => rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=')];
        }
        foreach ($bad as $input) {
            try {
                NetworkMapFilters::fromInput($input);
                self::fail('Invalid input accepted');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Network map filters are invalid.', $exception->getMessage());
            }
        }
        foreach ([true, false] as $direction) {
            $cursor = new Cursor(['id' => 12], $direction);
            self::assertSame($cursor->encode(), NetworkMapFilters::fromInput(['map_device_cursor' => $cursor->encode()])->deviceCursor->encode());
        }
        self::assertSame('ā device', NetworkMapFilters::fromInput(['map_search' => '  ā device  '])->search);
    }
}
