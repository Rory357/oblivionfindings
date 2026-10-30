<?php

namespace Tests\Unit\Chat;

use App\Models\UserPushSubscription;
use App\Services\Chat\ChatBrowserPushTransport;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ChatBrowserPushTransportTest extends TestCase
{
    private Repository $config;

    private array $history = [];

    protected function setUp(): void
    {
        $keys = VAPID::createVapidKeys();
        $this->config = new Repository(['chat' => ['notifications' => ['push_hosts' => ['push.example.test']]], 'services' => ['webpush' => [
            'subject' => 'mailto:test@example.test', 'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey'],
        ]]]);
    }

    private function subscription(): UserPushSubscription
    {
        $keys = VAPID::createVapidKeys();

        return new UserPushSubscription(['provider' => 'webpush', 'enabled' => true, 'token' => 'https://push.example.test/subscription/secret',
            'keys' => ['p256dh' => $keys['publicKey'], 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')]]);
    }

    private function transport(mixed $response): ChatBrowserPushTransport
    {
        $handler = HandlerStack::create(new MockHandler([$response]));
        $handler->push(Middleware::history($this->history));
        $client = new WebPush(['VAPID' => [
            'subject' => $this->config->get('services.webpush.subject'), 'publicKey' => $this->config->get('services.webpush.public_key'),
            'privateKey' => $this->config->get('services.webpush.private_key'),
        ]], [], 10, ['handler' => $handler, 'allow_redirects' => false], new NullLogger);

        return new class($this->config, $client) extends ChatBrowserPushTransport
        {
            public function __construct(Repository $config, private WebPush $client)
            {
                parent::__construct($config);
            }

            protected function createClient(): WebPush
            {
                return $this->client;
            }
        };
    }

    public function test_encrypts_with_the_installed_library_and_keeps_the_provider_queue_and_topic_bounded(): void
    {
        $payload = ['title' => 'New Chat message', 'body' => 'Open Chat to view the message.', 'data' => ['notification_id' => 'private-stable-id']];
        self::assertSame('accepted', $this->transport(new Response(201))->send($this->subscription(), $payload));
        self::assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        self::assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        self::assertSame('60', $request->getHeaderLine('TTL'));
        self::assertSame(substr(hash('sha256', 'private-stable-id'), 0, 32), $request->getHeaderLine('Topic'));
        self::assertStringNotContainsString('Open Chat', (string) $request->getBody());
        self::assertFalse($this->history[0]['options']['allow_redirects']);
    }

    public static function outcomes(): array
    {
        return [[201, 'accepted'], [404, 'expired'], [410, 'expired'], [408, 'retry'], [429, 'retry'], [503, 'retry'],
            [401, 'unavailable'], [403, 'unavailable'], [400, 'rejected'], [302, 'rejected']];
    }

    #[DataProvider('outcomes')]
    public function test_classifies_provider_results_without_exposing_the_response(int $status, string $expected): void
    {
        self::assertSame($expected, $this->transport(new Response($status, ['Location' => 'https://private.example.test'], 'Private provider detail'))
            ->send($this->subscription(), ['data' => ['notification_id' => 'one']]));
        self::assertCount(1, $this->history);
    }

    public function test_handles_an_uncertain_network_result_as_retry_without_retaining_its_reason(): void
    {
        $transport = $this->transport(new ConnectException('Private endpoint and keys', new Request('POST', 'https://push.example.test/secret')));
        self::assertSame('retry', $transport->send($this->subscription(), []));
    }

    public static function rejectedEndpoints(): array
    {
        return array_map(fn ($url) => [$url], ['http://push.example.test/x', 'https://127.0.0.1/x', 'https://[::1]/x',
            'https://push.example.test.attacker.test/x', 'https://attacker.test/push.example.test',
            'https://user@push.example.test/x', 'https://push.example.test:8443/x', 'https://push.example.test/x#fragment',
            'https://push.example.test./x', "https://push.example.test/\nx", 'https://push.example.test\\@attacker.test/x']);
    }

    #[DataProvider('rejectedEndpoints')]
    public function test_denies_untrusted_or_ambiguous_endpoints_without_network_io(string $url): void
    {
        $subscription = $this->subscription();
        $subscription->token = $url;
        self::assertSame('rejected', $this->transport(new Response(201))->send($subscription, []));
        self::assertCount(0, $this->history);
    }

    public function test_missing_configuration_invalid_keys_and_native_tokens_never_send(): void
    {
        $transport = $this->transport(new Response(201));
        $subscription = $this->subscription();
        $subscription->keys = ['p256dh' => 'invalid', 'auth' => 'invalid'];
        self::assertSame('rejected', $transport->send($subscription, []));
        $subscription = $this->subscription();
        $subscription->provider = 'expo';
        self::assertSame('rejected', $transport->send($subscription, []));
        $this->config->set('services.webpush.private_key', '');
        self::assertSame('unavailable', $transport->send($this->subscription(), []));
        self::assertCount(0, $this->history);
    }
}
