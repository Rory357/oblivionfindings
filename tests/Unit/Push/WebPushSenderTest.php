<?php

namespace Tests\Unit\Push;

use App\Services\Push\PushResult;
use App\Services\Push\WebPushSender;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Delivery, VAPID and result mapping, against a faked HTTP client. On Windows run with OPENSSL_CONF set. */
class WebPushSenderTest extends TestCase
{
    use BrowserSide;

    /** A pair in the stored .env form, as `webpush:vapid` writes it (test-only key). */
    private const VAPID_PUBLIC = 'BBQ7OUV-ahvaBqGeiw7UJCTnp3Qr4XpmYiKDGvkalT63usYapfRfm7QH2Eryge8v6mmXsydf4O1MLGReeGqPnmY';

    private const VAPID_PRIVATE = 'Vuca0WUF9thPoZZOpxaFa0bN_A422RUO_lOUrrEyEhY';

    /** A subscription in the form browsers store it (base64url, unpadded) — the RFC 8291 example user agent. */
    private const UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

    private const UA_PRIVATE = 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94';

    private const AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';

    private const ENDPOINT = 'https://push.example.test/wpush/v2/opaque-subscription-id?x=1';

    private const OPTIONS = ['ttl' => 60, 'urgency' => 'normal', 'topic' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'];

    private Repository $config;

    private Factory $http;

    /** @var list<array{request: Request, options: array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->config = new Repository(['services' => ['webpush' => [
            'subject' => 'mailto:push@oblivion.example', 'public_key' => self::VAPID_PUBLIC, 'private_key' => self::VAPID_PRIVATE,
        ]]]);
        Carbon::setTestNow(Carbon::create(2026, 10, 23, 9, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    private function sender(mixed $response = null): WebPushSender
    {
        $this->sent = [];
        $this->http = new Factory;
        $this->http->fake(function (Request $request, array $options) use ($response) {
            $this->sent[] = ['request' => $request, 'options' => $options];

            return $response ?? Factory::response('', 201);
        });

        return new WebPushSender($this->config, $this->http);
    }

    private function sendDefault(WebPushSender $sender, string $payload = '{"title":"New message"}'): PushResult
    {
        return $sender->send(self::ENDPOINT, self::UA_PUBLIC, self::AUTH, $payload, self::OPTIONS);
    }

    private function header(int $index, string $name): string
    {
        return $this->sent[$index]['request']->toPsrRequest()->getHeaderLine($name);
    }

    public function test_posts_one_encrypted_record_with_the_rfc_8030_headers_and_no_redirects(): void
    {
        $payload = '{"title":"Medication due","body":"Open Oblivion to record the dose."}';
        self::assertSame(PushResult::Accepted, $this->sendDefault($this->sender(), $payload));

        self::assertCount(1, $this->sent);
        $request = $this->sent[0]['request'];
        self::assertSame('POST', $request->method());
        self::assertSame(self::ENDPOINT, $request->url());
        self::assertSame('aes128gcm', $this->header(0, 'Content-Encoding'));
        self::assertSame('application/octet-stream', $this->header(0, 'Content-Type'));
        self::assertSame('60', $this->header(0, 'TTL'));
        self::assertSame('normal', $this->header(0, 'Urgency'));
        self::assertSame(self::OPTIONS['topic'], $this->header(0, 'Topic'));
        self::assertFalse($this->sent[0]['options']['allow_redirects']);
        self::assertSame(3, $this->sent[0]['options']['connect_timeout']);
        self::assertSame(10, $this->sent[0]['options']['timeout']);

        $body = $request->body();
        self::assertStringNotContainsString('Medication', $body);
        [$uaPrivate, $uaPublic] = self::browserKeyPair(self::unb64u(self::UA_PRIVATE));
        self::assertSame($payload, self::decrypt($body, $uaPrivate, $uaPublic, self::unb64u(self::AUTH)));
    }

    public function test_omits_the_topic_header_when_no_topic_is_given(): void
    {
        $this->sender()->send(self::ENDPOINT, self::UA_PUBLIC, self::AUTH, 'x', ['ttl' => 0, 'urgency' => 'high']);

        self::assertSame('', $this->header(0, 'Topic'));
        self::assertSame('0', $this->header(0, 'TTL'));
        self::assertSame('high', $this->header(0, 'Urgency'));
    }

    public function test_the_vapid_token_verifies_with_its_key_and_names_only_the_push_origin(): void
    {
        $this->sendDefault($this->sender());
        $token = self::verifyVapid($this->header(0, 'Authorization'));

        self::assertSame(['typ' => 'JWT', 'alg' => 'ES256'], $token['header']);
        self::assertSame('https://push.example.test', $token['claims']['aud']);
        self::assertSame('mailto:push@oblivion.example', $token['claims']['sub']);
        self::assertSame(self::unb64u(self::VAPID_PUBLIC), $token['key']);
        $now = Carbon::now()->getTimestamp();
        self::assertGreaterThan($now, $token['claims']['exp']);
        self::assertLessThanOrEqual($now + 24 * 3600, $token['claims']['exp'], 'RFC 8292: at most 24 hours ahead');
        self::assertSame($now + 12 * 3600, $token['claims']['exp']);
    }

    public function test_the_audience_keeps_a_non_default_port_and_drops_case_path_and_query(): void
    {
        $sender = $this->sender();
        $sender->send('https://PUSH.Example.test:8443/a/b?c=d', self::UA_PUBLIC, self::AUTH, 'x', self::OPTIONS);

        self::assertSame('https://push.example.test:8443', self::verifyVapid($this->header(0, 'Authorization'))['claims']['aud']);
    }

    public function test_reuses_a_token_per_origin_until_an_hour_before_it_expires(): void
    {
        $sender = $this->sender();
        $this->sendDefault($sender);
        $first = $this->header(0, 'Authorization');

        Carbon::setTestNow(Carbon::now()->addHours(10)->addMinutes(59));
        $this->sendDefault($sender);
        self::assertSame($first, $this->header(1, 'Authorization'), 'reused within 11 hours');

        $sender->send('https://other.example.test/x', self::UA_PUBLIC, self::AUTH, 'x', self::OPTIONS);
        self::assertSame('https://other.example.test', self::verifyVapid($this->header(2, 'Authorization'))['claims']['aud']);

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->sendDefault($sender);
        $renewed = $this->header(3, 'Authorization');
        self::assertNotSame($first, $renewed, 'renewed an hour before expiry');
        self::assertSame(Carbon::now()->getTimestamp() + 12 * 3600, self::verifyVapid($renewed)['claims']['exp']);
    }

    public function test_a_changed_key_or_subject_gets_a_new_token(): void
    {
        $sender = $this->sender();
        $this->sendDefault($sender);
        $this->config->set('services.webpush.subject', 'https://oblivion.example/contact');
        $this->sendDefault($sender);

        self::assertSame('https://oblivion.example/contact', self::verifyVapid($this->header(1, 'Authorization'))['claims']['sub']);
    }

    public function test_verifies_the_rfc_8292_example_token_with_the_same_jws_handling(): void
    {
        // RFC 8292 section 2.4: proves the R ‖ S reading used by these tests against an outside signature.
        $token = self::verifyVapid('vapid t=eyJ0eXAiOiJKV1QiLCJhbGciOiJFUzI1NiJ9.eyJhdWQiOiJodHRwczovL3B1c2guZXhhbXBsZS5uZXQiLCJleHAiOjE0NTM1MjM3NjgsInN1YiI6Im1haWx0bzpwdXNoQGV4YW1wbGUuY29tIn0.'
            .'i3CYb7t4xfxCDquptFOepC9GAu_HLGkMlMuCGSK2rpiUfnK9ojFwDXb1JrErtmysazNjjvW2L9OkSSHzvoD1oA, '
            .'k=BA1Hxzyi1RUM1b5wjxsn7nGxAszw2u61m164i3MrAIxHF6YK5h4SDYic-dRuU_RCPCfA5aq9ojSwk5Y2EmClBPs');

        self::assertSame(['aud' => 'https://push.example.net', 'exp' => 1453523768, 'sub' => 'mailto:push@example.com'], $token['claims']);
    }

    public static function statuses(): array
    {
        return [
            [200, PushResult::Accepted], [201, PushResult::Accepted], [202, PushResult::Accepted],
            [404, PushResult::Expired], [410, PushResult::Expired],
            [408, PushResult::Retry], [429, PushResult::Retry], [500, PushResult::Retry], [502, PushResult::Retry], [503, PushResult::Retry],
            [401, PushResult::Unavailable], [403, PushResult::Unavailable],
            [400, PushResult::Rejected], [413, PushResult::Rejected], [301, PushResult::Rejected], [302, PushResult::Rejected], [100, PushResult::Rejected],
        ];
    }

    #[DataProvider('statuses')]
    public function test_maps_every_push_service_status(int $status, PushResult $expected): void
    {
        $response = Factory::response('Private provider detail', $status, ['Location' => 'https://private.example.test/next']);

        self::assertSame($expected, $this->sendDefault($this->sender($response)));
        self::assertCount(1, $this->sent, 'one request, redirects not followed');
        self::assertSame($expected, PushResult::fromStatus($status));
    }

    public function test_no_response_means_retry(): void
    {
        self::assertSame(PushResult::Retry, $this->sendDefault($this->sender(Factory::failedConnection('Private endpoint detail'))));
        self::assertSame(PushResult::Retry, PushResult::fromStatus(null));
    }

    public static function malformedSubscriptions(): array
    {
        $point = self::unb64u(self::UA_PUBLIC);

        return [
            'p256dh not base64' => ['not base64!', self::AUTH],
            'p256dh too short' => [self::b64u(substr($point, 0, 64)), self::AUTH],
            'p256dh without 0x04' => [self::b64u("\x05".substr($point, 1)), self::AUTH],
            'p256dh off the curve' => [self::b64u("\x04".str_repeat("\x01", 64)), self::AUTH],
            'auth too short' => [self::UA_PUBLIC, self::b64u(random_bytes(15))],
            'auth not base64' => [self::UA_PUBLIC, 'auth key'],
            'empty keys' => ['', ''],
        ];
    }

    #[DataProvider('malformedSubscriptions')]
    public function test_rejects_malformed_subscription_keys_before_any_network_call(string $p256dh, string $auth): void
    {
        self::assertSame(PushResult::Rejected, $this->sender()->send(self::ENDPOINT, $p256dh, $auth, 'x', self::OPTIONS));
        self::assertCount(0, $this->sent);
    }

    public static function unusableRequests(): array
    {
        return [
            'plain http endpoint' => ['http://push.example.test/x', 'x', self::OPTIONS],
            'not a URL' => ['push.example.test/x', 'x', self::OPTIONS],
            'payload over one record' => [self::ENDPOINT, str_repeat('a', 3994), self::OPTIONS],
            'negative TTL' => [self::ENDPOINT, 'x', ['ttl' => -1, 'urgency' => 'normal']],
            'TTL as text' => [self::ENDPOINT, 'x', ['ttl' => '60', 'urgency' => 'normal']],
            'unknown urgency' => [self::ENDPOINT, 'x', ['ttl' => 60, 'urgency' => 'urgent']],
            'topic too long' => [self::ENDPOINT, 'x', ['ttl' => 60, 'urgency' => 'low', 'topic' => str_repeat('a', 33)]],
            'topic outside base64url' => [self::ENDPOINT, 'x', ['ttl' => 60, 'urgency' => 'low', 'topic' => 'chat:1']],
        ];
    }

    #[DataProvider('unusableRequests')]
    public function test_rejects_requests_it_cannot_send_as_they_are(string $endpoint, string $payload, array $options): void
    {
        self::assertSame(PushResult::Rejected, $this->sender()->send($endpoint, self::UA_PUBLIC, self::AUTH, $payload, $options));
        self::assertCount(0, $this->sent);
    }

    public static function brokenVapidSettings(): array
    {
        return [
            'no subject' => ['subject', ''],
            'no public key' => ['public_key', ''],
            'no private key' => ['private_key', ''],
            'public key not a point' => ['public_key', 'BBQ7OUV-ahvaBqGeiw7UJCTnp3Qr4XpmYiKDGvkalT63'],
            'private key too long' => ['private_key', self::b64u(random_bytes(33))],
            'private key of another pair' => ['private_key', 'OB-RmD3eaBcCkAN4Qccn000_NEl3J0IGjpZNBm6VLiA'],
        ];
    }

    #[DataProvider('brokenVapidSettings')]
    public function test_missing_or_mismatched_vapid_settings_are_unavailable_without_sending(string $key, string $value): void
    {
        $this->config->set('services.webpush.'.$key, $value);

        self::assertSame(PushResult::Unavailable, $this->sendDefault($this->sender()));
        self::assertCount(0, $this->sent);
    }

    public function test_stored_keys_in_padded_or_standard_base64_still_sign_with_the_same_identity(): void
    {
        $this->config->set('services.webpush.public_key', base64_encode(self::unb64u(self::VAPID_PUBLIC)));
        $this->config->set('services.webpush.private_key', base64_encode(self::unb64u(self::VAPID_PRIVATE)));
        $sender = $this->sender();

        self::assertSame(PushResult::Accepted, $sender->send(self::ENDPOINT, self::UA_PUBLIC.'=', self::AUTH.'==', 'x', self::OPTIONS));
        self::assertSame(self::unb64u(self::VAPID_PUBLIC), self::verifyVapid($this->header(0, 'Authorization'))['key']);
    }
}
