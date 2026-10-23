<?php

namespace Tests\Unit\Chat;

use App\Models\UserPushSubscription;
use App\Services\Chat\ChatBrowserPushTransport;
use App\Services\Push\VapidKeys;
use App\Services\Push\WebPushSender;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** On Windows run with OPENSSL_CONF set to Herd's php84/extras/ssl/openssl.cnf. */
class ChatBrowserPushTransportTest extends TestCase
{
    private Repository $config;

    /** @var list<array{request: Request, options: array<string, mixed>}> */
    private array $history = [];

    protected function setUp(): void
    {
        $keys = VapidKeys::generate();
        $this->config = new Repository(['chat' => ['notifications' => ['push_hosts' => ['push.example.test']]], 'services' => ['webpush' => [
            'subject' => 'mailto:test@example.test', 'public_key' => $keys->publicKey, 'private_key' => $keys->privateKey,
        ]]]);
    }

    private function subscription(): UserPushSubscription
    {
        return new UserPushSubscription(['provider' => 'webpush', 'enabled' => true, 'token' => 'https://push.example.test/subscription/secret',
            'keys' => ['p256dh' => VapidKeys::generate()->publicKey, 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')]]);
    }

    private function transport(mixed $response): ChatBrowserPushTransport
    {
        $http = new Factory;
        $http->fake(function (Request $request, array $options) use ($response) {
            $this->history[] = ['request' => $request, 'options' => $options];

            return $response;
        });

        return new ChatBrowserPushTransport($this->config, new WebPushSender($this->config, $http));
    }

    public function test_encrypts_with_oblivions_sender_and_keeps_the_provider_queue_and_topic_bounded(): void
    {
        $payload = ['title' => 'New Chat message', 'body' => 'Open Chat to view the message.', 'data' => ['notification_id' => 'private-stable-id']];
        self::assertSame('accepted', $this->transport(Factory::response('', 201))->send($this->subscription(), $payload));
        self::assertCount(1, $this->history);
        $request = $this->history[0]['request']->toPsrRequest();
        self::assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        self::assertSame('60', $request->getHeaderLine('TTL'));
        self::assertSame('normal', $request->getHeaderLine('Urgency'));
        self::assertSame(substr(hash('sha256', 'private-stable-id'), 0, 32), $request->getHeaderLine('Topic'));
        self::assertStringStartsWith('vapid t=', $request->getHeaderLine('Authorization'));
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
        self::assertSame($expected, $this->transport(Factory::response('Private provider detail', $status, ['Location' => 'https://private.example.test']))
            ->send($this->subscription(), ['data' => ['notification_id' => 'one']]));
        self::assertCount(1, $this->history);
    }

    public function test_handles_an_uncertain_network_result_as_retry_without_retaining_its_reason(): void
    {
        $transport = $this->transport(Factory::failedConnection('Private endpoint and keys'));
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
        self::assertSame('rejected', $this->transport(Factory::response('', 201))->send($subscription, []));
        self::assertCount(0, $this->history);
    }

    public function test_missing_configuration_invalid_keys_and_native_tokens_never_send(): void
    {
        $transport = $this->transport(Factory::response('', 201));
        $subscription = $this->subscription();
        $subscription->keys = ['p256dh' => 'invalid', 'auth' => 'invalid'];
        self::assertSame('rejected', $transport->send($subscription, []));
        $subscription = $this->subscription();
        $subscription->keys = ['p256dh' => ['not', 'a', 'string'], 'auth' => 'invalid'];
        self::assertSame('rejected', $transport->send($subscription, []));
        $subscription = $this->subscription();
        $subscription->provider = 'expo';
        self::assertSame('rejected', $transport->send($subscription, []));
        $this->config->set('services.webpush.private_key', '');
        self::assertSame('unavailable', $transport->send($this->subscription(), []));
        self::assertCount(0, $this->history);
    }
}
