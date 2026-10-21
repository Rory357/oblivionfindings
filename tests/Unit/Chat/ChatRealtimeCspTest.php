<?php

use App\Http\Middleware\AddContentSecurityPolicy;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ChatRealtimeCspTest extends TestCase
{
    public function test_live_updates_allow_only_the_current_websocket_origin(): void
    {
        config(['app.csp_enabled' => true, 'app.csp_enforce' => true, 'chat.shared_experience' => true, 'chat.realtime.enabled' => true]);

        foreach (['https://care.example:8443/chat' => 'wss://care.example:8443', 'http://127.0.0.1:8881/chat' => 'ws://127.0.0.1:8881'] as $url => $origin) {
            $response = (new AddContentSecurityPolicy)->handle(Request::create($url), fn () => new Response('', 200, ['Content-Type' => 'text/html']));
            $policy = $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString(' '.$origin.';', $policy);
            $this->assertStringNotContainsString('pusher.com', $policy);
            $this->assertStringNotContainsString('wss://*', $policy);
        }
    }

    public function test_disabled_rollout_or_transport_adds_no_socket_exception(): void
    {
        config(['app.csp_enabled' => true, 'app.csp_enforce' => true]);

        foreach ([[false, true], [true, false]] as [$shared, $realtime]) {
            config(['chat.shared_experience' => $shared, 'chat.realtime.enabled' => $realtime]);
            $response = (new AddContentSecurityPolicy)->handle(Request::create('https://care.example/chat'), fn () => new Response('', 200, ['Content-Type' => 'text/html']));
            $this->assertStringNotContainsString('wss://', $response->headers->get('Content-Security-Policy'));
        }
    }
}
