<?php

use App\Models\ChatCall;
use App\Models\ChatCallParticipant;
use App\Services\Chat\ChatWebhookVerifier;
use App\Services\Chat\SelfHostedCalling;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class SelfHostedCallingTest extends TestCase
{
    private Container $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['chat' => ['calling' => [
            'url' => 'wss://voice.example.test', 'api_url' => 'https://voice.example.test',
            'api_key' => 'test-only', 'api_secret' => str_repeat('test', 12), 'verified' => true, 'authorization_gateway_verified' => true,
        ]]]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    public function test_it_issues_short_lived_microphone_only_room_scoped_credentials(): void
    {
        $service = new SelfHostedCalling;
        $call = new ChatCall(['id' => 'opaque-call-id']);
        $participant = new ChatCallParticipant(['media_identity' => 'opaque-participant-id']);
        $result = $service->connection($call, $participant);
        $claims = JWT::decode($result['token'], new Key(str_repeat('test', 12), 'HS256'));
        $this->assertSame('opaque-participant-id', $claims->sub);
        $this->assertSame('opaque-call-id', $claims->video->room);
        $this->assertSame(['microphone'], $claims->video->canPublishSources);
        $this->assertFalse($claims->video->canPublishData);
        $this->assertFalse($claims->video->canUpdateOwnMetadata);
        $this->assertSame(30, $claims->exp - $claims->iat);
        $this->assertObjectNotHasProperty('roomAdmin', $claims->video);
        $this->assertObjectNotHasProperty('roomRecord', $claims->video);
        $this->assertObjectNotHasProperty('name', $claims);
    }

    public function test_it_refuses_managed_or_insecure_calling_endpoints(): void
    {
        $service = new SelfHostedCalling;
        config(['chat.calling.url' => 'wss://organisation.livekit.cloud']);
        $this->assertFalse($service->ready());
        config(['chat.calling.url' => 'ws://voice.example.test']);
        $this->assertFalse($service->ready());
        config(['chat.calling.url' => 'wss://voice.example.test', 'chat.calling.api_url' => 'http://voice.example.test']);
        $this->assertFalse($service->ready());
    }

    public function test_configuration_alone_does_not_enable_calls(): void
    {
        config(['chat.calling.verified' => false]);
        $service = new SelfHostedCalling;
        $this->assertTrue($service->configured());
        $this->assertFalse($service->ready());
    }

    public function test_signed_media_events_are_bound_to_the_exact_payload(): void
    {
        $body = json_encode(['id' => 'event-1', 'event' => 'participant_joined', 'createdAt' => time(), 'room' => ['name' => 'opaque-call-id']]);
        $token = JWT::encode(['iss' => 'test-only', 'exp' => time() + 60, 'sha256' => base64_encode(hash('sha256', $body, true))], str_repeat('test', 12), 'HS256');
        $this->assertSame('participant_joined', (new ChatWebhookVerifier)->decode($body, $token)['event']);
        $this->expectException(UnexpectedValueException::class);
        (new ChatWebhookVerifier)->decode(str_replace('participant_joined', 'room_finished', $body), $token);
    }

    public function test_an_unverified_authorization_gateway_keeps_calls_disabled(): void
    {
        config(['chat.calling.authorization_gateway_verified' => false]);
        $this->assertFalse((new SelfHostedCalling)->ready());
    }
}
