<?php

use App\Http\Middleware\EnforceSessionTimeout;
use App\Support\SecurityPolicy;
use Illuminate\Auth\GenericUser;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ChatSessionActivityTest extends TestCase
{
    private mixed $previousApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Facade::getFacadeApplication();
        Facade::setFacadeApplication(new Container);
        Cache::swap(Mockery::mock());
        Auth::swap(Mockery::mock());
        Cache::shouldReceive('forget')->with(SecurityPolicy::CACHE_KEY)->andReturn(true);
        Cache::shouldReceive('rememberForever')->with(SecurityPolicy::CACHE_KEY, Mockery::type('Closure'))
            ->andReturn(['session_timeout_minutes' => 30]);
        SecurityPolicy::flushCache();
        Carbon::setTestNow(Carbon::parse('2026-10-18 14:00:00', date_default_timezone_get()));
    }

    protected function tearDown(): void
    {
        SecurityPolicy::flushCache();
        Carbon::setTestNow();
        Facade::clearResolvedInstance('cache');
        Facade::clearResolvedInstance('auth');
        Facade::setFacadeApplication($this->previousApp);
        Mockery::close();
        parent::tearDown();
    }

    private function request(string $method, string $path, ?int $activity): Request
    {
        $request = Request::create($path, $method);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => new GenericUser(['id' => 22]));
        $session = new Store('chat-session-test', new ArraySessionHandler(120));
        $session->start();
        if ($activity !== null) {
            $session->put('last_activity_at', $activity);
        }
        $request->setLaravelSession($session);

        return $request;
    }

    public static function backgroundRequests(): array
    {
        return [
            ['GET', '/chat/api/calls?device_id=example'],
            ['HEAD', '/chat/api/conversations'],
            ['GET', '/chat/api/family-inbox'],
            ['GET', '/chat/api/conversations/12/messages'],
            ['GET', '/chat/notifications/example/display'],
            ['PATCH', '/chat/api/availability'],
            ['POST', '/chat/api/realtime/auth'],
            ['POST', '/chat/api/live/ticket'],
            ['POST', '/chat/api/conversations/12/changes'],
            ['POST', '/chat/api/conversations/12/read'],
            ['POST', '/chat/api/calls/example/connected'],
        ];
    }

    #[DataProvider('backgroundRequests')]
    public function test_background_work_cannot_extend_an_idle_session(string $method, string $path): void
    {
        $activity = now()->subMinutes(20)->timestamp;
        $request = $this->request($method, $path, $activity);
        $response = new Response('', 204);
        $this->assertSame($response, (new EnforceSessionTimeout)->handle($request, fn () => $response));
        $this->assertSame($activity, $request->session()->get('last_activity_at'));
    }

    public static function humanRequests(): array
    {
        return [
            ['GET', '/chat'],
            ['GET', '/chat/notifications/example'],
            ['GET', '/my-day'],
            ['POST', '/chat/api/conversations/12/messages'],
            // Typing is the person at the keyboard.
            ['POST', '/chat/api/conversations/12/typing'],
            ['POST', '/chat/api/conversations/12/calls'],
            ['POST', '/chat/api/calls/example/join'],
            ['POST', '/chat/api/calls/example/leave'],
            ['PUT', '/settings/chat'],
        ];
    }

    #[DataProvider('humanRequests')]
    public function test_explicit_actions_keep_the_existing_activity_contract(string $method, string $path): void
    {
        $request = $this->request($method, $path, now()->subMinutes(20)->timestamp);
        (new EnforceSessionTimeout)->handle($request, fn () => new Response('', 204));
        $this->assertSame(now()->timestamp, $request->session()->get('last_activity_at'));
    }

    public static function expiredSettingsRequests(): array
    {
        return [['GET', '/settings/chat/configuration'], ['PUT', '/settings/chat'],
            ['POST', '/settings/chat/service-health'], ['POST', '/settings/chat/calling-health']];
    }

    #[DataProvider('expiredSettingsRequests')]
    public function test_expired_settings_requests_return_private_json_before_any_controller(string $method, string $path): void
    {
        $request = $this->request($method, $path, now()->subMinutes(31)->timestamp);
        $request->session()->put('private_marker', 'discard me');
        $guard = Mockery::mock();
        $guard->shouldReceive('logout')->once();
        Auth::shouldReceive('guard')->once()->andReturn($guard);
        $reached = false;
        $response = (new EnforceSessionTimeout)->handle($request, function () use (&$reached) {
            $reached = true;

            return new Response;
        });
        $this->assertFalse($reached);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('chat_session_expired', json_decode($response->getContent(), true)['code']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNull($request->session()->get('private_marker'));
        $this->assertFalse($response->headers->has('Location'));
    }

    public function test_missing_activity_gets_one_baseline_and_cannot_stay_alive_by_polling(): void
    {
        $request = $this->request('GET', '/chat/api/calls', null);
        $middleware = new EnforceSessionTimeout;
        $middleware->handle($request, fn () => new Response('', 204));
        $baseline = now()->timestamp;
        $this->assertSame($baseline, $request->session()->get('last_activity_at'));
        Carbon::setTestNow(now()->addMinutes(20));
        $middleware->handle($request, fn () => new Response('', 204));
        $this->assertSame($baseline, $request->session()->get('last_activity_at'));
        Carbon::setTestNow(now()->addMinutes(11));
        $guard = Mockery::mock();
        $guard->shouldReceive('logout')->once();
        Auth::shouldReceive('guard')->once()->andReturn($guard);
        $oldId = $request->session()->getId();
        $request->session()->put('private_marker', 'discard me');
        $reached = false;
        $response = $middleware->handle($request, function () use (&$reached) {
            $reached = true;

            return new Response;
        });
        $this->assertFalse($reached);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('chat_session_expired', json_decode($response->getContent(), true)['code']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNull($request->session()->get('private_marker'));
        $this->assertNotSame($oldId, $request->session()->getId());
        $this->assertNotEmpty($request->session()->token());
    }

    public function test_a_message_cannot_revive_an_already_expired_session(): void
    {
        $request = $this->request('POST', '/chat/api/conversations/12/messages', now()->subMinutes(31)->timestamp);
        $guard = Mockery::mock();
        $guard->shouldReceive('logout')->once();
        Auth::shouldReceive('guard')->once()->andReturn($guard);
        $response = (new EnforceSessionTimeout)->handle($request, function () {
            $this->fail('An expired session must not reach message delivery.');
        });
        $this->assertSame(401, $response->getStatusCode());
    }
}
