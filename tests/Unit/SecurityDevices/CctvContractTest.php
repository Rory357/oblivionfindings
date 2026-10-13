<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Cctv\Services\CctvContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CctvContractTest extends TestCase
{
    public static function offer(): string
    {
        $fingerprint = implode(':', array_fill(0, 32, 'AA'));

        return "v=0\r\no=- 1 1 IN IP4 0.0.0.0\r\ns=-\r\nt=0 0\r\na=group:BUNDLE 0\r\nm=video 9 UDP/TLS/RTP/SAVPF 96\r\nc=IN IP4 0.0.0.0\r\na=mid:0\r\na=recvonly\r\na=rtcp-mux\r\na=ice-ufrag:opaque\r\na=ice-pwd:opaquecredentiallongenough\r\na=ice-options:trickle\r\na=fingerprint:sha-256 {$fingerprint}\r\na=setup:actpass\r\na=rtpmap:96 H264/90000\r\na=fmtp:96 packetization-mode=1;profile-level-id=42e01f\r\na=candidate:1 1 udp 1 192.0.2.10 40000 typ relay raddr 0.0.0.0 rport 0\r\na=end-of-candidates\r\n";
    }

    private function deny(callable $operation): void
    {
        try {
            $operation();
            self::fail('Unsafe CCTV input accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('invalid_request', $e->getMessage());
        }
    }

    public function test_only_complete_relay_video_h264_negotiation_is_accepted(): void
    {
        $offer = self::offer();
        self::assertSame($offer, CctvContract::sdp($offer, 'recvonly'));
        $answer = str_replace('a=recvonly', 'a=sendonly', $offer);
        self::assertSame($answer, CctvContract::sdp($answer, 'sendonly'));
        foreach ([str_replace('typ relay', 'typ host', $offer), str_replace('H264/90000', 'VP8/90000', $offer), str_replace('a=recvonly', 'a=sendrecv', $offer), str_replace('m=video', 'm=audio', $offer), $offer."m=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\n", str_replace('a=rtcp-mux', '', $offer), $offer."a=remote-candidates:1 127.0.0.1 22\r\n", str_replace('SAVPF 96', 'SAVPF 96 97', $offer), str_repeat('x', 32769), $offer."\0", $offer."a=rtpmap:97 rtx/90000\r\n"] as $bad) {
            $this->deny(fn () => CctvContract::sdp($bad, 'recvonly'));
        }
    }

    public function test_original_chrome_profile_count_and_optional_completion_marker_preserve_offer_bytes(): void
    {
        $offer = str_replace("a=end-of-candidates\r\n", '', self::offer());
        $maps = [];
        foreach (range(96, 105) as $payload) {
            $maps[] = "a=rtpmap:{$payload} H264/90000\r\n";
        }
        $offer = str_replace('SAVPF 96', 'SAVPF '.implode(' ', range(96, 105)), $offer);
        $offer = str_replace("a=rtpmap:96 H264/90000\r\n", implode('', $maps), $offer);
        self::assertSame($offer, CctvContract::sdp($offer, 'recvonly'));
        $this->deny(fn () => CctvContract::sdp(str_replace("a=candidate:1 1 udp 1 192.0.2.10 40000 typ relay raddr 0.0.0.0 rport 0\r\n", '', $offer), 'recvonly'));
        $this->deny(fn () => CctvContract::sdp(str_replace('SAVPF '.implode(' ', range(96, 105)), 'SAVPF '.implode(' ', range(96, 112)), $offer).implode('', array_map(fn ($p) => "a=rtpmap:{$p} H264/90000\r\n", range(106, 112))), 'recvonly'));
        $this->deny(fn () => CctvContract::sdp(str_replace(['SAVPF 96 ', 'a=rtpmap:96 '], ['SAVPF 128 ', 'a=rtpmap:128 '], $offer), 'recvonly'));
    }

    public function test_dtls_requires_one_complete_sha256_fingerprint_in_offer_and_answer(): void
    {
        $offer = self::offer();
        $line = 'a=fingerprint:sha-256 '.implode(':', array_fill(0, 32, 'AA'));
        foreach (['a=fingerprint:sha-256 AA:BB', 'a=fingerprint:sha-1 '.implode(':', array_fill(0, 32, 'AA')), 'a=fingerprint:sha-256 '.implode(':', array_fill(0, 31, 'AA')), 'a=fingerprint:sha-256 '.implode(':', array_fill(0, 33, 'AA')), 'a=fingerprint:sha-256 '.implode(':', array_fill(0, 32, 'GG')), ''] as $bad) {
            $this->deny(fn () => CctvContract::sdp(str_replace($line, $bad, $offer), 'recvonly'));
            $this->deny(fn () => CctvContract::sdp(str_replace([$line, 'a=recvonly'], [$bad, 'a=sendonly'], $offer), 'sendonly'));
        }
        $this->deny(fn () => CctvContract::sdp($offer.$line."\r\n", 'recvonly'));
    }

    public function test_locator_keeps_exact_query_but_denies_other_authorities_credentials_and_schemes(): void
    {
        $config = ['stream_address' => '10.44.0.10', 'stream_port' => 7441, 'tls_server_name' => 'nvr.example.test'];
        $url = 'rtsps://nvr.example.test:7441/opaque-token?enableSrtp';
        self::assertSame($url, CctvContract::locator($url, $config));
        foreach (['rtsp://nvr.example.test:7441/token', 'rtsps://other.example.test:7441/token', 'rtsps://nvr.example.test:443/token', 'rtsps://username@nvr.example.test:7441/token', 'rtsps://username:password@nvr.example.test:7441/token', 'rtsps://nvr.example.test:7441/token#control', 'rtsps://nvr.example.test:7441/', 'rtsps://nvr.example.test/token', "rtsps://nvr.example.test:7441/token\n"] as $bad) {
            $this->deny(fn () => CctvContract::locator($bad, $config));
        }
    }

    public function test_network_requires_exact_approved_port_and_respects_ipv4_ipv6_exclusions(): void
    {
        self::assertTrue(CctvContract::network(['10.44.0.0/24'], [], ['tcp' => [7441]], '10.44.0.10', 7441));
        self::assertFalse(CctvContract::network(['10.44.0.0/24'], ['10.44.0.8/29'], ['tcp' => [7441]], '10.44.0.10', 7441));
        self::assertFalse(CctvContract::network(['10.44.0.0/24'], [], ['tcp' => [443]], '10.44.0.10', 7441));
        self::assertFalse(CctvContract::network(['10.44.0.0/24'], [], ['tcp' => [7441]], '10.45.0.10', 7441));
        self::assertTrue(CctvContract::network(['fd00:44::/64'], [], ['tls' => [7441]], 'fd00:44::10', 7441));
        self::assertFalse(CctvContract::network(['fd00:44::/64'], ['fd00:44::10'], ['tls' => [7441]], 'fd00:44::10', 7441));
        self::assertFalse(CctvContract::network(['10.44.0.0/24'], ['invalid'], ['tcp' => [7441]], '10.44.0.10', 7441));
    }

    public function test_setup_is_closed_typed_and_cannot_supply_a_media_url(): void
    {
        $input = ['expected_actor_id' => 9, 'request_key' => '11111111-1111-4111-8111-111111111111', 'expected_revision' => 0, 'recorder_device_id' => 7, 'collector_id' => 3, 'discovery_scope_id' => 4, 'console_id' => 'console-one', 'provider_camera_id' => 'protect-camera-one', 'stream_address' => '10.44.0.10', 'stream_port' => 7441, 'tls_server_name' => 'nvr.example.test', 'tls_pin' => str_repeat('a', 64), 'purpose' => 'Review this camera and recorder source.'];
        self::assertSame($input, CctvContract::sourceInput($input));
        foreach ([['url' => 'rtsps://arbitrary'], ['expected_actor_id' => '9'], ['stream_address' => 'nvr.example.test'], ['stream_port' => 65536], ['tls_pin' => str_repeat('A', 64)], ['console_id' => 'console/other'], ['provider_camera_id' => '%2fother'], ['purpose' => "hidden\u{202E}text"], ['collector_id' => 1.0], ['request_key' => str_repeat('a', 36)]] as $change) {
            $this->deny(fn () => CctvContract::sourceInput(array_replace($input, $change)));
        }
    }

    public function test_claimed_relay_addresses_ports_and_connection_metadata_are_bound_to_the_deployment_pool(): void
    {
        $turn = ['urls' => ['turns:relay.example.test:5349'], 'username' => 'opaque', 'credential' => 'opaque', 'expires_at' => '2026-10-08T00:05:00Z', 'relay_cidrs' => ['192.0.2.0/24'], 'relay_port_min' => 39000, 'relay_port_max' => 41000];
        $offer = self::offer();
        CctvContract::relaySdp($offer, $turn, 'recvonly');
        self::assertTrue(true);
        foreach ([str_replace('raddr 0.0.0.0', 'raddr invalid-host', $offer), str_replace('rport 0', 'rport 65536', $offer), str_replace('192.0.2.10', '127.0.0.1', $offer), str_replace('40000 typ relay', '22 typ relay', $offer), str_replace('c=IN IP4 0.0.0.0', 'c=IN IP4 127.0.0.1', $offer), $offer."a=rtcp:22 IN IP4 127.0.0.1\r\n", $offer."a=control:rtsps://other.example/control\r\n"] as $bad) {
            $this->deny(fn () => CctvContract::relaySdp($bad, $turn, 'recvonly'));
        }
        foreach ([['relay_cidrs' => ['0.0.0.0/0']], ['relay_cidrs' => ['::/0']], ['relay_port_min' => 1], ['relay_port_min' => 40001, 'relay_port_max' => 40000], ['relay_cidrs' => []], ['untrusted' => true]] as $change) {
            $this->deny(fn () => CctvContract::relay(array_replace($turn, $change)));
        }
    }

    public function test_original_ordered_material_hashes_are_distinct_from_idempotency_object_key_order(): void
    {
        $source = ['url' => 'rtsps://10.44.0.10:7441/token?enableSrtp', 'username' => null, 'password' => null];
        self::assertSame('{"url":"rtsps://10.44.0.10:7441/token?enableSrtp","username":null,"password":null}', CctvContract::json($source));
        $reverse = array_reverse($source, true);
        self::assertNotSame(hash('sha256', CctvContract::json($source)), hash('sha256', CctvContract::json($reverse)));
        self::assertSame(CctvContract::hash($source), CctvContract::hash($reverse));
    }
}
