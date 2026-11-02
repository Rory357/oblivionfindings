<?php

namespace Tests\Unit\Chat;

use App\Services\Chat\ChatServiceHealth;
use App\Services\Chat\ChatVoiceInspector;
use App\Services\Chat\SelfHostedCalling;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\TestCase;

class ChatServiceHealthTest extends TestCase
{
    private Container $previous;

    private mixed $previousFacades;

    private Config $config;

    private Cache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $this->previousFacades = Facade::getFacadeApplication();
        $container = new Container;
        $this->config = new Config(['chat' => ['shared_experience' => false]]);
        $container->instance('config', $this->config);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
        $this->cache = new Cache(new ArrayStore);
        DB::swap(Mockery::mock()->shouldReceive('select')->with('select 1')->andReturn([])->getMock());
        Schema::swap(Mockery::mock()->shouldReceive('hasTable')->andReturn(true)->getMock());
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacades);
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    private function service(?MalwareScanner $scanner = null): ChatServiceHealth
    {
        return new ChatServiceHealth($this->config, $this->cache, $scanner ?? new MalwareScanner, new ChatVoiceInspector($this->config), new SelfHostedCalling);
    }

    private function rows(array $snapshot): array
    {
        return array_column($snapshot['services'], null, 'id');
    }

    public function test_reports_all_five_services_without_enabling_or_claiming_unavailable_features(): void
    {
        $snapshot = $this->service()->snapshot();
        $rows = $this->rows($snapshot);
        self::assertCount(5, $rows);
        self::assertFalse($snapshot['rollout_enabled']);
        self::assertSame('passed', $rows['messaging']['state']);
        self::assertSame('disabled', $rows['realtime']['state']);
        self::assertSame('not_available', $rows['push']['state']);
        self::assertSame('not_configured', $rows['uploads']['state']);
        self::assertSame('not_configured', $rows['calling']['state']);
        self::assertNull($rows['calling']['owner']);
        self::assertSame('unknown', $rows['messaging']['trend']);
        Http::assertNothingSent();
    }

    public function test_compares_only_a_previous_recent_observation_and_stores_no_diagnostic_content(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $first = $this->service()->snapshot();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        $this->config->set('chat.realtime.enabled', true);
        $next = $this->rows($this->service()->snapshot());
        self::assertSame('unchanged', $next['messaging']['trend']);
        self::assertSame('changed', $next['realtime']['trend']);
        self::assertSame($first['services'][0]['checked_at'], $next['messaging']['compared_at']);
        $retained = $this->cache->get('chat:service-health:last-check:v1');
        self::assertSame(['state', 'checked_at'], array_keys($retained['services']['messaging']));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(2));
        self::assertSame('unknown', $this->rows($this->service()->snapshot())['messaging']['trend']);
    }

    public function test_isolates_a_failed_probe_and_never_returns_exception_details(): void
    {
        DB::swap(Mockery::mock()->shouldReceive('select')->andThrow(new \RuntimeException('secret path /private/client'))->getMock());
        $snapshot = $this->service()->snapshot();
        self::assertSame('unavailable', $this->rows($snapshot)['messaging']['state']);
        self::assertCount(5, $snapshot['services']);
        self::assertStringNotContainsString('secret path', json_encode($snapshot));
        self::assertStringNotContainsString('/private/client', json_encode($snapshot));
    }

    public function test_missing_chat_schema_does_not_pass_the_storage_check(): void
    {
        Schema::swap(Mockery::mock()->shouldReceive('hasTable')->andReturn(false)->getMock());
        self::assertSame('unavailable', $this->rows($this->service()->snapshot())['messaging']['state']);
    }

    public function test_scans_only_a_harmless_sample_with_a_short_timeout_and_always_removes_it(): void
    {
        $this->config->set('chat.files.scanner.binary', PHP_BINARY);
        $samplePath = null;
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->expects(self::once())->method('scanPath')->willReturnCallback(function ($path, $settings) use (&$samplePath) {
            $samplePath = $path;
            self::assertSame("Oblivion Chat health check. No user content.\n", file_get_contents($path));
            self::assertSame(5, $settings['timeout_seconds']);

            return new MalwareScanResult(MalwareScanDisposition::Clean, 'test');
        });
        $row = $this->rows($this->service($scanner)->snapshot())['uploads'];
        self::assertSame('partial', $row['state']);
        self::assertStringContainsString('Signature freshness', $row['detail']);
        self::assertStringContainsString('checked by Oblivion\'s own voice checker. The optional ffprobe second opinion is not set up.', $row['detail']);
        self::assertFileDoesNotExist($samplePath);
        self::assertStringNotContainsString($samplePath, json_encode($row));
    }

    public function test_scanner_failure_removes_the_sample_and_reports_no_clean_verdict(): void
    {
        $this->config->set('chat.files.scanner.binary', PHP_BINARY);
        $samplePath = null;
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->method('scanPath')->willReturnCallback(function ($path) use (&$samplePath) {
            $samplePath = $path;
            throw new \RuntimeException('private scanner details');
        });
        $snapshot = $this->service($scanner)->snapshot();
        self::assertSame('unavailable', $this->rows($snapshot)['uploads']['state']);
        self::assertFileDoesNotExist($samplePath);
        self::assertStringNotContainsString('private scanner details', json_encode($snapshot));
    }

    public function test_realtime_uses_a_filtered_read_and_does_not_broadcast(): void
    {
        $this->config->set('chat.realtime.enabled', true);
        $this->config->set('broadcasting.connections.chat', ['key' => 'test', 'secret' => 'test', 'app_id' => 'test']);
        $pusher = Mockery::mock()->shouldReceive('get')->once()->with('/channels', ['filter_by_prefix' => 'private-chat.health-check.'])->andReturn((object) ['channels' => []])->getMock();
        $broadcaster = Mockery::mock()->shouldReceive('getPusher')->once()->andReturn($pusher)->getMock();
        Broadcast::swap(Mockery::mock()->shouldReceive('connection')->once()->with('chat')->andReturn($broadcaster)->getMock());
        self::assertSame('passed', $this->rows($this->service()->snapshot())['realtime']['state']);
    }

    public function test_a_reachable_calling_server_does_not_claim_verified_media(): void
    {
        $this->config->set('chat.calling', ['url' => 'wss://voice.example.test', 'api_url' => 'https://voice.example.test', 'api_key' => 'test', 'api_secret' => str_repeat('test', 12), 'verified' => false]);
        Http::fake(['https://voice.example.test/twirp/livekit.RoomService/ListRooms' => Http::response(['rooms' => []])]);
        $row = $this->rows($this->service()->snapshot())['calling'];
        self::assertSame('partial', $row['state']);
        self::assertStringContainsString('real calls are not tested', $row['detail']);
        Http::assertSentCount(1);
    }

    public function test_an_html_success_page_is_not_a_working_calling_api(): void
    {
        $this->config->set('chat.calling', ['url' => 'wss://voice.example.test', 'api_url' => 'https://voice.example.test', 'api_key' => 'test', 'api_secret' => str_repeat('test', 12), 'verified' => true, 'authorization_gateway_verified' => true]);
        Http::fake(['https://voice.example.test/*' => Http::response('<html>Proxy welcome page</html>', 200)]);
        self::assertSame('unavailable', $this->rows($this->service()->snapshot())['calling']['state']);
    }

    public function test_cache_failure_does_not_prevent_the_actual_checks(): void
    {
        $cache = $this->createMock(Cache::class);
        $cache->method('get')->willThrowException(new \RuntimeException('cache down'));
        $cache->method('put')->willThrowException(new \RuntimeException('cache down'));
        $service = new ChatServiceHealth($this->config, $cache, new MalwareScanner, new ChatVoiceInspector($this->config), new SelfHostedCalling);
        $snapshot = $service->snapshot();
        self::assertCount(5, $snapshot['services']);
        self::assertSame('passed', $this->rows($snapshot)['messaging']['state']);
        self::assertSame('unknown', $this->rows($snapshot)['messaging']['trend']);
    }
}
