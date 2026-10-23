<?php

namespace Tests\Unit\Push;

use App\Services\Push\PushEndpoints;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The push-host allow-list shared by Chat push and PushChannel notices. */
class PushEndpointsTest extends TestCase
{
    private function endpoints(): PushEndpoints
    {
        return new PushEndpoints(new Repository(['chat' => ['notifications' => ['push_hosts' => [
            'fcm.googleapis.com', 'web.push.apple.com', '.notify.windows.com',
        ]]]]));
    }

    public static function allowed(): array
    {
        return array_map(fn ($url) => [$url], [
            'https://fcm.googleapis.com/fcm/send/abc:def',
            'https://web.push.apple.com/QK7l3a',
            'https://wns2-bl2p.notify.windows.com/w/?token=BQYAAAB',
            'https://WNS2-PAR02P.Notify.Windows.com/w/?token=x',
            'https://a.b.notify.windows.com/w/',
        ]);
    }

    #[DataProvider('allowed')]
    public function test_allows_listed_hosts_and_hosts_below_a_dotted_domain(string $url): void
    {
        self::assertTrue($this->endpoints()->allows($url));
    }

    public static function refused(): array
    {
        return array_map(fn ($url) => [$url], [
            'https://notify.windows.com/w/',                       // the bare domain is not "below" it
            'https://xnotify.windows.com/w/',                      // a different domain sharing the tail
            'https://wns2.notify.windows.com.attacker.test/w/',
            'https://attacker.test/wns2.notify.windows.com',
            'https://wns2.notify.windows.com./w/',
            'https://wns2.notify.windows.com:8443/w/',
            'http://wns2.notify.windows.com/w/',
            'https://user@wns2.notify.windows.com/w/',
            'https://wns2.notify.windows.com/w/#x',
            'https://sub.fcm.googleapis.com/fcm/send/x',           // exact entries don't cover subdomains
            'https://203.0.113.7/w/',
            'not a url',
        ]);
    }

    #[DataProvider('refused')]
    public function test_refuses_everything_else(string $url): void
    {
        self::assertFalse($this->endpoints()->allows($url));
    }

    public function test_a_lone_dot_allows_nothing(): void
    {
        $endpoints = new PushEndpoints(new Repository(['chat' => ['notifications' => ['push_hosts' => ['.', '']]]]));

        self::assertFalse($endpoints->allows('https://fcm.googleapis.com/x'));
    }
}
